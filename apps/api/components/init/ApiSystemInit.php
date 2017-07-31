<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * ApiSystemInit
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.0
 */
 
class ApiSystemInit extends CApplicationComponent 
{
    protected $_hasRanOnBeginRequest = false;
    protected $_hasRanOnEndRequest = false;
    
    public function init()
    {
        parent::init();
        
        // hook into events and add our methods.
        Yii::app()->attachEventHandler('onBeginRequest', array($this, 'runOnBeginRequest'));
        Yii::app()->attachEventHandler('onEndRequest', array($this, 'runOnEndRequest'));
    }
    
    public function runOnBeginRequest(CEvent $event)
    {
        if ($this->_hasRanOnBeginRequest) {
            return;
        }
        
        // no cookies for this app.
        ini_set('session.use_cookies', '0');
        
        // and mark the event as completed.
        $this->_hasRanOnBeginRequest = true;
    }
    
    public function runOnEndRequest(CEvent $event)
    {
        if ($this->_hasRanOnEndRequest) {
            return;
        }
        
        // and mark the event as completed.
        $this->_hasRanOnEndRequest = true;
    }
}