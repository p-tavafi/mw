<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * DswhController
 *
 * Delivery Servers Web Hooks (DSWH) handler
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.4.8
 */

class DswhController extends Controller
{
    public function init()
    {
        set_time_limit(0);
        ini_set('memory_limit', -1);
        parent::init();
        
        /* because posting too fast sometimes can lead to dupes */
        usleep(rand(100000, 3000000)); // 0.1 => 3 sec
    }
    
    public function actionIndex($id)
    {
        $server = DeliveryServer::model()->findByPk((int)$id);
        
        if (empty($server)) {
            Yii::app()->end();
        }
        
        $map = array(
            'mandrill-web-api'     => array($this, 'processMandrill'),
            'amazon-ses-web-api'   => array($this, 'processAmazonSes'),
            'mailgun-web-api'      => array($this, 'processMailgun'),
            'sendgrid-web-api'     => array($this, 'processSendgrid'),
            'leadersend-web-api'   => array($this, 'processLeadersend'),
            'elasticemail-web-api' => array($this, 'processElasticemail'),
            'dyn-web-api'          => array($this, 'processDyn'),
            'sparkpost-web-api'    => array($this, 'processSparkpost'),
            'mailjet-web-api'      => array($this, 'processMailjet'),
            'sendinblue-web-api'   => array($this, 'processSendinblue'),
            'tipimail-web-api'     => array($this, 'processTipimail'),
        );

        $map = (array)Yii::app()->hooks->applyFilters('dswh_process_map', $map, $server, $this);
        if (isset($map[$server->type]) && is_callable($map[$server->type])) {
            call_user_func_array($map[$server->type], array($server, $this));
        }

        Yii::app()->end();
    }

    public function actionDrh()
    {
        $request = Yii::app()->request;
        if (!count($request->getPost(null))) {
            Yii::app()->end();
        }

        $event = $request->getPost('event_type');
        // header name: X-GreenArrow-Click-Tracking-ID
        // header value: [CAMPAIGN_UID]|[SUBSCRIBER_UID]
        $cs = explode('|', $request->getPost('click_tracking_id'));

        if (empty($event) || empty($cs) || count($cs) != 2) {
            $this->end();
        }

        list($campaignUid, $subscriberUid) = $cs;

        $campaign = Campaign::model()->findByAttributes(array(
            'campaign_uid' => $campaignUid
        ));
        if (empty($campaign)) {
            $this->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_uid'   => $subscriberUid,
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            $this->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            $this->end();
        }

        if (stripos($event, 'bounce') !== false) {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id   = $campaign->campaign_id;
            $bounceLog->subscriber_id = $subscriber->subscriber_id;
            $bounceLog->message       = $request->getPost('bounce_text');
            $bounceLog->bounce_type   = $request->getPost('bounce_type') == 'h' ? CampaignBounceLog::BOUNCE_HARD : CampaignBounceLog::BOUNCE_SOFT;
            $bounceLog->save();

            if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                $subscriber->addToBlacklist($bounceLog->message);
            }

            $this->end();
        }

        if ($event == 'scomp') {
            if (Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                $this->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($trackUnsubscribe)) {
                $this->end();
            }

            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = 'Abuse complaint!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            $this->end();
        }

        $this->end();
    }
    
    public function processMandrill()
    {
        if (!MW_COMPOSER_SUPPORT) {
            Yii::app()->end();
        }

        $request = Yii::app()->request;
        $mandrillEvents = $request->getPost('mandrill_events');

        if (empty($mandrillEvents)) {
            Yii::app()->end();
        }

        $mandrillEvents = CJSON::decode($mandrillEvents);
        if (empty($mandrillEvents) || !is_array($mandrillEvents)) {
            $mandrillEvents = array();
        }

        foreach ($mandrillEvents as $evt) {
            if (!empty($evt['type']) && $evt['type'] == 'blacklist' && !empty($evt['action']) && $evt['action'] == 'add') {
                if (!empty($evt['reject']['email'])) {
                    EmailBlacklist::addToBlacklist($evt['reject']['email'], (!empty($evt['reject']['detail']) ? $evt['reject']['detail'] : null));
                }
                continue;
            }

            if (empty($evt['msg']) || !is_array($evt['msg'])) {
                continue;
            }

            $msgData = $evt['msg'];
            $event   = !empty($evt['event']) ? $evt['event'] : null;

            $globalMetaData    = !empty($msgData['metadata']) && is_array($msgData['metadata']) ? $msgData['metadata'] : array();
            $recipientMetaData = !empty($msgData['recipient_metadata']) && is_array($msgData['recipient_metadata']) ? $msgData['recipient_metadata'] : array();
            $metaData          = array_merge($globalMetaData, $recipientMetaData);

            if (empty($metaData['campaign_uid']) || empty($metaData['subscriber_uid'])) {
                continue;
            }

            $campaignUid   = trim($metaData['campaign_uid']);
            $subscriberUid = trim($metaData['subscriber_uid']);

            $campaign = Campaign::model()->findByUid($campaignUid);
            if (empty($campaign)) {
                continue;
            }

            $subscriber = ListSubscriber::model()->findByAttributes(array(
                'list_id'           => $campaign->list_id,
                'subscriber_uid'    => $subscriberUid,
                'status'            => ListSubscriber::STATUS_CONFIRMED,
            ));

            if (empty($subscriber)) {
                continue;
            }

            $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($bounceLog)) {
                continue;
            }

            $returnReason = array();
            if (!empty($msgData['diag'])) {
                $returnReason[] = $msgData['diag'];
            }
            if (!empty($msgData['bounce_description'])) {
                $returnReason[] = $msgData['bounce_description'];
            }
            $returnReason = implode(" ", $returnReason);

            if (in_array($event, array('hard_bounce', 'soft_bounce'))) {
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id     = $campaign->campaign_id;
                $bounceLog->subscriber_id   = $subscriber->subscriber_id;
                $bounceLog->message         = $returnReason;
                $bounceLog->bounce_type     = $event == 'soft_bounce' ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
                $bounceLog->save();

                if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                    $subscriber->addToBlacklist($bounceLog->message);
                }

                continue;
            }

            if (in_array($event, array('reject', 'blacklist'))) {
                $subscriber->addToBlacklist($returnReason);
                continue;
            }

            if(in_array($event, array('spam', 'unsub'))) {
                if ($event == 'spam' && Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                    $subscriber->delete();
                    continue;
                }

                $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

                $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                    'campaign_id'   => $campaign->campaign_id,
                    'subscriber_id' => $subscriber->subscriber_id,
                ));
                
                if (!empty($trackUnsubscribe)) {
                    continue;
                }
                
                $trackUnsubscribe = new CampaignTrackUnsubscribe();
                $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
                $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
                $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
                $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
                $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
                $trackUnsubscribe->save(false);

                continue;
            }
        }

        Yii::app()->end();
    }

    public function processAmazonSes($server)
    {
        if (!MW_COMPOSER_SUPPORT || !version_compare(PHP_VERSION, '5.3.3', '>=')) {
            Yii::app()->end();
        }

        try {
            $message   = call_user_func(array('\Aws\Sns\MessageValidator\Message', 'fromRawPostData'));
            $className = '\Aws\Sns\MessageValidator\MessageValidator';
            $validator = new $className();
            $validator->validate($message);
        } catch (Exception $e) {
            Yii::app()->end();
        }

        if ($message->get('Type') === 'SubscriptionConfirmation') {
            try {

                $types  = DeliveryServer::getTypesMapping();
                $type   = $types[$server->type];
                $server = DeliveryServer::model($type)->findByPk((int)$server->server_id);
                $result = $server->getSnsClient()->confirmSubscription(array(
                    'TopicArn'  => $message->get('TopicArn'),
                    'Token'     => $message->get('Token'),
                ));
                if (stripos($result->get('SubscriptionArn'), 'pending') === false) {
                    $server->subscription_arn = $result->get('SubscriptionArn');
                    $server->save(false);
                }
                Yii::app()->end();

            } catch (Exception $e) {}
            
            $className = '\Guzzle\Http\Client';
            $client    = new $className();
            $client->get($message->get('SubscribeURL'))->send();
            Yii::app()->end();
        }

        if ($message->get('Type') !== 'Notification') {
            Yii::app()->end();
        }

        $data = new CMap((array)CJSON::decode($message->get('Message')));
        if (!$data->itemAt('notificationType') || $data->itemAt('notificationType') == 'AmazonSnsSubscriptionSucceeded' || !$data->itemAt('mail')) {
            Yii::app()->end();
        }

        $mailMessage = $data->itemAt('mail');
        if (empty($mailMessage['messageId'])) {
            Yii::app()->end();
        }

        $deliveryLog = CampaignDeliveryLog::model()->findByAttributes(array(
            'email_message_id' => $mailMessage['messageId'],
            'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
        ));

        if (empty($deliveryLog)) {
            $deliveryLog = CampaignDeliveryLogArchive::model()->findByAttributes(array(
                'email_message_id' => $mailMessage['messageId'],
                'status'           => CampaignDeliveryLogArchive::STATUS_SUCCESS,
            ));
        }

        if (empty($deliveryLog)) {
            Yii::app()->end();
        }

        $campaign = Campaign::model()->findByPk($deliveryLog->campaign_id);
        if (empty($campaign)) {
            Yii::app()->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_id'    => $deliveryLog->subscriber_id,
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            Yii::app()->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            Yii::app()->end();
        }

        if ($data->itemAt('notificationType') == 'Bounce' && ($bounce = $data->itemAt('bounce'))) {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id     = $campaign->campaign_id;
            $bounceLog->subscriber_id   = $subscriber->subscriber_id;
            $bounceLog->message         = !empty($bounce['bouncedRecipients'][0]['diagnosticCode']) ? $bounce['bouncedRecipients'][0]['diagnosticCode'] : 'BOUNCED BACK';
            $bounceLog->bounce_type     = $bounce['bounceType'] !== 'Permanent' ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
            $bounceLog->save();

            if ($bounce['bounceType'] === 'Permanent') {
                $subscriber->addToBlacklist($bounceLog->message);
            }
            Yii::app()->end();
        }

        if ($data->itemAt('notificationType') == 'Complaint' && ($complaint = $data->itemAt('complaint'))) {
            if (Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                Yii::app()->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);
            
            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));
            
            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }
            
            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = 'Abuse complaint!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }

        Yii::app()->end();
    }

    public function processMailgun()
    {
        if (!MW_COMPOSER_SUPPORT || !version_compare(PHP_VERSION, '5.3.2', '>=')) {
            Yii::app()->end();
        }

        $request  = Yii::app()->request;
        $event    = $request->getPost('event');
        $metaData = $request->getPost('metadata');

        if (empty($metaData) || empty($event)) {
            Yii::app()->end();
        }

        $metaData = CJSON::decode($metaData);
        if (empty($metaData['campaign_uid']) || empty($metaData['subscriber_uid'])) {
            Yii::app()->end();
        }

        $campaign = Campaign::model()->findByAttributes(array(
            'campaign_uid' => $metaData['campaign_uid']
        ));
        if (empty($campaign)) {
            Yii::app()->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_uid'   => $metaData['subscriber_uid'],
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            Yii::app()->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            Yii::app()->end();
        }

        if ($event == 'bounced') {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id   = $campaign->campaign_id;
            $bounceLog->subscriber_id = $subscriber->subscriber_id;
            $bounceLog->message       = $request->getPost('error') . ' ' . $request->getPost('reason');
            $bounceLog->bounce_type   = CampaignBounceLog::BOUNCE_HARD;
            $bounceLog->save();

            $subscriber->addToBlacklist($bounceLog->message);

            Yii::app()->end();
        }

        if ($event == 'dropped') {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id   = $campaign->campaign_id;
            $bounceLog->subscriber_id = $subscriber->subscriber_id;
            $bounceLog->message       = $request->getPost('error') . ' ' . $request->getPost('reason');
            $bounceLog->bounce_type   = $request->getPost('reason') != 'hardfail' ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
            $bounceLog->save();

            if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                $subscriber->addToBlacklist($bounceLog->message);
            }

            Yii::app()->end();
        }

        if ($event == 'complained') {
            if (Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                Yii::app()->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);
            
            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));
            
            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }
            
            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = 'Abuse complaint!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }

        Yii::app()->end();
    }

    public function processSendgrid()
    {
        if (!MW_COMPOSER_SUPPORT || !version_compare(PHP_VERSION, '5.3.3', '>=')) {
            Yii::app()->end();
        }

        $events = file_get_contents("php://input");
        if (empty($events)) {
            Yii::app()->end();
        }

        $events = CJSON::decode($events);
        if (empty($events) || !is_array($events)) {
            $events = array();
        }
        $events = Yii::app()->ioFilter->xssClean($events);

        foreach ($events as $evt) {
            if (empty($evt['event']) || !in_array($evt['event'], array('dropped', 'deferred' , 'bounce', 'spamreport'))) {
                continue;
            }

            if (empty($evt['campaign_uid']) || empty($evt['subscriber_uid'])) {
                continue;
            }

            $campaignUid   = trim($evt['campaign_uid']);
            $subscriberUid = trim($evt['subscriber_uid']);

            $campaign = Campaign::model()->findByUid($campaignUid);
            if (empty($campaign)) {
                continue;
            }

            $subscriber = ListSubscriber::model()->findByAttributes(array(
                'list_id'           => $campaign->list_id,
                'subscriber_uid'    => $subscriberUid,
                'status'            => ListSubscriber::STATUS_CONFIRMED,
            ));

            if (empty($subscriber)) {
                continue;
            }

            $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($bounceLog)) {
                continue;
            }

            // https://sendgrid.com/docs/API_Reference/Webhooks/event.html
            if (in_array($evt['event'], array('dropped', 'deferred'))) {
           
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id   = $campaign->campaign_id;
                $bounceLog->subscriber_id = $subscriber->subscriber_id;
                $bounceLog->message       = !empty($evt['reason']) ? $evt['reason'] : $evt['event'];
                $bounceLog->message       = !empty($bounceLog->message) ? $bounceLog->message : 'Internal Bounce';
                $bounceLog->bounce_type   = CampaignBounceLog::BOUNCE_INTERNAL;
                $bounceLog->save();
                
                continue;
            }

            if (in_array($evt['event'], array('bounce'))) {
       
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id   = $campaign->campaign_id;
                $bounceLog->subscriber_id = $subscriber->subscriber_id;
                $bounceLog->message       = isset($evt['reason']) ? $evt['reason'] : 'BOUNCED BACK';
                $bounceLog->bounce_type   = CampaignBounceLog::BOUNCE_HARD;
                $bounceLog->save();

                if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                    $subscriber->addToBlacklist($bounceLog->message);
                }

                continue;
            }

            if(in_array($evt['event'], array('spamreport'))) {
                if ($evt['event'] == 'spamreport' && Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                    $subscriber->delete();
                    continue;
                }

                $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

                $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                    'campaign_id'   => $campaign->campaign_id,
                    'subscriber_id' => $subscriber->subscriber_id,
                ));

                if (!empty($trackUnsubscribe)) {
                    continue;
                }

                $trackUnsubscribe = new CampaignTrackUnsubscribe();
                $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
                $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
                $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
                $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
                $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
                $trackUnsubscribe->save(false);

                continue;
            }
        }

        Yii::app()->end();
    }

    public function processLeadersend()
    {
        $request = Yii::app()->request;
        $events  = $request->getPost('leadersend_events');

        if (empty($events)) {
            Yii::app()->end();
        }

        $events = CJSON::decode($events);
        if (empty($events) || !is_array($events)) {
            $events = array();
        }

        foreach ($events as $evt) {
            if (empty($evt['msg']) || empty($evt['msg']['id'])) {
                continue;
            }
            if (empty($evt['event']) || !in_array($evt['event'], array('spam', 'soft_bounce', 'hard_bounce', 'reject'))) {
                continue;
            }

            $deliveryLog = CampaignDeliveryLog::model()->findByAttributes(array(
                'email_message_id' => $evt['msg']['id'],
                'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
            ));

            if (empty($deliveryLog)) {
                $deliveryLog = CampaignDeliveryLogArchive::model()->findByAttributes(array(
                    'email_message_id' => $evt['msg']['id'],
                    'status'           => CampaignDeliveryLogArchive::STATUS_SUCCESS,
                ));
            }

            if (empty($deliveryLog)) {
                continue;
            }

            $campaign = Campaign::model()->findByPk($deliveryLog->campaign_id);
            if (empty($campaign)) {
                continue;
            }

            $subscriber = ListSubscriber::model()->findByAttributes(array(
                'list_id'          => $campaign->list_id,
                'subscriber_id'    => $deliveryLog->subscriber_id,
                'status'           => ListSubscriber::STATUS_CONFIRMED,
            ));

            if (empty($subscriber)) {
                continue;
            }

            $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($bounceLog)) {
                continue;
            }

            if (in_array($evt['event'], array('soft_bounce', 'hard_bounce'))) {
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id     = $campaign->campaign_id;
                $bounceLog->subscriber_id   = $subscriber->subscriber_id;
                $bounceLog->message         = !empty($evt['msg']['delivery_report']) ? $evt['msg']['delivery_report'] : 'BOUNCED BACK';
                $bounceLog->bounce_type     = $evt['event'] == 'soft_bounce' ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
                $bounceLog->save();

                if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                    $subscriber->addToBlacklist($bounceLog->message);
                }
                continue;
            }

            if ($evt['event'] == 'spam') {
                if (Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                    $subscriber->delete();
                    continue;
                }

                $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

                $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                    'campaign_id'   => $campaign->campaign_id,
                    'subscriber_id' => $subscriber->subscriber_id,
                ));

                if (!empty($trackUnsubscribe)) {
                    continue;
                }

                $trackUnsubscribe = new CampaignTrackUnsubscribe();
                $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
                $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
                $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
                $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
                $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
                $trackUnsubscribe->save(false);

                continue;
            }

            if ($evt['event'] == 'reject') {
                $subscriber->addToBlacklist(!empty($evt['msg']['delivery_report']) ? $evt['msg']['delivery_report'] : 'BOUNCED BACK');
                continue;
            }
        }

        Yii::app()->end();
    }

    public function processElasticemail()
    {
        $request     = Yii::app()->request;
        $category    = trim($request->getQuery('category'));
        $transaction = trim($request->getQuery('transaction'));
        $status      = trim($request->getQuery('status'));

        if (empty($transaction) || empty($category)) {
            Yii::app()->end();
        }

        $deliveryLog = CampaignDeliveryLog::model()->findByAttributes(array(
            'email_message_id' => $transaction,
            'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
        ));

        if (empty($deliveryLog)) {
            $deliveryLog = CampaignDeliveryLogArchive::model()->findByAttributes(array(
                'email_message_id' => $transaction,
                'status'           => CampaignDeliveryLogArchive::STATUS_SUCCESS,
            ));
        }

        if (empty($deliveryLog)) {
            Yii::app()->end();
        }

        $campaign = Campaign::model()->findByPk($deliveryLog->campaign_id);
        if (empty($campaign)) {
            Yii::app()->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_id'    => $deliveryLog->subscriber_id,
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            Yii::app()->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            Yii::app()->end();
        }
        
        // All categories:
        // https://elasticemail.com/support/delivery/http-web-notification
        
        if ($status == 'AbuseReport') {
            if (Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                Yii::app()->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }
            
            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }
        
        if ($status == 'Unsubscribed') {
            
            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }

            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }
        
        // $bounceCategories = array(
        //    'NoMailbox', 'AccountProblem',
        //    'Throttled', 'ConnectionTerminated',
        //);
        $bounceCategories = array('NoMailbox');
        
        $bounceCategories = array_map('strtolower', $bounceCategories);
        $categoryID       = strtolower($category);

        if (in_array($categoryID, $bounceCategories)) {
            $hardBounceCategories = array('NoMailbox', 'AccountProblem');
            $hardBounceCategories = array_map('strtolower', $hardBounceCategories);
            
            $softBounceCategories = array('Throttled', 'ConnectionTerminated');
            $softBounceCategories = array_map('strtolower', $softBounceCategories);

            $bounceType = null;
            
            if (in_array($categoryID, $hardBounceCategories)) {
                $bounceType = CampaignBounceLog::BOUNCE_HARD;
            } elseif (in_array($categoryID, $softBounceCategories)) {
                $bounceType = CampaignBounceLog::BOUNCE_SOFT;
            }
            
            if ($bounceType === null) {
                Yii::app()->end();
            }

            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id     = $campaign->campaign_id;
            $bounceLog->subscriber_id   = $subscriber->subscriber_id;
            $bounceLog->message         = $category;
            $bounceLog->bounce_type     = $bounceType;
            $bounceLog->save();

            if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                $subscriber->addToBlacklist($bounceLog->message);
            }
            
            Yii::app()->end();
        }

        Yii::app()->end();
    }

    public function processDyn($server)
    {
        $request    = Yii::app()->request;
        $event      = $request->getQuery('event');
        $bounceRule = $request->getQuery('rule', $request->getQuery('bouncerule')); // bounce rule
        $bounceType = $request->getQuery('type', $request->getQuery('bouncetype')); // bounce type
        $campaign   = $request->getQuery('campaign'); // campaign uid
        $subscriber = $request->getQuery('subscriber'); // subscriber uid

        $allowedEvents = array('bounce', 'complaint', 'unsubscribe');
        if (!in_array($event, $allowedEvents)) {
            Yii::app()->end();
        }

        $campaign = Campaign::model()->findByUid($campaign);
        if (empty($campaign)) {
            Yii::app()->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_uid'   => $subscriber,
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            Yii::app()->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            Yii::app()->end();
        }

        if ($event == 'bounce') {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id     = $campaign->campaign_id;
            $bounceLog->subscriber_id   = $subscriber->subscriber_id;
            $bounceLog->message         = $bounceRule;
            $bounceLog->bounce_type     = $bounceType == 'soft' ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
            $bounceLog->save();

            if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                $subscriber->addToBlacklist($bounceLog->message);
            }
            Yii::app()->end();
        }
        
        /* remove from suppression list. */
        if ($event == 'complaint') {
            $url = sprintf('https://api.email.dynect.net/rest/json/suppressions/activate?apikey=%s&emailaddress=%s', $server->password, urlencode($subscriber->email));
            AppInitHelper::simpleCurlPost($url, array(), 5);
        }

        if (in_array($event, array('complaint', 'unsubscribe'))) {
            if (Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                Yii::app()->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }

            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }

        Yii::app()->end();
    }

    public function processSparkpost()
    {
        $events = file_get_contents("php://input");
        if (empty($events)) {
            Yii::app()->end();
        }
        $events = CJSON::decode($events);

        if (empty($events) || !is_array($events)) {
            $events = array();
        }
        $events = Yii::app()->ioFilter->xssClean($events);
  
        foreach ($events as $evt) {
            if (empty($evt['msys']['message_event'])) {
                continue;
            }
            $evt = $evt['msys']['message_event'];
            if (empty($evt['type']) || !in_array($evt['type'], array('bounce', 'spam_complaint', 'list_unsubscribe', 'link_unsubscribe'))) {
                continue;
            }

            if (empty($evt['rcpt_meta']) || empty($evt['rcpt_meta']['campaign_uid']) || empty($evt['rcpt_meta']['subscriber_uid'])) {
                continue;
            }

            $campaign = Campaign::model()->findByUid($evt['rcpt_meta']['campaign_uid']);
            if (empty($campaign)) {
                continue;
            }

            $subscriber = ListSubscriber::model()->findByAttributes(array(
                'list_id'          => $campaign->list_id,
                'subscriber_uid'   => $evt['rcpt_meta']['subscriber_uid'],
                'status'           => ListSubscriber::STATUS_CONFIRMED,
            ));

            if (empty($subscriber)) {
                continue;
            }

            $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($bounceLog)) {
                continue;
            }

            if (in_array($evt['type'], array('bounce'))) {

                // https://support.sparkpost.com/customer/portal/articles/1929896-bounce-classification-codes
                $bounceType = CampaignBounceLog::BOUNCE_INTERNAL;
                if (in_array($evt['bounce_class'], array(10, 30, 90))) {
                    $bounceType = CampaignBounceLog::BOUNCE_HARD;
                } elseif (in_array($evt['bounce_class'], array(20, 40, 60))) {
                    $bounceType = CampaignBounceLog::BOUNCE_SOFT;
                }
                
                $defaultBounceMessage = 'BOUNCED BACK';
                if ($bounceType == CampaignBounceLog::BOUNCE_INTERNAL) {
                    $defaultBounceMessage = 'Internal Bounce';
                }
                
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id     = $campaign->campaign_id;
                $bounceLog->subscriber_id   = $subscriber->subscriber_id;
                $bounceLog->message         = !empty($evt['reason']) ? $evt['reason'] : $defaultBounceMessage;
                $bounceLog->bounce_type     = $bounceType;
                $bounceLog->save();

                if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                    $subscriber->addToBlacklist($bounceLog->message);
                }

                continue;
            }

            if (in_array($evt['type'], array('spam_complaint', 'list_unsubscribe', 'link_unsubscribe'))) {
                if ($evt['type'] == 'spam_complaint' && Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                    $subscriber->delete();
                    continue;
                }

                $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

                $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                    'campaign_id'   => $campaign->campaign_id,
                    'subscriber_id' => $subscriber->subscriber_id,
                ));

                if (!empty($trackUnsubscribe)) {
                    continue;
                }

                $trackUnsubscribe = new CampaignTrackUnsubscribe();
                $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
                $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
                $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
                $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
                $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
                $trackUnsubscribe->save(false);

                continue;
            }
        }

        Yii::app()->end();
    }
    
    public function processMailjet()
    {
        $events = file_get_contents("php://input");
        if (empty($events)) {
            Yii::app()->end();
        }
        
        $events = CJSON::decode($events);
        
        if (empty($events) || !is_array($events)) {
            $events = array();
        }
        
        $events = Yii::app()->ioFilter->xssClean($events);
        
        if (isset($events['event'])) {
            $events = array($events);
        }

        foreach ($events as $event) {
            if (!isset($event['MessageID'], $event['event'])) {
                continue;
            }

            $deliveryLog = CampaignDeliveryLog::model()->findByAttributes(array(
                'email_message_id' => $event['MessageID'],
                'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
            ));

            if (empty($deliveryLog)) {
                $deliveryLog = CampaignDeliveryLogArchive::model()->findByAttributes(array(
                    'email_message_id' => $event['MessageID'],
                    'status'           => CampaignDeliveryLogArchive::STATUS_SUCCESS,
                ));
            }

            if (empty($deliveryLog)) {
                continue;
            }

            $campaign = Campaign::model()->findByPk($deliveryLog->campaign_id);
            if (empty($campaign)) {
                continue;
            }

            $subscriber = ListSubscriber::model()->findByAttributes(array(
                'list_id'          => $campaign->list_id,
                'subscriber_id'    => $deliveryLog->subscriber_id,
                'status'           => ListSubscriber::STATUS_CONFIRMED,
            ));

            if (empty($subscriber)) {
                continue;
            }

            $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($bounceLog)) {
                continue;
            }
            
            if (in_array($event['event'], array('bounce', 'blocked'))) {
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id     = $campaign->campaign_id;
                $bounceLog->subscriber_id   = $subscriber->subscriber_id;
                $bounceLog->message         = !empty($event['error'])  ? $event['error'] : 'BOUNCED BACK';
                $bounceLog->bounce_type     = empty($event['hard_bounce']) ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
                $bounceLog->save();

                if (!empty($event['hard_bounce'])) {
                    $subscriber->addToBlacklist($bounceLog->message);
                }
                
                continue;
            }
            
            if (in_array($event['event'], array('spam', 'unsub'))) {

                if ($event['event'] == 'spam' && Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                    $subscriber->delete();
                    continue;
                }

                $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                    'campaign_id'   => $campaign->campaign_id,
                    'subscriber_id' => $subscriber->subscriber_id,
                ));
                
                if (!empty($trackUnsubscribe)) {
                    continue;
                }
                
                $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

                $trackUnsubscribe = new CampaignTrackUnsubscribe();
                $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
                $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
                $trackUnsubscribe->note          = $event['event'] == 'spam' ? 'Abuse complaint!' : 'Unsubscribed via Web Hook!';
                $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
                $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
                $trackUnsubscribe->save(false);

                continue;
            }
        }

        Yii::app()->end();
    }

    public function processSendinblue()
    {
        $event = file_get_contents("php://input");
        if (empty($event)) {
            Yii::app()->end();
        }

        $event = CJSON::decode($event);

        if (empty($event) || !is_array($event) || empty($event['event']) || empty($event['message-id'])) {
            Yii::app()->end();
        }

        $event = Yii::app()->ioFilter->xssClean($event);

        $deliveryLog = CampaignDeliveryLog::model()->findByAttributes(array(
            'email_message_id' => $event['message-id'],
            'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
        ));

        if (empty($deliveryLog)) {
            $deliveryLog = CampaignDeliveryLogArchive::model()->findByAttributes(array(
                'email_message_id' => $event['message-id'],
                'status'           => CampaignDeliveryLogArchive::STATUS_SUCCESS,
            ));
        }

        if (empty($deliveryLog)) {
            Yii::app()->end();
        }

        $campaign = Campaign::model()->findByPk($deliveryLog->campaign_id);
        if (empty($campaign)) {
            Yii::app()->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_id'    => $deliveryLog->subscriber_id,
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            Yii::app()->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            Yii::app()->end();
        }

        if (in_array($event['event'], array('hard_bounce', 'soft_bounce', 'blocked', 'invalid_email'))) {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id     = $campaign->campaign_id;
            $bounceLog->subscriber_id   = $subscriber->subscriber_id;
            $bounceLog->message         = !empty($event['reason'])  ? $event['reason'] : 'BOUNCED BACK';
            $bounceLog->bounce_type     = $event['event'] == 'soft_bounce' ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
            $bounceLog->save();

            if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
                $subscriber->addToBlacklist($bounceLog->message);
            }

            Yii::app()->end();
        }

        if (in_array($event['event'], array('spam', 'unsubscribe'))) {

            if ($event['event'] == 'spam' && Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                Yii::app()->end();
            }

            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = $event['event'] == 'spam' ? 'Abuse complaint!' : 'Unsubscribed via Web Hook!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }

        Yii::app()->end();
    }

    public function processTipimail()
    {
        $event = file_get_contents("php://input");
        if (empty($event)) {
            Yii::app()->end();
        }

        $event = CJSON::decode($event);

        if (empty($event) || !is_array($event) || empty($event['status'])) {
            Yii::app()->end();
        }
        
        if (empty($event['meta']) || empty($event['meta']['campaign_uid']) || empty($event['meta']['subscriber_uid'])) {
            Yii::app()->end();
        }
        
        $event = Yii::app()->ioFilter->xssClean($event);
        
        $campaign = Campaign::model()->findByAttributes(array(
            'campaign_uid' => $event['meta']['campaign_uid'],
        ));
        
        if (empty($campaign)) {
            Yii::app()->end();
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id'          => $campaign->list_id,
            'subscriber_uid'   => $event['meta']['subscriber_uid'],
            'status'           => ListSubscriber::STATUS_CONFIRMED,
        ));

        if (empty($subscriber)) {
            Yii::app()->end();
        }

        $bounceLog = CampaignBounceLog::model()->findByAttributes(array(
            'campaign_id'   => $campaign->campaign_id,
            'subscriber_id' => $subscriber->subscriber_id,
        ));

        if (!empty($bounceLog)) {
            Yii::app()->end();
        }

        if (in_array($event['status'], array('error', 'rejected', 'hardbounced'))) {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id    = $campaign->campaign_id;
            $bounceLog->subscriber_id  = $subscriber->subscriber_id;
            $bounceLog->message        = !empty($event['description']) ? $event['description'] : 'BOUNCED BACK';
            $bounceLog->bounce_type    = CampaignBounceLog::BOUNCE_HARD;
            $bounceLog->save();

            $subscriber->addToBlacklist($bounceLog->message);

            Yii::app()->end();
        }

        if (in_array($event['status'], array('complaint', 'unsubscribed'))) {

            if ($event['status'] == 'complaint' && Yii::app()->options->get('system.cron.process_feedback_loop_servers.subscriber_action', 'unsubscribe') == 'delete') {
                $subscriber->delete();
                Yii::app()->end();
            }

            $trackUnsubscribe = CampaignTrackUnsubscribe::model()->findByAttributes(array(
                'campaign_id'   => $campaign->campaign_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if (!empty($trackUnsubscribe)) {
                Yii::app()->end();
            }

            $subscriber->saveStatus(ListSubscriber::STATUS_UNSUBSCRIBED);

            $trackUnsubscribe = new CampaignTrackUnsubscribe();
            $trackUnsubscribe->campaign_id   = $campaign->campaign_id;
            $trackUnsubscribe->subscriber_id = $subscriber->subscriber_id;
            $trackUnsubscribe->note          = $event['status'] == 'complaint' ? 'Abuse complaint!' : 'Unsubscribed via Web Hook!';
            $trackUnsubscribe->ip_address    = Yii::app()->request->userHostAddress;
            $trackUnsubscribe->user_agent    = StringHelper::truncateLength(Yii::app()->request->userAgent, 255);
            $trackUnsubscribe->save(false);

            Yii::app()->end();
        }

        Yii::app()->end();
    }

    public function end($message = "OK")
    {
        if ($message) {
            echo $message;
        }
        Yii::app()->end();
    }
}
