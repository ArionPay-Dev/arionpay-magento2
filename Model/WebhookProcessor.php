<?php
declare(strict_types=1);

namespace ArionPay\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Applies verified ArionPay events to Magento orders. Whether an invoice is paid is decided by ArionPay alone; this
 * class never compares crypto amounts.
 */
class WebhookProcessor
{
    private Config $config;
    private ResourceConnection $resource;
    private LockManagerInterface $locks;
    private OrderRepositoryInterface $orders;
    private InvoiceManager $invoices;
    private OrderSender $orderSender;
    private InvoiceSender $invoiceSender;

    public function __construct(
        Config $config,
        ResourceConnection $resource,
        LockManagerInterface $locks,
        OrderRepositoryInterface $orders,
        InvoiceManager $invoices,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender
    ) {
        $this->config = $config;
        $this->resource = $resource;
        $this->locks = $locks;
        $this->orders = $orders;
        $this->invoices = $invoices;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
    }

    /**
     * @param array<string,mixed> $event a verified event
     * @return string processed, duplicate, busy, unknown_invoice, mismatch or not_found
     */
    public function apply(array $event): string
    {
        $remote = $event['data']['invoice'];
        $invoiceId = (string) ($remote['id'] ?? '');
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('arionpay_invoice');
        $row = $db->fetchRow($db->select()->from($table)->where('invoice_id = ?', $invoiceId));
        if (!$row) {
            return 'unknown_invoice';
        }
        $metadata = isset($remote['metadata']) && is_array($remote['metadata']) ? $remote['metadata'] : [];
        if ((int) ($metadata['magento_order_id'] ?? 0) !== (int) $row['order_id']
            || (string) ($remote['orderId'] ?? '') !== (string) $row['increment_id']
            || (bool) ($remote['test'] ?? false) !== (bool) $row['test']) {
            return 'mismatch';
        }

        $lock = 'arionpay_order_' . (int) $row['order_id'];
        if (!$this->locks->lock($lock, 10)) {
            return 'busy';
        }
        try {
            $events = $this->resource->getTableName('arionpay_event');
            if ($db->fetchOne($db->select()->from($events, ['event_id'])->where('event_id = ?', (string) $event['id']))) {
                return 'duplicate';
            }
            try {
                /** @var Order $order */
                $order = $this->orders->get((int) $row['order_id']);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                return 'not_found';
            }
            $db->update($table, ['status' => substr((string) ($remote['status'] ?? ''), 0, 20)], ['invoice_id = ?' => $invoiceId]);
            $latest = (string) $db->fetchOne($db->select()->from($table, ['invoice_id'])->where('order_id = ?', (int) $row['order_id'])->order('created_at DESC')->limit(1));
            $isCurrent = $latest === $invoiceId;
            $paid = $this->invoices->isOrderPaid($order);

            switch ((string) $event['type']) {
                case 'invoice.paid':
                    $this->onPaid($order, $remote, $row);
                    break;
                case 'invoice.pending':
                    if ($isCurrent && !$paid) {
                        $this->comment($order, $this->text('ArionPay invoice {id}: {paid} {asset} was seen on the network and is waiting for confirmations. Do not ship yet.', $remote));
                    }
                    break;
                case 'invoice.partial':
                    if ($isCurrent && !$paid) {
                        $this->comment($order, $this->text('ArionPay invoice {id}: partial payment of {paid} of {due} {asset}. The invoice stays open for the rest. Do not ship yet.', $remote));
                    }
                    break;
                case 'invoice.expired':
                    if ($isCurrent && !$paid) {
                        $this->comment($order, $this->text('ArionPay invoice {id} expired without full payment. The order is not paid. A late payment can still arrive; if ArionPay accepts it, this order is updated. The customer can pay again from the link in the order status page.', $remote));
                    }
                    break;
                case 'invoice.needs_review':
                    $this->comment($order, $this->text('ArionPay invoice {id} needs your review in the ArionPay dashboard: {reason} The order status was not changed.', $remote));
                    break;
            }

            $db->insertOnDuplicate($events, ['event_id' => substr((string) $event['id'], 0, 64), 'invoice_id' => $invoiceId, 'type' => substr((string) $event['type'], 0, 32)], ['type']);
            return 'processed';
        } finally {
            $this->locks->unlock($lock);
        }
    }

    private function onPaid(Order $order, array $remote, array $row): void
    {
        if ($this->invoices->isOrderPaid($order)) {
            $this->comment($order, $this->text('ArionPay reports invoice {id} as paid, but this order was already paid. If it was paid another way, this may be a double payment: check your wallet and refund the customer if needed.', $remote));
            return;
        }
        if ($order->isCanceled() || $order->getState() === Order::STATE_CLOSED) {
            $this->comment($order, $this->text('ArionPay reports invoice {id} as paid, but this order is canceled or closed, so it was not changed. Check the payment in your ArionPay dashboard and handle the order by hand.', $remote));
            return;
        }

        // The invoice must still match the order: same currency, same total to the cent (not a payment tolerance:
        // it catches an order whose total was edited after the customer was sent to pay).
        [$expected, $currency] = $this->invoices->amountFor($order);
        $paid = is_numeric($remote['amountFiat'] ?? null) ? number_format((float) $remote['amountFiat'], 2, '.', '') : '';
        $paidCurrency = strtoupper((string) ($remote['currency'] ?? ''));
        if ($paidCurrency !== $currency || $paid !== $expected || $row['amount'] !== $expected) {
            $order->addCommentToStatusHistory(sprintf('ArionPay invoice %s is paid (%s %s), but that does not match this order\'s total (%s %s), so the order was NOT marked paid. Please check the order and the invoice.', $remote['id'], $paid !== '' ? $paid : '?', $paidCurrency, $expected, $currency));
            $this->orders->save($order);
            return;
        }

        $txid = '';
        if (!empty($remote['transactions']) && is_array($remote['transactions'])) {
            $last = end($remote['transactions']);
            $txid = is_array($last) && isset($last['txid']) && is_scalar($last['txid']) ? (string) $last['txid'] : '';
        }
        $payment = $order->getPayment();
        $payment->setTransactionId($txid !== '' ? $txid : (string) $remote['id']);
        $payment->setLastTransId($txid !== '' ? $txid : (string) $remote['id']);
        $payment->setIsTransactionClosed(true);
        $payment->setAdditionalInformation('arionpay_invoice_id', (string) $remote['id']);
        $payment->setAdditionalInformation('arionpay_asset', (string) ($remote['asset'] ?? ''));
        $payment->setAdditionalInformation('arionpay_paid_crypto', (string) ($remote['paidCrypto'] ?? ''));
        $payment->registerCaptureNotification((float) $order->getBaseGrandTotal(), true);
        $order->addCommentToStatusHistory($this->paidNote($remote, $txid), false, true);
        $this->orders->save($order);

        $this->resource->getConnection()->update($this->resource->getTableName('arionpay_invoice'), ['order_paid' => 1], ['invoice_id = ?' => (string) $remote['id']]);

        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
            $invoice = $payment->getCreatedInvoice();
            if ($invoice) {
                $this->invoiceSender->send($invoice);
            }
        } catch (\Throwable $e) {
            $this->config->log('Order ' . $order->getIncrementId() . ' is paid, but an email could not be sent: ' . $e->getMessage(), (int) $order->getStoreId());
        }
    }

    private function comment(Order $order, string $text): void
    {
        $order->addCommentToStatusHistory($text);
        $this->orders->save($order);
    }

    private function paidNote(array $remote, string $txid): string
    {
        $parts = [$this->text('ArionPay invoice {id} is paid: {paid} {asset} received on {network}. Transaction: {tx}.', $remote)];
        if (!empty($remote['late'])) {
            $parts[] = 'Paid late: the payment arrived after the invoice expired.';
        }
        if (!empty($remote['manual'])) {
            $parts[] = 'You accepted this payment by hand in the ArionPay dashboard.';
        }
        if (!empty($remote['overpaid'])) {
            $parts[] = 'The customer paid more than the invoice amount.';
        }
        if (!empty($remote['underpaidWithinTolerance'])) {
            $parts[] = 'The customer paid slightly less, and ArionPay accepted it under your store\'s payment tolerance.';
        }
        if (!empty($remote['needsReview'])) {
            $parts[] = 'ArionPay flagged this invoice for review: see your ArionPay dashboard.';
        }
        return implode(' ', $parts);
    }

    /** Fill a message with invoice values: {id} {paid} {due} {asset} {network} {reason} {tx}. */
    private function text(string $template, array $remote): string
    {
        $s = static fn (string $k): string => isset($remote[$k]) && is_scalar($remote[$k]) ? mb_substr(strip_tags((string) $remote[$k]), 0, 300) : '';
        $tx = '';
        if (!empty($remote['transactions']) && is_array($remote['transactions'])) {
            $last = end($remote['transactions']);
            $tx = is_array($last) && isset($last['txid']) && is_scalar($last['txid']) ? (string) $last['txid'] : '';
        }
        $text = strtr($template, [
            '{id}' => $s('id'), '{paid}' => $s('paidCrypto'), '{due}' => $s('amountCrypto'), '{asset}' => $s('asset'),
            '{network}' => $s('network'), '{reason}' => $s('reviewReason') !== '' ? $s('reviewReason') : '-', '{tx}' => $tx !== '' ? $tx : '-',
        ]);
        return (!empty($remote['test']) ? '[TEST - no real money] ' : '') . $text;
    }
}
