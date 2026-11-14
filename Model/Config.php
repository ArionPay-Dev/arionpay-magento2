<?php
declare(strict_types=1);

namespace ArionPay\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Store\Model\ScopeInterface;

/**
 * ArionPay settings (Stores > Configuration > Sales > Payment Methods > ArionPay) and the API client.
 */
class Config
{
    public const CODE = 'arionpay';
    public const VERSION = '1.0.0';

    /** Fiat currencies ArionPay can price an invoice in. */
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];

    private ScopeConfigInterface $scopeConfig;
    private EncryptorInterface $encryptor;
    private DirectoryList $directoryList;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor, DirectoryList $directoryList)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->directoryList = $directoryList;
    }

    public function get(string $field, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue('payment/' . self::CODE . '/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->get('active', $storeId) === '1';
    }

    public function isTest(?int $storeId = null): bool
    {
        return $this->get('test_mode', $storeId) === '1';
    }

    public function apiKey(?int $storeId = null): string
    {
        return trim($this->get('api_key', $storeId));
    }

    public function apiSecret(?int $storeId = null): string
    {
        return $this->secret('api_secret', $storeId);
    }

    public function webhookSecret(?int $storeId = null): string
    {
        return $this->secret('webhook_secret', $storeId);
    }

    /**
     * config.xml declares these fields encrypted, so Magento normally hands them over already decrypted. A value that
     * still looks encrypted ("0:3:...", e.g. set with config:set or another config source) is decrypted here.
     */
    private function secret(string $field, ?int $storeId): string
    {
        $value = trim($this->get($field, $storeId));
        if (preg_match('/^\d+:\d+:/', $value)) {
            $value = trim((string) $this->encryptor->decrypt($value));
        }
        return $value;
    }

    public function isConfigured(?int $storeId = null): bool
    {
        return $this->apiKey($storeId) !== '' && $this->apiSecret($storeId) !== '' && $this->webhookSecret($storeId) !== '';
    }

    public function client(?int $storeId = null): \ArionPay\Client
    {
        $base = rtrim(trim($this->get('base_url', $storeId)), '/');
        return new \ArionPay\Client($this->apiKey($storeId), $this->apiSecret($storeId), [
            'baseUrl' => $base !== '' ? $base : 'https://merchant.arionpay.com',
            'appInfo' => 'magento2 arionpay-magento2/' . self::VERSION,
        ]);
    }

    public function log(string $message, ?int $storeId = null): void
    {
        if ($this->get('debug', $storeId) !== '1') {
            return;
        }
        try {
            $file = $this->directoryList->getPath('log') . '/arionpay.log';
            @file_put_contents($file, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // logging must never break a payment
        }
    }
}
