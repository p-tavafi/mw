<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * PaymentHandlerAbstract
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.4.4
 */
 
abstract class PaymentHandlerAbstract extends CApplicationComponent
{
    // the extension instance for easy access
    public $extension;
    
    // the controller calling the handler
    public $controller;

    abstract public function renderPaymentView();
    
    abstract public function processOrder();
}
