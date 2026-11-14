<?php

declare(strict_types=1);

namespace Pyz\Client\Quote;

use Pyz\Client\Quote\Session\VersionedQuoteSession;
use Spryker\Client\Quote\QuoteFactory as SprykerQuoteFactory;

/**
 * Overrides quote session creation so the cart lives in a version-CAS'd Redis key (Layers B+C)
 * instead of inside the session blob.
 */
class QuoteFactory extends SprykerQuoteFactory
{
    /**
     * @return \Spryker\Client\Quote\Session\QuoteSessionInterface
     */
    public function createSession()
    {
        return new VersionedQuoteSession(
            $this->getSessionClient(),
            $this->getCurrencyClient(),
            $this->getStoreClient(),
            $this->getQuoteTransferExpanderPlugins(),
        );
    }
}
