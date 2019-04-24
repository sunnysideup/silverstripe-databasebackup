<?php

/**
 * keeps a record for every database backup made...
 *
 *
 *
 *
 */


class DatabasebackupLog extends DataObject
{
    private static $singular_name = "Database Backup";

    private static $plural_name = "Database Backups";

    private static $db = array(
        "Title" => "Varchar(255)",
        "Notes" => "Text",
        "FullLocation" => "Varchar(255)",
        "SizeInBytes" => "Int",
        "DebugMessage" => "Text"
    );

    private static $indexes = array(
        "FullLocation" => true
    );

    private static $default_sort = "Created DESC";

    private static $casting = array(
        "SizeInMegabytes" => "Int"
    );

    private static $summary_fields = array(
        "Title" => "Title",
        "Created" => "Created",
        "SizeInMegabytes" => "Size (in Mb.)",
    );

    /**
     * location for backup file e.g. /var/backups/db.sql
     * @var String
     */
    private static $full_location_for_db_backup_file = "";

    /**
     * number of cycles before the database backups get deleted forgood...
     * @var Int
     */
    private static $max_db_copies = 3;

    /**
     * at the moment only the gzip compression is supported!
     * @var String
     */
    private static $compression = "";

    /**
     * for security reasons we set this to false ...
     * @var Boolean
     */
    private static $allow_restores_in_live_environment = false;

    public function canCreate($member = null)
    {
        return Permission::check("ADMIN");
    }

    public function canDelete($member = null)
    {
        return Permission::check("ADMIN");
    }

    public function canEdit($member = null)
    {
        return Permission::check("ADMIN");
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->addFieldToTab("Root.Main", new ReadonlyField("Created"));
        $fields->addFieldToTab("Root.Main", new ReadonlyField("FullLocation"));
        $fields->addFieldToTab("Root.Main", new ReadonlyField("SizeInBytes"));
        $fields->addFieldToTab("Root.Main", new ReadonlyField("SizeInMegabytes"));
        $fields->removeFieldFromTab("Root.Main", "DebugMessage");
        $fields->addFieldToTab("Root.Main", new LiteralField("DownloadLink", "<h2><a href=\"/DatabasebackupLogDetailForm_Controller/download/".$this->ID."/\">download now</a></h2>"));
        return $fields;
    }

    /**
     * @casting
     * @return Int
     */
    public function getSizeInMegabytes()
    {
        if($this->SizeInBytes) {
            return round($this->SizeInBytes / 1024 / 1024, 2);
        } else {
            return 'n/a';
        }
    }

    /**
     * Adds a button the Site Config page of the CMS to rebuild the Lucene search index.
     */
    public function getCMSActions()
    {
        $actions = parent::getCMSActions();
        if (Permission::check("ADMIN")) {
            if ($fileLocation = $this->getFullLocationWithExtension()) {
                clearstatcache();
                if ($this->FullLocation && file_exists($this->FullLocation)) {
                    //do nothing
                } else {
                    $lastChanged = _t('Databasebackup.NO_BACKUP_IS_AVAILABLE', 'This Backup is NOT Available ... Once created, you can access it here: '.$fileLocation);
                }
                if (!$this->exists()) {
                    $actions->push(
                        new FormAction(
                            'doMakeDatabaseBackup',
                            _t('Databasebackup.MAKE_DATABASE_BACKUP', 'Make Database Backup')."; ".$lastChanged
                        )
                    );
                } else {
                    if ($this->FullLocation && file_exists($this->FullLocation)) {
                        if (!Director::IsLive() || $this->Config()->get("allow_restores_in_live_environment")) {
                            $actions->push(
                                new FormAction(
                                    'doRestoreDatabaseBackup',
                                    _t('Databasebackup.RESTORE_DB_BACKUP_NOW', 'Restore This Database (override current one)')
                                )
                            );
                        }
                    }
                }
            }
            $this->extend('updateCMSActions', $actions);
        }
        return $actions;
    }

    /**
     * if backup does not exist then make it ...
     * set size
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        clearstatcache();
        if (!$this->exists()) {
            if (!$this->FullLocation) {
                if ($fileLocation = $this->getFullLocationWithExtension()) {
                    $fileLocation = $this->cycleDatabaseBackupFiles($fileLocation);
                    global $databaseConfig;
                    $compression = $this->Config()->get("compression");
                    if ($compression == "gzip") {
                        $command = "mysqldump -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]."  | gzip >  ".$fileLocation;
                    } else {
                        $command = "mysqldump -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]." >  ".$fileLocation;
                    }
                    $this->DebugMessage = exec($command);
                    $this->FullLocation = $fileLocation;
                    clearstatcache();
                    $this->SizeInBytes = filesize($this->FullLocation);
                }
            }
        }
        //just in case, we do this everytime...
        if (!$this->SizeInBytes && file_exists($this->FullLocation)) {
            $this->SizeInBytes = filesize($this->FullLocation);
        }
        if (!$this->Title) {
            $this->Title = $this->FullLocation." (" .$this->getSizeInMegabytes()."mb.)";
        }
    }

    /**
     * delete me if file does not exist
     */
    public function onAfterWrite()
    {
        parent::onAfterWrite();
        clearstatcache();
        if (!file_exists($this->FullLocation)) {
            $this->delete();
        }
    }


    /**
     * delete file if I get deleted
     */
    public function onBeforeDelete()
    {
        parent::onBeforeDelete();
        clearstatcache();
        if (file_exists($this->FullLocation)) {
            unlink($this->FullLocation);
        }
    }

    /**
     *
     * @return Boolean
     */
    public function restoreDatabaseBackup()
    {
        if (!Director::IsLive() || $this->Config()->get("allow_restores_in_live_environment")) {
            $fileLocation = $this->FullLocation;
            if (file_exists($fileLocation)) {
                $this->saveToSession();
                global $databaseConfig;
                $compression = $this->Config()->get("compression");
                if ($compression == "gzip") {
                    $command = "gunzip <  ".$fileLocation. " | mysql -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]." ";
                } else {
                    $command = "mysql -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]." <  ".$fileLocation;
                }
                exec($command);
                //reset list of backups ...
                $this->requireDefaultRecords();
                $this->retrieveFromSession();
                Controller::curr()->redirect("/admin/databasebackuplog/");
                return true;
            }
        }
        return false;
    }

    /**
     * we have this so that when we restore a database
     * we dont loose the backup information...
     * saves all the logs to session
     */
    protected function saveToSession()
    {
        foreach (DatabasebackupLog::get() as $object) {
            $array[$object->ID] = array(
                "FullLocation" => $object->FullLocation,
                "Title" => $object->Title,
                "Notes" => $object->Notes,
                "Created" => $object->Created
            );
            Session::set("DatabasebackupLogs", serialize($array));
        }
    }

    /**
     *
     * retrieves and updates all the logs from session
     */
    protected function retrieveFromSession()
    {
        $array = unserialize(Session::get("DatabasebackupLogs"));
        foreach ($array as $id => $values) {
            $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $values["FullLocation"]))->first();
            if ($obj) {
                $obj->Title = convert::raw2sql($values["Title"]);
                $obj->Notes = convert::raw2sql($values["Notes"]);
                $obj->Created = convert::raw2sql($values["Created"]);
                $obj->write();
            }
        }
    }

    /**
     * move all of the database copies up one,
     * deleting the upper one.
     *
     * Returns the name of the file name freed up ... (by moving all of them one up...)
     *
     * @param string $fileLocation
     *
     * @return string File Location
     *
     */
    protected function cycleDatabaseBackupFiles($fileLocation)
    {
        $copyFileLocation = $fileLocation;
        $max = $this->Config()->get("max_db_copies");
        for ($i = $max; $i > -1; $i--) {
            $lowerFileLocation = $this->olderBackupFileName($fileLocation, $i);
            if ($i == $max) {
                //delete the top one ...
                clearstatcache();
                if (file_exists($lowerFileLocation)) {
                    clearstatcache();
                    $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $lowerFileLocation))->First();
                    if ($obj) {
                        $obj->delete();
                    }
                }
            } else {
                $j = $i + 1;
                $higherFileLocation = $fileLocation.".".$j.".bak";
                clearstatcache();
                if (file_exists($lowerFileLocation)) {
                    //double-check the top one ...
                    if (file_exists($higherFileLocation)) {
                        clearstatcache();
                        $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $higherFileLocation))->First();
                        if ($obj) {
                            $obj->delete();
                        }
                    }
                    clearstatcache();
                    if (rename($lowerFileLocation, $higherFileLocation)) {
                        $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $lowerFileLocation))->First();
                        if ($obj) {
                            $obj->FullLocation = $higherFileLocation;
                            $obj->write();
                        }
                    }
                }
            }
        }
        //just in case there were NO DBes to cycle....
        if (!isset($lowerFileLocation)) {
            $lowerFileLocation = $this->olderBackupFileName($fileLocation, ".0.bak");
        }

        clearstatcache();
        if (file_exists($fileLocation)) {
            if (file_exists($lowerFileLocation)) {
                unlink($lowerFileLocation);
                clearstatcache();
                if (!file_exists($lowerFileLocation)) {
                    $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $lowerFileLocation))->First();
                    if ($obj) {
                        $obj->delete();
                    }
                }
            }
            clearstatcache();
            if (rename($fileLocation, $lowerFileLocation)) {
                $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $fileLocation))->First();
                if ($obj) {
                    $obj->FullLocation = $lowerFileLocation;
                    $obj->write();
                }
            }
        }
        return $fileLocation;
    }

    /**
     * returns best file location with compression extension...
     *
     * @return String | Null
     */
    protected function getFullLocationWithExtension()
    {
        $fileLocation = $this->Config()->get("full_location_for_db_backup_file");
        if ($fileLocation) {
            $compression = $this->Config()->get("compression");
            if ($compression == "gzip") {
                $fileLocation .= ".gz";
            }
            return $fileLocation;
        }
        return null;
    }

    /**
     * returns file name for older back up file (cycled one)
     *
     * @param String $fileLocation
     * @param Int $position
     *
     * @return String
     */
    protected function olderBackupFileName($fileLocation, $position)
    {
        return $fileLocation.".".$position.".bak";
    }

    /**
     * check for existing backups
     *
     */
    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        $array = array($this->getFullLocationWithExtension());
        $arrayOfIDs = DatabasebackupLog::get()->map("ID", "ID")->toArray();
        for ($i = 0; $i < 100; $i++) {
            $array[] = $this->olderBackupFileName($array[0], $i);
        }
        foreach ($array as $fileLocation) {
            clearstatcache();
            if (file_exists($fileLocation)) {
                $obj = DatabasebackupLog::get()->filter(array("FullLocation" => $fileLocation))->First();
                if ($obj) {
                    //do nothing
                } else {
                    $className = $this->class;
                    $obj = new $className;
                    //make sure it has a full file location!
                    $obj->FullLocation = $fileLocation;
                    $obj->write();
                }
                unset($arrayOfIDs[$obj->ID]);
            }
        }
        $objects = DatabasebackupLog::get()->filter(array("ID" => $arrayOfIDs));
        foreach ($objects as $obj) {
            $obj->delete();
        }
    }
}
