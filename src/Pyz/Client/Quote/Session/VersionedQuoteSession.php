<?php

declare(strict_types=1);

namespace Pyz\Client\Quote\Session;

use Generated\Shared\Transfer\ItemTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Pyz\Client\Quote\Storage\VersionedQuoteStore;
use Spryker\Client\Quote\Session\QuoteSession;

/**
 * Layers B + C wired into the real Spryker quote flow.
 *
 * B: the QuoteTransfer is de-cached OUT of the session blob into its own version-CAS'd Redis key
 *    (quote:<store>:<sessionId>); the session cookie's id IS the pointer (deterministic + stable
 *    across a session's concurrent requests, so parallel writers converge on ONE object).
 * C: on write, if the version we read is unchanged we clean-overwrite (removes/clears work); on a
 *    version conflict (a concurrent writer moved it) we ADD-WINS merge (union cart items by
 *    groupKey) and retry -> concurrent add-to-cart can never lose an item.
 *
 * Extends the vendor QuoteSession to reuse currency + expander-plugin handling; only the storage
 * backend (get/set) is replaced.
 */
class VersionedQuoteSession extends QuoteSession
{
    /** @var array<string,int> request-scoped read-version per pointer (for CAS continuity) */
    private static array $readVersion = [];

    private ?VersionedQuoteStore $store = null;

    private function store(): VersionedQuoteStore
    {
        return $this->store ??= new VersionedQuoteStore();
    }

    /**
     * @return \Generated\Shared\Transfer\QuoteTransfer
     */
    public function getQuote()
    {
        $ptr = $this->pointer();
        $quoteTransfer = new QuoteTransfer();

        [$data, $version] = $this->store()->get($ptr);
        static::$readVersion[$ptr] = $version;
        if (is_string($data) && $data !== '') {
            $arr = @unserialize($data);
            if (is_array($arr)) {
                $quoteTransfer->fromArray($arr, true);
            }
        }

        $this->setCurrency($quoteTransfer);

        return $this->expandQuoteTransfer($quoteTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     *
     * @return $this
     */
    public function setQuote(QuoteTransfer $quoteTransfer)
    {
        $this->setCurrency($quoteTransfer);
        $quoteTransfer = $this->expandQuoteTransfer($quoteTransfer);

        $this->casStore($this->pointer(), $quoteTransfer);
        $this->updateCurrency($quoteTransfer);

        return $this;
    }

    /**
     * @return $this
     */
    public function clearQuote()
    {
        $ptr = $this->pointer();
        $this->store()->delete($ptr);
        unset(static::$readVersion[$ptr]);

        return $this;
    }

    private function casStore(string $ptr, QuoteTransfer $myQuote): void
    {
        $expectedVersion = static::$readVersion[$ptr] ?? null;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            [$curData, $curVersion] = $this->store()->get($ptr);

            if ($expectedVersion !== null && $curVersion === $expectedVersion) {
                // No concurrent change since we read -> clean overwrite (honors add, remove, clear).
                $writeArray = $myQuote->toArray();
            } else {
                // Concurrent writer moved the object -> add-wins union so no add is lost.
                $writeArray = $this->addWinsMerge($myQuote, $curData)->toArray();
            }

            $newVersion = $this->store()->casPut($ptr, $curVersion, serialize($writeArray));
            if ($newVersion !== -1) {
                static::$readVersion[$ptr] = $newVersion;

                return;
            }

            // Lost the race: force merge mode and retry with jittered backoff.
            $expectedVersion = null;
            usleep(random_int(200, 2000));
        }
    }

    /**
     * Add-wins: keep all of my items, then add any item the current stored cart has that I do not
     * (keyed by groupKey, falling back to sku). Never drops a concurrently-added item.
     */
    private function addWinsMerge(QuoteTransfer $myQuote, ?string $curData): QuoteTransfer
    {
        if (!is_string($curData) || $curData === '') {
            return $myQuote;
        }
        $arr = @unserialize($curData);
        if (!is_array($arr)) {
            return $myQuote;
        }
        $current = (new QuoteTransfer())->fromArray($arr, true);

        $have = [];
        foreach ($myQuote->getItems() as $item) {
            $have[$this->itemKey($item)] = true;
        }
        foreach ($current->getItems() as $item) {
            if (!isset($have[$this->itemKey($item)])) {
                $myQuote->addItem($item);
                $have[$this->itemKey($item)] = true;
            }
        }

        return $myQuote;
    }

    private function itemKey(ItemTransfer $item): string
    {
        $groupKey = (string)$item->getGroupKey();

        return $groupKey !== '' ? $groupKey : (string)$item->getSku();
    }

    /**
     * Deterministic pointer: the session id is stable across a session's concurrent requests, so
     * parallel add-to-cart writers all target the SAME versioned quote object.
     */
    private function pointer(): string
    {
        $store = $this->storeClient->getCurrentStore()->getNameOrFail();
        $sessionId = session_id();
        if ($sessionId === '') {
            $sessionId = 'nosession';
        }

        return $store . ':' . $sessionId;
    }
}
