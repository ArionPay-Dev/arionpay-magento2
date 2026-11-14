<?php
declare(strict_types=1);

namespace ArionPay\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;

/**
 * ArionPay invoices for Magento orders: makes one, reuses the open one, and remembers which belongs to which order.
 */
class InvoiceManager
{
    private Config $config;
    private ResourceConnection $resource;
    private UrlInterface $url;

    public function __construct(Config $config, ResourceConnection $resource, UrlInterface $url)
    {
        $this->config = $config;
        $this->resource = $resource;
        $this->url = $url;
    }

    /**
     * The amount and currency the customer pays: the order currency when ArionPay accepts it, otherwise the base one.
     *
     * @return array{0:string,1:string} amount as a fixed 2-decimal string, currency code
     */
    public function amountFor(Order $order): array
    {
        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        if (in_array($currency, Config::CURRENCIES, true)) {
            return [number_format((float) $order->getGrandTotal(), 2, '.', ''), $currency];
        }
        return [number_format((float) $order->getBaseGrandTotal(), 2, '.', ''), strtoupper((string) $order->getBaseCurrencyCode())];
    }

    /** The payment page for this order: the open invoice when it still fits, otherwise a new one. */
    public function paymentUrl(Order $order): string
    {
        $storeId = (int) $order->getStoreId();
        [$amount, $currency] = $this->amountFor($order);
        $test = $this->config->isTest($storeId);
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('arionpay_invoice');

        $latest = $db->fetchRow($db->select()->from($table)->where('order_id = ?', (int) $order->getId())->order('created_at DESC')->limit(1));
        if ($latest && $latest['status'] === 'new' && $latest['amount'] === $amount && $latest['currency'] === $currency && (bool) $latest['test'] === $test
            && $latest['expires_at'] && strtotime($latest['expires_at'] . ' UTC') > time() + 300) {
            return (string) $latest['invoice_url'];
        }

        $attempt = (int) $db->fetchOne($db->select()->from($table, ['n' => 'COUNT(*)'])->where('order_id = ?', (int) $order->getId())) + 1;
        $site = substr(hash('sha256', $this->url->getBaseUrl() . '|' . $storeId), 0, 12);
        $key = $this->statusKey($order);
        $statusUrl = $this->url->getUrl('arionpay/order/status', ['_secure' => true, '_query' => ['order' => $order->getIncrementId(), 'key' => $key]]);
        $created = $this->config->client($storeId)->createInvoice([
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => (string) $order->getIncrementId(),
            'description' => mb_substr('Order #' . $order->getIncrementId() . ' at ' . $order->getStore()->getFrontendName(), 0, 500),
            'successUrl' => $statusUrl,
            'cancelUrl' => $statusUrl,
            'callbackUrl' => $this->url->getUrl('arionpay/webhook/index', ['_secure' => true, '_nosid' => true]),
            'metadata' => ['magento_order_id' => (int) $order->getId(), 'magento_increment_id' => (string) $order->getIncrementId(), 'platform' => 'magento2'],
            'idempotencyKey' => 'magento-' . $site . '-' . $order->getIncrementId() . '-' . $attempt,
            'test' => $test,
        ]);
        $expires = !empty($created['expiresAt']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $created['expiresAt'])) : null;
        $db->insertOnDuplicate($table, [
            'invoice_id' => (string) $created['id'],
            'order_id' => (int) $order->getId(),
            'increment_id' => (string) $order->getIncrementId(),
            'invoice_url' => (string) $created['invoiceUrl'],
            'amount' => $amount,
            'currency' => $currency,
            'test' => $test ? 1 : 0,
            'status' => 'new',
            'expires_at' => $expires,
        ], ['invoice_url']);
        $order->addCommentToStatusHistory(($test ? '[TEST - no real money] ' : '') . sprintf('Customer sent to ArionPay to pay invoice %s (%s %s).', $created['id'], $amount, $currency));
        $order->save();
        $this->config->log('Invoice ' . $created['id'] . ' created for order ' . $order->getIncrementId() . ' (' . $amount . ' ' . $currency . ($test ? ', test' : '') . ').', $storeId);
        return (string) $created['invoiceUrl'];
    }

    /** A secret per order for the status page link (the order's protect code, never shown elsewhere). */
    public function statusKey(Order $order): string
    {
        return hash_hmac('sha256', (string) $order->getIncrementId(), (string) $order->getProtectCode());
    }

    public function isOrderPaid(Order $order): bool
    {
        $db = $this->resource->getConnection();
        $paid = $db->fetchOne($db->select()->from($this->resource->getTableName('arionpay_invoice'), ['order_paid'])->where('order_id = ?', (int) $order->getId())->where('order_paid = 1')->limit(1));
        return (bool) $paid || ($order->hasInvoices() && (float) $order->getTotalDue() <= 0.00001);
    }
}
