<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class G2APayRest
{
    /**
     * @param $order
     * @param $amount
     * @param $transaction_id
     * @return bool
     */
    public function refundOrder($order, $amount, $transaction_id)
    {
        $gateway_settings = G2APayHelper::getGatewaySettings();

        try {
            $amount = G2APayHelper::getValidAmount($amount);
            $data   = array(
                    'action' => 'refund',
                    'amount' => $amount,
                    'hash'   => $this->generateRefundHash($order, $amount, $transaction_id,
                        $gateway_settings['apisecret']),
            );

            $path   = sprintf('transactions/%s', $transaction_id);
            $url    = $this->getRestUrl($gateway_settings['resturl'], $path);
            $client = $this->createRestClient($url, G2APayClient::METHOD_PUT, $gateway_settings);

            $result = $client->request($data);

            return is_array($result) && isset($result['status']) && strcasecmp($result['status'], 'ok') === 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param $url
     * @param $method
     * @param $payment_method_settings
     * @return G2APayClient
     */
    protected function createRestClient($url, $method, $payment_method_settings)
    {
        $client = new G2APayClient($url);
        $client->setMethod($method);
        $client->addHeader('Authorization', $payment_method_settings['apihash'] . ';'
            . $this->getAuthorizationHash($payment_method_settings));

        return $client;
    }

    /**
     * @param $order
     * @param $amount
     * @param $transaction_id
     * @param $api_secret
     * @return string
     */
    protected function generateRefundHash($order, $amount, $transaction_id, $api_secret)
    {
        $string  = $transaction_id . $order->ID . G2APayHelper::getValidAmount($order->total)
            . $amount . $api_secret;

        return hash('sha256', $string);
    }

    /**
     * @param $base_url
     * @param string $path
     * @return string
     */
    public function getRestUrl($base_url, $path = '')
    {
        return $base_url . '/' . ltrim($path, '/');
    }

    /**
     * Returns generated authorization hash.
     *
     * @param $payment_method_settings
     * @return string
     */
    public function getAuthorizationHash($payment_method_settings)
    {
        $string = $payment_method_settings['apihash'] . $payment_method_settings['merchantemail']
            . $payment_method_settings['apisecret'];

        return hash('sha256', $string);
    }
}
