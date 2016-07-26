<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class G2APayIpn
{
    private $postParams;

    const STATUS_CANCELED          = 'canceled';
    const STATUS_COMPLETE          = 'complete';
    const STATUS_REFUNDED          = 'refunded';
    const STATUS_PARTIALY_REFUNDED = 'partial_refunded';
    const SUCCESS                  = 'Success';
    const ORDER_PAID_STATUS        = 'complete';

    /**
     * G2APayIpn constructor.
     */
    public function __construct()
    {
        $this->postParams = $this->createArrayOfRequestParams();
    }

    /**
     * @return string
     */
    public function processIpn()
    {
        if ($_SERVER['REQUEST_METHOD'] !== G2APayClient::METHOD_POST) {
            return 'Invalid request method';
        }

        $orderId = $this->getPostParam('userOrderId');

        if (!$orderId) {
            return 'Invalid parameters';
        }

        $payment = edd_get_payment_by('id', $orderId);

        if (!$this->comparePrices($this->postParams, $payment->total)) {
            return 'Price does not match';
        }
        if ($this->getPostParam('status') === self::STATUS_CANCELED) {
            return 'Canceled';
        }
        if (!$this->ifCalculatedHashMatch($this->postParams)) {
            return 'Calculated hash does not match';
        }
        if ($this->getPostParam('transactionId') && $this->getPostParam('status') === self::STATUS_COMPLETE) {
            edd_update_payment_status($orderId, self::ORDER_PAID_STATUS);
            G2APayHelper::addTransactionConfirmation($orderId, $this->getPostParam('transactionId'),
                $this->getPostParam('amount'), ucfirst(self::STATUS_COMPLETE));

            return self::SUCCESS;
        }
        if ($this->getPostParam('refundedAmount') && $this->getPostParam('status') === self::STATUS_REFUNDED) {
            G2APayHelper::addRefundConfirmation($orderId, $this->postParams, ucfirst(self::STATUS_REFUNDED));

            return self::SUCCESS;
        }
        if ($this->getPostParam('status') === self::STATUS_PARTIALY_REFUNDED && $this->getPostParam('refundedAmount')) {
            $status = str_replace('_', ' ', self::STATUS_PARTIALY_REFUNDED);
            G2APayHelper::addRefundConfirmation($orderId, $this->postParams, ucfirst($status));

            return self::SUCCESS;
        }
    }

    /**
     * Modify request from G2A Pay to array format.
     *
     * @return array
     */
    private function createArrayOfRequestParams()
    {
        return $_POST;
    }

    /**
     * @param $vars
     * @param $orderTotal
     * @return bool
     */
    private function comparePrices($vars, $orderTotal)
    {
        return G2APayHelper::getValidAmount($vars['amount']) === G2APayHelper::getValidAmount($orderTotal);
    }

    /**
     * @param $vars
     * @return bool
     */
    private function ifCalculatedHashMatch($vars)
    {
        global $edd_options;

        if (edd_is_test_mode()) {
            return G2APayHelper::calculateHash($vars, $edd_options['sandbox_g2apay_apisecret']) === $vars['hash'];
        }

        return G2APayHelper::calculateHash($vars, $edd_options['production_g2apay_apisecret']) === $vars['hash'];
    }

    /**
     * @param $key
     * @return string|null
     */
    public function getPostParam($key)
    {
        return isset($this->postParams[$key]) ? $this->postParams[$key] : null;
    }
}
