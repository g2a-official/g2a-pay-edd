<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class G2APayHelper
{
    const SANDBOX                       = 'sandbox';
    const PRODUCTION                    = 'production';
    const PRODUCTION_URL                = 'https://checkout.pay.g2a.com/index/';
    const SANDBOX_URL                   = 'https://checkout.test.pay.g2a.com/index/';
    const DISCOUNT                      = 'commerce_discount';
    const SHIPPING                      = 'shipping';
    const G2APAY_IPN_LISTENER_NAME      = 'G2APayIPN';
    const G2APAY_TRANSACTION_TABLE_NAME = 'g2apay_transactions';
    const REST_PRODUCTION_URL           = 'https://pay.g2a.com/rest';
    const REST_SANDBOX_URL              = 'https://www.test.pay.g2a.com/rest';

    /**
     * @param $params
     * @param $api_secret
     * @return string
     */
    public static function calculateHash($params, $api_secret)
    {
        if (isset($params['order_id'])) {
            $unhashedString = $params['order_id'] . self::getValidAmount($params['price'])
                . $params['currency'] . $api_secret;
        } else {
            $unhashedString = $params['transactionId'] . $params['userOrderId'] . $params['amount']
                . $api_secret;
        }

        return hash('sha256', $unhashedString);
    }

    /**
     * Return price in correct format.
     *
     * @param $amount
     * @return float
     */
    public static function getValidAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param $server
     * @param $token
     * @return string
     */
    public static function getPaymentUrl($server, $token = null)
    {
        $token = $token ? 'gateway?token=' . $token : null;

        return ($server === self::PRODUCTION ? self::PRODUCTION_URL : self::SANDBOX_URL) . $token;
    }

    /**
     * This method prepare based on order array which is send to G2A Pay.
     *
     * @param $payment
     * @param $settings
     * @return array
     */
    public static function prepareVarsArray($payment, $settings)
    {
        $return_url = add_query_arg([
            'payment-confirmation' => 'g2apay',
            'payment-id'           => $payment['order_id'],
        ], get_permalink(edd_get_option('success_page', false)));
        $cancel_url = edd_get_failed_transaction_uri('?payment-id=' . $payment['order_id']);

        return array(
            'api_hash'    => $settings['apihash'],
            'hash'        => self::calculateHash($payment, $settings['apisecret']),
            'order_id'    => $payment['order_id'],
            'amount'      => self::getValidAmount($payment['price']),
            'currency'    => $payment['currency'],
            'url_failure' => $cancel_url,
            'url_ok'      => $return_url,
            'items'       => self::getItemsArray($payment['purchase_data']),
        );
    }

    /**
     * @param $order
     * @return array
     */
    public static function getItemsArray($order)
    {
        $itemsInfo  = array();
        foreach ($order['cart_details'] as $orderItem) {
            $productUrl   = get_site_url() . DIRECTORY_SEPARATOR . '?download=' . $orderItem['name'];
            $itemsInfo[]  = array(
                'sku'    => $orderItem['id'],
                'name'   => $orderItem['name'],
                'amount' => G2APayHelper::getValidAmount($orderItem['price'] * $orderItem['quantity']),
                'qty'    => (integer) $orderItem['quantity'],
                'id'     => $orderItem['id'],
                'price'  => G2APayHelper::getValidAmount($orderItem['price']),
                'url'    => $productUrl,
            );
        }

        return $itemsInfo;
    }

    /**
     * @param $order_id
     * @return array|bool|null|object
     */
    public static function getIpnByOrderId($order_id)
    {
        global $wpdb;

        if (!is_numeric($order_id)) {
            return false;
        }

        $query = 'SELECT * FROM ' . self::G2APAY_TRANSACTION_TABLE_NAME . ' WHERE order_id =' . $order_id;

        return $wpdb->get_results($query);
    }

    /**
     * @return array
     */
    public static function getGatewaySettings()
    {
        global $edd_options;

        $gateway_settings = array();
        if (edd_is_test_mode()) {
            $gateway_settings = array(
                'apisecret'     => $edd_options['sandbox_g2apay_apisecret'],
                'apihash'       => $edd_options['sandbox_g2apay_apihash'],
                'merchantemail' => $edd_options['sandbox_g2apay_merchantemail'],
                'url'           => self::SANDBOX_URL,
                'resturl'       => self::REST_SANDBOX_URL,
            );
        } else {
            $gateway_settings = array(
                'apisecret'     => $edd_options['production_g2apay_apisecret'],
                'apihash'       => $edd_options['production_g2apay_apihash'],
                'merchantemail' => $edd_options['production_g2apay_merchantemail'],
                'url'           => self::PRODUCTION_URL,
                'resturl'       => self::REST_PRODUCTION_URL,
            );
        }

        return $gateway_settings;
    }

    /**
     * @param $order_id
     * @param $transaction_id
     * @param $amount
     * @param $status
     */
    public static function addTransactionConfirmation($order_id, $transaction_id, $amount, $status)
    {
        global $wpdb;

        $wpdb->insert(self::G2APAY_TRANSACTION_TABLE_NAME, array(
            'order_id'       => $order_id,
            'transaction_id' => $transaction_id,
            'amount_paid'    => self::getValidAmount($amount),
            'status'         => $status,
        ));
    }

    /**
     * @param $order_id
     * @param $ipn_params
     * @param $status
     */
    public static function addRefundConfirmation($order_id, $ipn_params, $status)
    {
        global $wpdb;

        $ipn_db_record = self::getIpnByOrderId($order_id);
        if ($ipn_db_record) {
            $refunded_amount = $ipn_db_record[0]->amount_refunded + $ipn_params['refundedAmount'];
            $wpdb->update(self::G2APAY_TRANSACTION_TABLE_NAME, array(
                'amount_refunded' => $refunded_amount,
                'status'          => $status,
            ), array('order_id' => $order_id));
        } else {
            $refunded_amount = self::getValidAmount($ipn_params['refundedAmount']);
            $paid_amount     = self::getValidAmount($ipn_params['amount']);
            $wpdb->insert(self::G2APAY_TRANSACTION_TABLE_NAME, array(
                'order_id'        => $order_id,
                'transaction_id'  => $ipn_params['transactionId'],
                'amount_paid'     => $paid_amount,
                'amount_refunded' => $refunded_amount,
                'status'          => $status,
            ));
        }
    }

    /**
     * @param $message
     * @param $type
     */
    public static function setSessionMessage($message, $type)
    {
        if (!self::isTypeValid($type)) {
            return;
        }

        $_SESSION['g2apay_' . $type . '_message'] = $message;
    }

    /**
     * @param $type
     * @return string|null
     */
    public static function getSessionMessage($type)
    {
        if (!self::isTypeValid($type)) {
            return;
        }

        return isset($_SESSION['g2apay_' . $type . '_message']) ? $_SESSION['g2apay_' . $type . '_message'] : null;
    }

    /**
     * @param $type
     */
    public static function unsetSessionMessage($type)
    {
        if (!self::isTypeValid($type)) {
            return;
        }

        unset($_SESSION['g2apay_' . $type . '_message']);
    }

    /**
     * @param $type
     * @return bool
     */
    public static function isTypeValid($type)
    {
        $valid_types = array('error', 'success');

        return in_array($type, $valid_types);
    }
}
