<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * DeliveryServerElasticemailWebApi
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.5
 *
 */

class DeliveryServerElasticemailWebApi extends DeliveryServer
{
    protected $serverType = 'elasticemail-web-api';

    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        $rules = array(
            array('username, password', 'required'),
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
            'username' => Yii::t('servers', 'Your elastic email account username/email.'),
            'password' => Yii::t('servers', 'One of your elastic email api keys.'),
        );

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    public function attributePlaceholders()
    {
        $placeholders = array(
            'username'  => Yii::t('servers', 'Username'),
            'password'  => Yii::t('servers', 'Api key'),
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

        list($fromEmail, $fromName) = $this->getMailer()->findEmailAndName($params['from']);
        list($toEmail, $toName)     = $this->getMailer()->findEmailAndName($params['to']);

        if (!empty($params['fromName'])) {
            $fromName = $params['fromName'];
        }

        $replyToEmail = $replyToName = null;
        if (!empty($params['replyTo'])) {
            list($replyToEmail, $replyToName) = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $sent = false;

        try {
            $postData = array(
                'username'      => $this->username,
                'api_key'       => $this->password,
                'from'          => !empty($fromEmail) ? $fromEmail : $this->from_email,
                'from_name'     => !empty($fromName) ? $fromName : $this->from_name,
                'sender'        => !empty($fromEmail) ? $fromEmail : $this->from_email,
                'sender_name'   => !empty($fromName) ? $fromName : $this->from_name,
                'reply_to'      => !empty($replyToEmail) ? $replyToEmail : $this->from_email,
                'reply_to_name' => !empty($replyToName) ? $replyToName : $this->from_name,
                'to'            => sprintf('"%s" <%s>', $toName, $toEmail),
                'subject'       => $params['subject'],
                'body_html'     => !empty($params['body']) ? $params['body'] : null,
                'body_text'     => !empty($params['plainText']) ? $params['plainText'] : CampaignHelper::htmlToText($params['body']),
                'encodingtype'  => 4, // was 3
            );

            $headers = array();
            if (!empty($params['headers'])) {
                $headers = $this->parseHeadersIntoKeyValue($params['headers']);
                $i = 0;
                foreach ($headers as $name => $value) {
                    $i++;
                    $postData['header' . $i] = sprintf('%s: %s', $name, $value);
                }
            }

            // attachments
            $onlyPlainText = !empty($params['onlyPlainText']) && $params['onlyPlainText'] === true;
            if (!$onlyPlainText && !empty($params['attachments']) && is_array($params['attachments'])) {
                $attachments  = array();
                $campaign     = null;
                $headerPrefix = Yii::app()->params['email.custom.header.prefix'];
                if (!empty($headers) && !empty($headers[$headerPrefix . 'Campaign-Uid'])) {
                    $campaign = Campaign::model()->findByAttributes(array('campaign_uid' => $headers[$headerPrefix . 'Campaign-Uid']));
                    if (!empty($campaign) && !$campaign->getIsDraft()) {
                        $attachments = Yii::app()->options->get(sprintf('customer.campaigns.tmp_attachments.%s', $campaign->campaign_uid), array());
                    }
                }
                if (empty($attachments)) {
                    $_attachments = array_unique($params['attachments']);
                    foreach ($_attachments as $attachment) {
                        if (is_file($attachment)) {
                            $upload = $this->uploadAttachment($attachment);
                            if (!empty($upload['attachId'])) {
                                $attachments[] = $upload['attachId'];
                            }
                        }
                    }
                    if (!empty($campaign) && !$campaign->getIsDraft()) {
                        Yii::app()->options->set(sprintf('customer.campaigns.tmp_attachments.%s', $campaign->campaign_uid), $attachments);
                    }
                }
                if (!empty($attachments)) {
                    $postData['attachments'] = implode(';', $attachments);
                }
            }
            if ($onlyPlainText) {
                unset($postData['body_html']);
            }
            $response = AppInitHelper::simpleCurlPost('https://api.elasticemail.com/mailer/send', $postData, (int)$this->timeout);

            if ($response['status'] != 'success' || strpos($response['message'], '-') === false) {
                throw new Exception(Yii::app()->ioFilter->stripClean($response['message']));
            }

            $this->getMailer()->addLog('OK');
            $sent = array('message_id' => trim($response['message']));
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
        $params['transport'] = self::TRANSPORT_ELASTICEMAIL_WEB_API;
        return parent::getParamsArray($params);
    }

    public function requirementsFailed()
    {
        return false;
    }

    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->hostname = 'web-api.elasticemail.com';
    }

    // taken directly from http://elasticemail.com/api-documentation/attachments-upload
    // and added extra checks
    protected function uploadAttachment($filePath) 
    {
        static $functionsOk = null;
        if ($functionsOk === null) {
            $functionsOk = true;
            $functions = array('fputs', 'feof', 'fsockopen');
            foreach ($functions as $function) {
                if (!CommonHelper::functionExists($function)) {
                    $functionsOk = false;
                    break;
                }
            }
        }
        if ($functionsOk !== true) {
            return array(
                'status'  => false,
                'error'   => Yii::t('servers', 'Missing one of the following functions: {functions}', array('{functions}' => 'fputs, feof, fsockopen')),
                'result'  => '',
                'attachId'=> null,
            );
        }
        $data = http_build_query(array('username' => $this->username, 'api_key' => $this->password, 'file' => basename($filePath)), '', '&');
        $file = file_get_contents($filePath);
        $result = '';

        $fp = @fsockopen('ssl://api.elasticemail.com', 443, $errno, $errstr, 30);

        if ($fp){
            fputs($fp, "PUT /attachments/upload?" . $data . " HTTP/1.1\r\n");
            fputs($fp, "Host: api.elasticemail.com\r\n");
            fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
            fputs($fp, "Content-length: ". strlen($file) ."\r\n");
            fputs($fp, "Connection: close\r\n\r\n");
            fputs($fp, $file);
            while(!feof($fp)) {
                $result .= fgets($fp, 128);
            }
        } else {
            return array(
                'status'  => false,
                'error'   => $errstr.'('.$errno.')',
                'result'  => $result,
                'attachId'=> null,
            );
        }
        fclose($fp);
        $_result = explode("\r\n\r\n", $result, 2);

        return array(
            'status'   => true,
            'error'    => null,
            'result'   => $result,
            'attachId' => isset($_result[1]) ? $_result[1] : ''
        );
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
