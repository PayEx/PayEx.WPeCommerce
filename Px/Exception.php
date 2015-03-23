<?php
/**
 * PayEx Library: Exception
 * Created by AAIT Team.
 */
class Px_Exception extends Exception
{
    public function __construct($message, $code = 0)
    {
        parent::__construct($message, $code);
    }
}
