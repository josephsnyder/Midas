<?php
/*=========================================================================
 MIDAS Server
 Copyright (c) Kitware SAS. 26 rue Louis Guérin. 69100 Villeurbanne, FRANCE
 All rights reserved.
 More information http://www.kitware.com

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

         http://www.apache.org/licenses/LICENSE-2.0.txt

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
=========================================================================*/

require_once BASE_PATH.'/modules/tracker/models/base/TrendModelBase.php';

/**
 * Trend model for the tracker module.
 *
 * @package Modules\Tracker\Model
 */
class Tracker_TrendModel extends Tracker_TrendModelBase
{
    /**
     * Return the trend DAO that matches the given the producer id, metric name, and associated items.
     *
     * @param int $producerId producer id
     * @param string $metricName metric name
     * @param null|int $configItemId configuration item id
     * @param null|int $testDatasetId test dataset item id
     * @param null|int $truthDatasetId truth dataset item id
     * @return false|Tracker_TrendDao trend DAO or false if none exists
     */
    public function getMatch($producerId, $metricName, $configItemId, $testDatasetId, $truthDatasetId)
    {
        $sql = $this->database->select()->setIntegrityCheck(false)->where('producer_id = ?', $producerId)->where(
            'metric_name = ?',
            $metricName
        );

        if ($configItemId == null) {
            $sql->where('config_item_id IS NULL');
        } else {
            $sql->where('config_item_id = ?', $configItemId);
        }

        if ($truthDatasetId == null) {
            $sql->where('truth_dataset_id IS NULL');
        } else {
            $sql->where('truth_dataset_id = ?', $truthDatasetId);
        }

        if ($testDatasetId == null) {
            $sql->where('test_dataset_id IS NULL');
        } else {
            $sql->where('test_dataset_id = ?', $testDatasetId);
        }

        return $this->initDao('Trend', $this->database->fetchRow($sql), $this->moduleName);
    }

    /**
     * Return a chronologically ordered list of scalars for the given trend.
     *
     * @param Tracker_TrendDao $trend trend DAO
     * @param null|string $startDate start date
     * @param null|string $endDate end date
     * @param null|int $userId user id
     * @param null|string $branch branch name
     * @return array scalar DAOs
     */
    public function getScalars($trend, $startDate = null, $endDate = null, $userId = null, $branch = null)
    {
        $sql = $this->database->select()->setIntegrityCheck(false)->from('tracker_scalar')->where(
            'trend_id = ?',
            $trend->getKey()
        )->order(array('submit_time ASC'));
        if ($startDate) {
            $sql->where('submit_time >= ?', $startDate);
        }
        if ($endDate) {
            $sql->where('submit_time <= ?', $endDate);
        }
        if ($branch !== null) {
            $sql->where('branch = ?', $branch);
        }
        $scalars = array();
        $rowset = $this->database->fetchAll($sql);

        /** @var Zend_Db_Table_Row_Abstract $row */
        foreach ($rowset as $row) {
            $scalars[] = $this->initDao('Scalar', $row, $this->moduleName);
        }

        return $scalars;
    }

    /**
     * Return all trends corresponding to the given producer. They will be grouped by distinct
     * config/test/truth dataset combinations.
     *
     * @param Tracker_ProducerDao $producerDao producer DAO
     * @return array array of associative arrays with keys "configItem", "testDataset", "truthDataset", and "trends"
     */
    public function getTrendsGroupByDatasets($producerDao)
    {
        $sql = $this->database->select()->setIntegrityCheck(false)->from(
            $this->_name,
            array('config_item_id', 'test_dataset_id', 'truth_dataset_id')
        )->where('producer_id = ?', $producerDao->getKey())->distinct();

        /** @var ItemModel $itemModel */
        $itemModel = MidasLoader::loadModel('Item');
        $results = array();
        $rows = $this->database->fetchAll($sql);

        /** @var Zend_Db_Table_Row_Abstract $row */
        foreach ($rows as $row) {
            $configItem = $row['config_item_id'] == null ? null : $itemModel->load($row['config_item_id']);
            $testDataset = $row['test_dataset_id'] == null ? null : $itemModel->load($row['test_dataset_id']);
            $truthDataset = $row['truth_dataset_id'] == null ? null : $itemModel->load($row['truth_dataset_id']);
            $result = array(
                'configItem' => $configItem,
                'testDataset' => $testDataset,
                'truthDataset' => $truthDataset,
            );
            $result['trends'] = $this->getAllByParams(
                array(
                    'producer_id' => $producerDao->getKey(),
                    'config_item_id' => $row['config_item_id'],
                    'test_dataset_id' => $row['test_dataset_id'],
                    'truth_dataset_id' => $row['truth_dataset_id'],
                )
            );
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Return the trend DAOs that match the given associative array of database columns and values.
     *
     * @param array $params associative array of database columns and values
     * @return array trend DAOs
     */
    public function getAllByParams($params)
    {
        $sql = $this->database->select()->setIntegrityCheck(false);
        foreach ($params as $column => $value) {
            if ($value === null) {
                $sql->where($column.' IS NULL');
            } else {
                $sql->where($column.' = ?', $value);
            }
        }
        $sql->order('display_name ASC');
        $rows = $this->database->fetchAll($sql);
        $trends = array();

        /** @var Zend_Db_Table_Row_Abstract $row */
        foreach ($rows as $row) {
            $trends[] = $this->initDao('Trend', $row, $this->moduleName);
        }

        return $trends;
    }
}
