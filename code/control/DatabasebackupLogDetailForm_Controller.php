<?php

class DatabasebackupLogDetailForm_Controller extends Controller
{
    private static $allowed_actions = array(
        "download" => 'ADMIN'
    );

    public function download($request)
    {
        $id = intval($request->param("ID"));
        if ($id) {
            $obj = DatabasebackupLog::get()->byID($id);
            return SS_HTTPRequest::send_file(file_get_contents($obj->FullLocation), basename($obj->FullLocation));
        }
        user_error("Could not action download", E_USER_WARNING);
    }
}
