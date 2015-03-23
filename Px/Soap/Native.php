<?php
/**
 * PayEx Library: Soap Adapter: Native
 * Created by AAIT Team.
 */
class Px_Soap_Native extends Px_Soap_Abstract
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
        $rc = new ReflectionClass('SoapClient');
        return $rc->newInstanceArgs($arg_list);
    }
}
