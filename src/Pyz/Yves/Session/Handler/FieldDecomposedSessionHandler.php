<?php

declare(strict_types=1);

namespace Pyz\Yves\Session\Handler;

use Predis\Client;
use Spryker\Shared\Kernel\Store;
use Spryker\Shared\SessionRedis\SessionRedisConstants;
use SessionHandlerInterface;
use Spryker\Shared\Config\Config;

/**
 * Layer A (PoC): lock-free, field-decomposed session handler for Spryker Yves.
 *
 * Stores the session as ONE Redis hash, decomposed to per-attribute granularity:
 *   m:c / m:u / m:l   -> Symfony MetadataBag scalars (m:u merged by MAX -> never a real conflict)
 *   a:<attr>          -> one field per attribute (NOT the whole _sf2_attributes blob)
 *   f                 -> flashes
 *
 * No pessimistic lock. On write we diff against the read() snapshot and apply ONLY changed/removed
 * fields via one atomic Lua script. Requests touching disjoint fields commit concurrently.
 *
 * Requires session.serialize_handler=php_serialize (forced in open()).
 * Proven end-to-end in ../harness against real symfony/http-foundation + Valkey.
 */
class FieldDecomposedSessionHandler implements SessionHandlerInterface
{
    /** @var array<string,array<string,string>> */
    private array $snap = [];
    private ?Client $r = null;

    public function __construct(private int $ttl = 1800) {}

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

    public function open($path, $name): bool
    {
        // Decomposition needs the whole-array serialize format.
        @ini_set('session.serialize_handler', 'php_serialize');
        return true;
    }

    public function read($id): string|false
    {
        $h = $this->redis()->hgetall($this->key($id)) ?: [];
        $this->snap[$id] = $h;

        $meta = [
            'c' => isset($h['m:c']) ? (int)$h['m:c'] : 0,
            'u' => isset($h['m:u']) ? (int)$h['m:u'] : 0,
            'l' => isset($h['m:l']) ? (int)$h['m:l'] : 0,
        ];
        $attrs = [];
        foreach ($h as $f => $v) {
            if (str_starts_with($f, 'a:')) {
                $attrs[substr($f, 2)] = unserialize($v);
            }
        }
        $flashes = isset($h['f']) ? unserialize($h['f']) : [];

        return serialize([
            '_sf2_attributes'  => $attrs,
            '_symfony_flashes' => $flashes,
            '_sf2_meta'        => $meta,
        ]);
    }

    public function write($id, $data): bool
    {
        $arr = @unserialize((string)$data);
        if (!is_array($arr)) { $arr = []; }

        $desired = [];
        $meta = $arr['_sf2_meta'] ?? [];
        $desired['m:c'] = (string)($meta['c'] ?? 0);
        $desired['m:u'] = (string)($meta['u'] ?? 0);
        $desired['m:l'] = (string)($meta['l'] ?? 0);
        foreach (($arr['_sf2_attributes'] ?? []) as $k => $v) {
            $desired['a:' . $k] = serialize($v);
        }
        $flashes = $arr['_symfony_flashes'] ?? [];
        if (!empty($flashes)) { $desired['f'] = serialize($flashes); }

        $snap = $this->snap[$id] ?? [];
        $set = [];
        $max = [];
        foreach ($desired as $f => $v) {
            if (($snap[$f] ?? null) === $v) { continue; }
            if ($f === 'm:u') { $max[$f] = $v; } else { $set[$f] = $v; }
        }
        $del = [];
        foreach ($snap as $f => $_) {
            if (!array_key_exists($f, $desired)) { $del[] = $f; }
        }
        if (!isset($max['m:u'])) { $max['m:u'] = $desired['m:u']; }

        $ops = [];
        if ($set) { $ops['set'] = $set; }
        if ($del) { $ops['del'] = array_values($del); }
        if ($max) { $ops['max'] = $max; }

        $lua = <<<'LUA'
local key = KEYS[1]
local ttl = tonumber(ARGV[1])
local ops = cjson.decode(ARGV[2])
if ops.set then for f,v in pairs(ops.set) do redis.call('HSET', key, f, v) end end
if ops.del then for _,f in ipairs(ops.del) do redis.call('HDEL', key, f) end end
if ops.max then
  for f,v in pairs(ops.max) do
    local cur = tonumber(redis.call('HGET', key, f))
    local nv = tonumber(v)
    if cur == nil or (nv and nv > cur) then redis.call('HSET', key, f, v) end
  end
end
redis.call('EXPIRE', key, ttl)
return 1
LUA;
        $this->redis()->eval($lua, 1, $this->key($id), (string)$this->ttl, json_encode($ops, JSON_FORCE_OBJECT));
        return true;
    }

    public function destroy($id): bool { $this->redis()->del([$this->key($id)]); unset($this->snap[$id]); return true; }
    public function gc($max): int|false { return 0; }
    public function close(): bool { return true; }

    private function key(string $id): string { return 'sess:' . $id; }
}
