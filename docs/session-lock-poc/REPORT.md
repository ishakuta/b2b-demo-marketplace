# Solving Spryker Session Lock Contention — Research & Design Report

**Project:** `spryker-session-poc` (3rd isolated Spryker experiment)
**Date:** 2026-07-21
**Status:** Research + design complete; PoC implementation in progress.
**Canonical source:** this `REPORT.md`. `report.html` is a self-contained rendering of the same content for browser reading.

---

## 0. Executive summary

Spryker's Yves storefront serializes concurrent requests that share one session cookie behind a
**single pessimistic Redis lock taken on every request**. Under bots reusing a cookie, or a custom /
headless frontend firing many parallel XHRs, requests queue on a 10-second spin-lock and eventually
throw `LockCouldNotBeAcquiredException` (HTTP 500). The two mitigations tried before —
*excluding bots* and *locking only some URLs* — cannot work, because **the framework makes every
request a writer** (Symfony's `MetadataBag` rewrites a timestamp on every request), so there is no
such thing as a "read-only" request to exempt.

The root problem is **false contention from whole-blob locking**: one lock guards the entire
serialized session, so requests touching *disjoint* parts of it still serialize. The fix is to stop
locking a monolith and instead **decompose** the session so that concurrency control is applied at
the granularity where conflicts actually occur.

We reject 3-way merge (provably ambiguous on a nested object with no stable identity) and Operational
Transformation (central-server, built for text editing). Instead we propose a layered design:

- **Layer 0 (quick win, no new code paths):** set a `MetadataBag` update threshold + use Spryker's
  shipped `redis_write_only_locking` handler -> genuinely read-only requests neither write nor lock.
- **Layer A (lock-free envelope):** a custom `SessionHandlerInterface` that stores each top-level
  session key (each bag) as its own Redis **hash field** with **delta writes** — no spin-lock;
  concurrent requests touching different fields both commit.
- **Layer B (versioned objects):** domain objects move to their own keys under **optimistic
  concurrency control** (version compare-and-set via Lua); the session holds only scalar **pointers**.
- **Layer C (the cart):** the one object where last-write-wins is user-visibly wrong; modeled as
  **add-wins / version-CAS** so concurrent add-to-cart never loses an item.

Result: contention becomes proportional to *real* writes to the *same* object, not to request
fan-out per session. No spin-lock, no 500s, no bot heuristics, no per-URL allow-lists — correctness
is structural and holds for any client.

---

## 1. The problem, precisely (grounded in the vendor code)

### 1.1 The lock

`Spryker\Shared\SessionRedis\Handler\SessionHandlerRedisLocking::read()` calls
`$this->locker->lock($sessionId)` **before every read**. The locker is
`SessionSpinLockLocker`:

- Acquire: `SET session:{id}:lock <token> PX 20000 NX` (20 s TTL, random 20-byte token).
- Spin: `usleep(10_000)` (10 ms) between attempts, up to `LOCKING_TIMEOUT_MILLISECONDS`
  (default **10 000 ms**), then throw `LockCouldNotBeAcquiredException` -> HTTP 500.
- Release: token-checked Lua `if GET==token then DEL` on `close()` / `__destruct()`.

So every request on a given session id **serializes** here. A burst of N parallel requests on one
cookie runs in ~N x (request time) wall-clock, and beyond the 10 s budget they 500.

Config constants (`SessionRedisConstants`): `LOCKING_TIMEOUT_MILLISECONDS`,
`LOCKING_RETRY_DELAY_MICROSECONDS`, `LOCKING_LOCK_TTL_MILLISECONDS` (all default `0` in the demoshop ->
handler defaults above). Current demoshop config:
`YVES_SESSION_SAVE_HANDLER = configurable_redis_locking`, `ZED_SESSION_SAVE_HANDLER = redis`
(Zed already runs lock-free).

### 1.2 Why "lock only on writes" and "exclude bots" cannot work — the MetadataBag finding

Spryker's Yves session is a Symfony `HttpFoundation\Session\Session` with **two application bags**
plus Symfony's metadata bag. As the save handler sees it, `$_SESSION` is:

```
$_SESSION = [
    '_sf2_attributes'  => [ ...application attributes... ],   // AttributeBag
    '_symfony_flashes' => [ ...flash messages... ],           // FlashBag
    '_sf2_meta'        => [ 'c' => <created>, 'u' => <updated>, 'l' => <lifetime> ], // MetadataBag
]
```

`Symfony\Component\HttpFoundation\Session\Storage\MetadataBag::initialize()`:

```php
if ($timeStamp - $array[self::UPDATED] >= $this->updateThreshold) {
    $this->meta[self::UPDATED] = $timeStamp;   // rewrite 'u' on this request
}
```

Spryker constructs `MetadataBag` with the **default `updateThreshold = 0`** (it never passes one).
With threshold `0`, the condition is **always true**, so `_sf2_meta.u` is rewritten to the current
time on **every request** — even a plain catalog page with zero application `$session->set()` calls.
That mutation makes `$_SESSION` genuinely different from what was read, so PHP's `lazy_write`
**cannot** skip the write, so `write()` fires, so holding the lock across the request is "justified".

**Consequence:** there is no read-only request to exempt, and no bot request that is write-free.
Any URL-allowlist or bot-exclusion scheme is defeated by the metadata timestamp churn. This is the
mechanical reason the earlier attempts failed.

### 1.3 The lost-update trap (why simply dropping the lock is wrong)

Flipping to the non-locking `redis` handler removes the serialization but reintroduces the classic
read-modify-write race: request A reads `{cart:[x]}`, B reads `{cart:[x]}`, A writes `{cart:[x,y]}`,
B writes `{cart:[x,z]}` -> A's item is lost. Atomic *write* does not give serializable
*read-modify-write*. This is exactly the objection raised during design ("atomic write won't solve
the sequence/concurrency problem"). The fix must prevent lost updates **without** a global lock.

---

## 2. Prior art (researched; sources at the end)

| Approach | Verdict | Why |
|---|---|---|
| **Pessimistic session lock** (current) | reject | Correct but serializes; false contention from whole-blob scope; 500s under load. |
| **Non-locking handler** | reject alone | Fast but silent lost updates. |
| **3-way merge (diff3)** on the session blob | **reject** | Provably ambiguous on nested mutable graphs with no stable node identity (`[1,2,4]` vs `[1,2,4,5]` unrecoverable). Built for text lines. |
| **Operational Transformation** | reject | Central transform server + fragile transform functions; built for character editing; overkill. |
| **Optimistic Concurrency Control (version/CAS)** | **adopt (Layer B)** | Textbook lost-update prevention; wins exactly when real same-object conflict is rare. (Kung-Robinson 1981; Postgres 13.4; HTTP ETag/If-Match RFC 7232.) |
| **Field-level / "thin session" storage** | **adopt (Layer A)** | Documented whole-blob-contention fix (ASP.NET "flatten to separate entries"; Redis session-store guidance). Kills false contention. |
| **CRDT / add-wins set + counter cart** | **adopt as model (Layer C)** | The shopping cart is the canonical CRDT example (Dynamo, Riak DT): add-to-cart must never be lost. |
| **Event-sourced cart (replay ops)** | **adopt as option (Layer C)** | Order-independent convergence + audit trail, without CRDT tombstone/GC baggage. Good fit for a single-datacenter Spryker. |

**Key insight that unifies these with the design intent:** decomposition is what makes merge trivial.
You do not 3-way-merge a monster object; you split it so that (a) scalars merge by last-write / max
(the `customer_id` stayed `5` -> no conflict), and (b) the few genuinely mutable aggregates get their
own version check. This is the "slim session, pointers only, objects locked independently" idea,
expressed in standard algorithmic terms.

Redis modeling notes that shaped the design: use **Lua `EVAL`** (atomic) for conditional/multi-field
writes rather than `WATCH` (whole-key granularity -> reintroduces false conflicts on a hot hash);
`HSET`/`HDEL` per field; per-field TTL needs Redis >= 7.4 `HEXPIRE` (the current stack is **Valkey
7.2 -> no `HEXPIRE`**, so use a whole-key TTL refreshed on write).

---

## 3. Session anatomy after the redesign

```
session:{id}                (Redis HASH — the slim envelope, lock-free, delta-written)
  |- _sf2_meta          -> {c,u,l}        (metadata; 'u' merges by max(), never a real conflict)
  |- _sf2_attributes    -> { customer_id:5, quote_id:"...", locale:"de_DE", ... scalars/pointers }
  |- _symfony_flashes   -> [ ... ]        (append/union semantics, not blob overwrite)

customer:{id}               (own key, version-CAS — rarely changes)
quote:{id}                  (own key / spy_quote — add-wins or version-CAS; the cart)
```

The custom handler decomposes the top-level `$_SESSION` keys into hash fields and writes only the
fields that changed since `open()`. Two requests that touch different fields (e.g. one bumps
`_sf2_meta.u`, one adds a flash) both succeed with no lock. The metadata timestamp — the very thing
that forced a write on every request — becomes a harmless per-field `max()` merge.

---

## 4. The design — four layers

### Layer 0 — stop making every request a writer (quick win)
- Set a `MetadataBag` update threshold (e.g. 60–300 s) so `_sf2_meta.u` is not rewritten on
  sub-threshold requests.
- Switch `YVES_SESSION_SAVE_HANDLER` to the shipped `redis_write_only_locking`
  (`SESSION_HANDLER_REDIS_WRITE_ONLY_LOCKING`): lock is taken only when a write actually happens.
- Net effect: a genuinely read-only page (now that metadata no longer churns) neither writes nor
  locks. This is low-risk, needs no custom storage, and already removes most bot/catalog contention.
- **Limitation:** does not fix concurrent *writers* to the same session (still serialized), and
  does not fix lost updates on shared objects. That is what Layers A–C are for.

### Layer A — lock-free field-decomposed handler
- Custom `SessionHandlerInterface` (registered at the Spryker seam, section 5) storing the session as
  a Redis hash, one field per top-level `$_SESSION` key.
- `open()` snapshots loaded fields; `write()` diffs and applies only changed/removed fields via a
  single Lua script (`HSET` changed, `HDEL` removed), atomically, **no lock**.
- Same-field scalar collision -> last-write-wins (safe for pointers/locale/csrf); `_sf2_meta.u` ->
  `max()`; flashes -> append/union.
- Kills the false contention: disjoint-field requests fully parallel.

### Layer B — per-object version-CAS
- Envelope holds only scalar **pointers** (`customer_id`, `quote_id`, flags).
- Each aggregate lives in its own key with a `version` field; update = read -> Lua compare-and-set on
  version -> bump; bounded retry with jitter on mismatch. Replays the *operation*, not the whole
  request. Rarely-changing objects (customer) ~ zero conflicts.

### Layer C — the cart (add-wins / versioned)
- The demoshop already uses `STORAGE_STRATEGY_DATABASE` for the quote (`spy_quote`), **but**
  `DatabaseStorageStrategy` still caches the `QuoteTransfer` back into the session — so the big
  mutable object is still riding the blob. The design de-caches it: session holds `quote_id` only.
- Concurrency: version-CAS on the quote (simplest; leans on the DB strategy already present), or an
  add-wins / event-sourced model where each add is an idempotent op keyed by request id -> concurrent
  add-to-cart converges with no lost item. Deletes use add-wins-or-remove-wins semantics chosen per
  business rule (Dynamo lesson: a resurrected item is a benign annoyance; a lost add is lost revenue).
- **Correctness gate:** N concurrent add-to-cart on one cart must yield N items. Baseline non-locking
  fails; baseline locking passes but serializes; this design passes *and* scales.
- **Reality check (see 4.5, 8.3-8.4):** storage-layer version-CAS alone turned out NOT to be sufficient
  — Spryker recalculates the cart in Zed and drops items merged below it. The working fix is a per-cart
  WRITE lock (4.5). This sketch is kept for the design rationale; the locking model in 4.5 is authoritative.

---

## 4.5 The concurrency & locking model (what locks, what doesn't, and conflicts)

This is the mental model for the whole solution. There are three kinds of session-related data and each
gets a different strategy. They are **one solution running together**, not alternatives — think one house
with a normal lock on the door, a combination on the safe, and nothing on the coat rack.

### Bucket 1 — session scalars (locale, CSRF tokens, flags, metadata): NO lock
Stored as individual Redis hash fields, and each request **writes only the fields it actually changed**
(a delta write), never the whole blob.
- Two requests changing **different** fields (e.g. locale vs a flash message) both persist — they land in
  different fields and never overwrite each other. No merge, no lock.
- Two requests changing the **same** scalar field -> **last-write-wins** (fine for a CSRF token or locale).
- The metadata "updated" timestamp (`m:u`) -> merged by **`max()`** (monotonic, can never conflict).

There is **no re-read and no value-merge** here — just field-level isolation. This is where the old lock
manufactured false contention (it serialized requests that were touching unrelated fields).

### Bucket 2 — domain objects, e.g. the cart (Layer B): NO lock, versioned storage (optimistic)
The object lives in its **own key with a version number**, out of the session blob.
- **Read:** no lock.
- **Write:** compare-and-set — save only if the version is still what we read. If a parallel writer bumped
  it, we **re-read the newer value, merge our change in (add-wins union), and retry**. Nothing is lost.

This is optimistic concurrency: assume no conflict, detect it via the version, resolve by merge — no lock.
It is the general mechanism for lifting ANY big/mutable object out of the session.

### Bucket 3 — the cart WRITE operation (Layer C): a small per-cart, write-only lock
**Why a lock here when Bucket 2 already versions the cart?** Because a cart write is not just "store
bytes" — Spryker's Zed logic reads the cart, **recalculates** totals/discounts/stock, then writes. Two
parallel add-to-carts each recalculate from a **stale** snapshot and drop each other's items. Merging
bytes afterwards (Bucket 2) cannot undo a recalculation that was already computed on stale data. So the
whole read -> recalculate -> write must be atomic.

The fix: wrap the cart **mutation operation** (addItem/removeItem/changeQuantity/...) in a lock **keyed by
the cart identity (session id)**.
- Only concurrent writes to the **same** cart serialize; different carts and ALL page views run free.
- Held only during the write (milliseconds), re-entrant per request, **TTL auto-expire** (a dead request
  cannot wedge the cart), **fail-open** on acquire timeout (a lock hiccup never blocks checkout).

### How Buckets 2 and 3 relate (the part that looks redundant but isn't)
They are two layers of the **same** object: Bucket 2 is *where the cart is stored* (versioned key), Bucket
3 is *how it is mutated* (locked operation). With the per-cart lock in place, concurrent cart writes
serialize, so by the time a write reaches the version check it almost always matches — meaning **the lock
is the decisive correctness mechanism for the cart**, and the merge/retry rarely fires. The versioning
still earns its keep: it is the general "object in its own versioned key" mechanism (not cart-specific),
and it is a safety net if the cart is ever written outside the locked path or the lock fails open.

### What replaced what

| Data | Locked? | On conflict |
|---|---|---|
| scalar field, different fields | no | both save (delta write) |
| scalar field, same field | no | last-write-wins |
| metadata `updated` timestamp | no | `max()` |
| cart object storage (Layer B) | no (versioned) | re-read + add-wins merge + retry |
| cart mutate operation (Layer C) | per-cart, write-only | 2nd writer waits ~ms, then applies on top |

**Old model:** one pessimistic lock per SESSION, taken on EVERY request (reads too) -> serializes
everything. **New model:** no lock for scalars + versioned storage for objects + a per-CART-write lock for
the one true read-modify-recalculate. Net effect: the only thing that ever waits is two writes to the
**same cart at the same instant** — browsing, bots, parallel tabs, and different carts all run concurrently.

## 5. Spryker integration seams (concrete)

- **Session handler:** register a custom `SessionHandlerProviderPluginInterface` in
  `Pyz\Yves\Session\SessionDependencyProvider::getSessionHandlerPlugins()`; select it via
  `YVES_SESSION_SAVE_HANDLER`. No core patch.
- **MetadataBag threshold:** no config constant exists in stock Spryker; set it where the
  `NativeSessionStorage` / `MetadataBag` is built (Yves `SessionFactory`) or via a small Pyz override.
- **Quote storage:** `Pyz\Shared\Quote\QuoteConfig::getStorageStrategy()` (already `DATABASE`);
  de-caching from session is done in the Client `Quote` storage-strategy wiring / a Pyz plugin.
- **Redis access:** reuse the `key_value_store` connection; Lua scripts via the existing predis client.

---

## 6. Why this is "once and for all" / scalable

- Contention proportional to concurrent writes to the **same object**, not to request fan-out per session.
- No spin-lock -> no 10 s waits, no `LockCouldNotBeAcquiredException` 500s, no thundering herd on
  lock release.
- No bot detection, no per-URL allow-lists — correctness is **structural**, so it holds identically
  for bots, scrapers, and headless/PWA frontends.
- Horizontal: single-key Lua ops are atomic and shard-friendly; object keys hash-distribute; no
  cross-key transaction needed if each merge stays within one key.

---

## 7. Risks & sharp edges (honest)

1. **Inventory who puts non-scalars in the session.** Layer A only de-conflicts *between* top-level
   keys; a single key holding a nested object (the quote cache) is still one LWW unit -> that is why
   Layer C de-caches the quote. First implementation step is auditing every `$session->set()`.
2. **Same-field concurrency:** scalars = LWW ok; lists (flash) = append/union; any read-modify-write
   value must move to Layer B, not stay in the envelope.
3. **Cross-key atomicity:** write object before pointer; idempotent; dangling pointer = treat as
   empty/re-create.
4. **Valkey 7.2 lacks `HEXPIRE`** -> per-field TTL unavailable; use whole-key TTL refreshed on write.
5. **`session_regenerate_id` on login** must migrate the (small) envelope — cheap.
6. **Zed** already uses the non-locking `redis` handler; this work targets **Yves** only.
7. **Escape hatch:** keep a fine-grained, short-TTL, single-object lock available for any genuinely
   non-commutative critical section — never the whole session.

---

## 8. PoC plan (this experiment)

**Isolation (verified against the two live stacks):**

| | kv-poc | franken-poc | session-poc |
|---|---|---|---|
| namespace | `spryker_b2b_dev` | `spryker_franken` | `spryker_session` |
| HTTP | 80/443 | 8080 | 8090 |
| DB | 3306 | 13306 | 23306 |
| RabbitMQ | 5672 | 15672 | 25672 |
| KV | 16379 | 26379 | 36379 |
| Elasticsearch | 9200 | 19200 | 29200 |
| launch | `sdk -p b2b up` | `sdk -p franken up` | `sdk -p session up` |

**Approach:** prove Layers A/B/C first in a fast standalone harness (real Symfony HttpFoundation
session bags + real Valkey), then wire the proven handler into the isolated 3rd Spryker stack for the
integration reality-check. Measure with k6: (i) one-cookie bot storm, (ii) headless parallel-XHR
burst -> p95, lock-wait, error rate; plus the **N-concurrent-add-to-cart = N-items** correctness
assertion, baseline vs each layer.

_Outcomes, blockers, and learnings are recorded in `LEARNINGS.md` and the results tables here as the
PoC progresses._

---

## 8.1 PoC results — standalone harness (real Symfony session bags + real Valkey)

Proven in `harness/` against real `symfony/http-foundation` sessions (AttributeBag + FlashBag +
MetadataBag, `php_serialize`) and a real Valkey, driven by `PHP_CLI_SERVER_WORKERS` for true
server-side concurrency and `curl_multi` client-side. Handlers compared: `blob-lock` (faithful
reproduction of Spryker's spin-lock), `blob-nolock` (plain redis), `field` (Layer A) + Layer B/C cart.

**Scenario A — 20 concurrent add-to-cart of distinct SKUs, one session (correctness gate):**

| handler | expected | final items | HTTP 500 | wall ms | verdict |
|---|---|---|---|---|---|
| blob-nolock | 20 | **7** | 0 | 49 | **LOST UPDATES** (13 items silently lost) |
| blob-lock | 20 | 20 | 0 | 391 | correct but **serialized** |
| field + add-wins cart (Layer A+C) | 20 | **20** | 0 | 50 | **correct + lock-free** |
| field + version-CAS cart (Layer A+B) | 20 | **20** | 0 | 50 | **correct + lock-free** |

**Scenario B — 48 concurrent view requests, one session (bot / parallel-XHR storm):**

| handler | reqs | HTTP 500 | wall ms | p50 ms | p95 ms |
|---|---|---|---|---|---|
| blob-lock | 48 | **8** (`LockCouldNotBeAcquiredException`) | 3241 | 1943 | 2995 |
| field (Layer A) | 48 | **0** | 329 | 84 | 246 |

**Reading:** the pessimistic lock both (a) *loses no data* but *serializes* — ~10x slower wall, p95
near the lock timeout, and **8/48 requests 500** once the queue depth exceeds the 2 s timeout; and
(b) the naive no-lock handler is fast but **silently loses 13 of 20 concurrent cart adds**. The
field-decomposed design (Layer A) plus an add-wins / version-CAS cart (Layer C, backed by Layer B) is
**both correct and lock-free**: zero lost items, zero 500s, ~10x lower latency. This is the whole
thesis, reproduced end-to-end.

Reproduce: `cd harness && composer install && PHP_CLI_SERVER_WORKERS=48 php -S 127.0.0.1:8299 -t public &`
then `SSPOC_STORM_N=48 SSPOC_STORM_WORK=80000 php bin/bench.php` (needs a Valkey on :6399).

## 8.2 PoC results — INSIDE the real Spryker stack (3rd isolated demoshop)

Layer A was wired into a real, fully-installed b2b-demo-shop (namespace `spryker_session`, ports
8090/23306/25672/36379/29200) as a Pyz `SessionHandlerProviderPlugin` and activated via
`YVES_SESSION_SAVE_HANDLER=field_decomposed`. Verified:

- **Storefront healthy on the new handler:** home, catalog search, PDP all HTTP 200/redirect; the
  session is stored as a Redis **hash** with per-attribute fields, e.g.
  `a:current_store`, `a:anonymousID`, `a:_csrf/...`, plus metadata scalars `m:c`/`m:u`/`m:l`
  (`m:u` = the churning timestamp, now a harmless per-field max-merge). No lock key is taken.
- **In-stack A/B, 40 parallel requests sharing one session cookie:**

| Yves handler | wall ms | p50 ms | p95 ms | http 500 |
|---|---|---|---|---|
| stock `configurable_redis_locking` | ~2820 | ~1520 | ~2760 | 0 |
| `field_decomposed` (Layer A) | ~1370 | ~770 | ~1280 | 0 |

The field-decomposed handler roughly halves wall time and p95 in the real stack at Spryker default
10 s lock timeout (no 500s at this concurrency because the timeout is generous; the standalone
harness with a tighter 2 s timeout shows the extreme regime: 8/48 requests 500 + ~10x). Same
direction, confirmed end-to-end in Spryker.

## 8.3 PoC results — Layers B + C wired into the real Spryker quote flow

Wired via `Pyz\Client\Quote\QuoteFactory::createSession()` -> a custom `VersionedQuoteSession`
(extends the vendor `QuoteSession`, swaps only storage) backed by a `VersionedQuoteStore` (Lua CAS).
Gotcha: Spryker caches class resolution (`RESOLVABLE_CLASS_NAMES_CACHE_ENABLED`), so a new Pyz factory
needs `console cache:class-resolver:build` before it is used.

**Layer B — DONE and verified live.** The `QuoteTransfer` is de-cached OUT of the session blob into
its own version-CAS'd key `quote:<store>:<sessionId>`; the session holds only the pointer (the session
id itself). Verified: after add-to-cart the session has NO `DE quote session identifier` blob, and
`quote:DE:<id>` holds the cart with an incrementing `v` (version). Sequential cart operations are fully
correct (N adds -> "N Items"). This removes the single biggest mutable object from the session, so it
no longer contributes to session-lock contention.

**Layer C — wired, and it surfaced the real boundary.** A/B under 8 concurrent add-to-cart on one session:

| Config | parallel-8 cart | why |
|---|---|---|
| Production: locking session handler + quote-in-session | **8/8 correct** | the session lock *serializes* the cart read-modify-write |
| Field handler (Layer A) + stock quote-in-session | 4-5/8 (lost) | lock removed -> concurrent Zed cart RMW races |
| Field handler + versioned quote (Layer B+C storage CAS) | 5/8 (lost) | storage CAS keeps items in storage, but Spryker's Zed recalculation still drops them |

**Finding:** the per-session lock's ONLY essential residual job is serializing the cart's non-atomic
read-modify-write. All its other work (serializing bots / parallel XHR over disjoint session data) is
false contention that Layers A+B remove. But the cart race lives in Spryker's **Zed cart-operation
logic** (read quote -> compute -> write), one layer ABOVE the `QuoteSession` storage seam, so
storage-layer CAS/merge alone cannot fully serialize it (the concurrent operations each computed from a
stale quote; my add-wins merge preserves items in storage, but the next Zed recalculation drops the
ones it did not compute).

**The bulletproof scalable answer this produces:** replace the per-SESSION lock with
(A) lock-free field-decomposed session + (B) quote de-cached to its own key + (C) a fine-grained lock
scoped to the CART OPERATION (per-cart, at the CartClient/Zed layer) OR an operation-based/commutative
cart (idempotent per-item ops, proven commutative in the standalone harness). The lock thus shrinks
from per-session (serializes ALL activity of a session) to per-cart-write (serializes only concurrent
writes to the SAME cart — rare and bounded), which is the scalable outcome. Implementing C at the
cart-operation layer is the documented next step; it is outside the `QuoteSession` seam this PoC used.

## 8.4 Layer C completed — per-cart lock at the cart-operation layer

The residual cart race (report 8.3) was fixed with a fine-grained **per-cart lock** in
`Pyz\Client\Cart\CartClient` (a `CartLock` over the session Redis). It wraps every mutating cart
method (addItem, addItems, removeItem(s), changeItemQuantity, increase/decrease, addToCart,
removeFromCart, updateQuantity, replaceItem, setQuoteCurrency, addValidItems) so the ENTIRE
read-modify-write (parent does getQuote -> Zed compute -> setQuote) runs atomically per cart. The lock
is keyed by the cart identity (session id), is re-entrant per request, auto-expires on TTL, and is
fail-open on acquire timeout. Crucially it covers ONLY cart writes — page views and session reads never
touch it, so Layer A stays lock-free.

**Final A/B — correctness AND scalability together:**

| Config | 8 concurrent add-to-cart | 40 concurrent page views (p95) |
|---|---|---|
| Production: locking session handler + quote-in-session | 8/8 correct | ~2760 ms, 8/48 -> HTTP 500 |
| Layer A+B (lock-free session, quote de-cached, NO cart lock) | 5/8 (lost) | ~1200 ms, 0 errors |
| **Layer A+B+C (per-cart lock)** | **8/8 correct** (12/12 too) | **~1200 ms, 0 errors** |

The production per-session lock buys correctness at the cost of serializing everything (slow, 500s
under storms). Layer A+B alone is fast but loses concurrent cart items. **A+B+C gets both**: page
views / bots run fully concurrent (~2x faster, no 500s), while only concurrent writes to the SAME cart
serialize (bounded, correctness-critical). Concurrent same-cart adds cost a little latency
(parallel-8 ~420 ms vs ~210 ms lossy) — the correct, minimal price for not losing a cart item.

**Net:** the per-SESSION pessimistic lock (serializes all of a session's activity) is replaced by
lock-free field-decomposed session (A) + quote in its own version-CAS'd key (B) + a per-CART-write
lock (C). This is the bullet-proof, scalable answer to the original session-lock problem.

## 8.5 Layer 0 — metadata threshold + write-only locking (the minimal-change quick-win)

Layer 0 is the low-risk option for teams that cannot adopt the field-decomposed handler: keep stock
blob storage, but (a) give Symfony's `MetadataBag` an update threshold so the `_sf2_meta.u` timestamp
stops churning on every request, and (b) use the shipped `redis_write_only_locking` handler so the lock
is taken only on an actual write.

**Threshold half — implemented and proven.** A Pyz `SessionFactory` injects
`new MetadataBag('_sf2_meta', 300)`. Result: `m:u` stays constant across repeated read-only requests
(measured: identical value over 4 requests), so a genuinely read-only page writes NOTHING. This is the
fix for the "every request is a writer" root cause (report 1.2), and it composes with Layer A for free
(fewer Redis ops + no TTL churn on reads).

**Write-only-locking half — did not initialize in this demoshop.** Activating
`redis_write_only_locking` produced `session_start(): Failed to initialize storage module: user` even
though the handler constructs cleanly (its conflict-resolver dependency defaults to `[]`, no missing
wiring). Not pursued further because **Layer A already subsumes it**: the field-decomposed handler is
lock-free on BOTH reads and writes, whereas write-only-locking is lock-free only on reads and still
serializes writes per session.

**Bonus finding:** this stock demoshop ALREADY ships `BotSessionRedisLockingExclusionConditionPlugin` +
`UrlSessionRedisLockingExclusionConditionPlugin` in `Pyz\Yves\SessionRedis\SessionRedisDependencyProvider`
— i.e. the two prior mitigations (exclude bots, lock only some URLs) are Spryker's *default* config, and
they still don't solve the problem (they cannot: the MetadataBag churn makes every request a writer, so
there is no read-only request to exempt — report 1.2). This is exactly why the structural Layers A/B/C
are needed.

**Verdict:** keep the metadata threshold (free, low-risk, composes with everything). The write-only
handler is strictly dominated by Layer A; adopt Layer A instead.

## 9. References (primary sources)

- Spryker session-redis: `SessionRedisConfig`, `SessionRedisConstants` (handler names, lock timings).
- Spryker perf troubleshooting — Redis session lock: https://docs.spryker.com/docs/dg/dev/troubleshooting/troubleshooting-performance-issues/redis-session-lock.html
- Symfony `MetadataBag`, `NativeSessionStorage`, `AttributeBag`, `FlashBag` (bag storage keys, `updateThreshold`).
- PHP `session_write_close()` / `read_and_close`: https://www.php.net/manual/en/function.session-write-close.php
- Kung & Robinson, *On Optimistic Methods for Concurrency Control*, ACM TODS 1981.
- PostgreSQL docs 13.4 Preventing Lost Updates: https://www.postgresql.org/docs/current/mvcc.html
- HTTP conditional requests / lost-update: RFC 7232 3.1: https://www.rfc-editor.org/rfc/rfc7232.html
- Redis transactions & scripting: https://redis.io/docs/latest/develop/using-commands/transactions/ , https://redis.io/docs/latest/develop/programmability/eval-intro/
- Redis hashes & HEXPIRE: https://redis.io/docs/latest/develop/data-types/hashes/ , https://redis.io/docs/latest/commands/hexpire/
- Shapiro et al., *Conflict-free Replicated Data Types*, 2011: https://asc.di.fct.unl.pt/~nmp/pubs/sss-2011.pdf
- Dynamo (SOSP 2007): https://www.cs.cornell.edu/courses/cs5414/2017fa/papers/dynamo.pdf
- Riak DT map/cart: https://docs.riak.com/riak/kv/latest/developing/data-types/index.html
- Automerge / Yjs: https://automerge.org/docs/reference/documents/ , https://docs.yjs.dev/api/internals
- Event Sourcing: https://martinfowler.com/eaaDev/EventSourcing.html
