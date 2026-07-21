<?php

declare(strict_types=1);

namespace Pyz\Yves\Session;

use Spryker\Yves\Session\SessionFactory as SprykerSessionFactory;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Layer 0: give Symfony's MetadataBag an update threshold so the `_sf2_meta.u` (updated) timestamp is
 * NOT rewritten on every request. Combined with the shipped `redis_write_only_locking` handler, a
 * genuinely read-only request (catalog browsing, most bot traffic) then neither writes nor locks.
 * Default threshold 300s; override with SSPOC_META_THRESHOLD.
 */
class SessionFactory extends SprykerSessionFactory
{
    public function createNativeSessionStorage(): SessionStorageInterface
    {
        $sessionStorage = $this->createSessionStorage();
        $threshold = (int)(getenv('SSPOC_META_THRESHOLD') ?: 300);

        return new NativeSessionStorage(
            $sessionStorage->getOptions(),
            $sessionStorage->getAndRegisterHandler(),
            new MetadataBag('_sf2_meta', $threshold),
        );
    }
}
