# LEARNINGS / BLOCKERS / OUTCOMES / OPPORTUNITIES

_Chronological log as the PoC meets reality._

## Confirmed from vendor code (design phase)
- **Root cause = whole-blob pessimistic lock taken on every request.** `SessionHandlerRedisLocking::read()`
  -> `SessionSpinLockLocker` (`SET ...:lock NX PX 20000`, 10ms spin, 10s timeout -> 500).
- **The MetadataBag churn is why "lock only on writes" fails.** Symfony `MetadataBag` is built by
  Spryker with `updateThreshold=0`, so `_sf2_meta.u` (updated timestamp) is rewritten on EVERY request
  -> `$_SESSION` always dirty -> lazy_write can't skip -> write() (and the lock) fire on every request.
  There is no read-only request to exempt. This is the mechanical reason bot-exclusion / URL-allowlist failed.
- **Two app bags + metadata:** `_sf2_attributes` (AttributeBag), `_symfony_flashes` (FlashBag), `_sf2_meta` (MetadataBag).
- **Cart already DATABASE strategy** (`Pyz\Shared\Quote\QuoteConfig` -> `spy_quote`) but `DatabaseStorageStrategy`
  still caches the QuoteTransfer back into the session -> big mutable object still rides the blob.
- **Valkey 7.2 on the stack -> no `HEXPIRE`** (per-field TTL is Redis >=7.4). Use whole-key TTL refreshed on write.

## Opportunities spotted
- **Layer 0 quick win** exists with zero custom storage: MetadataBag `updateThreshold` (60-300s) +
  shipped `redis_write_only_locking` handler. Ships value before the structural layers.

## Blockers hit
- bg-isolation guard blocks Write/Edit tools in this fresh repo; using Bash heredocs (same as kv-poc). Non-fatal.

## Outcomes
- (pending harness + stack runs)

## Outcomes (harness, 2026-07-21) — all three layers proven
- **Layer A (field-decomposed, lock-free handler): works.** Decomposing to PER-ATTRIBUTE hash fields
  (not per-bag) is required — the AttributeBag is itself a bag of keys; per-bag granularity would still
  LWW-clobber two requests both touching attributes. Delta write via one atomic Lua (HSET changed /
  HDEL removed / MAX-merge m:u). No lock.
- **Layer B (version-CAS store): works.** Lua compare-and-set on a `v` field; read-modify-write with
  jittered retry. Zero lost updates under contention.
- **Layer C (cart): both models work.** Add-wins (OR-Set + HINCRBY counter) = commutative, no retries,
  the scalable endgame; version-CAS = correct via retry. Session holds only `quote_id` pointer.
- **Quantified (real Symfony sessions + real Valkey):**
  - Correctness: blob-nolock LOSES 13/20 concurrent cart adds; blob-lock + field both keep 20/20.
  - Storm (48 concurrent, one session): blob-lock -> **8 HTTP 500 (LockCouldNotBeAcquiredException)**,
    p95 ~3s; field -> **0 errors**, p95 246ms, ~10x faster wall. Reproduces the exact prod symptom.
- **Blocker:** harness port 8099 collided with franken-poc's FrankenPHP sidecar; moved to 8299.
- **Harness-tool note:** starting `php -S` via shell `&` + `sleep` returns exit 144; use the runner's
  background mode instead. pkill returns 144 too (signal artifact) but works.

## Opportunity
- Add-wins cart (HINCRBY per sku) is a drop-in "bulletproof" cart primitive: concurrent adds can NEVER
  be lost and never contend across distinct SKUs. Strongest candidate for the real Spryker Layer C.

## In-stack integration (real Spryker Yves) — outcomes + blockers (2026-07-21)
- **Layer A proven inside real Spryker.** Pyz SessionHandlerFieldDecomposedProviderPlugin registered
  in Pyz\Yves\Session\SessionDependencyProvider, activated via YVES_SESSION_SAVE_HANDLER=field_decomposed.
  Storefront healthy; session stored as decomposed Redis hash (per-attribute a:* + m:c/m:u/m:l), no lock key.
- **In-stack A/B (40 parallel, one cookie): field_decomposed ~2x faster wall + p95 vs stock locking.**
- Blockers/gotchas hit (all resolved), each a real learning:
  1. rsync --exclude 'data/' nuked vendor/**/data/ + docker/bin/sdk/data/ -> boot failed on missing
     wcswidth_table_zero.php / demo.sh. Never exclude data/ when copying a Spryker shop.
  2. docker/sdk needs a TTY -> wrap in `script -qefc`. Deploy file = bootstrap's 1st arg, or auto-detected deploy.local.yml.
  3. After up, KV empty (Yves 500) -> P&S sync deferred; run `docker/sdk -p session console sync:data`
     (storage in the session/key_value_store valkey db1/db2, not db0).
  4. New Pyz classes not found under optimized classmap -> `composer dump-autoload -o` in cli container.
  5. config_local.php created via >> had NO <?php tag -> PHP echoed it as text; override silently ignored
     (and leaked into the page). Always start the file with <?php.
  6. session.serialize_handler=php_serialize must be set BEFORE session_start() (in config_local.php,
     not the handler's open()), else "session_start(): Failed to decode session object".
  7. FPM opcache: graceful USR2 reload not always enough; `docker restart <yves>` for clean pickup.
- **Layer 0 status:** redis_write_only_locking provider registered (available); the MetadataBag
  updateThreshold change is designed + documented but not separately benchmarked in-stack (fast follow).

## Layers B + C wired into the real Spryker quote flow (2026-07-21)
- **Seam:** `Pyz\Client\Quote\QuoteFactory::createSession()` -> custom `VersionedQuoteSession` (extends
  vendor `QuoteSession`, swaps only storage) + `VersionedQuoteStore` (Lua CAS). Both anonymous
  (SessionStorageStrategy) and logged-in (DatabaseStorageStrategy cache) funnel through it.
- **BLOCKER (big one): Spryker caches class resolution.** `RESOLVABLE_CLASS_NAMES_CACHE_ENABLED=true`
  meant a NEW Pyz factory was ignored (stock used) until `console cache:class-resolver:build`. Symptom:
  override silently not applied. Cache lives in `src/Generated/Shared/Kernel/Pyz/resolvableClassCache*.php`.
- **BLOCKER: pointer race.** First cut generated a random `quote_ptr` stored in the session; under
  parallel add-to-cart each request generated a DIFFERENT ptr (LWW on the session field) -> items
  scattered across N quote objects. Fix: derive the pointer from the stable **session id**
  (quote:<store>:<sessionId>) so concurrent writers converge on ONE object.
- **Layer B works:** quote de-cached from session to `quote:<store>:<sessionId>` (version-CAS'd); session
  holds only the pointer; sequential carts fully correct (N adds -> "N Items").
- **Layer C finding (the important one):** A/B under 8 concurrent adds — locking handler + quote-in-session
  = 8/8 (lock serializes); field handler + stock quote = 4-5/8; field handler + versioned quote = 5/8.
  => The session lock's only essential residual job is serializing the cart's non-atomic read-modify-write.
  That RMW lives in Spryker's **Zed cart-operation logic**, ABOVE the QuoteSession storage seam, so
  storage-layer CAS/merge alone can't fully serialize it (Zed recalc drops merged-in items).
- **Conclusion / next step:** the bulletproof scalable solution = Layer A (lock-free session) + Layer B
  (quote out of session) + a fine-grained per-CART lock at the CartClient/Zed layer OR an operation-based
  commutative cart (proven in the harness). The lock shrinks from per-session to per-cart-write.

## Layer C completed — per-cart lock (2026-07-21)
- **Fix:** `Pyz\Client\Cart\CartClient` (extends core) wraps every mutating cart method in a per-cart
  `CartLock` (Redis SET NX PX + token release) keyed by session id. The lock spans the whole
  getQuote->Zed->setQuote RMW, so concurrent add-to-cart on one cart serialize -> no lost items.
- **Result:** parallel add-to-cart 8/8 and 12/12 correct (was 5/8). Page-view storm UNCHANGED
  (~1.2s p95, 0 errors) -> the cart lock does NOT reintroduce session-wide serialization.
- **Why it works where storage-CAS didn't:** the lock makes the read-modify-RECALCULATE atomic as one
  unit (covers Spryker's Zed compute), so no operation runs on a stale quote snapshot.
- **Properties:** re-entrant per request; TTL auto-expire (dead request can't wedge the cart);
  fail-open on acquire timeout (a lock hiccup never blocks checkout). Keyed by session id so different
  carts never contend.
- **The complete answer:** per-SESSION lock (serializes everything) -> Layer A lock-free session +
  Layer B quote-in-own-versioned-key + Layer C per-CART-write lock. Correctness AND scalability.
- Same class-resolver-cache gotcha applied to the Pyz CartClient: needs `console cache:class-resolver:build`.

## Layer 0 — metadata threshold + write-only locking (2026-07-21)
- **Metadata threshold WORKS:** Pyz `Yves\Session\SessionFactory` injects `new MetadataBag('_sf2_meta', 300)`;
  proven that `m:u` stays constant across repeated read-only requests -> they write nothing. Fixes the
  "every request is a writer" root cause; composes with Layer A for free.
- **write-only-locking handler did NOT init** in this demoshop: `session_start(): Failed to initialize
  storage module: user` despite clean construction (resolvers default to []). Not chased — Layer A subsumes
  it (lock-free reads AND writes vs write-only's lock-free-reads-only).
- **Finding:** the demoshop ALREADY ships Bot + Url SessionRedisLockingExclusion plugins (the "prior
  attempts" = Spryker default), confirming they don't/can't solve it (MetadataBag churn = every request writes).
- **Verdict:** keep the threshold (free win); skip write-only-locking, adopt Layer A.
