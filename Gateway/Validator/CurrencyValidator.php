<?php
declare(strict_types=1);

namespace ArionPay\Payment\Gateway\Validator;

use ArionPay\Payment\Model\Config;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/** ArionPay is offered only for currencies it can price an invoice in. */
class CurrencyValidator extends AbstractValidator
{
    public function validate(array $validationSubject): ResultInterface
    {
        $currency = strtoupper((string) ($validationSubject['currency'] ?? ''));
        return $this->createResult(in_array($currency, Config::CURRENCIES, true));
    }
}
