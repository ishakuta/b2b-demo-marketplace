<?php

declare(strict_types=1);

namespace Pyz\Client\Quote\Storage;

use Predis\Client;
use Spryker\Shared\Config\Config;
use Spryker\Shared\SessionRedis\SessionRedisConstants;

/**
 * Layer B: per-object optimistic-concurrency (version compare-and-set) store for the cart.
 * The QuoteTransfer lives in its own key `quote:<ptr>` (hash {v: version, d: serialized data}),
 * NOT inside the session blob. CAS is a single atomic Lua script.
 */
final class VersionedQuoteStore
{
    private ?Client $r = null;

    public function __construct(private int $ttl = 3600) {}

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

    /**
     * @return array{0:string|null,1:int} [serialized data|null, version]
     */
    public function get(string $ptr): array
    {
        $h = $this->redis()->hgetall($this->key($ptr));
        if (!$h) {
            return [null, 0];
        }
        return [$h['d'] ?? null, (int)($h['v'] ?? 0)];
    }

    /**
     * Atomic compare-and-set. Returns the new version, or -1 on version conflict.
     */
    public function casPut(string $ptr, int $expectedVersion, string $data): int
    {
        $lua = <<<'LUA'
local key = KEYS[1]
local expected = tonumber(ARGV[1])
local cur = tonumber(redis.call('HGET', key, 'v')) or 0
if cur ~= expected then return -1 end
local nv = cur + 1
redis.call('HSET', key, 'v', nv, 'd', ARGV[2])
redis.call('EXPIRE', key, tonumber(ARGV[3]))
return nv
LUA;
        return (int)$this->redis()->eval($lua, 1, $this->key($ptr), (string)$expectedVersion, $data, (string)$this->ttl);
    }

    public function delete(string $ptr): void
    {
        $this->redis()->del([$this->key($ptr)]);
    }

    private function key(string $ptr): string
    {
        return 'quote:' . $ptr;
    }
}
