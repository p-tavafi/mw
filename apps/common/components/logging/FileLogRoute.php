<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * FileLogRoute
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.9.3
 */

class FileLogRoute extends CFileLogRoute 
{
    /**
     * Formats a log message given different fields.
     * @param string $message message content
     * @param integer $level message level
     * @param string $category message category
     * @param integer $time timestamp
     * @return string formatted message
     */
    protected function formatLogMessage($message, $level, $category, $time)
    {
        if (!MW_IS_CLI) {
            $ip = Yii::app()->request->getUserHostAddress();
            return @date('Y/m/d H:i:s', $time)." [$level] [$category] [$ip] $message\n";
        }
        return @date('Y/m/d H:i:s', $time)." [$level] [$category] $message\n";
    }
}