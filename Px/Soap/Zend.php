<?php
/**
 * PayEx Library: Soap Adapter: Zend_Soap_Client
 * Created by AAIT Team.
 */
class Px_Soap_Zend extends Px_Soap_Abstract
{
    /**
     * Get Adapter Instance
     * @static
     * @return object
     */
    static public function getAdapter()
    {
        // Get Object using Reflection
        $arg_list = func_get_args();
        $rc = new ReflectionClass('Zend_Soap_Client');
        return $rc->newInstanceArgs($arg_list);
    }
}
