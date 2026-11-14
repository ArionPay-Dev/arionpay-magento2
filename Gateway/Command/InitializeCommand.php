<?php
declare(strict_types=1);

namespace ArionPay\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;

/**
 * Placing the order: no money moves yet. The order waits as "Pending Payment" until a signed ArionPay webhook
 * confirms the payment, and the order email is sent only then.
 */
class InitializeCommand implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        $stateObject = $commandSubject['stateObject'];
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
        return null;
    }
}
