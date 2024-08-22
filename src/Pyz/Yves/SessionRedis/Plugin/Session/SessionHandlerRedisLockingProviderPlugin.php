<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Pyz\Yves\SessionRedis\Plugin\Session;

use SessionHandlerInterface;
use Spryker\Yves\SessionRedis\Plugin\Session\SessionHandlerRedisLockingProviderPlugin as SprykerSessionHandlerRedisLockingProviderPlugin;

class SessionHandlerRedisLockingProviderPlugin extends SprykerSessionHandlerRedisLockingProviderPlugin
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return \SessionHandlerInterface
     */
    public function getSessionHandler(): SessionHandlerInterface
    {
        return $this->getFactory()->createSessionHandlerRedisLocking();
    }
}
