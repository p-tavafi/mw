<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * IpLocation
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.2
 */
 
/**
 * This is the model class for table "ip_location".
 *
 * The followings are the available columns in table 'ip_location':
 * @property string $location_id
 * @property string $ip_address
 * @property string $country_code
 * @property string $country_name
 * @property string $zone_name
 * @property string $city_name
 * @property string $latitude
 * @property string $longitude
 * @property string $date_added
 *
 * The followings are the available model relations:
 * @property CampaignTrackOpen[] $trackOpens
 * @property CampaignTrackUnsubscribe[] $trackUnsubscribes
 * @property CampaignTrackUrl[] $trackUrls
 */
class IpLocation extends ActiveRecord
{
    public $counter;
    
	/**
	 * @return string the associated database table name
	 */
	public function tableName()
	{
		return '{{ip_location}}';
	}

	/**
	 * @return array validation rules for model attributes.
	 */
	public function rules()
	{
		// NOTE: you should only define rules for those attributes that
		// will receive user inputs.
		return array(
			array('ip_address, country_code, country_name, latitude, longitude', 'required'),
			array('ip_address', 'length', 'max'=>15),
			array('country_code', 'length', 'max'=>3),
			array('country_name, zone_name, city_name', 'length', 'max'=>150),
			array('latitude', 'length', 'max'=>10),
			array('longitude', 'length', 'max'=>11),
		);
	}

	/**
	 * @return array relational rules.
	 */
	public function relations()
	{
		// NOTE: you may need to adjust the relation name and the related
		// class name for the relations automatically generated below.
		return array(
			'trackOpens' => array(self::HAS_MANY, 'CampaignTrackOpen', 'location_id'),
            'trackUnsubscribes' => array(self::HAS_MANY, 'CampaignTrackUnsubscribe', 'location_id'),
            'trackUrls' => array(self::HAS_MANY, 'CampaignTrackUrl', 'location_id'),
		);
	}

	/**
	 * @return array customized attribute labels (name=>label)
	 */
	public function attributeLabels()
	{
		$labels = array(
			'location_id'    => Yii::t('ip_location', 'Location'),
			'ip_address'     => Yii::t('ip_location', 'Ip address'),
			'country_code'   => Yii::t('ip_location', 'Country code'),
			'country_name'   => Yii::t('ip_location', 'Country name'),
			'zone_name'      => Yii::t('ip_location', 'Zone name'),
			'city_name'      => Yii::t('ip_location', 'City name'),
			'latitude'       => Yii::t('ip_location', 'Latitude'),
			'longitude'      => Yii::t('ip_location', 'Longitude'),
		);
        
        return CMap::mergeArray($labels, parent::attributeLabels());
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 *
	 * Typical usecase:
	 * - Initialize the model fields with values from filter form.
	 * - Execute this method to get CActiveDataProvider instance which will filter
	 * models according to data in model fields.
	 * - Pass data provider to CGridView, CListView or any similar widget.
	 *
	 * @return CActiveDataProvider the data provider that can return the models
	 * based on the search/filter conditions.
	 */
	public function search()
	{
		$criteria=new CDbCriteria;

		return new CActiveDataProvider($this, array(
			'criteria' => $criteria,
		));
	}

	/**
	 * Returns the static model of the specified AR class.
	 * Please note that you should have this exact method in all your CActiveRecord descendants!
	 * @param string $className active record class name.
	 * @return IpLocation the static model class
	 */
	public static function model($className=__CLASS__)
	{
		return parent::model($className);
	}
    
    public function getLocation($separator = ', ', array $attributes = array())
    {
        if (empty($attributes)) {
            $attributes = array('country_name', 'zone_name', 'city_name');
        }
        
        $location = array();
        foreach ($attributes as $attribute) {
            if (!empty($this->$attribute)) {
                $location[] = $this->$attribute;
            }
        }
        
        return implode($separator, $location);
    }
}
