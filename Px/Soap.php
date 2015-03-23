<?php
/**
 * PayEx Library: Soap Client
 * Created by AAIT Team.
 */
require_once realpath(dirname(__FILE__) . '/Soap/Abstract.php');
require_once realpath(dirname(__FILE__) . '/Exception.php');

class Px_Soap
{
    protected static $_adapter = null;
    protected static $_adapter_name = 'Native';

    /**
     * Get Soap Client
     * @static
     * @return object
     * @throws Exception
     */
    static public function getClient()
    {
        // Get Function Arguments
        $arg_list = func_get_args();

        // Get Adapter
        self::$_adapter_name = self::getAdapter();
        $adapter_file = realpath(dirname(__FILE__) . '/Soap/' . self::$_adapter_name . '.php');
        $adapter_class = 'Px_Soap_' . self::$_adapter_name;

        // Get Adapter Instance
        if (file_exists($adapter_file)) {
            require_once $adapter_file;
            return call_user_func_array($adapter_class . '::getAdapter', $arg_list);
        }

        throw new Px_Exception('Soap Adapter not available.');
    }

    /**
     * Detect Default Adapter
     * @static
     * @return string
     */
    static protected function getAdapter()
    {
        //return 'NuSoap';
        // Check NuSoap
        if (!extension_loaded('soap')) {
            return 'NuSoap';
        }

        // Check Zend Framework
        if (class_exists('Zend_Soap_Client')) {
            return 'Zend';
        }

        // Use Default Soap
        return 'Native';
    }
}
