<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * FilterVarHelper
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.5.9
 */

class FilterVarHelper
{
    /**
     * FilterVarHelper::filter()
     *
     * @param string $variable
     * @param int $filters
     * @return bool
     */
    public static function filter($variable, $filter = FILTER_DEFAULT, $options = array())
    {
        return filter_var($variable, $filter, $options);
    }

    /**
     * FilterVarHelper::email()
     *
     * @param string $email
     * @return bool
     */
    public static function email($email)
    {
        static $validator;
        if ($validator === null) {
            $validator = new CEmailValidator();
        }
        return $validator->validateValue($email);
    }

    /**
     * FilterVarHelper::url()
     *
     * @param string $url
     * @return bool
     */
    public static function url($url)
    {
        // because it is not multibyte aware...
        // return self::filter($url, FILTER_VALIDATE_URL);
        return (bool)preg_match('/^https?.*/i', $url);
    }

    /**
     * FilterVarHelper::ip()
     *
     * @param string $ip
     * @return bool
     */
    public static function ip($ip)
    {
        if (strpos($ip, '/') !== false) {
            $min = 0;
            $max = 32;
            
            // ipv6 
            if (substr_count($ip, ':') > 1) {
                $min = 1;
                $max = 128;
            }
            $ip = explode('/', $ip, 2);
            
            if ((int)$ip[1] < $min || (int)$ip[1] > $max) {
                return false;
            }
            $ip = array_shift($ip);
        }
        return self::filter($ip, FILTER_VALIDATE_IP);
    }
}
