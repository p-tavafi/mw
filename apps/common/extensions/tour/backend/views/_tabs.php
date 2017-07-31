<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.0
 */

$controller = $this->getId();
$action     = $this->getAction()->getId();
?>
<ul class="nav nav-tabs" style="border-bottom: 0px;">
    <li class="<?php echo $controller == 'ext_tour_settings' ? 'active' : 'inactive';?>">
        <a href="<?php echo $this->createUrl('ext_tour_settings/index')?>">
            <?php echo $this->extension->t('Common');?>
        </a>
    </li>
    <li class="<?php echo stripos($controller, 'ext_tour_slideshow') === 0 ? 'active' : 'inactive';?>">
        <a href="<?php echo $this->createUrl('ext_tour_slideshows/index')?>">
            <?php echo $this->extension->t('Slideshows');?>
        </a>
    </li>
</ul>
