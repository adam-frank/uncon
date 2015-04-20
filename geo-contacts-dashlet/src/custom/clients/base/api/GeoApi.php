<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}
/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/06_Customer_Center/10_Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */

require_once('data/BeanFactory.php');

class GeoApi extends SugarApi
{

    /** @var RelateRecordApi */
    protected $relateRecordApi;

    public function registerApiRest()
    {
        return array(
            'near' => array(
                'reqType' => 'GET',
                'path' => array('near', '?'),
                'pathVars' => array('', 'location'),
                'method' => 'findNear',
                'shortHelp' => 'Globally find records near a location',
            ),
            'moduleNearLocation' => array(
                'reqType' => 'GET',
                'path' => array('<module>', 'near', '?'),
                'pathVars' => array('module', '', 'location'),
                'method' => 'findNear',
                'shortHelp' => 'Find records of a specific type near a location',
            ),
        );
    }

    /**
     * Creates new record of the given module and returns its formatted representation
     *
     * @param ServiceBase $api
     * @param array       $args API arguments
     *
     * @return array Formatted representation of the bean
     * @throws SugarApiExceptionInvalidParameter
     * @throws SugarApiExceptionMissingParameter
     * @throws SugarApiExceptionNotAuthorized
     */
    public function findNear(ServiceBase $api, array $args)
    {
        if (empty($args['location']) || empty($args['lat_long'])) {
            throw new SugarApiExceptionMissingParameter("Missing location");
        }

        $modules = !empty($args['module']) ? array($args['module']) : $this->getValidModules();
        // Load global search engine
        $engine = $this->getEngine();
        $iName = $engine->getReadIndexName($modules);
        $client = $engine->getClient();
        $index = $client->getIndex($iName);
        $query = new Elastica\Query\MatchAll();
        $search = new Elastica\Search($client);
        $search->addIndex($index);
        foreach($modules as $module) {
            $search->addType($module);
        }
        if (!empty($args['distance'])) {
            $filter = new Elastica\Filter\GeoDistance('lat_long_c', $args['location'], $args['distance']);
            $query = new Elastica\Query\Filtered($query, $filter);
        }

        //Add sort
        $query = Elastica\Query::create($query);
        $query->addSort(array(
            '_geo_distance' => array(
                "lat_long_c" => $args['location'],
                "order" => "asc",
                "unit" => "mi"
            )
        ));


        $results = $search->search($query)->getResults();
        $ret = array();
        foreach ($results as $result) {
            $dist = $result->getParam("sort");
            $module = $result->getType();
            $bean = $this->formatBeanFromResult($api, $args, $result, BeanFactory::getBean($module));
            $bean['_distance'] = $dist[0];
            $bean['_distance_unit'] = 'km';
            $ret[] = $bean;
        }

        return $ret;

    }

    protected function getValidModules(){
        global $beanList;
        $modules = array_keys($beanList);
        $ret = array();
        foreach ($modules as $module) {
            $seed = BeanFactory::getBean($module);
            if (!empty($seed->field_defs['lat_long_c'])) {
                $ret[] = $module;
            }
        }
        return $ret;
    }

    /**
     * Get global search provider
     *
     * @return SugarSearchEngineElastic $engine
     */
    protected function getEngine()
    {
        $searchEngine = SugarSearchEngineFactory::getInstance();
        if ($searchEngine instanceof SugarSearchEngineElastic) {
            return $searchEngine;
        }
    }

    /**
     * Wrapper around formatBean based on Result
     *
     * @param \RestService $api
     * @param Result       $result
     *
     * @return array
     */
    protected function formatBeanFromResult(\RestService $api, array $args, \Elastica\Result $result, SugarBean $bean)
    {
        // pass in field list from available data fields on result
        if (empty($args['fields'])) {
            $args['fields'] = array_keys($result->getSource());
        }
        $bean->retrieve($result->getId());

        return $this->formatBean($api, $args, $bean);
    }

}
