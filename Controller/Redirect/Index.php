<?php
declare(strict_types=1);

namespace ArionPay\Payment\Controller\Redirect;

use ArionPay\Payment\Model\Config;
use ArionPay\Payment\Model\InvoiceManager;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Right after "Place order": sends the customer to the ArionPay payment page for the order just placed.
 */
class Index implements HttpGetActionInterface
{
    private CheckoutSession $checkoutSession;
    private InvoiceManager $invoices;
    private Config $config;
    private RedirectFactory $redirectFactory;
    private ManagerInterface $messages;

    public function __construct(CheckoutSession $checkoutSession, InvoiceManager $invoices, Config $config, RedirectFactory $redirectFactory, ManagerInterface $messages)
    {
        $this->checkoutSession = $checkoutSession;
        $this->invoices = $invoices;
        $this->config = $config;
        $this->redirectFactory = $redirectFactory;
        $this->messages = $messages;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId() || $order->getPayment()->getMethod() !== Config::CODE) {
            return $redirect->setPath('checkout/cart');
        }
        if ($this->invoices->isOrderPaid($order)) {
            return $redirect->setPath('checkout/onepage/success');
        }
        try {
            return $redirect->setUrl($this->invoices->paymentUrl($order));
        } catch (\Throwable $e) {
            $this->config->log('Could not create an ArionPay invoice for order ' . $order->getIncrementId() . ': ' . $e->getMessage(), (int) $order->getStoreId());
            $this->messages->addErrorMessage(__('The crypto payment could not be started right now. Your order #%1 is saved: please try again in a minute from the link below.', $order->getIncrementId()));
            return $redirect->setPath('arionpay/order/status', ['_query' => ['order' => $order->getIncrementId(), 'key' => $this->invoices->statusKey($order)]]);
        }
    }
}
