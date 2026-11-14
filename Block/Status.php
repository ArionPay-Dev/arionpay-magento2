<?php
declare(strict_types=1);

namespace ArionPay\Payment\Block;

use ArionPay\Payment\Model\Config;
use ArionPay\Payment\Model\InvoiceManager;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;

class Status extends Template
{
    private Registry $registry;
    private InvoiceManager $invoices;
    private Config $config;

    public function __construct(Template\Context $context, Registry $registry, InvoiceManager $invoices, Config $config, array $data = [])
    {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->invoices = $invoices;
        $this->config = $config;
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('arionpay_status_order');
        return $order instanceof Order ? $order : null;
    }

    public function isPaid(): bool
    {
        $order = $this->getOrder();
        return $order !== null && $this->invoices->isOrderPaid($order);
    }

    public function isClosed(): bool
    {
        $order = $this->getOrder();
        return $order !== null && ($order->isCanceled() || $order->getState() === Order::STATE_CLOSED);
    }

    public function isTest(): bool
    {
        $order = $this->getOrder();
        return $this->config->isTest($order ? (int) $order->getStoreId() : null);
    }

    public function getPayUrl(): string
    {
        $order = $this->getOrder();
        return $order ? $this->getUrl('arionpay/order/pay', ['_secure' => true, '_query' => ['order' => $order->getIncrementId(), 'key' => $this->invoices->statusKey($order)]]) : '';
    }

    /** No full-page cache: the status changes when the payment arrives. */
    public function getCacheLifetime()
    {
        return null;
    }
}
