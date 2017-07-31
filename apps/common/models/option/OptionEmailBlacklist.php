<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * OptionEmailBlacklist
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.2
 */
 
class OptionEmailBlacklist extends OptionBase
{
    // settings category
    protected $_categoryName = 'system.email_blacklist';
    
    public $local_check = 'yes';
    
    public $allow_new_records = 'yes';
    
    public $regular_expressions;

    public function rules()
    {
        $rules = array(
            array('local_check, allow_new_records', 'required'),
            array('local_check, allow_new_records', 'in', 'range' => array_keys($this->getCheckOptions())),
            array('regular_expressions', 'safe'),
        );
        
        return CMap::mergeArray($rules, parent::rules());    
    }
    
    public function attributeLabels()
    {
        $labels = array(
            'local_check'         => Yii::t('settings', 'Local checks'),
            'allow_new_records'   => Yii::t('settings', 'Allow adding new records'),
            'regular_expressions' => Yii::t('settings', 'Regular expressions'),
        );
        
        return CMap::mergeArray($labels, parent::attributeLabels());    
    }
    
    public function attributePlaceholders()
    {
        $placeholders = array(
            'local_check'         => '',
            'regular_expressions' => "/abuse@(.*)/i\n/spam@(.*)/i\n/(.*)@abc\.com/i",
        );
        
        return CMap::mergeArray($placeholders, parent::attributePlaceholders());
    }
    
    public function attributeHelpTexts()
    {
        $texts = array(
            'local_check'         => Yii::t('settings', 'Whether to check the email addresses against local database.'),
            'allow_new_records'   => Yii::t('settings', 'Whether to allow adding new records to the email blacklist'),
            'regular_expressions' => Yii::t('settings', 'List of regular expressions for blacklisting an email. Please use one expression per line and make sure it is correct.'),
        );
        
        return CMap::mergeArray($texts, parent::attributeHelpTexts());
    }
    
    public function getCheckOptions()
    {
        return $this->getYesNoOptions();
    }
}
