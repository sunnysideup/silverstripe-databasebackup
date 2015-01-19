<?php

/**
 * keeps a record for every database backup made...
 *
 *
 *
 *
 */


class DatabasebackupLog extends DataObject {

	private static $db = array(
		"Title" => "Varchar(255)",
		"FullLocation" => "Varchar(255)",
		"SizeInBytes" => "Int",
		"DebugMessage" => "Text"
	);

	private static $indexes = array(
		"FullLocation" => true
	);


	private static $casting = array(
		"SizeInMegabytes" => "Int"
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


	function canCreate($member = null){
		return Permission::check("ADMIN");
	}

	function canDelete($member = null){
		return Permission::check("ADMIN");
	}

	function canEdit($member = null){
		return Permission::check("ADMIN");
	}

	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->addFieldToTab("Root.Main", new ReadonlyField("FullLocation"));
		$fields->addFieldToTab("Root.Main", new ReadonlyField("SizeInBytes"));
		$fields->addFieldToTab("Root.Main", new ReadonlyField("SizeInMegabytes"));
		$fields->addFieldToTab("Root.Main", new ReadonlyField("Created"));
		$fields->addFieldToTab("Root.Main", new ReadonlyField("DebugMessage"));
		return $fields;
	}

	/**
	 * @casting
	 * @return Int
	 */
	function getSizeInMegabytes(){
		return round($this->SizeInBytes / 1024 / 1024, 2);
	}

	/**
	 * Adds a button the Site Config page of the CMS to rebuild the Lucene search index.
	 */
	public function getCMSActions() {
		$actions = parent::getCMSActions();
		if($fileLocation = $this->getFullLocationWithExtension() || 1 == 1) {
			clearstatcache();
			if(file_exists($this->FullLocation)) {
				//do nothing
			}
			else {
				$lastChanged = _t('Databasebackup.NO_BACKUP_IS_AVAILABLE', 'This Backup is Available ... ');
			}
			if(Permission::check("ADMIN")) {
				if(!$this->exists()) {
					$actions->push(
						new FormAction(
							'doMakeDatabaseBackup',
							_t('Databasebackup.MAKE_DATABASE_BACKUP', 'Make Database Backup')."; ".$lastChanged
						)
					);
				}
				else {
					if(file_exists($this->FullLocation)) {
						$actions->push(
							new FormAction(
								'doRestoreDatabaseBackup',
								_t('Databasebackup.RESTORE_DB_BACKUP_NOW', 'Restore This Database (override current one)')
							)
						);
						$actions->push(
							new FormAction(
								'doDownload',
								_t('Databasebackup.DOWNLOAD', 'Download')
							)
						);
					}
				}
			}
		}
		$this->extend('updateCMSActions', $actions);
		return $actions;
	}

	/**
	 * if backup does not exist then make it ...
	 * set size
	 */
	function onBeforeWrite(){
		parent::onBeforeWrite();
		clearstatcache();
		if(!$this->exists()) {
			if(!$this->FullLocation) {
				if($fileLocation = $this->getFullLocationWithExtension()) {
					$fileLocation = $this->cycleDatabaseBackupFiles($fileLocation);
					global $databaseConfig;
					$compression = $this->Config()->get("compression");
					if($compression == "gzip") {
						$command = "mysqldump -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]."  | gzip >  ".$fileLocation;
					}
					else{
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
		if(!$this->SizeInBytes) {
			$this->SizeInBytes = filesize($this->FullLocation);
		}
		if(!$this->Title) {
			$this->Title = $this->FullLocation." (" .$this->getSizeInMegabytes."mb.)";
		}
	}

	/**
	 * delete me if file does not exist
	 */
	function onAfterWrite(){
		parent::onAfterWrite();
		clearstatcache();
		if(!file_exists($this->FullLocation)) {
			$this->delete();
		}
	}


	/**
	 * delete file if I get deleted
	 */
	function onAfterDelete(){
		parent::onAfterDelete();
		clearstatcache();
		if(file_exists($this->FullLocation)) {
			unlink($this->FullLocation);
		}
	}

	/**
	 *
	 * @return Boolean
	 */
	public function restoreDatabaseBackup() {
		$fileLocation = $this->FullLocation;
		if(file_exists($fileLocation)) {
			global $databaseConfig;
			$compression = $this->Config()->get("compression");
			if($compression == "gzip") {
				$command = "gunzip <  ".$fileLocation. " | mysql -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]." ";
			}
			else {
				$command = "mysql -u ".$databaseConfig["username"]." -p".$databaseConfig["password"]." ".$databaseConfig["database"]." <  ".$fileLocation;
			}
			exec($command);
			//reset list of backups ...
			DB::query("DELETE FROM DatabasebackupLog;");
			$this->requireDefaultRecords();
			Controller::curr()->redirect("/admin/databasebackuplog/");
			return true;
		}
		return false;
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
	protected function cycleDatabaseBackupFiles($fileLocation){
		$copyFileLocation = $fileLocation;
		$max = $this->Config()->get("max_db_copies");
		for($i = $max; $i > -1; $i--) {
			$lowerFileLocation = $this->olderBackupFileName($fileLocation, $i);
			if($i == $max) {
				//delete the top one ...
				clearstatcache();
				if(file_exists($lowerFileLocation)) {
					unlink($lowerFileLocation);
					clearstatcache();
					if(!file_exists($lowerFileLocation)) {
						$obj = DatabasebackupLog::get()->filter(array("FullLocation" => $lowerFileLocation))->First();
						if($obj) {
							$obj->delete();
						}
					}
				}
			}
			else {
				$j = $i + 1;
				$higherFileLocation = $fileLocation.".".$j.".bak";
				clearstatcache();
				if(file_exists($lowerFileLocation)) {
					//double-check the top one ...
					if(file_exists($higherFileLocation)) {
						unlink($higherFileLocation);
						clearstatcache();
						if(!file_exists($higherFileLocation)) {
							$obj = DatabasebackupLog::get()->filter(array("FullLocation" => $higherFileLocation))->First();
							if($obj) {
								$obj->delete();
							}
						}
					}
					clearstatcache();
					if(rename($lowerFileLocation, $higherFileLocation)) {
						$obj = DatabasebackupLog::get()->filter(array("FullLocation" => $lowerFileLocation))->First();
						if($obj) {
							$obj->FullLocation = $higherFileLocation;
							$obj->write();
						}
					}
				}
			}
		}
		//just in case there were NO DBes to cycle....
		if(!isset($lowerFileLocation)) {
			$lowerFileLocation = $fileLocation.".0.bak";
		}

		clearstatcache();
		if(file_exists($fileLocation)) {
			if(file_exists($lowerFileLocation)) {
				unlink($lowerFileLocation);
				clearstatcache();
				if(!file_exists($lowerFileLocation)) {
					$obj = DatabasebackupLog::get()->filter(array("FullLocation" => $lowerFileLocation))->First();
					if($obj) {
						$obj->delete();
					}
				}
			}
			clearstatcache();
			if(rename($fileLocation, $lowerFileLocation)) {
				$obj = DatabasebackupLog::get()->filter(array("FullLocation" => $fileLocation))->First();
				if($obj) {
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
	protected function getFullLocationWithExtension(){
		$fileLocation = $this->Config()->get("full_location_for_db_backup_file");
		if($fileLocation) {
			$compression = $this->Config()->get("compression");
			if($compression == "gzip") {
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
	protected function olderBackupFileName($fileLocation, $position){
		return $fileLocation.".".$position.".bak";
	}

	/**
	 * check for existing backups
	 *
	 */
	public function requireDefaultRecords(){
		parent::requireDefaultRecords();
		$array = array($this->getFullLocationWithExtension());
		for($i = 0; $i < 100; $i++) {
			$array[] = $this->olderBackupFileName($array[0], $i);
		}
		foreach($array as $fileLocation) {
			clearstatcache();
			if(file_exists($fileLocation)) {
				$className = $this->class;
				$obj = new $className;
				//make sure it has a full file location!
				$obj->FullLocation = $fileLocation;
				$obj->write();
			}
		}
	}


}
