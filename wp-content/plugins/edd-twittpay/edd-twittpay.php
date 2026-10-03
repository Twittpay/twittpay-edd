<?php
/**
 * Plugin Name: EDD TwittPay
 * Description: Adds TwittPay as a payment gateway for Easy Digital Downloads - bKash, Nagad, Rocket, Upay and cards through your own gateway.
 * Version: 1.0.0
 * Author: TwittPay
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: edd-twittpay
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Register the gateway
 * ---------------------------------------------------------------------- */

add_filter('edd_payment_gateways', function ($gateways) {
    $gateways['twittpay'] = [
        'admin_label'    => 'TwittPay',
        'checkout_label' => __('Pay with bKash / Nagad / Rocket / Card', 'edd-twittpay'),
    ];

    return $gateways;
});

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

add_filter('edd_settings_gateways', function ($settings) {
    return array_merge($settings, [
        [
            'id'   => 'twittpay_settings',
            'name' => '<strong>' . __('TwittPay Settings', 'edd-twittpay') . '</strong>',
            'desc' => __('Your own payment gateway.', 'edd-twittpay'),
            'type' => 'header',
        ],
        [
            'id'   => 'twittpay_api_key',
            'name' => __('Brand Key', 'edd-twittpay'),
            'desc' => __('From your gateway dashboard, under Brands.', 'edd-twittpay'),
            'type' => 'text',
        ],
        [
            'id'   => 'twittpay_currency_rate',
            'name' => __('USD to BDT Rate', 'edd-twittpay'),
            'desc' => __('Only used when your store currency is not BDT. 1 USD = this many BDT.', 'edd-twittpay'),
            'type' => 'text',
            'std'  => '120',
        ],
    ]);
});

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Scheme and host of the configured endpoint. A pasted path is trimmed off. */
function edd_twittpay_base_url()
{
    return 'https://checkout.twittpay.com';
}

/** One POST to the API. JSON in, array out. */
function edd_twittpay_api($endpoint, $payload)
{
    $base   = edd_twittpay_base_url();
    $apiKey = trim((string) edd_get_option('twittpay_api_key', ''));

    if ($base === '' || $apiKey === '') {
        return new WP_Error('twittpay_not_configured', __('TwittPay is not fully configured.', 'edd-twittpay'));
    }

    // metadata has to arrive as a JSON object; a PHP list would encode as an
    // array and be rejected.
    if (isset($payload['metadata'])) {
        $payload['metadata'] = (object) $payload['metadata'];
    }

    $response = wp_remote_post($base . $endpoint, [
        'method'      => 'POST',
        'timeout'     => 30,
        'headers'     => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'API-KEY'      => $apiKey,
        ],
        'body'        => wp_json_encode($payload),
        'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($body)) {
        return new WP_Error('twittpay_bad_response', __('The gateway sent back something that is not JSON.', 'edd-twittpay'));
    }

    return $body;
}

/**
 * The verify status: PENDING, COMPLETED or ERROR when the transaction is real, and
 * an empty string when it is not - a miss answers a number, not text.
 */
function edd_twittpay_status($verified)
{
    if (!is_array($verified) || !isset($verified['status']) || !is_string($verified['status'])) {
        return '';
    }

    return strtoupper(trim($verified['status']));
}

/** metadata comes back from verify as a JSON string. */
function edd_twittpay_metadata($verified)
{
    if (!is_array($verified) || !isset($verified['metadata'])) {
        return [];
    }

    $meta = $verified['metadata'];

    if (is_array($meta)) {
        return $meta;
    }

    if (is_object($meta)) {
        return (array) $meta;
    }

    if (is_string($meta) && $meta !== '') {
        $decoded = json_decode($meta, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/** The transaction id, from the URL, a form body, or a JSON body. */
function edd_twittpay_transaction_id()
{
    foreach (['transactionId', 'transaction_id'] as $key) {
        if (!empty($_GET[$key])) {
            return sanitize_text_field(wp_unslash($_GET[$key]));
        }

        if (!empty($_POST[$key])) {
            return sanitize_text_field(wp_unslash($_POST[$key]));
        }
    }

    $raw = file_get_contents('php://input');

    if (!empty($raw)) {
        $body = json_decode($raw, true);

        if (is_array($body)) {
            foreach (['transactionId', 'transaction_id'] as $key) {
                if (!empty($body[$key])) {
                    return sanitize_text_field($body[$key]);
                }
            }
        }
    }

    return '';
}

/** The gateway charges BDT. Any other store currency is converted. */
function edd_twittpay_to_bdt($amount, $currency)
{
    if (strtoupper($currency) === 'BDT') {
        return (float) $amount;
    }

    $rate = (float) edd_get_option('twittpay_currency_rate', 120);

    if ($rate <= 0) {
        $rate = 1;
    }

    return (float) $amount * $rate;
}

/**
 * Verify a transaction and, if it really is complete, mark the payment paid.
 * Safe to call twice - a payment that is already complete is left alone.
 *
 * @return string the status that was read, or '' when nothing could be read
 */
function edd_twittpay_settle($transactionId)
{
    if (empty($transactionId)) {
        return '';
    }

    $verified = edd_twittpay_api('/api/payment/verify', ['transaction_id' => $transactionId]);

    if (is_wp_error($verified)) {
        edd_record_gateway_error('TwittPay Error', $verified->get_error_message());

        return '';
    }

    $status = edd_twittpay_status($verified);

    if ($status === '') {
        return '';
    }

    $meta      = edd_twittpay_metadata($verified);
    $paymentId = !empty($meta['payment_id']) ? absint($meta['payment_id']) : 0;
    $payment   = $paymentId ? edd_get_payment($paymentId) : false;

    // The purchase key is the fallback, on the EDD versions that still look it up.
    if (!$payment && !empty($meta['purchase_key']) && function_exists('edd_get_payment_by')) {
        $payment = edd_get_payment_by('key', $meta['purchase_key']);
    }

    if (!$payment) {
        return $status;
    }

    $current = strtolower((string) $payment->status);

    if ($status === 'COMPLETED') {
        if (!in_array($current, ['publish', 'complete', 'completed'], true)) {
            edd_set_payment_transaction_id($payment->ID, $transactionId);
            edd_insert_payment_note(
                $payment->ID,
                sprintf(
                    /* translators: 1: payment method, 2: transaction id */
                    __('TwittPay: paid with %1$s. Transaction ID: %2$s', 'edd-twittpay'),
                    !empty($verified['payment_method']) ? $verified['payment_method'] : __('TwittPay', 'edd-twittpay'),
                    $transactionId
                )
            );
            edd_update_payment_status($payment->ID, 'publish');
        }

        return $status;
    }

    if ($status === 'PENDING') {
        // Sent, not approved by the merchant yet. The gateway calls the webhook
        // again with the answer, so the payment is left pending rather than failed.
        edd_insert_payment_note(
            $payment->ID,
            sprintf(
                /* translators: %s: transaction id */
                __('TwittPay: payment is being checked. Transaction ID: %s', 'edd-twittpay'),
                $transactionId
            )
        );

        return $status;
    }

    if (!in_array($current, ['publish', 'complete', 'completed', 'failed'], true)) {
        edd_insert_payment_note(
            $payment->ID,
            sprintf(
                /* translators: %s: transaction id */
                __('TwittPay: payment was not completed. Transaction ID: %s', 'edd-twittpay'),
                $transactionId
            )
        );
        edd_update_payment_status($payment->ID, 'failed');
    }

    return $status;
}

/* -------------------------------------------------------------------------
 * Checkout
 * ---------------------------------------------------------------------- */

add_action('edd_gateway_twittpay', function ($purchase_data) {
    $currency = strtoupper(edd_get_currency());

    $payment_data = [
        'price'        => $purchase_data['price'],
        'date'         => $purchase_data['date'],
        'user_email'   => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency'     => $currency,
        'downloads'    => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'user_info'    => $purchase_data['user_info'],
        'status'       => 'pending',
        'gateway'      => 'twittpay',
    ];

    $payment_id = edd_insert_payment($payment_data);

    if (!$payment_id) {
        edd_send_back_to_checkout('?payment-mode=twittpay');

        return;
    }

    $name = trim(
        (isset($purchase_data['user_info']['first_name']) ? $purchase_data['user_info']['first_name'] : '') . ' ' .
        (isset($purchase_data['user_info']['last_name']) ? $purchase_data['user_info']['last_name'] : '')
    );

    $successUrl = add_query_arg(
        [
            'payment-confirmation' => 'twittpay',
            'payment-id'           => $payment_id,
        ],
        get_permalink(edd_get_option('success_page'))
    );

    $post_data = [
        'cus_name'    => $name !== '' ? $name : 'Default Name',
        'cus_email'   => !empty($purchase_data['user_email']) ? $purchase_data['user_email'] : 'default@gmail.com',
        'amount'      => number_format(edd_twittpay_to_bdt($purchase_data['price'], $currency), 2, '.', ''),
        'success_url' => $successUrl,
        'cancel_url'  => edd_get_failed_transaction_uri(),
        'webhook_url' => site_url('/?edd-listener=twittpay'),
        'metadata'    => [
            'payment_id'    => (string) $payment_id,
            'purchase_key'  => (string) $purchase_data['purchase_key'],
            'order_amount'  => number_format((float) $purchase_data['price'], 2, '.', ''),
            'order_currency' => $currency,
            'source'        => 'edd',
        ],
    ];

    $body = edd_twittpay_api('/api/payment/create', $post_data);

    if (is_wp_error($body)) {
        edd_record_gateway_error('TwittPay Error', $body->get_error_message());
        edd_send_back_to_checkout('?payment-mode=twittpay');

        return;
    }

    if (!empty($body['status']) && !empty($body['payment_url'])) {
        edd_empty_cart();
        wp_redirect($body['payment_url']);
        exit;
    }

    // The message is logged for the shop owner, not shown to the customer - an
    // error string can carry the Brand Key back out.
    edd_record_gateway_error('TwittPay Error', isset($body['message']) ? $body['message'] : 'unknown error');
    edd_send_back_to_checkout('?payment-mode=twittpay');
});

/* -------------------------------------------------------------------------
 * The gateway's webhook
 * ---------------------------------------------------------------------- */

add_action('init', function () {
    if (!isset($_GET['edd-listener']) || $_GET['edd-listener'] !== 'twittpay') {
        return;
    }

    // The webhook is not signed, so nothing in it is trusted. The transaction id
    // is read and then checked against the API - a made-up id does not verify.
    $status = edd_twittpay_settle(edd_twittpay_transaction_id());

    if ($status === '') {
        status_header(400);
        echo wp_json_encode(['status' => false, 'message' => 'Unknown transaction.']);
        exit;
    }

    status_header(200);
    echo wp_json_encode(['status' => true, 'message' => $status]);
    exit;
});

/* -------------------------------------------------------------------------
 * The customer coming back
 *
 * The webhook usually gets here first, but if the gateway cannot reach this site
 * the return is the fallback - so it verifies too.
 * ---------------------------------------------------------------------- */

add_action('init', function () {
    if (empty($_GET['payment-confirmation']) || $_GET['payment-confirmation'] !== 'twittpay') {
        return;
    }

    edd_twittpay_settle(edd_twittpay_transaction_id());
});
