# Applying the field-decomposed session handler to a standard Spryker b2b-demo-shop

These are the exact files authored for the PoC (the shop itself is gitignored — too large).

Copy into the shop, preserving paths:
- src/Pyz/Yves/Session/Handler/FieldDecomposedSessionHandler.php  (Layer A handler)
- src/Pyz/Yves/Session/Plugin/SessionHandlerFieldDecomposedProviderPlugin.php  (provider plugin)
- src/Pyz/Yves/Session/SessionDependencyProvider.php  (registers the plugin)
- config/Shared/config_local.php  (activates it + sets php_serialize BEFORE session_start)
- deploy.session.yml -> use as deploy.local.yml (namespace + isolated port band)

Then:
1. composer dump-autoload -o   (inside cli: docker exec -w /data <ns>_cli_1 composer dump-autoload -o)
2. docker restart <ns>_yves_eu_1   (clear FPM opcache)
3. Verify: session keys become Redis hashes sess:<id> with a:<attr> + m:c/m:u/m:l fields, no :lock key.

Toggle back to stock: remove the two lines in config/Shared/config_local.php.

## Layers B + C (quote de-caching + versioned cart)
Copy also:
- src/Pyz/Client/Quote/QuoteFactory.php
- src/Pyz/Client/Quote/Session/VersionedQuoteSession.php
- src/Pyz/Client/Quote/Storage/VersionedQuoteStore.php

Then, CRITICAL (Spryker caches class resolution):
1. composer dump-autoload -o
2. docker exec -w /data <ns>_cli_1 vendor/bin/console cache:class-resolver:build   # else the new factory is ignored
3. docker restart <ns>_yves_eu_1

Verify: after add-to-cart the session has NO "DE quote session identifier" blob; instead
quote:<store>:<sessionId> holds the cart with an incrementing version field.

NOTE (Layer C boundary): concurrent add-to-cart correctness needs a per-cart lock at the cart-operation
layer or an operation-based cart — the QuoteSession storage seam alone cannot serialize Spryker's Zed
cart read-modify-write. See docs/REPORT.md section 8.3.

## Layer C fix — per-cart lock (makes concurrent add-to-cart correct)
Copy also:
- src/Pyz/Client/Cart/CartClient.php        (wraps mutating methods in a per-cart lock)
- src/Pyz/Client/Cart/Lock/CartLock.php     (Redis SET NX PX + token release; keyed by session id)
Then: composer dump-autoload -o ; console cache:class-resolver:build ; restart yves.
Verify: parallel add-to-cart of N distinct SKUs on one session -> cart shows N items; page-view
storms stay lock-free (only cart WRITES serialize). See docs/REPORT.md section 8.4.
