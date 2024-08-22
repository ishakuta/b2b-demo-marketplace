<?php

namespace Pyz\Yves\SessionRedis;

use Pyz\Shared\SessionRedis\Handler\SessionHandlerFactory;
use Spryker\Shared\SessionRedis\Handler\SessionHandlerFactoryInterface;
use Spryker\Yves\SessionRedis\SessionRedisFactory as SprykerSessionRedisFactory;

class SessionRedisFactory extends SprykerSessionRedisFactory
{
    /**
     * @return \Spryker\Shared\SessionRedis\Handler\SessionHandlerFactoryInterface
     */
    public function createSessionHandlerFactory(): SessionHandlerFactoryInterface
    {
        return new SessionHandlerFactory(
            $this->getMonitoringService(),
            $this->createSessionRedisLifeTimeCalculator(),
            $this->getConfig()->getLockingTimeoutMilliseconds(),
            $this->getConfig()->getLockingRetryDelayMicroseconds(),
            $this->getConfig()->getLockingLockTtlMilliseconds(),
        );
    }
}
