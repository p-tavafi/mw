<?php

/**
 * Console application bootstrap file
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.0
 */

// make sure we have enough time and memory.
ini_set('memory_limit', -1);
ini_set('max_execution_time', 0);
set_time_limit(0);

// for some fcgi installs
if (empty($_SERVER['SCRIPT_FILENAME'])) {
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
}

// define the type of application we are creating.
define('MW_APP_NAME', 'console');

// and start an instance of it.
require_once(dirname(__FILE__) . '/../init.php');
