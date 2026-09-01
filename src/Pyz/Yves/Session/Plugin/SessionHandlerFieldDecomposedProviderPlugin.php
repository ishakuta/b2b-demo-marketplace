<?php

declare(strict_types=1);

namespace Pyz\Yves\Session\Plugin;

use Pyz\Yves\Session\Handler\FieldDecomposedSessionHandler;
use SessionHandlerInterface;
use Spryker\Shared\SessionExtension\Dependency\Plugin\SessionHandlerProviderPluginInterface;
use Spryker\Shared\Session\SessionConstants;
use Spryker\Shared\Config\Config;

/**
 * Registers the lock-free, field-decomposed session handler (Layer A) under the name
 * 'field_decomposed'. Activate by setting YVES_SESSION_SAVE_HANDLER = 'field_decomposed'.
 */
class SessionHandlerFieldDecomposedProviderPlugin implements SessionHandlerProviderPluginInterface
{
    public const SESSION_HANDLER_FIELD_DECOMPOSED = 'field_decomposed';

    public function getSessionHandlerName(): string
    {
        return static::SESSION_HANDLER_FIELD_DECOMPOSED;
    }

    public function getSessionHandler(): SessionHandlerInterface
    {
        $ttl = (int)Config::get(SessionConstants::YVES_SESSION_TIME_TO_LIVE, 1800);
        return new FieldDecomposedSessionHandler($ttl > 0 ? $ttl : 1800);
    }
}
