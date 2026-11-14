<?php
declare(strict_types=1);

namespace ArionPay\Payment\Controller\Order;

use ArionPay\Payment\Model\Config;
use ArionPay\Payment\Model\InvoiceManager;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\OrderFactory;

/** "Pay now": reopens the open ArionPay invoice of an unpaid order, or makes a new one. Needs the order's secret key. */
class Pay implements HttpGetActionInterface
{
    private RequestInterface $request;
    private OrderFactory $orderFactory;
    private InvoiceManager $invoices;
    private Config $config;
    private RedirectFactory $redirectFactory;
    private ManagerInterface $messages;

    public function __construct(RequestInterface $request, OrderFactory $orderFactory, InvoiceManager $invoices, Config $config, RedirectFactory $redirectFactory, ManagerInterface $messages)
    {
        $this->request = $request;
        $this->orderFactory = $orderFactory;
        $this->invoices = $invoices;
        $this->config = $config;
        $this->redirectFactory = $redirectFactory;
        $this->messages = $messages;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->orderFactory->create()->loadByIncrementId((string) $this->request->getParam('order'));
        $key = (string) $this->request->getParam('key');
        if (!$order->getId() || $order->getPayment()->getMethod() !== Config::CODE || $key === '' || !hash_equals($this->invoices->statusKey($order), $key)) {
            return $redirect->setPath('/');
        }
        $status = ['_query' => ['order' => $order->getIncrementId(), 'key' => $key]];
        if ($this->invoices->isOrderPaid($order) || $order->isCanceled() || $order->getState() === \Magento\Sales\Model\Order::STATE_CLOSED) {
            return $redirect->setPath('arionpay/order/status', $status);
        }
        try {
            return $redirect->setUrl($this->invoices->paymentUrl($order));
        } catch (\Throwable $e) {
            $this->config->log('Could not create an ArionPay invoice for order ' . $order->getIncrementId() . ': ' . $e->getMessage(), (int) $order->getStoreId());
            $this->messages->addErrorMessage(__('The crypto payment could not be started right now. Please try again in a minute.'));
            return $redirect->setPath('arionpay/order/status', $status);
        }
    }
}
