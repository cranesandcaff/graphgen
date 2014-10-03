<?php

namespace Neoxygen\Neogen\Schema;

use Neoxygen\Neogen\Helper\CypherHelper;
use Faker\Factory;

class Processor
{
    private $labels = [];

    private $faker;

    private $queries = [];

    private $nodes = [];

    public function __construct()
    {
        $this->faker = Factory::create();
    }

    /**
     * Generate the queries for the creation of the nodes and relationships based on a schema file
     * It also add constraints for all node labels on the "neogen_id" property
     *
     *
     * @param array $schema
     */
    public function process(array $schema)
    {
        echo '------'."\n";
        $start = microtime(true);
        if (!isset($schema['nodes'])) {
            throw new \InvalidArgumentException('You need to define at least one node to generate');
        }
        $helper = new CypherHelper();
        $lap1 = microtime(true);
        $diff1 = $lap1 - $start;
        echo 'lap1 : '.$diff1."\n";

        foreach ($schema['nodes'] as $node) {
            if (!in_array($node['label'], $this->labels)) {
                $this->labels[] = $node['label'];
            }
            $count = isset($node['count']) ? $node['count'] : 1;
            $x = 1;
            while ($x <= $count) {
                $alias = $alias = str_replace('.', '', 'n' . microtime(true) . rand(0, 100000000000));
                $this->nodes[$node['label']][$alias] = $alias;
                $q = $helper->openMerge();
                $q .= $helper->addNodeLabel($alias, $node['label']);

                if (isset($node['properties'])) {
                    $c = count($node['properties']);
                    $i = 0;
                    $q .= $helper->openNodePropertiesBracket();
                    if ($c !== 0) {

                        foreach ($node['properties'] as $key => $type) {
                            if (is_array($type)) {
                                $value = call_user_func_array(array($this->faker, $type['type']), $type['params']);
                                if ($value instanceof \DateTime) {
                                    $value = $value->format('Y-m-d H:i:s');
                                }
                            } else {
                                $value = $this->faker->$type;
                            }
                            $q .= $helper->addNodeProperty($key, $value);
                            if ($i < $c - 1) {
                                $q .= ', ';
                            }
                            $i++;
                        }
                        $q .= ', ' . $helper->addNodeProperty('neogen_id', $alias);
                        $q .= $helper->closeNodePropertiesBracket();
                    }
                }


                $q .= $helper->closeMerge();
                $this->queries[] = $q;
                $x++;

            }
        }

        $lap2 = microtime(true);
        $diff2 = $lap2 - $lap1;
        echo 'lap 2 : '.$diff2."\n";

        foreach ($schema['relationships'] as $k => $rel) {
            $start = $rel['start'];
            $end = $rel['end'];
            $type = $rel['type'];
            $mode = $rel['mode'];
            $props = [];

            //preg_match('/([\\d]+|n)(\\.\\.)([\\d]+|n)/', $mode, $outputx);
            //if (isset($outputx[1]) && isset($output[3])) {
            //    $from = $outputx[1];
            //    $to = $outputx[3];
            //}


            if (!in_array($start, $this->labels) || !in_array($end, $this->labels)) {
                throw new \InvalidArgumentException('The start or end node of relationship ' . $k . ' is not defined');
            }

            switch ($mode) {
                case 'n..1':
                    foreach ($this->nodes[$start] as $node) {
                        if (isset($rel['properties'])) {
                            foreach ($rel['properties'] as $k => $t) {
                                if (is_array($t)) {
                                    $value = call_user_func_array(array($this->faker, $t['type']), $t['params']);
                                    if ($value instanceof \DateTime) {
                                        $value = $value->format('Y-m-d H:i:s');
                                    }
                                } else {
                                    $value = $this->faker->$t;
                                }
                                $props[$k] = $value;
                            }
                        }
                        $endNodes = $this->nodes[$end];
                        shuffle($endNodes);
                        $endNode = current($endNodes);
                        $this->queries[] = $helper->addRelationship($node, $endNode, $type, $props);

                    }
                    break;

                case 'n..n':
                    $endNodes = $this->nodes[$end];
                    $max = count($endNodes);
                    $pct = $max <= 20 ? 0.3 : 0.1;
                    $maxi = round($max * $pct);
                    $random = rand(1, $maxi);
                    foreach ($this->nodes[$start] as $node) {
                        for ($i = 1; $i <= $random; $i++) {
                            if (isset($rel['properties'])) {
                                foreach ($rel['properties'] as $k => $t) {
                                    if (is_array($t)) {
                                        $value = call_user_func_array(array($this->faker, $t['type']), $t['params']);
                                        if ($value instanceof \DateTime) {
                                            $value = $value->format('Y-m-d H:i:s');
                                        }
                                    } else {
                                        $value = $this->faker->$t;
                                    }
                                    $props[$k] = $value;
                                }
                            }
                            reset($endNodes);
                            shuffle($endNodes);
                            $endNode = current($endNodes);
                            next($endNodes);
                            if ($endNode !== $node) {
                                $this->queries[] = $helper->addRelationship($node, $endNode, $type, $props);
                            }

                        }
                    }
                    break;
                case '1..n':
                    foreach ($this->nodes[$end] as $node) {
                        if (isset($rel['properties'])) {
                            foreach ($rel['properties'] as $k => $t) {
                                if (is_array($t)) {
                                    $value = call_user_func_array(array($this->faker, $t['type']), $t['params']);
                                    if ($value instanceof \DateTime) {
                                        $value = $value->format('Y-m-d H:i:s');
                                    }
                                } else {
                                    $value = $this->faker->$t;
                                }
                                $props[$k] = $value;
                            }
                        }
                        $endNodes = $this->nodes[$start];
                        shuffle($endNodes);
                        $endNode = current($endNodes);
                        $this->queries[] = $helper->addRelationship($endNode, $node, $type, $props);

                    }
                    break;
            }
        }

        $lap3 = microtime(true);
        $diff3 = $lap3 - $lap2;
        echo 'lap 3 : '.$diff3."\n";
        echo '----------'."\n";
    }

    /**
     * Returns the constraints queries on the "neogen_id" property for all labels
     *
     * @return array
     */
    public function getConstraints()
    {
        $constraints = [];
        $calias = 'n'.sha1(microtime());
        foreach ($this->labels as $label) {
            $constraint = 'DROP CONSTRAINT ON ('.$calias.':'.$label.') ASSERT '.$calias.'.neogen_id IS UNIQUE; ';
            $constraint .= 'CREATE CONSTRAINT ON ('.$calias.':'.$label.') ASSERT '.$calias.'.neogen_id IS UNIQUE; ';
            $constraints[] = $constraint;
        }

        return $constraints;
    }

    /**
     * Return the queries to generate the nodes and the relationships to the database
     *
     * @return array
     */
    public function getQueries()
    {
        return $this->queries;
    }
}