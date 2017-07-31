<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * CampaignOptionShareReports
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.7.3
 */

class CampaignOptionShareReports extends CampaignOption
{
    /**
     * @inheritdoc
     */
    protected function afterConstruct()
    {
        $this->share_reports_password = StringHelper::random(12);
        parent::afterConstruct();
    }
    
    /**
     * @inheritdoc
     */
    protected function afterFind()
    {
        if (empty($this->share_reports_password)) {
            $this->share_reports_password = StringHelper::random(12);
        }
        parent::afterFind();
    }

    /**
	 * @return string the associated database table name
	 */
	public function tableName()
	{
		return '{{campaign_option}}';
	}

	/**
	 * @return array validation rules for model attributes.
	 */
	public function rules()
	{
		return array(
            array('share_reports_enabled, share_reports_password, share_reports_mask_email_addresses', 'required'),
        );
	}

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels()
    {
        $labels = array(
            'shareUrl' => Yii::t('campaigns', 'Share url'),
        );

        return CMap::mergeArray($labels, parent::attributeLabels());
    }

	/**
	 * Returns the static model of the specified AR class.
	 * Please note that you should have this exact method in all your CActiveRecord descendants!
	 * @param string $className active record class name.
	 * @return CampaignOption the static model class
	 */
	public static function model($className=__CLASS__)
	{
		return parent::model($className);
	}

    /**
     * @return mixed
     */
	public function getShareUrl()
    {
        return Yii::app()->apps->getAppUrl('frontend', 'campaigns/' . $this->campaign->campaign_uid . '/overview', true);
    }
}
