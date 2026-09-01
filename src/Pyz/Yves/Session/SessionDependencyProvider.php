<?php
declare(strict_types = 1);
namespace Pyz\Yves\Session;
use Pyz\Yves\Session\Plugin\SessionHandlerFieldDecomposedProviderPlugin;
use Spryker\Yves\Session\SessionDependencyProvider as SprykerSessionDependencyProvider;
use Spryker\Yves\SessionFile\Plugin\Session\SessionHandlerFileProviderPlugin;
use Spryker\Yves\SessionRedis\Plugin\Session\SessionHandlerConfigurableRedisLockingProviderPlugin;
use Spryker\Yves\SessionRedis\Plugin\Session\SessionHandlerRedisProviderPlugin;
use Spryker\Yves\SessionRedis\Plugin\Session\SessionHandlerRedisWriteOnlyLockingProviderPlugin;
class SessionDependencyProvider extends SprykerSessionDependencyProvider
{
    protected function getSessionHandlerPlugins(): array
    {
        return [
            new SessionHandlerRedisProviderPlugin(),
            new SessionHandlerFileProviderPlugin(),
            new SessionHandlerConfigurableRedisLockingProviderPlugin(),
            new SessionHandlerRedisWriteOnlyLockingProviderPlugin(), // Layer 0
            new SessionHandlerFieldDecomposedProviderPlugin(),        // Layer A
        ];
    }
}
