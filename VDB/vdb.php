<?php

/**
    VDB - an extension of the SQL database plugin for the PHP Fat-Free Framework

    The contents of this file are subject to the terms of the GNU General
    Public License Version 3.0. You may not use this file except in
    compliance with the license. Any of the license terms and conditions
    can be waived if you get permission from the copyright holder.

    Copyright (c) 2012 by ikkez
    Christian Knuth <mail@ikkez.de>

        @package VDB
        @version 0.4.2
 **/

class VDB extends DB {

    protected
        $dataTypes = array(
            'BOOL'=>array(              'mysql'=>" SET('1') ",
                                        'sqlite2?'=>' BOOLEAN ',
                                        'pgsql'=> ' text ',
            ),
            'BOOLEAN'=>array(           'mysql'=>" SET('1') ",
                                        'sqlite2?'=>' BOOLEAN ',
                                        'pgsql'=> ' text ',
            ),
            'INT8'=>array(              'mysql'=>' TINYINT(3) UNSIGNED ',
                                        'sqlite2?'=>' INTEGER ',
                                        'pgsql'=> ' integer ',
            ),
            'INT32'=>array(             'mysql'=>' INT(11) UNSIGNED ',
                                        'sqlite2?'=>' INTEGER ',
                                        'pgsql'=> ' integer ',
            ),
            'FLOAT'=>array(             'mysql|sqlite2?'=>' DOUBLE ',
                                        'pgsql'=> ' double precision ',
            ),
            'DOUBLE'=>array(            'mysql|sqlite2?'=>' DOUBLE ',
                                        'pgsql'=> ' double precision ',
            ),
            'TEXT8'=>array(             'mysql|sqlite2?'=>' VARCHAR(255) ',
                                        'pgsql'=> ' text ',
            ),
            'TEXT16'=>array(            'mysql|sqlite2?'=>' TEXT ',
                                        'pgsql'=> ' text ',
            ),
            'TEXT32'=>array(            'mysql'=>' LONGTEXT ',
                                        'sqlite2?'=>' TEXT ',
                                        'pgsql'=> ' text ',
            ),
            'SPECIAL_DATE'=>array(      'mysql|sqlite2?'=>' DATE ',
                                        'pgsql'=> ' date ',
            ),
            'SPECIAL_DATETIME'=>array(  'mysql|sqlite2?'=>' DATETIME ',
                                        'pgsql'=> ' timestamp without time zone ',
            ),
    );

    const
        TEXT_NoDatatype='The specified datatype %s is not defined in %s driver';


    private function findQuery($cmd) {
        $match=FALSE;
        foreach ($cmd as $backend=>$val)
            if (preg_match('/'.$backend.'/',$this->backend)) {
                $match=TRUE;
                break;
            }
        if (!$match) {
            trigger_error(self::TEXT_DBEngine);
            return FALSE;
        }
        return (is_array($val))?$val[0]:$val;
    }

    /**
     * get all tables of current DB
     * @return bool|array list of tables, or false
     */
    public function getTables() {
        $cmd=array(
            'mysql'=>array(
                "show tables"),
            'sqlite2?'=>array(
                "SELECT name FROM sqlite_master WHERE type='table' AND name!='sqlite_sequence'"),
            //TODO: check if that's working
            'mssql|pgsql|sybase|dblib|ibm|odbc'=>array(
                "select table_name from information_schema.tables where table_schema = 'public'")
        );
        $query = $this->findQuery($cmd);
        if(!$query) return false;

        $tables = $this->exec($query);
        if ($tables && is_array($tables) && count($tables)>0)
            foreach ($tables as &$table)
                $table = array_shift($table);
        return $tables;
    }

    /**
     * get columns of a table
     * @param $table
     * @param bool $types
     * @return array
     */
    public function getCols($table,$types = false) {
        $columns = array();
        $schema = $this->schema($table,60);
        foreach($schema['result'] as $cols) {
            if($types)  $columns[$cols[$schema['field']]] = $cols[$schema['type']];
            else        $columns[] = $cols[$schema['field']];
        }
        return $columns;
    }

    /**
     * add a column to a table
     * @param $table
     * @param $column
     * @param $type
     * @param bool $passThrough
     * @return bool
     */
    public function addCol($table,$column,$type,$passThrough = false) {
        // check if column is already existing
        if(in_array($column,$this->getCols($table))) return false;

        //prepare columntypes
        if($passThrough == false) {
            if(!array_key_exists(strtoupper($type),$this->dataTypes)){
                trigger_error(sprintf(self::TEXT_NoDatatype,strtoupper($type),$this->backend));
                return FALSE;
            }
            $type_val = $this->findQuery($this->dataTypes[strtoupper($type)]);
            if(!$type_val) return false;
        }
        $cmd=array(
            'sqlite2?'=>array(
                "ALTER TABLE `$table` ADD `$column` $type_val"),
            'mysql|pgsql'=>array(
                "ALTER TABLE $table ADD $column $type_val"),
            //TODO: complete that
            /*'mssql|sybase|dblib|ibm|odbc'=>array(
                "")*/
        );
        $query = $this->findQuery($cmd);
        if(!$query) return false;
        return (!$this->exec($query))?TRUE:FALSE;
    }

    /**
     * removes a column from a table
     * @param $table
     * @param $column
     * @return bool
     */
    public function removeCol($table,$column) {

        if(preg_match('/sqlite2?/',$this->backend)) {
            // SQlite does not support drop column directly
            $newCols = array();
            $schema = $this->schema($table,60);
            foreach($schema['result'] as $col)
                if(!in_array($column,$col) && $col[$schema['field']] != 'id')
                    $newCols[$col[$schema['field']]] = $col[$schema['type']];
            $this->begin();
            $this->createTable($table.'_new');
            foreach($newCols as $name => $type)
                $this->addCol($table.'_new',$name,$type,true);
            $fields = (!empty($newCols))?', '.implode(', ',array_keys($newCols)):'';
            $this->exec('INSERT INTO '.$table.'_new SELECT id'.$fields.' FROM '.$table);
            $this->dropTable($table);
            $this->renameTable($table.'_new',$table);
            $this->commit();
            return true;

        } else {
            $cmd=array(
                'mysql'=>array(
                    "ALTER TABLE `$table` DROP `$column` "),
                //TODO: complete that
                'mssql|sybase|dblib|pgsql|ibm|odbc'=>array(
                    "")
            );
            $query = $this->findQuery($cmd);
            if(!$query) return false;
            return (!$this->exec($query))?TRUE:FALSE;
        }
    }

    /**
     * rename a table
     * @param $table_name
     * @param $new_name
     * @return bool
     */
    public function renameTable($table_name,$new_name) {
        $cmd=array(
            'sqlite2?|pgsql'=>array(
                "ALTER TABLE $table_name RENAME TO $new_name;"),
            'mysql'=>array(
                "RENAME TABLE `$table_name` TO `$new_name`;"),
            //TODO: complete that
            /*'mssql|sybase|dblib|ibm|odbc'=>array(
                "")*/
        );
        $query = $this->findQuery($cmd);
        if(!$query) return false;
        return (!$this->exec($query))?TRUE:FALSE;
    }

    /**
     * drop table
     * @param $table
     * @return bool
     */
    public function dropTable($table) {
        $cmd=array(
            'mysql|sqlite2?'=>array(
                "DROP TABLE IF EXISTS $table;"),
            //TODO: complete that
            /*'pgsql|mssql|sybase|dblib|ibm|odbc'=>array(
                "")*/
        );
        $query = $this->findQuery($cmd);
        if(!$query) return false;
        return (!$this->exec($query))?TRUE:FALSE;
    }

    /**
     * create a basic table, containing ID field
     * @param $table
     * @return bool
     */
    public function createTable($table) {
        if(in_array($table,$this->getTables())) return true;
        $cmd=array(
            'sqlite2?'=>array(
                "CREATE TABLE $table (
                id INTEGER PRIMARY KEY AUTOINCREMENT )"),
            'mysql'=>array(
                "CREATE TABLE $table (
                id INT(11) NOT NULL AUTO_INCREMENT ,
                PRIMARY KEY ( id )
                ) DEFAULT CHARSET=utf8"),
            //TODO: check if that's working
            'mssql|sybase|dblib|pgsql|ibm|odbc'=>array(
                "CREATE TABLE $table (id SERIAL PRIMARY KEY)")
        );
        $query = $this->findQuery($cmd);
        if(!$query) return false;
        return (!$this->exec($query))?TRUE:FALSE;
    }
}