<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/*
 * Plugin Name: G2A Pay
 * Plugin URL: https://pay.g2a.com
 * Description: Easily integrate 100+ global and local payment methods with all-in-one solution.
 * Version: 1.0.0
 * Author: G2A Team
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once 'libraries' . DIRECTORY_SEPARATOR . 'g2apay' . DIRECTORY_SEPARATOR . 'G2APayAutoload.php';
G2APayAutoload::register();

add_action('edd_g2apay_cc_form', '__return_false');

/**
 * @param $gateways
 * @return array
 */
function edd_g2apay_register_gateway($gateways)
{
    global $wpdb;

    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . G2APayHelper::G2APAY_TRANSACTION_TABLE_NAME .
        ' ( `id` INT NOT NULL AUTO_INCREMENT , `order_id` INT NOT NULL , 
            `transaction_id` VARCHAR(70) NOT NULL , `status` VARCHAR(30) NOT NULL , `amount_paid` FLOAT NOT NULL , 
            `amount_refunded` FLOAT NOT NULL DEFAULT \'0\' , PRIMARY KEY (`id`))');
    $gateways['g2apay'] = array(
        'admin_label'    => 'G2A Pay',
        'checkout_label' => __('G2A Pay', 'pw_edd'),
    );

    return $gateways;
}

add_filter('edd_payment_gateways', 'edd_g2apay_register_gateway');

/**
 * @param $gateway_sections
 * @return mixed
 */
function edd_register_g2apay_gateway_section($gateway_sections)
{
    $gateway_sections['g2apay'] = __('G2A Pay', 'easy-digital-downloads');

    return $gateway_sections;
}

add_filter('edd_settings_sections_gateways', 'edd_register_g2apay_gateway_section', 1, 1);

function edd_g2apay_add_options_link()
{
    global $edd_g2apay_payment_page;

    $edd_g2apay_payment_page = add_submenu_page('edit.php?post_type=download',
        __('G2A Pay Payments', 'easy-digital-downloads'), __('G2A Pay Payments', 'easy-digital-downloads'),
        'edit_shop_payments', 'edd_g2apay_payment_history_page', 'edd_g2apay_payment_history_page');
}

add_action('admin_menu', 'edd_g2apay_add_options_link', 10);

/**
 * Shows page with transactions paid via G2A Pay.
 */
function edd_g2apay_payment_history_page()
{
    if ($message = G2APayHelper::getSessionMessage('error')) {
        edd_g2apay_admin_notice($message, false);
        G2APayHelper::unsetSessionMessage('error');
    }

    if ($message = G2APayHelper::getSessionMessage('success')) {
        edd_g2apay_admin_notice($message);
        G2APayHelper::unsetSessionMessage('success');
    }

    if (!isset($_POST['order_id'])) {
        echo '<div class="g2apay_payment_history">
              <h1>G2A Pay Payments History</h1>';
        $table = new G2APayPaymentHistoryTable();
        $table->prepare_items();
        $table->display();
        echo '</div>';

        return;
    }

    $order_id = html_entity_decode($_POST['order_id']);
    if (isset($_POST['refund_amount'])) {
        edd_g2apay_proceed_refund($_POST['refund_amount'], $order_id);
    }

    $g2apay_ipn       = G2APayHelper::getIpnByOrderId($order_id);
    $max_refund_value = $g2apay_ipn[0]->amount_paid - $g2apay_ipn[0]->amount_refunded;

    echo '<div id="refund_div">
        <h1>Refund for payment #' . $order_id . '</h1><br />
        <form id="refund_form" action="#" method="post">
            <input type="hidden" id="order_id" name="order_id" value="' . $order_id . '">
            <label for="refund_amount" id="refund_amount_label">Refund Amount (max: ' . $max_refund_value . ')</label>
            <input type="text" id="refund_amount" name="refund_amount" required /><br />
            <input style="margin-top: 10px" onclick="this.form.submit(); this.disabled=true;" type="submit" 
            id="proceed_refund" value="Refund">
        </form>
    </div>';
}

/**
 * @param $refund_amount
 * @param $g2apay_ipn
 * @return bool
 */
function edd_g2apay_validate_refund($refund_amount, $g2apay_ipn)
{
    try {
        $max_refund_value = $g2apay_ipn[0]->amount_paid - $g2apay_ipn[0]->amount_refunded;
        if (!is_numeric($refund_amount) || $refund_amount <= 0) {
            throw new G2APayException('You must specify a positive numeric amount to refund.');
        }
        if ($refund_amount > $max_refund_value) {
            throw new G2APayException('You cannot refund more than it was paid.');
        }

        return false;
    } catch (G2APayException $e) {
        return $e->getMessage();
    }
}

/**
 * @param $refund_amount
 * @param $order_id
 */
function edd_g2apay_proceed_refund($refund_amount, $order_id)
{
    try {
        $refund_amount = str_replace(',', '.', $refund_amount);
        $g2apay_ipn    = G2APayHelper::getIpnByOrderId($order_id);
        if ($message = edd_g2apay_validate_refund($refund_amount, $g2apay_ipn)) {
            throw new G2APayException($message);
        }
        $payment = edd_get_payment_by('id', $order_id);

        $g2a_rest = new G2APayRest();

        $success = $g2a_rest->refundOrder($payment, $refund_amount, $g2apay_ipn[0]->transaction_id);

        if (!$success) {
            throw new G2APayException(__('Online refund request failed for amount: ') . $refund_amount);
        }
        G2APayHelper::setSessionMessage(__('Refund successfully for amount: ') . $refund_amount, 'success');
        refreshPage();
    } catch (G2APayException $e) {
        G2APayHelper::setSessionMessage($e->getMessage(), 'error');
        refreshPage();
    }
}

/**
 * @param $message
 * @param bool $success
 */
function edd_g2apay_admin_notice($message, $success = true)
{
    $class   = $success ? 'notice notice-success is-dismissible' : 'notice notice-error';
    $message = __($message, 'easy-digital-downloads');

    printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
}

/**
 * Reloads current page.
 */
function refreshPage()
{
    echo '<script>location.reload();</script>';
}

/**
 * @param $purchase_data
 */
function edd_g2apay_process_payment($purchase_data)
{
    global $edd_options;

    $gateway_settings = G2APayHelper::getGatewaySettings();

    $payment = array(
        'price'        => $purchase_data['price'],
        'date'         => $purchase_data['date'],
        'user_email'   => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency'     => $edd_options['currency'],
        'downloads'    => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'user_info'    => $purchase_data['user_info'],
        'status'       => 'pending',
    );

    // record the pending payment
    $paymentId                = edd_insert_payment($payment);
    $payment['order_id']      = $paymentId;
    $payment['purchase_data'] = $purchase_data;

    $postVars = G2APayHelper::prepareVarsArray($payment, $gateway_settings);

    /** @var $client G2APayClient */
    $client = new G2APayClient($gateway_settings['url'] . 'createQuote');
    $client->setMethod(G2APayClient::METHOD_POST);
    $response = $client->request($postVars);

    if (edd_get_errors()) {
        edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }

    try {
        if (empty($response['token'])) {
            throw new G2APayException('Empty Token');
        }
        header('Location: ' . $gateway_settings['url'] . 'gateway?token=' . $response['token']);
        edd_empty_cart();
        edd_die();
    } catch (G2APayException $ex) {
        edd_record_gateway_error(__('Payment Error', 'easy-digital-downloads'),
            __('Some error occurs processing payment', 'easy-digital-downloads'));
        edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }
}

add_action('edd_gateway_g2apay', 'edd_g2apay_process_payment');

/**
 * Listens for a G2A Pay IPN requests.
 *
 * @return void
 */
function edd_listen_for_g2apay_ipn()
{
    if (isset($_GET['edd-listener']) && $_GET['edd-listener'] === G2APayHelper::G2APAY_IPN_LISTENER_NAME) {
        $g2aPayIpn = new G2APayIpn();
        $message   = $g2aPayIpn->processIpn();
        exit($message);
    }
}

add_action('init', 'edd_listen_for_g2apay_ipn');

/**
 * @param $settings
 * @return mixed
 */
function edd_g2apay_add_settings($settings)
{
    $g2apay_settings = array();
    $g2apay_settings = array(
        'g2apay_settings' => array(
            'id'   => 'g2apay_settings',
            'name' => '<strong>' . __('G2A Pay Settings', 'easy-digital-downloads') . '</strong>',
            'type' => 'header',
        ),
        'sandbox_g2apay_apisecret' => array(
            'id'   => 'sandbox_g2apay_apisecret',
            'name' => __('Test G2A Pay Api Secret', 'easy-digital-downloads'),
            'desc' => __('Enter your G2A Pay Api Secret for test mode', 'easy-digital-downloads'),
            'type' => 'text',
            'size' => 'regular',
        ),
        'sandbox_g2apay_apihash' => array(
            'id'   => 'sandbox_g2apay_apihash',
            'name' => __('Test G2A Pay Api Hash', 'easy-digital-downloads'),
            'desc' => __('Enter your G2A Pay Api Hash for test mode', 'easy-digital-downloads'),
            'type' => 'text',
            'size' => 'regular',
        ),
        'sandbox_g2apay_merchantemail' => array(
            'id'   => 'sandbox_g2apay_merchantemail',
            'name' => __('Test G2A Pay Merchant Email', 'easy-digital-downloads'),
            'desc' => __('Enter your G2A Pay Merchant Email for test mode', 'easy-digital-downloads'),
            'type' => 'text',
            'size' => 'regular',
        ),
        'production_g2apay_apisecret' => array(
            'id'   => 'production_g2apay_apisecret',
            'name' => __('Production G2A Pay Api Secret', 'easy-digital-downloads'),
            'desc' => __('Enter your G2A Pay Api Secret for production mode', 'easy-digital-downloads'),
            'type' => 'text',
            'size' => 'regular',
        ),
        'production_g2apay_apihash' => array(
            'id'   => 'production_g2apay_apihash',
            'name' => __('Production G2A Pay Api Hash', 'easy-digital-downloads'),
            'desc' => __('Enter your G2A Pay Api Hash for production mode', 'easy-digital-downloads'),
            'type' => 'text',
            'size' => 'regular',
        ),
        'production_g2apay_merchantemail' => array(
            'id'   => 'production_g2apay_merchantemail',
            'name' => __('Production G2A Pay Merchant Email', 'easy-digital-downloads'),
            'desc' => __('Enter your G2A Pay Merchant Email for production mode', 'easy-digital-downloads'),
            'type' => 'text',
            'size' => 'regular',
        ),
        'g2apay_ipn_url' => array(
            'id'       => 'g2apay_ipn_url',
            'name'     => __('G2A Pay IPN URL', 'easy-digital-downloads'),
            'type'     => 'text',
            'size'     => 'large',
            'std'      => add_query_arg('edd-listener', G2APayHelper::G2APAY_IPN_LISTENER_NAME, home_url('index.php')),
            'faux'     => true,
        ),
    );

    $settings['g2apay'] = $g2apay_settings;

    return $settings;
}

add_filter('edd_settings_gateways', 'edd_g2apay_add_settings');
