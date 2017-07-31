<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * DeleteInactiveSubscribersCommand
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.7.1
 */

class DeleteInactiveSubscribersCommand extends ConsoleCommand
{
    public function actionIndex($list_uid, $time, $limit = 1000)
    {
        if (empty($list_uid)) {
            $this->stdout('Please set the list UID by using the --list_uid flag!');
            return 0;
        }
        
        if (empty($time)) {
            $this->stdout('Please set the time using the --time flag!');
            return 0;
        }
        
        $list = Lists::model()->findByAttributes(array(
            'list_uid' => $list_uid,
        ));
        
        if (empty($list)) {
            $this->stdout('We cannot find the source list by it\'s UID!');
            return 0;
        }

        $count = $inactive = $success = $error = 0;
        $criteria = new CDbCriteria();
        $criteria->compare('t.list_id', $list->list_id);
        $criteria->compare('t.status', ListSubscriber::STATUS_CONFIRMED);
        $criteria->compare('DATE(t.date_added)', '<=' . date('Y-m-d', strtotime($time)));
        $criteria->limit = (int)$limit;

        $subscribersNotIn = array();
        $subscribers      = ListSubscriber::model()->findAll($criteria);
        while (!empty($subscribers)) {
            
            foreach ($subscribers as $subscriber) {

                $count++;

                $this->stdout(sprintf('Checking: "%s"...', $subscriber->email));

                if (!$subscriber->getIsInactiveInTimePeriod($time)) {

                    $subscribersNotIn[] = $subscriber->subscriber_id;
                    $this->stdout(sprintf('"%s" is active for the given period of time.', $subscriber->email));
                    continue;

                }

                $inactive++;

                if ($subscriber->delete()) {
                    $success++;
                    $this->stdout(sprintf('[SUCCESS] "%s" has been deleted!', $subscriber->email));
                } else {
                    $error++;
                    $this->stdout(sprintf('[FAIL] "%s" could not be deleted!', $subscriber->email));
                }
            }

            $_criteria = clone $criteria;
            if (!empty($subscribersNotIn)) {
                $subscribersNotIn = array_unique($subscribersNotIn);
                $_criteria->addNotInCondition('subscriber_id', $subscribersNotIn);
            }
            $subscribers = ListSubscriber::model()->findAll($_criteria);
        }

        $this->stdout(sprintf('Done processing %d subscribers out of which %d were inactive from which %d were deleted successfully and %d had errors!', $count, $inactive, $success, $error));
        return 0;
    }
    
    public function getHelp()
    {
        $cmd = $this->getCommandRunner()->getScriptName() .' '. $this->getName();
        
        $help  = sprintf('command: %s --list_uid=LIST_UID --time=EXPRESSION --limit=1000', $cmd) . "\n";
        $help .= '--list_uid=UID where UID is the list unique 13 chars id from where you want to delete subscribers.' . "\n";
        $help .= '--time=EXPRESSION where EXPRESSION can be any expression parsable by php\'s strtotime function. ie: --time="-6 months".' . "\n";
        $help .= '--limit=1000 where 1000 is the number of subscribers to process at once.' . "\n";
        
        return $help;
    }
}