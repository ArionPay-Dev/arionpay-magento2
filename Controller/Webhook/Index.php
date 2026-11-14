<?php
declare(strict_types=1);

namespace ArionPay\Payment\Controller\Webhook;

use ArionPay\Payment\Model\Config;
use ArionPay\Payment\Model\WebhookProcessor;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Webhook endpoint (https://your-shop/arionpay/webhook/index). Never changes an order unless the signature is valid.
 * ArionPay retries anything that is not 2xx for about 3 days.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_BODY_BYTES = 262144;

    private HttpRequest $request;
    private JsonFactory $jsonFactory;
    private Config $config;
    private WebhookProcessor $processor;

    public function __construct(HttpRequest $request, JsonFactory $jsonFactory, Config $config, WebhookProcessor $processor)
    {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->config = $config;
        $this->processor = $processor;
    }

    /** Server-to-server call signed by ArionPay: there is no form key to check. */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $secret = $this->config->webhookSecret();
        if ($secret === '') {
            $this->config->log('Webhook refused: the webhook signing secret is not set.');
            return $this->respond(503, ['error' => 'not_configured']);
        }
        $raw = (string) $this->request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->respond(413, ['error' => 'payload_too_large']);
        }
        $header = (string) $this->request->getHeader('ArionPay-Signature');
        try {
            $event = \ArionPay\Webhook::verify($raw, $header, $secret);
        } catch (\ArionPay\SignatureException $e) {
            $this->config->log($header === '' && $this->request->getHeader('X-Signature')
                ? 'Webhook refused: it has the old format. Turn off "old API (v1) compatibility" for this store in ArionPay.'
                : 'Webhook refused: ' . $e->getMessage());
            return $this->respond(401, ['error' => 'invalid_signature']);
        }
        if (\ArionPay\Webhook::isTestEvent($event)) {
            $this->config->log('Verified ArionPay test webhook received (' . $event['type'] . ').');
            return $this->respond(200, ['received' => true, 'test_event' => true]);
        }
        if (!in_array($event['type'], \ArionPay\Webhook::EVENT_TYPES, true)) {
            return $this->respond(200, ['received' => true, 'ignored' => 'unknown_event']);
        }
        $invoiceId = (string) ($event['data']['invoice']['id'] ?? '');
        if (!preg_match('/^[0-9a-f]{24}$/i', $invoiceId)) {
            return $this->respond(400, ['error' => 'malformed_event']);
        }

        $result = $this->processor->apply($event);
        $this->config->log('Webhook ' . $event['type'] . ' for invoice ' . $invoiceId . ': ' . $result . '.');
        switch ($result) {
            case 'busy':
                return $this->respond(503, ['error' => 'busy'], ['Retry-After' => '30']);
            case 'mismatch':
                return $this->respond(409, ['error' => 'invoice_order_mismatch']);
            case 'not_found':
                return $this->respond(404, ['error' => 'order_not_found']);
            case 'unknown_invoice':
                return $this->respond(200, ['received' => true, 'ignored' => 'not_a_magento_invoice']);
            case 'duplicate':
                return $this->respond(200, ['received' => true, 'duplicate' => true]);
            default:
                return $this->respond(200, ['received' => true]);
        }
    }

    private function respond(int $status, array $body, array $headers = [])
    {
        $result = $this->jsonFactory->create()->setHttpResponseCode($status)->setData($body);
        $result->setHeader('Cache-Control', 'no-store', true);
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }
        return $result;
    }
}
