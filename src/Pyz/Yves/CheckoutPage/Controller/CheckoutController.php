<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\CheckoutPage\Controller;

use ArrayObject;
use Generated\Shared\Transfer\QuoteValidationResponseTransfer;
use SprykerShop\Yves\CheckoutPage\Controller\CheckoutController as SprykerCheckoutController;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \SprykerShop\Yves\CheckoutPage\CheckoutPageFactory getFactory()
 * @method \Spryker\Client\Checkout\CheckoutClientInterface getClient()
 */
class CheckoutController extends SprykerCheckoutController
{
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Spryker\Yves\Kernel\View\View
     */
    public function customerAction(Request $request)
    {
        $quoteValidationResponseTransfer = $this->canProceedCheckout();

        if (!$quoteValidationResponseTransfer->getIsSuccessful()) {
            $this->processErrorMessages($quoteValidationResponseTransfer->getMessages());

            return $this->redirectResponseInternal(static::ROUTE_CART);
        }

        $response = $this->getFactory()->createCheckoutProcess()->process(
            $request,
            $this->getFactory()
                ->createCheckoutFormFactory()
                ->createCustomerFormCollection(),
        );

        if (!is_array($response)) {
            return $response;
        }

        return $this->view(
            $response,
            $this->getFactory()->getCustomerPageWidgetPlugins(),
            '@CheckoutPage/views/login/login.twig',
        );
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Spryker\Yves\Kernel\View\View
     */
    public function addressAction(Request $request)
    {
        // TEMP DIAGNOSTIC (remove after): trace where /checkout/address spends its time.
        // error_log -> stderr -> CloudWatch (yves log). Grep "CHKTRACE".
        $t0 = microtime(true);
        $tr = static function (string $label) use ($t0): void {
            error_log(sprintf('[CHKTRACE] %s t+%.3fs mem=%.1fMB', $label, microtime(true) - $t0, memory_get_usage(true) / 1048576));
        };
        $tr('addressAction ENTER');

        $quoteValidationResponseTransfer = $this->canProceedCheckout();
        $tr(sprintf('after canProceedCheckout (success=%d)', (int)$quoteValidationResponseTransfer->getIsSuccessful()));

        if (!$quoteValidationResponseTransfer->getIsSuccessful()) {
            $this->processErrorMessages($quoteValidationResponseTransfer->getMessages());
            $tr('redirect to cart (not applicable)');

            return $this->redirectResponseInternal(static::ROUTE_CART);
        }

        $tr('before createCheckoutProcess()->process()');
        $response = $this->getFactory()->createCheckoutProcess()->process(
            $request,
            $this->getFactory()
                ->createCheckoutFormFactory()
                ->createAddressFormCollection(),
        );
        $tr(sprintf('after process() (isArray=%d)', (int)is_array($response)));

        if (!is_array($response)) {
            return $response;
        }

        $tr('before view() build (twig render happens AFTER controller returns)');
        $view = $this->view(
            $response,
            $this->getFactory()->getCustomerPageWidgetPlugins(),
            '@CheckoutPage/views/address/address.twig',
        );
        $tr('after view() build - returning (if next gap is silent, hang is in twig/widget render)');

        return $view;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Spryker\Yves\Kernel\View\View
     */
    public function shipmentAction(Request $request)
    {
        $quoteValidationResponseTransfer = $this->canProceedCheckout();

        if (!$quoteValidationResponseTransfer->getIsSuccessful()) {
            $this->processErrorMessages($quoteValidationResponseTransfer->getMessages());

            return $this->redirectResponseInternal(static::ROUTE_CART);
        }

        $response = $this->getFactory()->createCheckoutProcess()->process(
            $request,
            $this->getFactory()
                ->createCheckoutFormFactory()
                ->createShipmentFormCollection(),
        );

        if (!is_array($response)) {
            return $response;
        }

        return $this->view(
            $response,
            $this->getFactory()->getCustomerPageWidgetPlugins(),
            '@CheckoutPage/views/shipment/shipment.twig',
        );
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Spryker\Yves\Kernel\View\View
     */
    public function paymentAction(Request $request)
    {
        $quoteValidationResponseTransfer = $this->canProceedCheckout();

        if (!$quoteValidationResponseTransfer->getIsSuccessful()) {
            $this->processErrorMessages($quoteValidationResponseTransfer->getMessages());

            return $this->redirectResponseInternal(static::ROUTE_CART);
        }

        $response = $this->getFactory()->createCheckoutProcess()->process(
            $request,
            $this->getFactory()
                ->createCheckoutFormFactory()
                ->getPaymentFormCollection(),
        );

        if (!is_array($response)) {
            return $response;
        }

        return $this->view(
            $response,
            $this->getFactory()->getCustomerPageWidgetPlugins(),
            '@CheckoutPage/views/payment/payment.twig',
        );
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Spryker\Yves\Kernel\View\View
     */
    public function summaryAction(Request $request)
    {
        $quoteValidationResponseTransfer = $this->canProceedCheckout();

        if (!$quoteValidationResponseTransfer->getIsSuccessful()) {
            $this->processErrorMessages($quoteValidationResponseTransfer->getMessages());

            return $this->redirectResponseInternal(static::ROUTE_CART);
        }

        $viewData = $this->getFactory()->createCheckoutProcess()->process(
            $request,
            $this->getFactory()
                ->createCheckoutFormFactory()
                ->createSummaryFormCollection(),
        );

        if (!is_array($viewData)) {
            return $viewData;
        }

        return $this->view(
            $viewData,
            $this->getFactory()->getSummaryPageWidgetPlugins(),
            '@CheckoutPage/views/summary/summary.twig',
        );
    }

    /**
     * @return \Generated\Shared\Transfer\QuoteValidationResponseTransfer
     */
    protected function canProceedCheckout(): QuoteValidationResponseTransfer
    {
        $quoteTransfer = $this->getFactory()
            ->getQuoteClient()
            ->getQuote();

        return $this->getFactory()
            ->getCheckoutClient()
            ->isQuoteApplicableForCheckout($quoteTransfer);
    }

    /**
     * @param \ArrayObject<int, \Generated\Shared\Transfer\MessageTransfer> $messageTransfers
     *
     * @return void
     */
    protected function processErrorMessages(ArrayObject $messageTransfers): void
    {
        foreach ($messageTransfers as $messageTransfer) {
            $this->addErrorMessage($messageTransfer->getValue());
        }
    }
}
