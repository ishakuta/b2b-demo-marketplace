<?php

namespace Pyz\Shared\SessionRedis\Handler;

use Spryker\Shared\SessionRedis\Handler\SessionHandlerFactory as SprykerSessionHandlerFactory;
use Spryker\Shared\SessionRedis\Redis\SessionRedisWrapperInterface;

class SessionHandlerFactory extends SprykerSessionHandlerFactory
{
    /**
     * @param \Spryker\Shared\SessionRedis\Redis\SessionRedisWrapperInterface $redisClient
     *
     * @return \SessionHandlerInterface
     */
    public function createSessionHandlerRedisLocking(SessionRedisWrapperInterface $redisClient): \SessionHandlerInterface
    {
        return new SessionHandlerRedisLocking(
            $redisClient,
            $this->createSessionSpinLockLocker($redisClient),
            $this->createSessionKeyBuilder(),
            $this->sessionRedisLifeTimeCalculator,
        );
    }
}
