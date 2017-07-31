<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * Ip_location_servicesController
 * 
 * Handles the actions for ip location services related tasks
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.2
 */
 
class Ip_location_servicesController extends Controller
{

    /**
     * Display available services
     */
    public function actionIndex()
    {
        $request = Yii::app()->request;
        $model = new IpLocationServicesList();
        
        $this->setData(array(
            'pageMetaTitle'     => $this->data->pageMetaTitle . ' | ' . Yii::t('ip_location', 'Ip location services'), 
            'pageHeading'       => Yii::t('ip_location', 'Ip location services'),
            'pageBreadcrumbs'   => array(
                Yii::t('ip_location', 'Ip location services'),
            ),
        ));
        
        $this->render('index', compact('model'));
    }

}