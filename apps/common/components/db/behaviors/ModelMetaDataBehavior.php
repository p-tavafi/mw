<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * ModelMetaDataBehavior
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.0
 */
 
class ModelMetaDataBehavior extends CActiveRecordBehavior
{
    private $_modelMetaData;
    
    /**
     * ModelMetaDataBehavior::getModelMetaData()
     * 
     * @return CMap
     */
    public function getModelMetaData()
    {
        if(empty($this->_modelMetaData) || !($this->_modelMetaData instanceof CMap)) {
            $this->_modelMetaData = new CMap();
        }
        
        if ($this->owner instanceof ActiveRecord && $this->owner->hasAttribute('meta_data') && !empty($this->owner->meta_data) && $this->_modelMetaData->getCount() == 0) {
            $this->_modelMetaData->mergeWith((array)(@unserialize($this->owner->meta_data)));    
        }
        
        return $this->_modelMetaData;
    }
    
    /**
     * ModelMetaDataBehavior::setModelMetaData()
     * 
     * @param string $key
     * @param mixed $value
     * @return ModelMetaDataBehavior
     */
    public function setModelMetaData($key, $value)
    {
        $this->getModelMetaData()->add($key, $value);
        return $this;
    }

    /**
     * ModelMetaDataBehavior::beforeSave()
     * 
     * @param mixed $event
     * @return
     */
    public function beforeSave($event)
    {
        if ($this->owner instanceof ActiveRecord && $this->owner->hasAttribute('meta_data')) {
            $this->owner->setAttribute('meta_data', @serialize($this->getModelMetaData()->toArray()));    
        }
    }


}