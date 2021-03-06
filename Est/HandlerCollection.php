<?php

class Est_HandlerCollection implements Iterator {

    /**
     * @var array
     */
    protected $handlers = array();

    /**
     * @var array
     */
    protected $labels = array();


    /**
     * Build from settings csv file
     *
     * @param $csvFile
     * @param $environment
     * @param string $defaultEnvironment
     * @param array $includeGroups
     * @param array $excludeGroups
     * @throws Exception
     */
    public function buildFromSettingsCSVFile($csvFile, $environment, $defaultEnvironment='DEFAULT', array $includeGroups=array(), array $excludeGroups=array()) {
        if (!is_file($csvFile)) {
            throw new Exception('SettingsFile is not present here: "'.$csvFile.'"');
        }
        $fh = fopen($csvFile, 'r');

        // first line: labels
        $this->labels = fgetcsv($fh);
        if (!$this->labels) {
            throw new Exception('Error while reading labels from csv file');
        }

        while ($row = fgetcsv($fh)) {

            $rowGroups = $this->getGroupsForRow($row);

            if (count($includeGroups) && count(array_intersect($rowGroups, $includeGroups)) == 0) {
                // current row's groups do not match given include groups
                continue;
            }

            if (count($excludeGroups) && count(array_intersect($rowGroups, $excludeGroups)) > 0) {
                // current row's groups do match given exclude groups
                continue;
            }


            $handlerClassname = trim($row[0]);

            if (empty($handlerClassname) || $handlerClassname[0] == '#' || $handlerClassname[0] == '/') {
                // This is a comment line. Skipping...
                continue;
            }

            if (!class_exists($handlerClassname)) {
                throw new Exception(sprintf('Could not find handler class "%s"', $handlerClassname));
            }


            // resolve loops in param1, param2, param3 using {{...|...|...}}
            $values = array();
            for ($i=1; $i<=3; $i++) {
                $value = trim($row[$i]);

                $values[$i] = array($value);

                for ($loopCounter=0; $loopCounter<4; $loopCounter++) { // replace up to 4 {{...}} constructs in the same value
                    foreach ($values[$i] as $index => $originalValue) {
                        $matches = array();
                        if (preg_match('/{{(.*?)}}/', $originalValue, $matches)) {
                            $tmp = Est_Div::trimExplode('|', $matches[1], false);
                            unset($values[$i][$index]);
                            foreach ($tmp as $v) {
                                $values[$i][] = preg_replace('/{{(.*?)}}/', $v, $originalValue, 1);
                            }
                        }
                    }
                }
            }

            foreach ($values[1] as $param1) {
                foreach ($values[2] as $param2) {
                    foreach ($values[3] as $param3) {

                        $handler = new $handlerClassname(); /* @var $handler Est_Handler_Abstract */
                        if (!$handler instanceof Est_Handler_Abstract) {
                            throw new Exception(sprintf('Handler of class "%s" is not an instance of Est_Handler_Abstract', $handlerClassname));
                        }

                        $handler->setParam1($param1);
                        $handler->setParam2($param2);
                        $handler->setParam3($param3);

                        $value = $this->getValueFromRow(
                            $row,
                            $environment,
                            $defaultEnvironment,
                            $handler
                        );
                        if (strtolower(trim($value)) == '--empty--') {
                            $value = '';
                        }

                        // set value
                        $handler->setValue($value);

                        $handler->register();

                        $this->addHandler($handler);
                    }
                }
            }

        }
    }

    /**
     * Get tags for given row
     *
     * @param array $row
     * @return array
     */
    protected function getGroupsForRow(array $row) {
        $tagsColumnIndex = $this->getColumnIndexForEnvironment('GROUPS', true);
        return $tagsColumnIndex ? Est_Div::trimExplode(',', $row[$tagsColumnIndex]) : array();
    }

    /**
     * Get column index for environment
     *
     * @param $environment
     * @param bool $checkOnly if set false will be returned instead of an exception thrown
     * @throws Exception
     * @return mixed
     */
    protected function getColumnIndexForEnvironment($environment, $checkOnly=false) {
        $columnIndex = array_search($environment, $this->labels);
        if ($columnIndex === false) {
            if ($checkOnly) {
                return false;
            } else {
                throw new Exception('Could not find environment '.$environment.' in csv file');
            }
        }
        if ($columnIndex <= 3) { // those are reserved for handler class, param1-3
            if ($checkOnly) {
                return false;
            } else {
                throw new Exception('Environment cannot be defined in one of the first four columns');
            }
        }
        return $columnIndex;
    }

    /**
     * Get value from row
     *
     * @param array $row
     * @param string $environment
     * @param string $fallbackEnvironment
     * @param Est_Handler_Abstract $handler
     * @return string
     */
    private function getValueFromRow(array $row, $environment, $fallbackEnvironment, Est_Handler_Abstract $handler=null) {
        $value              = null;
        $defaultColumnIndex = $this->getColumnIndexForEnvironment($fallbackEnvironment, true);
        if (isset($row[$this->getColumnIndexForEnvironment($environment)])) {
            $value = $row[$this->getColumnIndexForEnvironment($environment)];
            if ($value == '--empty--') {
                $value = '';
            } elseif (preg_match('/###REF:([^#]*)###/', $value, $matches)) {
                $value = $this->getValueFromRow($row, $matches[1], $fallbackEnvironment);
            } elseif ($value == '') {
                if ($defaultColumnIndex !== FALSE) {
                    $value = $row[$defaultColumnIndex];
                }
            }
        } else {
            if ($defaultColumnIndex !== FALSE) {
                $value = $row[$defaultColumnIndex];
            }
        }

        if (!is_null($handler)) {
            $value = str_replace('###PARAM1###', $handler->getParam1(), $value);
            $value = str_replace('###PARAM2###', $handler->getParam2(), $value);
            $value = str_replace('###PARAM3###', $handler->getParam3(), $value);
        }

        while (preg_match('/###VAR:([^#]*)###/', $value, $matches)) {
            $var = Est_VariableStorage::get($matches[1]);
            if ($var === false) {
                throw new \Exception('Variable "' . $matches[1] . '" is not set');
            }
            $value = preg_replace('/###VAR:([^#]*)###/', $var, $value, 1);
        }

        while (preg_match('/###FILE:([^#]*)###/', $value, $matches)) {
            $fileName = $matches[1];
            if (!file_exists($fileName)) {
                throw new \Exception('File "' . $fileName . '" does not exist');
            }
            $content = trim(file_get_contents($fileName));
            $value = preg_replace('/###FILE:([^#]*)###/', $content, $value, 1);
        }

        $value = str_replace('###ENVIRONMENT###', $environment, $value);
        $value = str_replace('###CWD###', getcwd(), $value);

        return $this->replaceWithEnvironmentVariables($value);
    }

    /**
     * Replaces this pattern ###ENV:TEST### with the environment variable
     * @param $string
     * @return string
     * @throws \Exception
     */
    protected function replaceWithEnvironmentVariables($string) {
        $matches = array();
        preg_match_all('/###ENV:([^#]*)###/', $string, $matches, PREG_PATTERN_ORDER);
        if (!is_array($matches) || !is_array($matches[0])) {
            return $string;
        }
        foreach ($matches[0] as $index => $completeMatch) {
            if (getenv($matches[1][$index]) === false) {
                throw new \Exception('Expected an environment variable ' . $matches[1][$index] . ' is not set');
            }
            $string = str_replace($completeMatch, getenv($matches[1][$index]), $string);
        }

        return $string;
    }

    /**
     * @param Est_Handler_Interface $handler
     * @throws Exception
     */
    public function addHandler(Est_Handler_Interface $handler) {
        $hash = $this->getHandlerHash($handler);
        if (isset($this->handlers[$hash])) {
            throw new Exception('Handler with this specification already exists. Cannot add: ' . $handler->getLabel());
        }
        $this->handlers[$hash] = $handler;
    }

    /**
     * Get handler
     *
     * @param $handlerClassname
     * @param $p1
     * @param $p2
     * @param $p3
     * @return Est_Handler_Interface || bool
     */
    public function getHandler($handlerClassname, $p1, $p2, $p3) {
        if (isset($this->handlers[$this->getHandlerHashByValues($handlerClassname, $p1, $p2, $p3)])) {
            return $this->handlers[$this->getHandlerHashByValues($handlerClassname, $p1, $p2, $p3)];
        } else {
            return false;
        }
    }

    /**
     * Get Handler hash
     *
     * @param Est_Handler_Interface $handler
     * @return string
     */
    protected function getHandlerHash(Est_Handler_Interface $handler) {
        return $this->getHandlerHashByValues(
            get_class($handler),
            $handler->getParam1(),
            $handler->getParam2(),
            $handler->getParam3()
        );
    }

    /**
     * Get handler hash by values
     *
     * @param $handlerClassname
     * @param $p1
     * @param $p2
     * @param $p3
     * @return string
     */
    protected function getHandlerHashByValues($handlerClassname, $p1, $p2, $p3) {
        return md5($handlerClassname.$p1.$p2.$p3);
    }

    public function rewind()
    {
        reset($this->handlers);
    }

    public function current()
    {
        return current($this->handlers);
    }

    public function key()
    {
        return key($this->handlers);
    }

    public function next()
    {
        return next($this->handlers);
    }

    public function valid()
    {
        $key = key($this->handlers);
        $var = ($key !== NULL && $key !== FALSE);
        return $var;
    }


}
