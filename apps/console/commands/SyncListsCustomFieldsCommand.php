<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * SyncListCustomFieldsCommand
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.8.8
 */
 
class SyncListsCustomFieldsCommand extends ConsoleCommand 
{
    
    public function actionIndex()
    {
        try {

            $this->stdout('Loading all lists...');

            // load all lists at once
            $db    = Yii::app()->getDb();
            $sql   = 'SELECT list_id FROM {{list}} WHERE `status` = "active"';
            $lists = $db->createCommand($sql)->queryAll();
            
            foreach ($lists as $list) {

                $this->stdout('Processing list id: ' . $list['list_id']);
                
                $cacheKey = sha1('system.cron.process_subscribers.sync_custom_fields_values.list_id.' . $list['list_id']);
                $cachedFieldsIds = Yii::app()->cache->get($cacheKey);
                $cachedFieldsIds = empty($cachedFieldsIds) || !is_array($cachedFieldsIds) ? array() : $cachedFieldsIds;
                
                // load all custom fields for the given list
                $this->stdout('Loading all custom fields for this list...');
                $sql    = 'SELECT field_id, default_value FROM {{list_field}} WHERE list_id = :lid';
                $fields = $db->createCommand($sql)->queryAll(true, array(':lid' => $list['list_id']));
                
                // for cache check
                $invalidateCache = false;
                $fieldsIds       = array();
                foreach ($fields as $field) {
                    $fieldsIds[] = $field['field_id'];
                    // new field added, invalidate everything
                    if (!in_array($field['field_id'], $cachedFieldsIds)) {
                        $invalidateCache = true;
                    }
                }
                
                // nothing has changed in the fields, we can stop
                if (!$invalidateCache) {
                    $this->stdout('No change detected in the custom fields for this list, we can continue with next list!');
                    continue;
                }
                
                // load 500 subscribers at once and find out if they have the right custom fields or not
                $this->stdout('Loading initial subscribers set for the list...');
                $limit       = 1000;
                $offset      = 0;
                $sql         = 'SELECT subscriber_id FROM {{list_subscriber}} WHERE list_id = :lid ORDER BY subscriber_id ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
                $subscribers = $db->createCommand($sql)->queryAll(true, array(':lid' => (int)$list['list_id']));

                $this->stdout('Entering subscribers loop...');
                while (!empty($subscribers)) {

                    // keep a reference of all subscribers ids
                    $sids = array();
                    foreach ($subscribers as $sub) {
                        $sids[] = $sub['subscriber_id'];
                    }

                    // load all custom fields values for existing subscribers
                    $this->stdout('Selecting fields values for subscribers...');
                    $sql = 'SELECT field_id, subscriber_id FROM {{list_field_value}} WHERE subscriber_id IN(' . implode(',', $sids) . ')';
                    $fieldsValues = $db->createCommand($sql)->queryAll();

                    // populate this to have the defaults set so we can diff them later
                    $fieldSubscribers = array();
                    foreach ($fields as $field) {
                        $fieldSubscribers[$field['field_id']] = array();
                    }

                    // we have set the defaults abive, we now just have to add to the array
                    foreach ($fieldsValues as $fieldValue) {
                        $fieldSubscribers[$fieldValue['field_id']][] = $fieldValue['subscriber_id'];
                    }
                    $fieldsValues = null;

                    foreach ($fieldSubscribers as $fieldId => $subscribers) {

                        // exclude $subscribers from $sids
                        $subscribers  = array_diff($sids, $subscribers);

                        if (!count($subscribers)) {
                            $this->stdout('Nothing to do...');
                            continue;
                        }

                        $this->stdout('Field id ' . $fieldId . ' is missing ' . count($subscribers) . ' subscribers data, adding it...');

                        $fieldValue   = '';
                        foreach ($fields as $field) {
                            if ($field['field_id'] == $fieldId) {
                                $fieldValue = $field['default_value'];
                                break;
                            }
                        }

                        $inserts = array();
                        foreach ($subscribers as $subscriberId) {
                            $inserts[] = array(
                                'field_id'      => $fieldId,
                                'subscriber_id' => $subscriberId,
                                'value'         => !empty($fieldValue) ? $fieldValue : '',
                                'date_added'    => new CDbExpression('NOW()'),
                                'last_updated'  => new CDbExpression('NOW()'),
                            );
                        }

                        $inserts = array_chunk($inserts, 100);
                        foreach ($inserts as $insert) {
                            $connection = $db->getSchema()->getCommandBuilder();
                            $command = $connection->createMultipleInsertCommand('{{list_field_value}}', $insert);
                            $command->execute();

                            $this->stdout('Inserted ' . count($insert) . ' rows for the value: ' . $fieldValue);
                        }
                        $inserts = null;
                    }

                    $this->stdout('Batch is done...');
                    $fieldSubscribers = null;

                    $offset      = $offset + $limit;
                    $sql         = 'SELECT subscriber_id FROM {{list_subscriber}} WHERE list_id = :lid ORDER BY subscriber_id ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
                    $subscribers = $db->createCommand($sql)->queryAll(true, array(':lid' => (int)$list['list_id']));

                    if (!empty($subscribers)) {
                        $this->stdout('Processing ' . count($subscribers) . ' more subscribers...');
                    }
                }

                // set the new cached ids
                Yii::app()->cache->set($cacheKey, $fieldsIds);
                
                // and ... done
                $this->stdout('Done, no more subscribers for this list!');
            }

            $this->stdout('Done!');
            
        } catch (Exception $e) {

            Yii::log($e->getMessage(), CLogger::LEVEL_ERROR);
            
        }

        return 0;
    }
}
