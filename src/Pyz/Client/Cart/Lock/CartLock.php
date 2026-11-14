<?php

declare(strict_types=1);

namespace Pyz\Client\Cart\Lock;

use Predis\Client;
use Spryker\Shared\Config\Config;
use Spryker\Shared\SessionRedis\SessionRedisConstants;

/**
 * Layer C: a fine-grained, per-cart Redis lock that serializes concurrent MUTATIONS of the same cart
 * (the getQuote -> Zed compute -> setQuote read-modify-write). Keyed by the cart identity (session id),
 * so it never blocks page views or session reads (those stay lock-free under Layer A). This replaces
 * the per-SESSION pessimistic lock with a per-CART-write lock: only concurrent writers to the SAME cart
 * serialize; everything else scales.
 *
 * Re-entrant within one request (a mutating op may nest), auto-expiring (TTL) if a request dies, and
 * fail-open on acquire timeout so a lock-server hiccup never wedges checkout.
 */
final class CartLock
{
    private ?Client $r = null;
    /** @var array<string,int> re-entrancy depth per key (per request/process) */
    private array $depth = [];
    /** @var array<string,string|null> lock token per key */
    private array $token = [];

    public function __construct(
        private int $ttlMs = 10000,
        private int $timeoutMs = 10000,
        private int $retryUs = 20000,
    ) {}

    private function redis(): Client
    {
        if ($this->r === null) {
            $this->r = new Client([
                'scheme' => Config::get(SessionRedisConstants::YVES_SESSION_REDIS_PROTOCOL, 'tcp'),
                'host'   => Config::get(SessionRedisConstants::YVES_SESSION_REDIS_HOST, 'session'),
                'port'   => (int)Config::get(SessionRedisConstants::YVES_SESSION_REDIS_PORT, 6379),
            ]);
            $db = Config::get(SessionRedisConstants::YVES_SESSION_REDIS_DATABASE, 2);
            if ($db !== null && $db !== '') {
                $this->r->select((int)$db);
            }
        }
        return $this->r;
    }

    public function acquire(string $key): void
    {
        if (($this->depth[$key] ?? 0) > 0) {
            $this->depth[$key]++; // reentrant: already held by this request

            return;
        }

        $token = bin2hex(random_bytes(16));
        $start = (int)(microtime(true) * 1000);
        do {
            if ($this->redis()->set($this->k($key), $token, 'PX', $this->ttlMs, 'NX')) {
                $this->token[$key] = $token;
                $this->depth[$key] = 1;

                return;
            }
            usleep($this->retryUs);
        } while (((int)(microtime(true) * 1000)) - $start < $this->timeoutMs);

        // Fail-open: proceed without the lock rather than block the request indefinitely.
        $this->depth[$key] = 1;
        $this->token[$key] = null;
    }

    public function release(string $key): void
    {
        $depth = $this->depth[$key] ?? 0;
        if ($depth <= 0) {
            return;
        }
        if ($depth > 1) {
            $this->depth[$key] = $depth - 1;

            return;
        }

        $this->depth[$key] = 0;
        $token = $this->token[$key] ?? null;
        if ($token !== null) {
            // token-checked release (never delete someone else's lock)
            $this->redis()->eval(
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
                1,
                $this->k($key),
                $token,
            );
        }
        unset($this->token[$key]);
    }

    private function k(string $key): string
    {
        return 'cartlock:' . $key;
    }
}
