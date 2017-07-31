<?php defined('MW_PATH') || exit('No direct script access allowed');

/**
 * CampaignQueueTableBehavior
 * 
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2017 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.3.7.9
 * 
 */
 
class CampaignQueueTableBehavior extends CActiveRecordBehavior
{
    // cache 
    protected static $_tablesIndex = array();
    
    /**
     * @inheritdoc
     */
    public function afterDelete($event)
    {
        parent::afterDelete($event);
        
        // make sure we remove the table in case it remains there
        if ($this->owner->getIsPendingDelete()) {
            $this->dropTable();
        }
    }
    
    /**
     * @return string
     */
    public function getTableName()
    {
        return '{{campaign_queue_' . (int)$this->owner->campaign_id . '}}';
    }

    /**
     * @return bool
     */
    public function tableExists()
    {
        // check from cache
        $tableName = $this->getTableName();
        if (!empty(self::$_tablesIndex[$tableName])) {
            return true;
        }
        
        $rows = Yii::app()->db->createCommand('SHOW TABLES LIKE "'. $tableName .'"')->queryAll();
        
        // make sure we add into cache
        return self::$_tablesIndex[$tableName] = (count($rows) > 0);
    }

    /**
     * @return bool
     */
    public function createTable()
    {
        if ($this->tableExists()) {
            return false;
        }
        
        $db         = Yii::app()->db;
        $owner      = $this->owner;
        $schema     = $db->schema;
        $tableName  = $this->getTableName();
        $campaignId = $owner->campaign_id;
        
        if ($owner->isAutoresponder) {

            $db->createCommand($schema->createTable($tableName, array(
                'subscriber_id' => 'INT(11) NOT NULL UNIQUE',
                'send_at'       => 'DATETIME NOT NULL',
            )))->execute();
            
            $key = $schema->createIndex('subscriber_id_send_at_' . $campaignId, $tableName, array('subscriber_id', 'send_at'));
            $db->createCommand($key)->execute();
            
        } else {

            $db->createCommand($schema->createTable($tableName, array(
                'subscriber_id' => 'INT(11) NOT NULL UNIQUE',
                'failures'      => 'INT(11) NOT NULL DEFAULT 0',
            )))->execute();
            
        }
        
        $fk = $schema->addForeignKey('subscriber_id_fk_' . $campaignId, $tableName, 'subscriber_id', '{{list_subscriber}}', 'subscriber_id', 'CASCADE', 'NO ACTION');
        $db->createCommand($fk)->execute();
        
        // mark as created
        self::$_tablesIndex[$tableName] = true;
        
        return true;
    }
    
    /**
     * @return bool
     */
    public function dropTable()
    {
        if (!$this->tableExists()) {
            return false;
        }
        
        $db         = Yii::app()->db;
        $owner      = $this->owner;
        $schema     = $db->schema;
        $tableName  = $this->getTableName();
        $campaignId = $owner->campaign_id;
        
        $db->createCommand()->delete($tableName);
        
        if ($owner->isAutoresponder) {
            $db->createCommand($schema->dropIndex('subscriber_id_send_at_' . $campaignId, $tableName))->execute();
        }

        $db->createCommand($schema->dropForeignKey('subscriber_id_fk_' . $campaignId, $tableName))->execute();
        $db->createCommand($schema->dropTable($tableName))->execute();
        
        // remove from cache
        if (array_key_exists($tableName, self::$_tablesIndex)) {
            unset(self::$_tablesIndex[$tableName]);
        }
        
        return true;
    }

    /**
     * @return bool
     */
    public function populateTable()
    {
        if ($this->tableExists()) {
            return false;
        }

        // make sure the table is created
        $this->createTable();
        
        // if AR no need to copy the subscribers over
        if ($this->owner->isAutoresponder) {
            return true;
        }
        
        $offset    = 0;
        $limit     = 500;
        $count     = 0;
        $max       = 0;
        $subsCache = array();
        
        $db        = Yii::app()->db;
        $owner     = $this->owner;
        $schema    = $db->getSchema();
        $tableName = $this->getTableName();

        $criteria = new CDbCriteria();
        $criteria->select = 't.subscriber_id';
        
        if ($owner->option->canSetMaxSendCount) {
            $max = $owner->option->max_send_count;
            if ($owner->option->canSetMaxSendCountRandom) {
                $criteria->order = 'RAND()';
            }
        }
        
        try {
            
            $subscribers = $owner->findSubscribers($offset, $limit, $criteria);
            
            while (!empty($subscribers)) {
                
                $insert = array();
                
                foreach ($subscribers as $subscriber) {
                    
                    if (!isset($subsCache[$subscriber->subscriber_id])) {
                        
                        $insert[] = array('subscriber_id' => $subscriber->subscriber_id);
                        $subsCache[$subscriber->subscriber_id] = true;
                        $count++;
                        
                    }
                    
                    if ($max > 0 && $count >= $max) {
                        break;
                    }
                }
                
                if (!empty($insert)) {
                    $schema->getCommandBuilder()->createMultipleInsertCommand($tableName, $insert)->execute();
                }
                
                if ($max > 0 && $count >= $max) {
                    break;
                }
                
                $offset      = $offset + $limit;
                $subscribers = $owner->findSubscribers($offset, $limit, $criteria);
            }
            
            unset($subscribers, $subsCache);
        
        } catch (Exception $e) {
            
            Yii::log($e->getMessage(), CLogger::LEVEL_ERROR);
            
            $this->dropTable();
            
        }
        
        return true;
    }

    /**
     * @param array $data
     * @param array $params
     * @return int
     */
    public function addSubscriber(array $data = array(), array $params = array())
    {
        // make sure the table is created
        $this->createTable();
        
        return Yii::app()->db->createCommand()->insert($this->getTableName(), $data, $params);
    }

    /**
     * @param int $subscriberId
     * @return int
     */
    public function deleteSubscriber($subscriberId)
    {
        // make sure the table is created
        $this->createTable();
        
        return Yii::app()->db->createCommand()->delete($this->getTableName(), 'subscriber_id = :sid', array(
            ':sid' => (int)$subscriberId,
        ));
    }
    
    /**
     * @return int
     */
    public function countSubscribers()
    {
        // make sure the table is created
        $this->createTable();
        
        $db        = Yii::app()->db;
        $owner     = $this->owner;
        $tableName = $this->getTableName();

        $query = $db->createCommand()->select('count(*) as cnt')->from($tableName);
        
        if ($owner->isAutoresponder) {
            $query->where('send_at <= NOW()');
        }
        
        $row = $query->queryRow();
        
        return (int)$row['cnt'];
    }

    /**
     * @param $offset
     * @param $limit
     * @return array
     */
    public function findSubscribers($offset, $limit)
    {
        // make sure the table is created
        $this->createTable();
        
        $db        = Yii::app()->db;
        $owner     = $this->owner;
        $tableName = $this->getTableName();
        
        $query = $db->createCommand()->select('subscriber_id')->from($tableName);
        
        if ($owner->isAutoresponder) {
            $query->where('send_at <= NOW()');
        }
        
        $query->offset($offset)->limit($limit);
        
        $rows        = $query->queryAll();
        $chunks      = array_chunk($rows, 300);
        $subscribers = array();
        
        foreach ($chunks as $chunk) {
            $ids = array();
            foreach ($chunk as $row) {
                $ids[] = $row['subscriber_id'];
            }
            
            $criteria = new CDbCriteria();
            $criteria->addInCondition('subscriber_id', $ids);
            $models = ListSubscriber::model()->findAll($criteria);
            
            foreach ($models as $model) {
                $subscribers[] = $model;
            }
        }
        
        // 1.4.0
        foreach ($subscribers as $index => $subscriber) {
            if ($subscriber->status == ListSubscriber::STATUS_CONFIRMED) {
                continue;
            }
            try {
                $this->deleteSubscriber($subscriber->subscriber_id);
            } catch (Exception $e) {
                
            }
            unset($subscribers[$index]);
        }
        $subscribers = array_values($subscribers);
        //
        
        return $subscribers;
    }
}