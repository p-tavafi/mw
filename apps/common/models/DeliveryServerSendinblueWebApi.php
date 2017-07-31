<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * DeliveryServerSendinblueWebApi
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.6.3
 *
 */

class DeliveryServerSendinblueWebApi extends DeliveryServer
{
    protected $serverType = 'sendinblue-web-api';

    protected $_initStatus;

    protected $_preCheckError;
    
    public $webhook = array();

    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        $rules = array(
            array('password', 'length', 'max' => 255),
        );
        return CMap::mergeArray($rules, parent::rules());
    }

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels()
    {
        $labels = array(
            'password'   => Yii::t('servers', 'Api key'),
        );
        return CMap::mergeArray(parent::attributeLabels(), $labels);
    }

    public function attributeHelpTexts()
    {
        $texts = array(
            'password' => Yii::t('servers', 'Your sendinblue api key'),
        );

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    public function attributePlaceholders()
    {
        $placeholders = array(
            'password'   => 'Dopn8UjyrPfH0pbg',
        );

        return CMap::mergeArray(parent::attributePlaceholders(), $placeholders);
    }

    /**
     * Returns the static model of the specified AR class.
     * Please note that you should have this exact method in all your CActiveRecord descendants!
     * @param string $className active record class name.
     * @return DeliveryServer the static model class
     */
    public static function model($className=__CLASS__)
    {
        return parent::model($className);
    }

    public function sendEmail(array $params = array())
    {
        $params = (array)Yii::app()->hooks->applyFilters('delivery_server_before_send_email', $this->getParamsArray($params), $this);

        if (!isset($params['from'], $params['to'], $params['subject'], $params['body'])) {
            return false;
        }

        list($toEmail, $toName)     = $this->getMailer()->findEmailAndName($params['to']);
        list($fromEmail, $fromName) = $this->getMailer()->findEmailAndName($params['from']);

        if (!empty($params['fromName'])) {
            $fromName = $params['fromName'];
        }

        $replyToEmail = null;
        $replyToName  = null;
        if (!empty($params['replyTo'])) {
            list($replyToEmail, $replyToName) = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $headerPrefix = Yii::app()->params['email.custom.header.prefix'];
        $headers = array();
        if (!empty($params['headers'])) {
            $headers = $this->parseHeadersIntoKeyValue($params['headers']);
        }
        $headers['X-Sender']   = $fromEmail;
        $headers['X-Receiver'] = $toEmail;
        $headers['Reply-To']   = $replyToEmail;
        $headers[$headerPrefix . 'Mailer'] = 'Sendinblue Web API';

        $metaData   = array();
        if (isset($headers[$headerPrefix . 'Campaign-Uid'])) {
            $metaData['campaign_uid'] = $headers[$headerPrefix . 'Campaign-Uid'];
        }
        if (isset($headers[$headerPrefix . 'Subscriber-Uid'])) {
            $metaData['subscriber_uid'] = $headers[$headerPrefix . 'Subscriber-Uid'];
        }
        
        $sent = false;

        try {
            if (!$this->preCheckWebHook()) {
                throw new Exception($this->_preCheckError);
            }
            
            $sendParams = array(
                'from'    => array($fromEmail, sprintf('=?%s?B?%s?=', strtolower(Yii::app()->charset), base64_encode($fromName))),
                'to'      => array($toEmail => $toName),
                'subject' => sprintf('=?%s?B?%s?=', strtolower(Yii::app()->charset), base64_encode($params['subject'])),
                'html'    => $params['body'],
                'text'    => !empty($params['plainText']) ? $params['plainText'] : CampaignHelper::htmlToText($params['body']),
                'headers' => $headers,
            );
            
            if ($replyToEmail) {
                $sendParams['replyto'] = array($replyToEmail, $replyToName);
            }

            if (!empty($params['attachments']) && is_array($params['attachments'])) {
                $sendParams['attachment'] = array();
                $_attachments = array_unique($params['attachments']);
                foreach ($_attachments as $attachment) {
                    if (is_file($attachment)) {
                        $fileName = basename($attachment);
                        $sendParams['attachment'][$fileName] = base64_encode(file_get_contents($attachment));
                    }
                }
            }

            $response = $this->getClient()->send_email($sendParams);
            if (empty($response) || empty($response['code']) || empty($response['data'])) {
                throw new Exception('Upstream response: ' . (empty($response) ? 'NULL' : print_r($response, true)));
            }
            
            if ($response['code'] != 'success' || empty($response['data']['message-id'])) {
                $message = isset($response['message']) ? $response['message'] : print_r($response, true);
                throw new Exception($message);
            }

            $this->getMailer()->addLog('OK');
            $sent = array('message_id' => $response['data']['message-id']);

        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        Yii::app()->hooks->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return $sent;
    }

    public function getClient()
    {
        static $clients = array();
        $id = (int)$this->server_id;
        if (!empty($clients[$id])) {
            return $clients[$id];
        }
        $className = '\Sendinblue\Mailin';
        return $clients[$id] = new $className('https://api.sendinblue.com/v2.0', $this->password);
    }

    public function requirementsFailed()
    {
        if (!MW_COMPOSER_SUPPORT) {
            return Yii::t('servers', 'The server type {type} requires your php version to be at least {version}!', array(
                '{type}'    => $this->serverType,
                '{version}' => 5.3,
            ));
        }
        return false;
    }

    public function getParamsArray(array $params = array())
    {
        $params['transport'] = self::TRANSPORT_SENDINBLUE_WEB_API;
        return parent::getParamsArray($params);
    }

    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->_initStatus = $this->status;
        $this->hostname    = 'web-api.sendinblue.com';
        $this->webhook     = (array)$this->getModelMetaData()->itemAt('webhook');
    }

    protected function afterFind()
    {
        $this->_initStatus = $this->status;
        $this->webhook     = (array)$this->getModelMetaData()->itemAt('webhook');
        parent::afterFind();
    }

    protected function beforeSave()
    {
        $this->getModelMetaData()->add('webhook', (array)$this->webhook);
        return parent::beforeSave();
    }

    protected function afterDelete()
    {
        if (!empty($this->webhook['id'])) {
            $this->getClient()->delete_webhook($this->webhook);
            $this->webhook = array();
        }
        parent::afterDelete();
    }

    protected function preCheckWebHook()
    {
        if (MW_IS_CLI || $this->isNewRecord || $this->_initStatus !== self::STATUS_INACTIVE) {
            return true;
        }

        try {
            
            if (!empty($this->webhook['id'])) {
                $response  = $this->getClient()->get_webhook($this->webhook);
                $webhookOK = false;
                if ($response['code'] == 'success' && isset($response['data'], $response['data']['url'])) {
                    if ($response['data']['url'] == $this->getDswhUrl()) {
                        $webhookOK = true;
                    }
                }
                if ($webhookOK) {
                    return true;
                }
                $this->getClient()->delete_webhook($this->webhook);
                $this->webhook = array();
            }
            
            $response = $this->getClient()->create_webhook(array(
                "url"         => $this->getDswhUrl(),
                "description" => "Notifications Webhook - DO NOT ALTER THIS IN ANY WAY!",
                "events"      => array("hard_bounce", "soft_bounce", "blocked", "spam", "invalid_email", "unsubscribed"),
                "is_plat"     => 0
            ));
            
            if ($response['code'] != 'success') {
                throw new Exception(print_r((array)$response, true));
            }
            
            $this->webhook = $response['data'];
            
        } catch (Exception $e) {
            $this->_preCheckError = $e->getMessage();
        }

        if ($this->_preCheckError) {
            return false;
        }

        return $this->save(false);
    }

    /**
     * @param array $params
     * @return array
     */
    public function getFormFieldsDefinition(array $params = array())
    {
        return parent::getFormFieldsDefinition(CMap::mergeArray(array(
            'username'                => null,
            'hostname'                => null,
            'port'                    => null,
            'protocol'                => null,
            'timeout'                 => null,
            'signing_enabled'         => null,
            'max_connection_messages' => null,
            'bounce_server_id'        => null,
            'force_sender'            => null,
        ), $params));
    }
}
