<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * DeliveryServerSendgridWebApi
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.4.9
 *
 */

class DeliveryServerSendgridWebApi extends DeliveryServer
{
    protected $serverType = 'sendgrid-web-api';

    protected $_initStatus;

    protected $_preCheckError;

    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        $rules = array(
            array('username, password', 'required'),
            array('username, password', 'length', 'max' => 255),
        );
        return CMap::mergeArray($rules, parent::rules());
    }

    public function attributeLabels()
    {
        $texts = array(
            'password'  => Yii::t('servers', 'Api key'),
        );

        return CMap::mergeArray(parent::attributeLabels(), $texts);
    }
    
    public function attributeHelpTexts()
    {
        $texts = array(
            'username'  => Yii::t('servers', 'Your sendgrid username.'),
            'password'  => Yii::t('servers', 'One of your sendgrid api key.'),
        );

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
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

        $replyToEmail = $replyToName = null;
        if (!empty($params['replyTo'])) {
            list($replyToEmail, $replyToName) = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $headerPrefix = Yii::app()->params['email.custom.header.prefix'];
        $headers = array();
        if (!empty($params['headers'])) {
            $headers = $this->parseHeadersIntoKeyValue($params['headers']);
        }
        $headers['Reply-To']    = !empty($replyToEmail) ? $replyToEmail : $fromEmail;
        $headers['X-Sender']    = $fromEmail;
        $headers['X-Receiver']  = $toEmail;
        $headers[$headerPrefix . 'Mailer'] = 'Sendgrid Web API';

        if (!isset($headers['Return-Path']) && !empty($params['returnPath'])) {
            list($returnPathEmail) = $this->getMailer()->findEmailAndName($params['returnPath']);
            $headers['Return-Path'] = $returnPathEmail;
        }

        $uniqueArgs = array();
        if (isset($headers[$headerPrefix . 'Campaign-Uid'])) {
            $uniqueArgs['campaign_uid'] = $headers[$headerPrefix . 'Campaign-Uid'];
        }
        if (isset($headers[$headerPrefix . 'Subscriber-Uid'])) {
            $uniqueArgs['subscriber_uid'] = $headers[$headerPrefix . 'Subscriber-Uid'];
        }

        $sent = false;

        try {
            if (!$this->preCheckWebHook()) {
                throw new Exception($this->_preCheckError);
            }

            $className = '\SendGrid\Email';
            $email = new $className();
            $email
                ->setFrom($fromEmail)
                ->setFromName($fromName)
                ->addTo(sprintf('=?%s?B?%s?= <%s>', strtolower(Yii::app()->charset), base64_encode($toName), $toEmail))
                ->setReplyTo($replyToEmail)
                ->setSubject($params['subject'])
                ->setText(!empty($params['plainText']) ? $params['plainText'] : CampaignHelper::htmlToText($params['body']))
                ->setHtml($params['body'])
                ->setUniqueArgs($uniqueArgs);

            foreach ($headers as $headerName => $headerValue) {
                $email->addHeader($headerName, $headerValue);
            }

            $onlyPlainText = !empty($params['onlyPlainText']) && $params['onlyPlainText'] === true;
            if (!$onlyPlainText && !empty($params['attachments']) && is_array($params['attachments'])) {
                $attachments = array_unique($params['attachments']);
                foreach ($attachments as $attachment) {
                    if (is_file($attachment)) {
                        $email->addAttachment($attachment);
                    }
                }
            }

            $result = $this->getClient()->send($email);
            if (!empty($result) && !empty($result->body['message']) && $result->body['message'] == 'success') {
                $this->getMailer()->addLog('OK');
                $sent = array('message_id' => StringHelper::random(60));
            } elseif (!empty($result) && !empty($result->body['message']) && $result->body['message'] != 'success') {
                throw new Exception(print_r((array)$result->body, true));
            } else {
                throw new Exception(Yii::t('servers', 'Unable to make the delivery!'));
            }

        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        Yii::app()->hooks->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return $sent;
    }

    public function getParamsArray(array $params = array())
    {
        $params['transport'] = self::TRANSPORT_SENDGRID_WEB_API;
        return parent::getParamsArray($params);
    }

    public function requirementsFailed()
    {
        if (!MW_COMPOSER_SUPPORT || !version_compare(PHP_VERSION, '5.3.3', '>=')) {
            return Yii::t('servers', 'The server type {type} requires your php version to be at least {version}!', array(
                '{type}'    => $this->serverType,
                '{version}' => '5.3.3',
            ));
        }
        return false;
    }

    public function getClient()
    {
        static $clients = array();
        $id = (int)$this->server_id;
        if (!empty($clients[$id])) {
            return $clients[$id];
        }
        $className = '\SendGrid';
        return $clients[$id] = new $className($this->password, array(
            'turn_off_ssl_verification' => true,
        ));
    }

    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->_initStatus = $this->status;
        $this->hostname    = 'web-api.sendgrid.com';
    }

    protected function afterFind()
    {
        $this->_initStatus = $this->status;
        parent::afterFind();
    }

    protected function preCheckWebHook()
    {
        if (MW_IS_CLI || $this->isNewRecord || $this->_initStatus !== self::STATUS_INACTIVE) {
            return true;
        }
        
        $postValues = array(
            'api_user'  => $this->username,
            'api_key'   => $this->password,
            'name'      => 'eventnotify',
            'processed' => 0,
            'dropped'   => 1,
            'deferred'  => 1,
            'delivered' => 0,
            'bounce'    => 1,
            'click'     => 0,
            'open'      => 0,
            'unsubscribe' => 0,
            'spamreport'=> 1,
            'url'       => $this->getDswhUrl(),
            'version'   => 3,
        );

        try {
            $className= '\Guzzle\Http\Client';
            $client   = new $className();
            $request  = $client->post('https://sendgrid.com/api/filter.setup.json', array(), $postValues);
            $response = $request->send();

            if ($response->getStatusCode() != 200) {
                throw new Exception($response->getBody());
            }
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
