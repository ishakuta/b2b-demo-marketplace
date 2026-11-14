<?php

declare(strict_types=1);

namespace Pyz\Client\Cart;

use ArrayObject;
use Generated\Shared\Transfer\CartChangeTransfer;
use Generated\Shared\Transfer\CurrencyTransfer;
use Generated\Shared\Transfer\ItemReplaceTransfer;
use Generated\Shared\Transfer\ItemTransfer;
use Generated\Shared\Transfer\QuoteResponseTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Pyz\Client\Cart\Lock\CartLock;
use Spryker\Client\Cart\CartClient as SprykerCartClient;

/**
 * Layer C: serialize concurrent cart MUTATIONS on the same cart with a fine-grained per-cart lock.
 * Wraps the whole read-modify-write (parent method does getQuote -> Zed -> setQuote) so no concurrent
 * add-to-cart is lost. The lock is keyed by the cart identity (session id) and only covers cart writes;
 * page views / session reads remain lock-free (Layer A).
 */
class CartClient extends SprykerCartClient
{
    private ?CartLock $cartLock = null;

    private function cartLock(): CartLock
    {
        return $this->cartLock ??= new CartLock();
    }

    private function cartKey(): string
    {
        $sessionId = session_id();

        return $sessionId !== '' ? $sessionId : 'nosession';
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function withCartLock(callable $operation)
    {
        $key = $this->cartKey();
        $this->cartLock()->acquire($key);
        try {
            return $operation();
        } finally {
            $this->cartLock()->release($key);
        }
    }

    public function addValidItems(CartChangeTransfer $cartChangeTransfer, array $params = []): QuoteTransfer
    {
        return $this->withCartLock(fn () => parent::addValidItems($cartChangeTransfer, $params));
    }

    public function addItem(ItemTransfer $itemTransfer, array $params = [])
    {
        return $this->withCartLock(fn () => parent::addItem($itemTransfer, $params));
    }

    public function addItems(array $itemTransfers, array $params = [])
    {
        return $this->withCartLock(fn () => parent::addItems($itemTransfers, $params));
    }

    public function removeItem($sku, $groupKey = null)
    {
        return $this->withCartLock(fn () => parent::removeItem($sku, $groupKey));
    }

    public function removeItems(ArrayObject $items)
    {
        return $this->withCartLock(fn () => parent::removeItems($items));
    }

    public function changeItemQuantity($sku, $groupKey = null, $quantity = 1)
    {
        return $this->withCartLock(fn () => parent::changeItemQuantity($sku, $groupKey, $quantity));
    }

    public function decreaseItemQuantity($sku, $groupKey = null, $quantity = 1)
    {
        return $this->withCartLock(fn () => parent::decreaseItemQuantity($sku, $groupKey, $quantity));
    }

    public function increaseItemQuantity($sku, $groupKey = null, $quantity = 1)
    {
        return $this->withCartLock(fn () => parent::increaseItemQuantity($sku, $groupKey, $quantity));
    }

    public function addToCart(CartChangeTransfer $cartChangeTransfer): QuoteResponseTransfer
    {
        return $this->withCartLock(fn () => parent::addToCart($cartChangeTransfer));
    }

    public function removeFromCart(CartChangeTransfer $cartChangeTransfer): QuoteResponseTransfer
    {
        return $this->withCartLock(fn () => parent::removeFromCart($cartChangeTransfer));
    }

    public function updateQuantity(CartChangeTransfer $cartChangeTransfer): QuoteResponseTransfer
    {
        return $this->withCartLock(fn () => parent::updateQuantity($cartChangeTransfer));
    }

    public function replaceItem(ItemReplaceTransfer $itemReplaceTransfer): QuoteResponseTransfer
    {
        return $this->withCartLock(fn () => parent::replaceItem($itemReplaceTransfer));
    }

    public function setQuoteCurrency(CurrencyTransfer $currencyTransfer): QuoteResponseTransfer
    {
        return $this->withCartLock(fn () => parent::setQuoteCurrency($currencyTransfer));
    }
}
