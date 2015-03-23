<?php

if (!defined('WPSC_CUSTOMER_COOKIE_PATH')) {
    define('WPSC_CUSTOMER_COOKIE_PATH', COOKIEPATH);
}

if (!defined('WPSC_CUSTOMER_DATA_EXPIRATION')) {
    define('WPSC_CUSTOMER_DATA_EXPIRATION', 48 * 3600);
}

if (!defined('WPSC_CUSTOMER_COOKIE')) {
    define('WPSC_CUSTOMER_COOKIE', 'wpsc_customer_cookie_' . COOKIEHASH);
}

if (!function_exists('_wpsc_set_customer_cookie')) {
    function _wpsc_set_customer_cookie($cookie, $expire)
    {
        $secure = is_ssl();
        setcookie(WPSC_CUSTOMER_COOKIE, $cookie, $expire, WPSC_CUSTOMER_COOKIE_PATH, COOKIE_DOMAIN, $secure, true);

        if ($expire < time())
            unset($_COOKIE[WPSC_CUSTOMER_COOKIE]);
        else
            $_COOKIE[WPSC_CUSTOMER_COOKIE] = $cookie;
    }
}

if (!function_exists('_wpsc_get_customer_meta_key')) {
    function _wpsc_get_customer_meta_key($key)
    {
        global $wpdb;

        $blog_prefix = is_multisite() ? $wpdb->get_blog_prefix() : '';
        return "{$blog_prefix}_wpsc_{$key}";
    }
}

if (!function_exists('_wpsc_create_customer_id_cookie')) {
    function _wpsc_create_customer_id_cookie($id, $fake_it = false)
    {

        $expire = time() + WPSC_CUSTOMER_DATA_EXPIRATION; // valid for 48 hours
        $data = $id . $expire;

        $user = get_user_by('id', $id);
        $pass_frag = substr($user->user_pass, 8, 4);

        $key = wp_hash($user->user_login . $pass_frag . '|' . $expire);

        $hash = hash_hmac('md5', $data, $key);
        $cookie = $id . '|' . $expire . '|' . $hash;

        // store ID, expire and hash to validate later
        if ($fake_it)
            $_COOKIE[WPSC_CUSTOMER_COOKIE] = $cookie;
        else
            _wpsc_set_customer_cookie($cookie, $expire);
    }
}

if (!function_exists('_wpsc_create_customer_id')) {
    function _wpsc_create_customer_id()
    {
        $role = get_role('wpsc_anonymous');

        if (!$role) {
            add_role('wpsc_anonymous', __('Anonymous', 'wpsc'));
        }

        $username = '_' . wp_generate_password(8, false, false);
        $password = wp_generate_password(12, false);

        // Prevent WP_Error: This email address is already registered.
        if (!defined('WP_IMPORTING')) {
            define('WP_IMPORTING', true);
        }

        $id = wp_create_user($username, $password);
        $user = new WP_User($id);
        $user->set_role('wpsc_anonymous');

        update_user_meta($id, '_wpsc_last_active', time());

        _wpsc_create_customer_id_cookie($id);

        return $id;
    }
}

if (!function_exists('wpsc_get_current_customer_id')) {
    function wpsc_get_current_customer_id()
    {
        $id = apply_filters('wpsc_get_current_customer_id', null);

        if (!empty($id))
            return $id;

        // if the user is logged in we use the user id
        if (is_user_logged_in()) {
            return get_current_user_id();
        } elseif (isset($_COOKIE[WPSC_CUSTOMER_COOKIE])) {
            list($id, $expire, $hash) = explode('|', $_COOKIE[WPSC_CUSTOMER_COOKIE]);
            return $id;
        }

        return _wpsc_create_customer_id();
    }
}

if (!function_exists('wpsc_update_customer_meta')) {
    function wpsc_update_customer_meta($key, $value, $id = false)
    {
        if (!$id)
            $id = wpsc_get_current_customer_id();

        $result = apply_filters('wpsc_update_customer_meta', null, $key, $value, $id);

        if ($result)
            return $result;

        return update_user_meta($id, _wpsc_get_customer_meta_key($key), $value);
    }
}

if (!function_exists('wpsc_get_customer_meta')) {
    function wpsc_get_customer_meta($key = '', $id = false)
    {
        if (!$id)
            $id = wpsc_get_current_customer_id();

        $result = apply_filters('wpsc_get_customer_meta', null, $key, $id);

        if ($result)
            return $result;

        return get_user_meta($id, _wpsc_get_customer_meta_key($key), true);
    }
}

if (!function_exists('wpsc_delete_customer_meta')) {
    function wpsc_delete_customer_meta($key, $id = false)
    {
        if (!$id)
            $id = wpsc_get_current_customer_id();

        $result = apply_filters('wpsc_delete_customer_meta', null, $key, $id);

        if ($result)
            return $result;

        return delete_user_meta($id, _wpsc_get_customer_meta_key($key));
    }
}

if (!function_exists('wpsc_update_purchase_log_details')) {
    function wpsc_update_purchase_log_details($unique_id, $details, $by = 'id')
    {
        global $wpdb;

        $ret = array();
        foreach ($details as $key => $value) {
            $key = $wpdb->escape($key);
            $value = $wpdb->escape($value);
            $ret[] = "`$key` = '$value'";
        }


        $unique_id = $wpdb->escape($unique_id);
        $by = $wpdb->escape($by);

        return $wpdb->query("UPDATE `" . WPSC_TABLE_PURCHASE_LOGS . "` SET " . implode(', ', $ret) . " WHERE `$by` = $unique_id;");
    }
}


if (!class_exists('WPSC_Purchase_Log')) {
    class WPSC_Purchase_Log
    {
        const INCOMPLETE_SALE = 1;
        const ORDER_RECEIVED = 2;
        const ACCEPTED_PAYMENT = 3;
        const JOB_DISPATCHED = 4;
        const CLOSED_ORDER = 5;
        const PAYMENT_DECLINED = 6;
        const REFUNDED = 7;
        const REFUND_PENDING = 8;
    }
}