<?php

class DatabasebackupLogDetailForm extends GridFieldDetailForm {


	private static $allowed_actions = array(
		"ItemEditForm" => "ADMIN",
		"doDownload" => "ADMIN",
	);

}

class DatabasebackupLogDetailForm_ItemRequest extends GridFieldDetailForm_ItemRequest {

	private static $allowed_actions = array(
		"ItemEditForm" => "ADMIN",
		"doDownload" => "ADMIN",
		'doRestoreDatabaseBackup' => "ADMIN"
	);

	function ItemEditForm() {
		$form = parent::ItemEditForm();
		$actions = $this->record->getCMSActions();
		$oldActions = $form->Actions();
		foreach($actions as $action) {
			$oldActions->push($action);
		}
		$form->setActions($oldActions);
		return $form;
	}

	/**
	 * run the action (separate method) and send the right message back
	 *
	 */
	public function doRestoreDatabaseBackup($data, $form) {
		if(!$this->record->ID) {
			return new SS_HTTPResponse("Please pass an ID in the form content", 400);
		}
		$databaseToRestore = DatabasebackupLog::get()->byID($this->record->ID);
		if(!$databaseToRestore) {
			return new SS_HTTPResponse("backup #$id not found", 400);
		}
		$outcome = $this->restoreDatabaseBackup($databaseToRestore);
		if($outcome) {
			$message = _t('Databasebackup.DB_RESTORED', 'Database Restored');
		}
		else {
			$message = _t('Databasebackup.DB_NOT_RESTORED', 'Database * NOT * Restored');
		}
		$this->response->addHeader('X-Status',rawurlencode($message));
		return $this->getResponseNegotiator()->respond($this->request);
	}


	/**
	 * downloads the file
	 * @param Array $data
	 * @param Form $form
	 */
	public function doDownload($data, $form) {
		if(!$this->record->ID) {
			return new SS_HTTPResponse("Please pass an ID in the form content", 400);
		}
		$databaseToRestore = DatabasebackupLog::get()->byID($this->record->ID);
		if(!$databaseToRestore) {
			return new SS_HTTPResponse("backup #$id not found", 400);
		}
		if(!file_exists($databaseToRestore->FullLocation)) {
			return new SS_HTTPResponse("file #$id not found", 400);
		}
		return SS_HTTPRequest::send_file(file_get_contents($databaseToRestore->FullLocation), basename($this->FullLocation));
	}

	/**
	 * copies back up files up one ...
	 * returns true on success, false on failure
	 *
	 * @param DatabasebackupLog $databaseToRestore
	 *
	 * @return Boolean
	 */
	private function restoreDatabaseBackup(DatabasebackupLog $databaseToRestore){
		if(Permission::check("ADMIN")) {
			Config::inst()->update("DatabasebackupLog", "max_db_copies", Config::inst()->get("DatabasebackupLog", "max_db_copies") + 1);
			//firstly make a backup of the current state ...
			$obj = new DatabasebackupLog();
			$obj->write();
			//make sure it still exists ...
			$databaseToRestore = DatabasebackupLog::get()->byID($databaseToRestore->ID);
			if($databaseToRestore) {
				return $databaseToRestore->restoreDatabaseBackup();
			}
		}
		return false;
	}




}
