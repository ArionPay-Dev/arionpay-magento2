<?php
declare(strict_types=1);

namespace ArionPay\Payment\Controller\Order;

use ArionPay\Payment\Model\Config;
use ArionPay\Payment\Model\InvoiceManager;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\OrderFactory;

/**
 * Where the customer lands after the ArionPay page: paid, or "not yet" with a Pay now button.
 * Opened only with the order's secret key, so nobody can look at another customer's order.
 */
class Status implements HttpGetActionInterface
{
    private RequestInterface $request;
    private OrderFactory $orderFactory;
    private InvoiceManager $invoices;
    private PageFactory $pageFactory;
    private RedirectFactory $redirectFactory;
    private Registry $registry;

    public function __construct(RequestInterface $request, OrderFactory $orderFactory, InvoiceManager $invoices, PageFactory $pageFactory, RedirectFactory $redirectFactory, Registry $registry)
    {
        $this->request = $request;
        $this->orderFactory = $orderFactory;
        $this->invoices = $invoices;
        $this->pageFactory = $pageFactory;
        $this->redirectFactory = $redirectFactory;
        $this->registry = $registry;
    }

    public function execute()
    {
        $order = $this->orderFactory->create()->loadByIncrementId((string) $this->request->getParam('order'));
        $key = (string) $this->request->getParam('key');
        if (!$order->getId() || $order->getPayment()->getMethod() !== Config::CODE || $key === '' || !hash_equals($this->invoices->statusKey($order), $key)) {
            return $this->redirectFactory->create()->setPath('/');
        }
        $this->registry->register('arionpay_status_order', $order);
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Order #%1', $order->getIncrementId()));
        return $page;
    }
}
