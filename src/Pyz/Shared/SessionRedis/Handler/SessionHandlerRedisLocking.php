<?php

namespace Pyz\Shared\SessionRedis\Handler;

use Spryker\Shared\SessionRedis\Handler\Exception\LockCouldNotBeAcquiredException;
use Spryker\Shared\SessionRedis\Handler\SessionHandlerRedisLocking as SprykerSessionHandlerRedisLocking;

class SessionHandlerRedisLocking extends SprykerSessionHandlerRedisLocking
{
    /**
     * @param string $sessionId
     *
     * @throws \Spryker\Shared\SessionRedis\Handler\Exception\LockCouldNotBeAcquiredException
     *
     * @return string
     */
    public function read($sessionId): string
    {
        $sessionData = $this->redisClient->get($this->keyBuilder->buildSessionKey($sessionId));

        return $this->normalizeSessionData($sessionData);
    }

    /**
     * @param string $sessionId
     * @param string $data
     *
     * @return bool
     */
    public function write($sessionId, $data): bool
    {
        if (!$this->locker->lock($sessionId)) {
            throw new LockCouldNotBeAcquiredException(
                sprintf(
                    '%s could not acquire access to the session %s',
                    static::class,
                    $sessionId,
                ),
            );
        }

        $savedSession = $this->read($sessionId);
        $savedSession = $this->decodeSessionData($savedSession);

        // $data is serialized $_SESSION, internal serialization format, not serialize function, but very similar
        // the main concern / problem to solve:
        // how to merge $savedSession (potentially newer) with the current $_SESSION (potentially conflicting)
        // $_SESSION[...] = ...
        // maybe even calculate a diff between current and saved session to decide does it make sense to write
        // in case this approach won't work for some reason
        // decouple session objects as much as possible, store only scalars in the session object
        // Customer, Quote, etc objects - should be stored separately

        // encode updated version
        $data = session_encode(); // encodes $_SESSION into data, again, as previously it was done automatically by PHP before entering this method

        $result = $this->redisClient->setex(
            $this->keyBuilder->buildSessionKey($sessionId),
            $this->sessionRedisLifeTimeCalculator->getSessionLifeTime(),
            $data,
        );

        $this->locker->lock($sessionId);

        return $result;
    }

    protected function decodeSessionData(string $data)
    {
        $backupSESSION = $_SESSION;
        session_decode($data);
        $decoded_data = $_SESSION;

        // restore backup
        $_SESSION = $backupSESSION;

        return $decoded_data;
    }
}
