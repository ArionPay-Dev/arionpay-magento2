<?php
/**
 * ArionPay for Magento 2 / Adobe Commerce / Mage-OS.
 *
 * @license MIT
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'ArionPay_Payment', __DIR__);

// The ArionPay PHP SDK ships inside the module; when Composer already provides it, Composer's copy wins.
if (!class_exists(\ArionPay\Client::class, true) && is_file(__DIR__ . '/lib/arionpay-php/autoload.php')) {
    require_once __DIR__ . '/lib/arionpay-php/autoload.php';
}
