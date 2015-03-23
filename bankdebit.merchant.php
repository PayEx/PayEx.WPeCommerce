<?php
/**
 * AAIT PayEx Bank Debit Merchant Gateway for WP eCommerce
 * Author: AAIT Team
 * Author URI: http://aait.se/
 */

$nzshpcrt_gateways[$num] = array(
    'name' => __('Payex Bank Debit', 'wpsc'),
    'api_version' => 2.0,
    'image' => plugins_url(basename(realpath(dirname(__FILE__) . '/..'))) . '/' . basename(dirname(__FILE__)) . '/images/payex.png',
    'class_name' => 'wpsc_merchant_bankdebit',
    'has_recurring_billing' => false,
    'wp_admin_cannot_cancel' => false,
    'display_name' => __('Payex Bank Debit', 'wpsc'),
    'requirements' => array(
        /// so that you can restrict merchant modules to PHP 5, if you use PHP 5 features
        'php_version' => 5.3,
        /// for modules that may not be present, like curl
        'extra_modules' => array()
    ),

    // this may be legacy, not yet decided
    'internalname' => 'wpsc_merchant_bankdebit',

    // All array members below here are legacy, and use the code in paypal_multiple.php
    'form' => array('wpsc_merchant_bankdebit', 'form_settings'),
    'submit_function' => array('wpsc_merchant_bankdebit', 'form_settings_submit'),
    'payment_type' => 'payex',
    'supported_currencies' => array(
        'currency_list' => array('DKK', 'EUR', 'GBP', 'NOK', 'SEK', 'USD'),
        'option_name' => 'payex_currency'
    )
);

add_action('init', array('wpsc_merchant_bankdebit', 'action_init'));

// Add compatible mode
require_once dirname(__FILE__) . '/Px/wpecommerce.compat.php';
if (!function_exists('_wpsc_action_merchant_v2_submit_gateway_options')) {
    $nzshpcrt_gateways[$num]['form'] = 'bankdebit_form_settings';
    $nzshpcrt_gateways[$num]['submit_function'] = 'bankdebit_form_settings_submit';

    function bankdebit_form_settings()
    {
        return call_user_func(array('wpsc_merchant_bankdebit', 'form_settings'));
    }

    function bankdebit_form_settings_submit()
    {
        return call_user_func(array('wpsc_merchant_bankdebit', 'form_settings_submit'));
    }
}

class wpsc_merchant_bankdebit extends wpsc_merchant
{
    var $name = '';

    protected $_px;

    /**
     * Init
     * @param null $purchase_id
     * @param bool $is_receiving
     */
    public function __construct($purchase_id = null, $is_receiving = false)
    {
        global $wpdb;

        $this->name = __('Payex Bank Debit', 'wpsc');
        parent::__construct($purchase_id, $is_receiving);

        // Install DB Tables
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpsc_payex_transactions` (
                `id` int(10) NOT NULL AUTO_INCREMENT,
                `order_id` int(11) DEFAULT NULL COMMENT 'Order Id',
                `transaction_id` int(11) DEFAULT NULL COMMENT 'PayEx Transaction Id',
                `transaction_status` int(11) DEFAULT NULL COMMENT 'PayEx Transaction Status',
                `transaction_data` text COMMENT 'PayEx Transaction Data',
                `date` datetime DEFAULT NULL COMMENT 'PayEx Transaction Date',
                `is_captured` tinyint(4) DEFAULT '0' COMMENT 'Is Captured',
                `is_canceled` tinyint(4) DEFAULT '0' COMMENT 'Is Canceled',
                `is_refunded` tinyint(4) DEFAULT '0' COMMENT 'Is Refunded',
                `total_refunded` float DEFAULT '0' COMMENT 'Refund Amount',
                PRIMARY KEY (`id`),
                UNIQUE KEY `transaction_id` (`transaction_id`),
                KEY `order_id` (`order_id`),
                KEY `transaction_status` (`transaction_status`),
                KEY `date` (`date`)
            ) AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
        ");

        // Init PayEx
        $this->getPx()->setEnvironment(get_option('bankdebit_account_number'), get_option('bankdebit_encryption_key'), (get_option('bankdebit_mode') === 'TEST'));
    }

    /**
     * Init
     */
    public function action_init()
    {
        // Success Page
        if (isset($_GET['sessionid']) && isset($_GET['gateway']) && $_GET['gateway'] === get_class($this)) {
            wpsc_delete_customer_meta('payex_sessionid');
        }
    }

    /**
     * Settings Form
     * @return string
     */
    public function form_settings()
    {
        $banks = is_array(get_option('bankdebit_banks')) ? get_option('bankdebit_banks') : array();

        $output = "
        <tr>
          <td>" . __('Account Number', 'wpsc') . "
          </td>
          <td>
				<input type='text' size='40' value='" . get_option('bankdebit_account_number') . "' name='bankdebit_account_number' />
          </td>
        </tr>
        <tr>
          <td>" . __('Encryption Key', 'wpsc') . "
          </td>
          <td>
				<input type='text' size='40' value='" . get_option('bankdebit_encryption_key') . "' name='bankdebit_encryption_key' />
          </td>
        </tr>
        <tr>
         <td>" . __('Mode', 'wpsc') . "
         </td>
         <td>
				<input " . ((get_option('bankdebit_mode') === 'TEST') ? "checked='checked'" : '') . " type='radio' name='bankdebit_mode' value='TEST' /> " . __('Test', 'wpsc') . "
				<input " . ((get_option('bankdebit_mode') === 'LIVE') ? "checked='checked'" : '') . " type='radio' name='bankdebit_mode' value='LIVE' /> " . __('Live', 'wpsc') . "
         </td>
        </tr>
        <tr>
         <td>" . __('Purchase Operation', 'wpsc') . "
         </td>
         <td>
				<select name='bankdebit_purchase_operation'>
					<option " . ((get_option('bankdebit_purchase_operation') === 'AUTHORIZATION') ? 'selected' : '') . " value='AUTHORIZATION'>" . __('Authorization', 'wpsc') . "</option>
					<option " . ((get_option('bankdebit_purchase_operation') === 'SALE') ? 'selected' : '') . " value='SALE'>" . __('Sale', 'wpsc') . "</option>
				</select>
         </td>
        </tr>
        <tr>
         <td>" . __('Banks', 'wpsc') . "
         </td>
         <td>
                <select name='bankdebit_banks[]' multiple='multiple' size='10'>
                    <option value='NB' " . (in_array('NB', $banks) ? 'selected' : '') . ">Nordea Bank</option>
                    <option value='FSPA' " . (in_array('FSPA', $banks) ? 'selected' : '') . ">Swedbank</option>
                    <option value='SEB' " . (in_array('SEB', $banks) ? 'selected' : '') . ">Svenska Enskilda Bank</option>
                    <option value='SHB' " . (in_array('SHB', $banks) ? 'selected' : '') . ">Handelsbanken</option>
                    <option value='NB:DK' " . (in_array('NB:DK', $banks) ? 'selected' : '') . ">Nordea Bank DK</option>
                    <option value='DDB' " . (in_array('DDB', $banks) ? 'selected' : '') . ">Den Danske Bank</option>
                    <option value='BAX' " . (in_array('BAX', $banks) ? 'selected' : '') . ">BankAxess</option>
                    <option value='SAMPO' " . (in_array('SAMPO', $banks) ? 'selected' : '') . ">Sampo</option>
                    <option value='AKTIA' " . (in_array('AKTIA', $banks) ? 'selected' : '') . ">Aktia, Säästöpankki</option>
                    <option value='OP' " . (in_array('OP', $banks) ? 'selected' : '') . ">Osuuspanki, Pohjola, Oko</option>
                    <option value='NB:FI' " . (in_array('NB:FI', $banks) ? 'selected' : '') . ">Nordea Bank Finland</option>
                    <option value='SHB:FI' " . (in_array('SHB:FI', $banks) ? 'selected' : '') . ">SHB:FI</option>
                    <option value='SPANKKI' " . (in_array('SPANKKI', $banks) ? 'selected' : '') . ">SPANKKI</option>
                    <option value='TAPIOLA' " . (in_array('TAPIOLA', $banks) ? 'selected' : '') . ">TAPIOLA</option>
                    <option value='AALAND' " . (in_array('AALAND', $banks) ? 'selected' : '') . ">Ålandsbanken</option>
                </select>
         </td>
        </tr>
        <tr>
         <td>" . __('Language', 'wpsc') . "
         </td>
         <td>
				<select name='bankdebit_language'>
                    <option " . ((get_option('bankdebit_language') === 'en-US') ? 'selected' : '') . " value='en-US'>" . __('English', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'sv-SE') ? 'selected' : '') . " value='sv-SE'>" . __('Swedish', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'nb-NO') ? 'selected' : '') . " value='nb-NO'>" . __('Norway', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'da-DK') ? 'selected' : '') . " value='da-DK'>" . __('Danish', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'es-ES') ? 'selected' : '') . " value='es-ES'>" . __('Spanish', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'de-DE') ? 'selected' : '') . " value='de-DE'>" . __('German', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'fi-FI') ? 'selected' : '') . " value='fi-FI'>" . __('Finnish', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'fr-FR') ? 'selected' : '') . " value='fr-FR'>" . __('French', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'pl-PL') ? 'selected' : '') . " value='pl-PL'>" . __('Polish', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'cs-CZ') ? 'selected' : '') . " value='cs-CZ'>" . __('Czech', 'wpsc') . "</option>
                    <option " . ((get_option('bankdebit_language') === 'hu-HU') ? 'selected' : '') . " value='hu-HU'>" . __('Hungarian', 'wpsc') . "</option>
				</select>
         </td>
        </tr>
        <tr>
         <td>" . __('Responsive Skinning', 'wpsc') . "
         </td>
         <td>
				<input " . ((get_option('bankdebit_responsive') === '1') ? "checked='checked'" : '') . " type='radio' name='bankdebit_responsive' value='1' /> " . __('Enabled', 'wpsc') . "
				<input " . ((get_option('bankdebit_responsive') === '0') ? "checked='checked'" : '') . " type='radio' name='bankdebit_responsive' value='0' /> " . __('Disabled', 'wpsc') . "
         </td>
        </tr>
        ";

        return $output;
    }

    /**
     * Save Settings Values
     * @return bool
     */
    public function form_settings_submit()
    {
        $fields = array(
            'bankdebit_account_number', 'bankdebit_encryption_key', 'bankdebit_mode', 'bankdebit_purchase_operation', 'bankdebit_language', 'bankdebit_banks', 'bankdebit_responsive'
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_option($field, !is_array($_POST[$field]) ? sanitize_user($_POST[$field]) : $_POST[$field]);
            }
        }

        return true;
    }

    /**
     * Submit method, sends the received data to the payment gateway
     * @return bool|void
     */
    public function submit()
    {
        global $wpdb, $wpsc_cart;

        // Set Session Id
        $this->session_id = $this->cart_data['session_id'];
        wpsc_update_customer_meta('payex_sessionid', $this->session_id);

        // Validate Social Security Number
        if (empty($_POST['bank_id'])) {
            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(__('Please select bank.', 'wpsc'));
            return;
        }
        $bank_id = $_POST['bank_id'];

        // Get Order Id
        $purchase_log_sql = $wpdb->prepare("SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `sessionid`= %s LIMIT 1", $this->session_id);
        $purchase_log = $wpdb->get_row($purchase_log_sql, ARRAY_A);
        $order_id = $purchase_log['id'];
        $customer_id = $purchase_log['user_ID'];

        // Convert Billing Region Code to Region Name
        if (is_numeric($purchase_log['billing_region'])) {
            $billing_region = wpsc_get_state_by_id($purchase_log['billing_region'], 'name');
            $this->cart_data['billing_address']['state'] = $billing_region ? $billing_region : $this->cart_data['billing_address']['state'];
        }

        // Convert Shipping Region Code to Region Name
        if (is_numeric($purchase_log['shipping_region'])) {
            $billing_region = wpsc_get_state_by_id($purchase_log['shipping_region'], 'name');
            $this->cart_data['shipping_address']['state'] = $billing_region ? $billing_region : $this->cart_data['billing_address']['state'];
        }

        // Set Order Status "Incomplete"
        $this->set_purchase_processed_by_purchid(2);

        // Get Urls
        $returnUrl = $this->cart_data['notification_url'];
        $returnUrl = add_query_arg('gateway', get_class($this), $returnUrl);
        $separator = (get_option('permalink_structure') !== '') ? '?' : '&';
        $cancelUrl = get_option('shopping_cart_url') . $separator . 'payex_cancel=true';
        $cancelUrl = add_query_arg('gateway', get_class($this), $cancelUrl);

        // Call PxOrder.Initialize8
        $params = array(
            'accountNumber' => '',
            'purchaseOperation' => 'SALE',
            'price' => 0,
            'priceArgList' => $bank_id . '=' . round($this->cart_data['total_price'] * 100),
            'currency' => $this->cart_data['store_currency'],
            'vat' => 0,
            'orderID' => $order_id,
            'productNumber' => $customer_id, // Customer Id
            'description' => get_bloginfo('name'),
            'clientIPAddress' => $_SERVER['REMOTE_ADDR'],
            'clientIdentifier' => 'USERAGENT=' . $_SERVER['HTTP_USER_AGENT'],
            'additionalValues' => get_option('bankdebit_responsive') === '1' ? 'USECSS=RESPONSIVEDESIGN' : '',
            'externalID' => '',
            'returnUrl' => $returnUrl,
            'view' => 'DIRECTDEBIT',
            'agreementRef' => '',
            'cancelUrl' => $cancelUrl,
            'clientLanguage' => get_option('bankdebit_language')
        );
        $result = $this->getPx()->Initialize8($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
            return;
        }

        $orderRef = $result['orderRef'];

        // Add Order Lines
        $i = 1;
        $wpec_taxes = new wpec_taxes_controller();
        // @todo Use $wpsc_cart->get_items() instead $wpsc_cart->cart_items
        foreach ($wpsc_cart->cart_items as $cart_item) {
            // Please check "wpec_taxes_calculate_total" function in taxes_controller.class.php
            $taxPrice = 0;
            $taxRate = 0;
            if ($wpec_taxes->wpec_taxes->wpec_taxes_get_enabled()) {
                // get selected country code
                $wpec_selected_country = $wpec_taxes->wpec_taxes_retrieve_selected_country();

                // set tax region
                $region = $wpec_taxes->wpec_taxes_retrieve_region();

                // get the rate for the country and region if set
                $tax_rate = $wpec_taxes->wpec_taxes->wpec_taxes_get_rate($wpec_selected_country, $region);

                if ($wpec_taxes->wpec_taxes_isincluded()) {
                    $taxes = $wpec_taxes->wpec_taxes_calculate_included_tax($cart_item);
                } else {
                    $taxes = $wpec_taxes->wpec_taxes_calculate_excluded_tax($cart_item, $tax_rate);
                }

                $taxPrice = $taxes['tax'];
                $taxRate = $taxes['rate'];
            }

            $priceWithTax = wpsc_tax_isincluded() ? $cart_item->total_price : $cart_item->total_price + $taxPrice;

            // Call PxOrder.AddSingleOrderLine2
            $params = array(
                'accountNumber' => '',
                'orderRef' => $orderRef,
                'itemNumber' => $i,
                'itemDescription1' => $cart_item->product_name,
                'itemDescription2' => '',
                'itemDescription3' => '',
                'itemDescription4' => '',
                'itemDescription5' => '',
                'quantity' => $cart_item->quantity,
                'amount' => (int)(100 * $priceWithTax), //must include tax
                'vatPrice' => (int)(100 * $taxPrice),
                'vatPercent' => (int)(100 * $taxRate)
            );
            $result = $this->getPx()->AddSingleOrderLine2($params);
            if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                $this->set_purchase_processed_by_purchid(1);
                $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
                return;
            }

            $i++;
        }

        // Add Shipping Line
        if (wpsc_cart_has_shipping()) {
            $shippingTaxRate = 0;
            $shippingTaxPrice = 0;
            $shippingPriceWithTax = $wpsc_cart->calculate_total_shipping();

            // It seems here shipping is not taxed.
            // But we can calc tax price using global settings :-)
            if ($wpec_taxes->wpec_taxes->wpec_taxes_get_enabled()) {
                if ($wpec_taxes->wpec_taxes_isincluded()) {
                    $shippingTaxRate = $wpsc_cart->tax_percentage;
                    $shippingPriceWithTax = $wpsc_cart->calculate_total_shipping();
                    $shippingTaxPrice = -1 * (($shippingPriceWithTax / (1 + ($shippingTaxRate / 100))) - $shippingPriceWithTax);
                } else {
                    $shippingPrice = $wpsc_cart->calculate_total_shipping();
                    $shippingTaxRate = $wpsc_cart->tax_percentage;
                    $shippingTaxPrice = $shippingPrice / 100 * $shippingTaxRate;
                    $shippingPriceWithTax = $shippingPrice + $shippingTaxPrice;
                }
            }

            $params = array(
                'accountNumber' => '',
                'orderRef' => $orderRef,
                'itemNumber' => $i,
                'itemDescription1' => $wpsc_cart->selected_shipping_option,
                'itemDescription2' => '',
                'itemDescription3' => '',
                'itemDescription4' => '',
                'itemDescription5' => '',
                'quantity' => 1,
                'amount' => (int)(100 * $shippingPriceWithTax), //must include tax
                'vatPrice' => (int)(100 * $shippingTaxPrice),
                'vatPercent' => (int)(100 * $shippingTaxRate)
            );
            $result = $this->getPx()->AddSingleOrderLine2($params);
            if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                $this->set_purchase_processed_by_purchid(1);
                $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
                return;
            }

            $i++;
        }

        // Add Discount Line
        if ($this->cart_data['has_discounts'] > 0) {
            $params = array(
                'accountNumber' => '',
                'orderRef' => $orderRef,
                'itemNumber' => $i,
                'itemDescription1' => __('Discount', 'wpsc'),
                'itemDescription2' => '',
                'itemDescription3' => '',
                'itemDescription4' => '',
                'itemDescription5' => '',
                'quantity' => 1,
                'amount' => (int)(-100 * abs($this->cart_data['cart_discount_value'])), //must include tax
                'vatPrice' => 0,
                'vatPercent' => 0
            );
            $result = $this->getPx()->AddSingleOrderLine2($params);
            if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
                $this->set_purchase_processed_by_purchid(1);
                $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
                return;
            }

            $i++;
        }

        // Add Order Address
        // Call PxOrder.AddOrderAddress2
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef,
            'billingFirstName' => $this->cart_data['billing_address']['first_name'],
            'billingLastName' => $this->cart_data['billing_address']['last_name'],
            'billingAddress1' => $this->cart_data['billing_address']['address'],
            'billingAddress2' => '',
            'billingAddress3' => '',
            'billingPostNumber' => (string)$this->cart_data['billing_address']['post_code'],
            'billingCity' => (string)$this->cart_data['billing_address']['city'],
            'billingState' => (string)$this->cart_data['billing_address']['state'],
            'billingCountry' => wpsc_get_country($this->cart_data['billing_address']['country']),
            'billingCountryCode' => $this->cart_data['billing_address']['country'],
            'billingEmail' => (string)$this->cart_data['email_address'],
            'billingPhone' => (string)$this->cart_data['billing_address']['phone'],
            'billingGsm' => '',
        );

        $shipping_params = array(
            'deliveryFirstName' => '',
            'deliveryLastName' => '',
            'deliveryAddress1' => '',
            'deliveryAddress2' => '',
            'deliveryAddress3' => '',
            'deliveryPostNumber' => '',
            'deliveryCity' => '',
            'deliveryState' => '',
            'deliveryCountry' => '',
            'deliveryCountryCode' => '',
            'deliveryEmail' => '',
            'deliveryPhone' => '',
            'deliveryGsm' => '',
        );

        if (wpsc_cart_has_shipping()) {
            $shipping_params = array(
                'deliveryFirstName' => $this->cart_data['shipping_address']['first_name'],
                'deliveryLastName' => $this->cart_data['shipping_address']['last_name'],
                'deliveryAddress1' => $this->cart_data['shipping_address']['address'],
                'deliveryAddress2' => '',
                'deliveryAddress3' => '',
                'deliveryPostNumber' => (string)$this->cart_data['shipping_address']['post_code'],
                'deliveryCity' => (string)$this->cart_data['shipping_address']['city'],
                'deliveryState' => (string)$this->cart_data['shipping_address']['state'],
                'deliveryCountry' => wpsc_get_country($this->cart_data['shipping_address']['country']),
                'deliveryCountryCode' => $this->cart_data['shipping_address']['country'],
                'deliveryEmail' => (string)$this->cart_data['email_address'],
                'deliveryPhone' => (string)$this->cart_data['billing_address']['phone'],
                'deliveryGsm' => '',
            );
        }

        $params += $shipping_params;

        $result = $this->getPx()->AddOrderAddress2($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
            return;
        }

        // Call PxOrder.PrepareSaleDD2
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef,
            'userType' => 0, // Anonymous purchase
            'userRef' => '',
            'bankName' => $bank_id
        );
        $result = $this->getPx()->PrepareSaleDD2($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
            return;
        }

        wp_redirect($result['redirectUrl']);
        exit();
    }

    /**
     * Parse_gateway_notification method, receives data from the payment gateway
     * @return bool|void
     */
    public function parse_gateway_notification()
    {
        global $wpdb;

        // Check OrderRef from PayEx
        if (!isset($_GET['orderRef'])) {
            $this->set_error_message(__('Can\' t to get order reference. Transaction Failure.', 'wpsc'));
            $this->return_to_checkout();
            return;
        }

        // Get Session Id
        $this->session_id = wpsc_get_customer_meta('payex_sessionid');
        if (!$this->session_id) {
            $this->set_error_message(__('Can\' t to get session id.', 'wpsc'));
            $this->return_to_checkout();
            return;
        }

        // Get Order Id
        $purchase_log_sql = $wpdb->prepare("SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `sessionid`= %s LIMIT 1", $this->session_id);
        $purchase_log = $wpdb->get_row($purchase_log_sql, ARRAY_A);
        $order_id = $purchase_log['id'];

        // Init PayEx
        $this->getPx()->setEnvironment(get_option('bankdebit_account_number'), get_option('bankdebit_encryption_key'), (get_option('bankdebit_mode') === 'TEST'));

        // Call PxOrder.Complete
        $params = array(
            'accountNumber' => '',
            'orderRef' => $_GET['orderRef']
        );
        $result = $this->getPx()->Complete($params);
        if ($result['errorCodeSimple'] !== 'OK') {
            $error_code = $result['transactionErrorCode'];
            $error_description = $result['transactionThirdPartyError'];
            if (empty($error_code) && empty($error_description)) {
                $error_code = $result['code'];
                $error_description = $result['description'];
            }
            $message = $error_code . ' (' . $error_description . ')';

            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $message));
            $this->return_to_checkout();
            return;
        }

        if (!isset($result['transactionNumber'])) {
            $result['transactionNumber'] = '';
        }

        // Save Transaction
        $this->addTransaction($order_id, $result['transactionNumber'], $result['transactionStatus'], $result, isset($result['date']) ? strtotime($result['date']) : time());

        /* Transaction statuses:
        0=Sale, 1=Initialize, 2=Credit, 3=Authorize, 4=Cancel, 5=Failure, 6=Capture */
        switch ((int)$result['transactionStatus']) {
            case 0:
            case 6:
                wpsc_update_purchase_log_details(
                    $this->session_id,
                    array(
                        'processed' => WPSC_Purchase_Log::ACCEPTED_PAYMENT,
                        'date' => time(),
                        'transactid' => $result['transactionNumber'],
                        'notes' => sprintf(__('Transaction captured. Transaction Id: %s', 'wpsc'), $result['transactionNumber'])
                    ),
                    'sessionid'
                );
                transaction_results($this->session_id, false, $result['transactionNumber']);

                // Redirect to Success Page
                $separator = (get_option('permalink_structure') !== '') ? '?' : '&';
                $returnURL = get_option('transact_url') . $separator . 'sessionid=' . $this->session_id . '&gateway=' . get_class($this);
                wp_redirect($returnURL);
                break;
            case 1:
                wpsc_update_purchase_log_details(
                    $this->session_id,
                    array(
                        'processed' => WPSC_Purchase_Log::ORDER_RECEIVED,
                        'date' => time(),
                        'transactid' => $result['transactionNumber'],
                        'notes' => sprintf(__('Transaction is pending. Transaction Id: %s', 'wpsc'), $result['transactionNumber'])
                    ),
                    'sessionid'
                );

                transaction_results($this->session_id, false, $result['transactionNumber']);

                // Redirect to Success Page
                $separator = (get_option('permalink_structure') !== '') ? '?' : '&';
                $returnURL = get_option('transact_url') . $separator . 'sessionid=' . $this->session_id . '&gateway=' . get_class($this);
                wp_redirect($returnURL);
                break;
            case 3:
                wpsc_update_purchase_log_details(
                    $this->session_id,
                    array(
                        'processed' => WPSC_Purchase_Log::ORDER_RECEIVED,
                        'date' => time(),
                        'transactid' => $result['transactionNumber'],
                        'notes' => sprintf(__('Transaction authorized. Transaction Id: %s', 'wpsc'), $result['transactionNumber'])
                    ),
                    'sessionid'
                );

                transaction_results($this->session_id, false, $result['transactionNumber']);

                // Redirect to Success Page
                $separator = (get_option('permalink_structure') !== '') ? '?' : '&';
                $returnURL = get_option('transact_url') . $separator . 'sessionid=' . $this->session_id . '&gateway=' . get_class($this);
                wp_redirect($returnURL);
                break;
            case 4:
                // Cancel
                wpsc_update_purchase_log_details(
                    $this->session_id,
                    array(
                        'processed' => WPSC_Purchase_Log::PAYMENT_DECLINED,
                        'date' => time(),
                        'transactid' => $result['transactionNumber'],
                        'notes' => sprintf(__('Transaction canceled. Transaction Id: %s', 'wpsc'), $result['transactionNumber'])
                    ),
                    'sessionid'
                );

                $this->set_error_message(__('Your order has been canceled.', 'wpsc'));
                $this->return_to_checkout();
                break;
            case 5:
            default:
                // Failed
                $error_code = $result['transactionErrorCode'];
                $error_description = $result['transactionThirdPartyError'];
                if (empty($error_code) && empty($error_description)) {
                    $error_code = $result['code'];
                    $error_description = $result['description'];
                }

                $message = sprintf(__('Payment declined. Error Code: %s', 'wpsc'), $error_code . ' (' . $error_description . ')');
                wpsc_update_purchase_log_details(
                    $this->session_id,
                    array(
                        'processed' => WPSC_Purchase_Log::PAYMENT_DECLINED,
                        'date' => time(),
                        'transactid' => $result['transactionNumber'],
                        'notes' => $message
                    ),
                    'sessionid'
                );
                $this->set_error_message($message);
                $this->return_to_checkout();
                break;
        }
    }

    /**
     * Get PayEx Handler
     * @return Px
     */
    public function getPx()
    {
        if (!$this->_px) {
            if (!class_exists('Px')) {
                require_once dirname(__FILE__) . '/Px/Px.php';
            }

            $this->_px = new Px();
        }

        return $this->_px;
    }

    /**
     * Save Transaction in PayEx Table
     * @param $order_id
     * @param $transaction_id
     * @param $transaction_status
     * @param $transaction_data
     * @param null $date
     */
    public function addTransaction($order_id, $transaction_id, $transaction_status, $transaction_data, $date = null)
    {
        global $wpdb;

        if (is_null($date)) {
            $date = time();
        }

        return $wpdb->insert(
            $wpdb->prefix . 'wpsc_payex_transactions',
            array(
                'order_id' => $order_id,
                'transaction_id' => $transaction_id,
                'transaction_status' => $transaction_status,
                'transaction_data' => serialize($transaction_data),
                'date' => date('Y-m-d H:i:s', $date),
            ),
            array(
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            )
        );
    }

    /**
     * Set Transaction as Captured
     * @param $transaction_id
     * @return bool
     */
    public function setAsCaptured($transaction_id)
    {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . 'wpsc_payex_transactions',
            array(
                'is_captured' => '1',
            ),
            array('transaction_id' => $transaction_id),
            array(
                '%d',
            ),
            array('%d')
        );
    }

    /**
     * Set Transaction as Canceled
     * @param $transaction_id
     * @return bool
     */
    public function setAsCanceled($transaction_id)
    {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . 'wpsc_payex_transactions',
            array(
                'is_canceled' => '1',
            ),
            array('transaction_id' => $transaction_id),
            array(
                '%d',
            ),
            array('%d')
        );
    }

    /**
     * Set Transaction as Refunded
     * @param $transaction_id
     * @param $total_refunded
     * @return bool
     */
    public function setAsRefunded($transaction_id, $total_refunded)
    {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . 'wpsc_payex_transactions',
            array(
                'is_refunded' => '1',
                'total_refunded' => $total_refunded,
            ),
            array('transaction_id' => $transaction_id),
            array(
                '%d',
                '%d',
            ),
            array('%d')
        );
    }

    /**
     * Get Available Banks
     * @return array
     */
    public static function getAvailableBanks()
    {
        return array(
            'NB' => 'Nordea Bank',
            'FSPA' => 'Swedbank',
            'SEB' => 'Svenska Enskilda Bank',
            'SHB' => 'Handelsbanken',
            'NB:DK' => 'Nordea Bank DK',
            'DDB' => 'Den Danske Bank',
            'BAX' => 'BankAxess',
            'SAMPO' => 'Sampo',
            'AKTIA' => 'Aktia, Säästöpankki',
            'OP' => 'Osuuspanki, Pohjola, Oko',
            'NB:FI' => 'Nordea Bank Finland',
            'SHB:FI' => 'SHB:FI',
            'SPANKKI' => 'SPANKKI',
            'TAPIOLA' => 'TAPIOLA',
            'AALAND' => 'Ålandsbanken',
        );
    }
}

// Add Bank Selection to Checkout
if (in_array('wpsc_merchant_bankdebit', (array)get_option('custom_gateway_options'))) {
    $output = "
	    <tr>
	        <br><br>
		    <td class='wpsc_CC_details'>" . __('Select bank:', 'wpsc') . "  *</td>
		    <td>
                <select name='bank_id' id='bank_id'>
        ";

    $banks = is_array(get_option('bankdebit_banks')) ? get_option('bankdebit_banks') : array();
    $available_banks = wpsc_merchant_bankdebit::getAvailableBanks();
    foreach ($banks as $_key => $bank_id) {
        $output .= "<option value='{$bank_id}'>{$available_banks[$bank_id]}</option>";
    }

    $output .= "
                </select>
		    </td>
	    </tr>
    ";
    $gateway_checkout_form_fields[$nzshpcrt_gateways[$num]['internalname']] = $output;
}
