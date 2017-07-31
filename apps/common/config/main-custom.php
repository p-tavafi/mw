<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * Custom application main configuration file
 * 
 * This file can be used to overload config/components/etc
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2014 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.1
 */
    
return array(

    // application components
    'components' => array(
        'db' => array(
            'connectionString'  => 'mysql:host=localhost;dbname=webhouse_m41l',
            'username'          => 'root',
            'password'          => '',
            'tablePrefix'       => 'mw_',
        ),
    ),
    // params
    'params' => array(
        'email.custom.header.prefix' => 'X-Bgbj-'
    ),
);