<?php
/**
 * AAIT PayEx Factoring Merchant Gateway for WP eCommerce
 * Author: AAIT Team
 * Author URI: http://aait.se/
 */

$nzshpcrt_gateways[$num] = array(
    'name' => __('Payex Factoring', 'wpsc'),
    'api_version' => 2.0,
    'image' => plugins_url(basename(realpath(dirname(__FILE__) . '/..'))) . '/' . basename(dirname(__FILE__)) . '/images/factoring.png',
    'class_name' => 'wpsc_merchant_factoring',
    'has_recurring_billing' => false,
    'wp_admin_cannot_cancel' => false,
    'display_name' => __('Payex Factoring', 'wpsc'),
    'requirements' => array(
        /// so that you can restrict merchant modules to PHP 5, if you use PHP 5 features
        'php_version' => 5.3,
        /// for modules that may not be present, like curl
        'extra_modules' => array()
    ),

    // this may be legacy, not yet decided
    'internalname' => 'wpsc_merchant_factoring',

    // All array members below here are legacy, and use the code in paypal_multiple.php
    'form' => array('wpsc_merchant_factoring', 'form_settings'),
    'submit_function' => array('wpsc_merchant_factoring', 'form_settings_submit'),
    'payment_type' => 'payex',
    'supported_currencies' => array(
        'currency_list' => array('DKK', 'EUR', 'GBP', 'NOK', 'SEK', 'USD'),
        'option_name' => 'payex_currency'
    )
);

add_action('init', array('wpsc_merchant_factoring', 'action_init'));

// Add compatible mode
require_once dirname(__FILE__) . '/Px/wpecommerce.compat.php';
if (!function_exists('_wpsc_action_merchant_v2_submit_gateway_options')) {
    $nzshpcrt_gateways[$num]['form'] = 'factoring_form_settings';
    $nzshpcrt_gateways[$num]['submit_function'] = 'factoring_form_settings_submit';

    function factoring_form_settings()
    {
        return call_user_func(array('wpsc_merchant_factoring', 'form_settings'));
    }

    function factoring_form_settings_submit()
    {
        return call_user_func(array('wpsc_merchant_factoring', 'form_settings_submit'));
    }
}

class wpsc_merchant_factoring extends wpsc_merchant
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

        $this->name = __('Payex Factoring', 'wpsc');
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
        $this->getPx()->setEnvironment(get_option('factoring_account_number'), get_option('factoring_encryption_key'), (get_option('factoring_mode') === 'TEST'));
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
        $output = "
        <tr>
          <td>" . __('Account Number', 'wpsc') . "
          </td>
          <td>
				<input type='text' size='40' value='" . get_option('factoring_account_number') . "' name='factoring_account_number' />
          </td>
        </tr>
        <tr>
          <td>" . __('Encryption Key', 'wpsc') . "
          </td>
          <td>
				<input type='text' size='40' value='" . get_option('factoring_encryption_key') . "' name='factoring_encryption_key' />
          </td>
        </tr>
        <tr>
         <td>" . __('Mode', 'wpsc') . "
         </td>
         <td>
				<input " . ((get_option('factoring_mode') === 'TEST') ? "checked='checked'" : '') . " type='radio' name='factoring_mode' value='TEST' /> " . __('Test', 'wpsc') . "
				<input " . ((get_option('factoring_mode') === 'LIVE') ? "checked='checked'" : '') . " type='radio' name='factoring_mode' value='LIVE' /> " . __('Live', 'wpsc') . "
         </td>
        </tr>
        <tr>
         <td>" . __('Payment Type', 'wpsc') . "
         </td>
         <td>
				<input " . ((get_option('factoring_type') === 'SELECT') ? "checked='checked'" : '') . " type='radio' name='factoring_type' value='SELECT' /> " . __('User select', 'wpsc') . "
				<input " . ((get_option('factoring_type') === 'FACTORING') ? "checked='checked'" : '') . " type='radio' name='factoring_type' value='FACTORING' /> " . __('Invoice 2.0 (Factoring)', 'wpsc') . "
				<input " . ((get_option('factoring_type') === 'CREDITACCOUNT') ? "checked='checked'" : '') . " type='radio' name='factoring_type' value='CREDITACCOUNT' /> " . __('Part Payment', 'wpsc') . "
         </td>
        </tr>
        <tr>
         <td>" . __('Language', 'wpsc') . "
         </td>
         <td>
				<select name='factoring_language'>
                    <option " . ((get_option('factoring_language') === 'en-US') ? 'selected' : '') . " value='en-US'>" . __('English', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'sv-SE') ? 'selected' : '') . " value='sv-SE'>" . __('Swedish', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'nb-NO') ? 'selected' : '') . " value='nb-NO'>" . __('Norway', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'da-DK') ? 'selected' : '') . " value='da-DK'>" . __('Danish', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'es-ES') ? 'selected' : '') . " value='es-ES'>" . __('Spanish', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'de-DE') ? 'selected' : '') . " value='de-DE'>" . __('German', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'fi-FI') ? 'selected' : '') . " value='fi-FI'>" . __('Finnish', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'fr-FR') ? 'selected' : '') . " value='fr-FR'>" . __('French', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'pl-PL') ? 'selected' : '') . " value='pl-PL'>" . __('Polish', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'cs-CZ') ? 'selected' : '') . " value='cs-CZ'>" . __('Czech', 'wpsc') . "</option>
                    <option " . ((get_option('factoring_language') === 'hu-HU') ? 'selected' : '') . " value='hu-HU'>" . __('Hungarian', 'wpsc') . "</option>
				</select>
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
            'factoring_account_number', 'factoring_encryption_key', 'factoring_mode', 'factoring_type', 'factoring_language'
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_option($field, sanitize_user($_POST[$field]));
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
        if (empty($_POST['social-security-number'])) {
            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(__('Please enter your Social Security Number and confirm your order.', 'wpsc'));
            return;
        }

        // Call PxVerification.GetConsumerLegalAddress
        $params = array(
            'accountNumber' => '',
            'countryCode' => $this->cart_data['billing_address']['country'], // Supported only "SE"
            'socialSecurityNumber' => $_POST['social-security-number']
        );
        $result = $this->getPx()->GetConsumerLegalAddress($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            if (preg_match('/\bInvalid parameter:SocialSecurityNumber\b/i', $result['description'])) {
                $this->set_purchase_processed_by_purchid(1);
                $this->set_error_message(__('Invalid Social Security Number', 'wpsc'));
                return;
            }

            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('Error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
            return;
        }

        $ssn = $_POST['social-security-number'];

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

        // Selected Payment Mode
        $view = get_option('factoring_type');
        if ($view === 'SELECT') {
            $view = $_POST['factoring-menu'];
        }

        // Set Order Status "Incomplete"
        $this->set_purchase_processed_by_purchid(2);

        // Call PxOrder.Initialize8
        $params = array(
            'accountNumber' => '',
            'purchaseOperation' => 'AUTHORIZATION',
            'price' => round($this->cart_data['total_price'] * 100),
            'priceArgList' => '',
            'currency' => $this->cart_data['store_currency'],
            'vat' => 0,
            'orderID' => $order_id,
            'productNumber' => $customer_id, // Customer Id
            'description' => get_bloginfo('name'),
            'clientIPAddress' => $_SERVER['REMOTE_ADDR'],
            'clientIdentifier' => '',
            'additionalValues' => '',
            'externalID' => '',
            'returnUrl' => 'http://localhost.no/return',
            'view' => $view,
            'agreementRef' => '',
            'cancelUrl' => 'http://localhost.no/cancel',
            'clientLanguage' => get_option('factoring_language')
        );
        $result = $this->getPx()->Initialize8($params);
        if ($result['code'] !== 'OK' || $result['description'] !== 'OK' || $result['errorCode'] !== 'OK') {
            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')'));
            return;
        }

        $orderRef = $result['orderRef'];

        // Try to guess Phone number
        if (empty($this->cart_data['billing_address']['phone']) && isset($_POST['collected_data'])) {
            foreach ($_POST['collected_data'] as $field) {
                if (is_string($field) && mb_substr($field, 0, 1) === '+') {
                    $this->cart_data['billing_address']['phone'] = $field;
                }
            }
        }

        // Call PxOrder.PurchaseInvoiceSale / PxOrder.PurchasePartPaymentSale
        $params = array(
            'accountNumber' => '',
            'orderRef' => $orderRef,
            'socialSecurityNumber' => $ssn,
            'legalFirstName' => $this->cart_data['billing_address']['first_name'],
            'legalLastName' => $this->cart_data['billing_address']['last_name'],
            'legalStreetAddress' => $this->cart_data['billing_address']['address'],
            'legalCoAddress' => '',
            'legalPostNumber' => $this->cart_data['billing_address']['post_code'],
            'legalCity' => $this->cart_data['billing_address']['city'],
            'legalCountryCode' => $this->cart_data['billing_address']['country'],
            'email' => $this->cart_data['email_address'],
            'msisdn' => (mb_substr($this->cart_data['billing_address']['phone'], 0, 1) === '+') ? $this->cart_data['billing_address']['phone'] : '+' . $this->cart_data['billing_address']['phone'],
            'ipAddress' => $_SERVER['REMOTE_ADDR'],
        );

        if ($view === 'FACTORING') {
            $result = $this->getPx()->PurchaseInvoiceSale($params);
        } else {
            $result = $this->getPx()->PurchasePartPaymentSale($params);
        }

        if ($result['code'] !== 'OK' || $result['description'] !== 'OK') {
            if (preg_match('/\bInvalid parameter:msisdn\b/i', $result['description'])) {
                $this->set_purchase_processed_by_purchid(1);
                $this->set_error_message(__('Phone number not recognized, please use +countrycodenumber  ex. +467xxxxxxxxxx', 'wpsc'), $result['errorCode'] . ' (' . $result['description'] . ')');
                return;
            }

            $error_code = $result['transactionErrorCode'];
            $error_description = $result['transactionThirdPartyError'];
            if (empty($error_code) && empty($error_description)) {
                $error_code = $result['code'];
                $error_description = $result['description'];
            }
            $message = $error_code . ' (' . $error_description . ')';

            $this->set_purchase_processed_by_purchid(1);
            $this->set_error_message(sprintf(__('PayEx error: %s', 'wpsc'), $message));
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
                exit();
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
                exit();
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
                exit();
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
     * Generate Invoice Print XML
     * @return mixed
     */
    protected function getInvoiceExtraPrintBlocksXML()
    {
        global $wpsc_cart;

        $dom = new DOMDocument('1.0', 'utf-8');
        $OnlineInvoice = $dom->createElement('OnlineInvoice');
        $dom->appendChild($OnlineInvoice);
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $OnlineInvoice->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsd', 'http://www.w3.org/2001/XMLSchema');

        $OrderLines = $dom->createElement('OrderLines');
        $OnlineInvoice->appendChild($OrderLines);

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

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $cart_item->product_name));
            $OrderLine->appendChild($dom->createElement('Qty', $cart_item->quantity));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $priceWithTax / $cart_item->quantity));
            $OrderLine->appendChild($dom->createElement('VatRate', $taxRate));
            $OrderLine->appendChild($dom->createElement('VatAmount', $taxPrice));
            $OrderLine->appendChild($dom->createElement('Amount', $priceWithTax));
            $OrderLines->appendChild($OrderLine);
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

            $OrderLine = $dom->createElement('OrderLine');
            $OrderLine->appendChild($dom->createElement('Product', $wpsc_cart->selected_shipping_option));
            $OrderLine->appendChild($dom->createElement('Qty', 1));
            $OrderLine->appendChild($dom->createElement('UnitPrice', $shippingPriceWithTax));
            $OrderLine->appendChild($dom->createElement('VatRate', $shippingTaxRate));
            $OrderLine->appendChild($dom->createElement('VatAmount', $shippingTaxPrice));
            $OrderLine->appendChild($dom->createElement('Amount', $shippingPriceWithTax));
            $OrderLines->appendChild($OrderLine);
        }

        // @todo Add Discount Line

        return str_replace("\n", '', $dom->saveXML());
    }
}

// Add Social Security Field to Checkout
if (in_array('wpsc_merchant_factoring', (array)get_option('custom_gateway_options'))) {
    $output = '';

    if (get_option('factoring_type') === 'SELECT') {
        $output .= "
    	    <tr>
    	        <br><br>
    		    <td class='wpsc_CC_details'>" . __('Please select payment method', 'wpsc') . "  *</td>
    		    <td>
     			    <select name='factoring-menu' id='factoring-menu' class='required-entry'>
    			        <option selected value='FACTORING'>" . __('Invoice 2.0 (Factoring)', 'wpsc') . "</option>
    			        <option value='CREDITACCOUNT'>" . __('Part Payment', 'wpsc') . "</option>
    			    </select>
    		    </td>
    	    </tr>
    	    <br>
        ";
    }

    $output .= "
	    <tr>
	        <br><br>
		    <td class='wpsc_CC_details'>" . __('Social Security Number', 'wpsc') . "  *</td>
		    <td>
			    <input type='text' value='' name='social-security-number' />
		    </td>
	    </tr>
    ";
    $gateway_checkout_form_fields[$nzshpcrt_gateways[$num]['internalname']] = $output;
}
