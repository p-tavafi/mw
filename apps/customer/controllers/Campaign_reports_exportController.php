<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * Campaign_reports_export
 *
 * Handles the actions for exporting campaign reports
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.2
 */

class Campaign_reports_exportController extends Controller
{
    /**
     * Default export limit
     */
    const DEFAULT_LIMIT = 300;
    
    /**
     * @var array
     */
    public $redirectRoute = array('campaigns/overview');

    /**
     * @var int
     */
    public $customerId = 0;
    
    /**
     * @inheritdoc
     */
    public function init()
    {
        set_time_limit(0);
        
        // 1.3.8.8
        ini_set('memory_limit', -1);
        
        // if not set from a child class, fallback on this customer
        if (empty($this->customerId)) {
            $this->customerId = (int)Yii::app()->customer->getId();
        }
        
        parent::init();
    }
    
    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionBasic($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $csvData = array();
        $csvData[] = array(Yii::t('campaign_reports', 'Processed'), $campaign->stats->getProcessedCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Sent with success'), $campaign->stats->getDeliverySuccessCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Sent success rate'), $campaign->stats->getDeliverySuccessRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Send error'), $campaign->stats->getDeliveryErrorCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Send error rate'), $campaign->stats->getDeliveryErrorRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Unique opens'), $campaign->stats->getUniqueOpensCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Unique open rate'), $campaign->stats->getUniqueOpensRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'All opens'), $campaign->stats->getOpensCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'All opens rate'), $campaign->stats->getOpensRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Bounced back'), $campaign->stats->getBouncesCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Bounce rate'), $campaign->stats->getBouncesRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Hard bounce'), $campaign->stats->getHardBouncesCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Hard bounce rate'), $campaign->stats->getHardBouncesRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Soft bounce'), $campaign->stats->getSoftBouncesCount(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Soft bounce rate'), $campaign->stats->getSoftBouncesRate(true) . '%');
        $csvData[] = array(Yii::t('campaign_reports', 'Unsubscribe'), $campaign->stats->getUnsubscribesCount(true));
        $csvData[] = array(Yii::t('campaign_reports', 'Unsubscribe rate'), $campaign->stats->getUnsubscribesRate(true) . '%');

        if ($campaign->option->url_tracking == CampaignOption::TEXT_YES) {
            $csvData[] = array(Yii::t('campaign_reports', 'Total urls for tracking'), $campaign->stats->getTrackingUrlsCount(true));
            $csvData[] = array(Yii::t('campaign_reports', 'Unique clicks'), $campaign->stats->getUniqueClicksCount(true));
            $csvData[] = array(Yii::t('campaign_reports', 'Unique clicks rate'), $campaign->stats->getUniqueClicksRate(true) . '%');
            $csvData[] = array(Yii::t('campaign_reports', 'All clicks'), $campaign->stats->getClicksCount(true));
            $csvData[] = array(Yii::t('campaign_reports', 'All clicks rate'), $campaign->stats->getClicksRate(true) . '%');
        }

        $fileName = 'basic-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        foreach ($csvData as $row) {
            fputcsv($fp, $row, ',', '"');
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionDelivery($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }

        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'sent-email-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Process status'),
            Yii::t('campaign_reports', 'Sent'),
            Yii::t('campaign_reports', 'Date added')
        );
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getDeliveryModels($campaign, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array(
                    $model->subscriber->displayEmail, 
                    ucfirst(Yii::t('app', $model->status)), 
                    ucfirst(Yii::t('app', $model->delivery_confirmed)), 
                    $model->dateAdded
                );
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getDeliveryModels($campaign, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getDeliveryModels(Campaign $campaign, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->select = 't.status, t.delivery_confirmed, t.date_added';
        $criteria->compare('t.campaign_id', (int)$campaign->campaign_id);
        $criteria->limit    = (int)$limit;
        $criteria->offset   = (int)$offset;
        $criteria->with = array(
            'subscriber' => array(
                'select'    => 'subscriber.email, subscriber.list_id',
                'together'  => true,
                'joinType'  => 'INNER JOIN',
            ),
        );
        $cdlModel = $campaign->getDeliveryLogsArchived() ? CampaignDeliveryLogArchive::model() : CampaignDeliveryLog::model();
        return $cdlModel->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionBounce($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'bounce-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Bounce type'),
            Yii::t('campaign_reports', 'Message'),
            Yii::t('campaign_reports', 'Date added')
        );
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getBounceModels($campaign, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array($model->subscriber->displayEmail, ucfirst(Yii::t('app', $model->bounce_type)), $model->message, $model->dateAdded);
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getBounceModels($campaign, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getBounceModels(Campaign $campaign, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->select = 't.bounce_type, t.message, t.date_added';
        $criteria->compare('t.campaign_id', (int)$campaign->campaign_id);
        $criteria->limit    = (int)$limit;
        $criteria->offset   = (int)$offset;
        $criteria->with = array(
            'subscriber' => array(
                'select'    => 'subscriber.email, subscriber.list_id',
                'together'  => true,
                'joinType'  => 'INNER JOIN',
            ),
        );
        return CampaignBounceLog::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionOpen($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }

        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'open-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Date added')
        );
        foreach (ListField::getAllByListId($campaign->list_id) as $field) {
            if ($field['tag'] == 'EMAIL') {
                continue;
            }
            $columns[] = $field['tag'];
        }
        
        fputcsv($fp, $columns, ',', '"');
        
        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getOpenModels($campaign, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array(
                    $model->subscriber->displayEmail, 
                    strip_tags($model->getIpWithLocationForGrid()), 
                    $model->user_agent, 
                    $model->dateAdded
                );
                foreach ($model->subscriber->getAllCustomFieldsWithValues() as $fieldTag => $fieldValue) {
                    if ($fieldTag == '[EMAIL]') {
                        continue;
                    }
                    $row[] = $fieldValue;
                }
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getOpenModels($campaign, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getOpenModels(Campaign $campaign, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->select = 't.location_id, t.ip_address, t.user_agent, t.date_added';
        $criteria->compare('t.campaign_id', (int)$campaign->campaign_id);
        $criteria->limit    = (int)$limit;
        $criteria->offset   = (int)$offset;
        $criteria->with = array(
            'subscriber' => array(
                'select'    => 'subscriber.subscriber_id, subscriber.list_id, subscriber.email',
                'together'  => true,
                'joinType'  => 'INNER JOIN',
            ),
        );
        return CampaignTrackOpen::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionOpen_unique($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'unique-open-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Open times'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Date added')
        );
        foreach (ListField::getAllByListId($campaign->list_id) as $field) {
            if ($field['tag'] == 'EMAIL') {
                continue;
            }
            $columns[] = $field['tag'];
        }
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getOpenUniqueModels($campaign, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array(
                    $model->subscriber->displayEmail, 
                    $model->counter, 
                    strip_tags($model->getIpWithLocationForGrid()), 
                    $model->user_agent, 
                    $model->dateAdded
                );
                foreach ($model->subscriber->getAllCustomFieldsWithValues() as $fieldTag => $fieldValue) {
                    if ($fieldTag == '[EMAIL]') {
                        continue;
                    }
                    $row[] = $fieldValue;
                }
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getOpenUniqueModels($campaign, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getOpenUniqueModels(Campaign $campaign, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->select = 't.location_id, t.ip_address, t.user_agent, t.date_added, COUNT(*) AS counter';
        $criteria->compare('campaign_id', (int)$campaign->campaign_id);
        $criteria->group = 't.subscriber_id';
        $criteria->order = 'counter DESC';
        $criteria->limit    = (int)$limit;
        $criteria->offset   = (int)$offset;
        $criteria->with = array(
            'subscriber' => array(
                'select'    => 'subscriber.subscriber_id, subscriber.list_id, subscriber.email',
                'together'  => true,
                'joinType'  => 'INNER JOIN',
            ),
        );
        return CampaignTrackOpen::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionUnsubscribe($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'unsubscribe-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Reason'),
            Yii::t('campaign_reports', 'Note'),
            Yii::t('campaign_reports', 'Date added')
        );
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getUnsubscribeModels($campaign, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array($model->subscriber->displayEmail, strip_tags($model->getIpWithLocationForGrid()), $model->user_agent, $model->reason, $model->note, $model->dateAdded);
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getUnsubscribeModels($campaign, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getUnsubscribeModels(Campaign $campaign, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->select = 't.location_id, t.ip_address, t.user_agent, t.reason, t.note, t.date_added';
        $criteria->compare('t.campaign_id', (int)$campaign->campaign_id);
        $criteria->limit    = (int)$limit;
        $criteria->offset   = (int)$offset;
        $criteria->with = array(
            'subscriber' => array(
                'select'    => 'subscriber.email, subscriber.list_id',
                'together'  => true,
                'joinType'  => 'INNER JOIN',
            ),
        );
        return CampaignTrackUnsubscribe::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @throws CHttpException
     */
    public function actionClick($campaign_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }

        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'click-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Url'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'Date added')
        );
        foreach (ListField::getAllByListId($campaign->list_id) as $field) {
            if ($field['tag'] == 'EMAIL') {
                continue;
            }
            $columns[] = $field['tag'];
        }
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getClickModels($campaign, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array(
                    $model->subscriber->displayEmail, 
                    $model->url->destination, 
                    $model->user_agent, 
                    $model->ip_address, 
                    $model->dateAdded
                );
                foreach ($model->subscriber->getAllCustomFieldsWithValues() as $fieldTag => $fieldValue) {
                    if ($fieldTag == '[EMAIL]') {
                        continue;
                    }
                    $row[] = $fieldValue;
                }
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getClickModels($campaign, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getClickModels(Campaign $campaign, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->compare('url.campaign_id', (int)$campaign->campaign_id);
        $criteria->with = array(
            'url' => array(
                'together' => true,
                'joinType' => 'INNER JOIN',
            ),
            'subscriber' => array(
                'together' => true,
                'joinType' => 'INNER JOIN',
            ),
        );
        $criteria->limit  = (int)$limit;
        $criteria->offset = (int)$offset;
        return CampaignTrackUrl::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @param $url_id
     * @throws CHttpException
     */
    public function actionClick_url($campaign_uid, $url_id)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        if ($campaign->option->url_tracking != CampaignOption::TEXT_YES) {
            $this->redirect($redirect);
        }

        $url = $this->loadUrlModel($campaign->campaign_id, $url_id);

        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'click-url-stats-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Url'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'Date added')
        );
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getClickUrlModels($campaign, $url, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array($model->subscriber->displayEmail, $url->destination, $model->user_agent, $model->ip_address, $model->dateAdded);
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getClickUrlModels($campaign, $url, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param CampaignUrl $url
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getClickUrlModels(Campaign $campaign, CampaignUrl $url, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->compare('url_id', (int)$url->url_id);
        $criteria->with = array(
            'subscriber' => array(
                'together' => true,
                'joinType' => 'INNER JOIN',
            ),
        );
        $criteria->limit  = (int)$limit;
        $criteria->offset = (int)$offset;
        return CampaignTrackUrl::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @param $subscriber_uid
     * @throws CHttpException
     */
    public function actionClick_by_subscriber($campaign_uid, $subscriber_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        $subscriber = $this->loadSubscriberModel($campaign->list_id, $subscriber_uid);

        if ($campaign->option->url_tracking != CampaignOption::TEXT_YES) {
            $this->redirect($redirect);
        }

        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'clicks-by-' . $subscriber->displayEmail . '-to-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Url'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'Date added')
        );
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getSubscriberClickUrlsModels($campaign, $subscriber, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array($subscriber->displayEmail, $model->url->destination, $model->user_agent, $model->ip_address, $model->dateAdded);
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getSubscriberClickUrlsModels($campaign, $subscriber, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getSubscriberClickUrlsModels(Campaign $campaign, ListSubscriber $subscriber, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria;
        $criteria->compare('t.subscriber_id', (int)$subscriber->subscriber_id);
        $criteria->compare('url.campaign_id', (int)$campaign->campaign_id);
        $criteria->with = array(
            'url' => array(
                'together' => true,
                'joinType' => 'INNER JOIN',
            ),
        );
        $criteria->limit  = (int)$limit;
        $criteria->offset = (int)$offset;
        return CampaignTrackUrl::model()->findAll($criteria);
    }

    /**
     * @param $campaign_uid
     * @param $subscriber_uid
     * @throws CHttpException
     */
    public function actionClick_by_subscriber_unique($campaign_uid, $subscriber_uid)
    {
        $campaign = $this->loadCampaignModel($campaign_uid);
        $notify   = Yii::app()->notify;
        $redirect = array($this->redirectRoute, 'campaign_uid' => $campaign->campaign_uid);
        
        // since 1.3.5.9
        if ($campaign->customer->getGroupOption('campaigns.can_export_stats', 'yes') != 'yes') {
            $this->redirect($redirect);
        }
        
        $subscriber = $this->loadSubscriberModel($campaign->list_id, $subscriber_uid);

        if ($campaign->option->url_tracking != CampaignOption::TEXT_YES) {
            $this->redirect($redirect);
        }

        if (!($fp = @fopen('php://output', 'w'))) {
            $notify->addError(Yii::t('campaign_reports', 'Cannot open export temporary file!'));
            $this->redirect($redirect);
        }

        $fileName = 'unique-clicks-by-' . $subscriber->displayEmail . '-to-' . $campaign->campaign_uid . '-' . date('Y-m-d-h-i-s') . '.csv';
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header('Content-type: application/csv');
        header("Content-Transfer-Encoding: Binary");
        header('Content-Disposition: attachment; filename="'.$fileName.'"');

        // columns
        $columns = array(
            Yii::t('campaign_reports', 'Email'),
            Yii::t('campaign_reports', 'Url'),
            Yii::t('campaign_reports', 'Clicked times'),
            Yii::t('campaign_reports', 'User agent'),
            Yii::t('campaign_reports', 'Ip address'),
            Yii::t('campaign_reports', 'Date added')
        );
        fputcsv($fp, $columns, ',', '"');

        // rows
        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;
        $models = $this->getSubscriberUniqueClickUrlsModels($campaign, $subscriber, $limit, $offset);
        while (!empty($models)) {
            foreach ($models as $model) {
                $row = array($subscriber->displayEmail, $model->url->destination, $model->counter, $model->user_agent, $model->ip_address, $model->dateAdded);
                fputcsv($fp, $row, ',', '"');
            }
            if (connection_status() != 0) {
                @fclose($fp);
                exit;
            }
            $offset = $offset + $limit;
            $models = $this->getSubscriberUniqueClickUrlsModels($campaign, $subscriber, $limit, $offset);
        }

        @fclose($fp);
        exit;
    }

    /**
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param int $limit
     * @param int $offset
     * @return static[]
     */
    protected function getSubscriberUniqueClickUrlsModels(Campaign $campaign, ListSubscriber $subscriber, $limit = self::DEFAULT_LIMIT, $offset = 0)
    {
        $criteria = new CDbCriteria();
        $criteria->select = 't.*, COUNT(*) AS counter';
        $criteria->compare('t.subscriber_id', (int)$subscriber->subscriber_id);

        $criteria->with = array(
            'url' => array(
                'select'    => 'url.url_id, url.destination',
                'together'  => true,
                'joinType'  => 'INNER JOIN',
                'condition' => 'url.campaign_id = :cid',
                'params'    => array(':cid' => $campaign->campaign_id),
            )
        );
        $criteria->group  = 't.url_id';
        $criteria->order  = 'counter DESC';
        $criteria->limit  = (int)$limit;
        $criteria->offset = (int)$offset;
        return CampaignTrackUrl::model()->findAll($criteria);
    }

    /**
     * Helper method to load the AR model
     */
    public function loadUrlModel($campaign_id, $url_id)
    {
        $model = CampaignUrl::model()->findByAttributes(array(
            'url_id'        => $url_id,
            'campaign_id'   => $campaign_id,
        ));

        if ($model === null) {
            throw new CHttpException(404, Yii::t('app', 'The requested page does not exist.'));
        }

        return $model;
    }

    /**
     * Helper method to load the AR model
     */
    public function loadSubscriberModel($list_id, $subscriber_uid)
    {
        $model = ListSubscriber::model()->findByAttributes(array(
            'subscriber_uid'    => $subscriber_uid,
            'list_id'           => $list_id,
        ));

        if ($model === null) {
            throw new CHttpException(404, Yii::t('app', 'The requested page does not exist.'));
        }

        return $model;
    }

    /**
     * Helper method to load the AR model
     */
    public function loadCampaignModel($campaign_uid)
    {
        $model = Campaign::model()->findByAttributes(array(
            'customer_id'   => (int)$this->customerId,
            'campaign_uid'  => $campaign_uid,
        ));

        if ($model === null) {
            throw new CHttpException(404, Yii::t('app', 'The requested page does not exist.'));
        }
        
        $this->setData('campaign', $model);
        
        return $model;
    }

}
