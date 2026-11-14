<?php
declare(strict_types=1);

namespace ArionPay\Payment\Model\Ui;

use ArionPay\Payment\Model\Config;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;

/** Data for the checkout payment step. */
class ConfigProvider implements ConfigProviderInterface
{
    private Config $config;
    private UrlInterface $url;

    public function __construct(Config $config, UrlInterface $url)
    {
        $this->config = $config;
        $this->url = $url;
    }

    public function getConfig(): array
    {
        return [
            'payment' => [
                Config::CODE => [
                    'redirectUrl' => $this->url->getUrl('arionpay/redirect/index', ['_secure' => true]),
                    'testMode' => $this->config->isTest(),
                ],
            ],
        ];
    }
}
