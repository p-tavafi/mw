<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * WebUser
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.0
 */
 
class WebUser extends BaseWebUser
{
    private $_model;
    
    private $_id;
    
    public function setId($id)
    {
        $this->_id = $id;
        return $this;
    }
    
    public function getId()
    {
        return $this->_id;
    }
    
    public function setName($name)
    {
        return $this;
    }
    
    public function getName()
    {
        return null;
    }
    
    public function getIsGuest()
    {
        return $this->getId() === null;
    }
    
    public function setReturnUrl($value)
    {
        return $this;
    }
    
    public function getReturnUrl($defaultUrl=null)
    {
        return null;
    }
    
    public function setModel(Customer $model)
    {
        $this->_model = $model;
        return $this;
    }
    
    public function getModel()
    {
        if ($this->_model !== null) {
            return $this->_model;
        }
        return $this->_model = Customer::model()->findByPk((int)$this->getId());
    }
}