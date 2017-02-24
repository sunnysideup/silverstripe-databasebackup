<?php



class Databasebackup_ModelAdmin extends ModelAdmin
{
    private static $managed_models = array('DatabasebackupLog');

    private static $url_segment = 'databasebackuplog';

    private static $menu_title = 'Database Backup';

    private static $allowed_actions = array(
        "test"
    );

    /**
     * @param Member $member
     * @return boolean
     */
    public function canView($member = null)
    {
        if (!$member && $member !== false) {
            $member = Member::currentUser();
        }

        // cms menus only for logged-in members
        if (!$member) {
            return false;
        }

        // Check for "CMS admin" permission
        if (Permission::checkMember($member, "ADMIN")) {
            return true;
        }
        return false;
    }


    /**
     *
     * allows for custom CMSActions
     */
    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id = null, $fields = null);
        $listfield = $form->Fields()->fieldByName("DatabasebackupLog");
        $model = Injector::inst()->get("DatabasebackupLog");
        $listfield->getConfig()->getComponentByType('GridFieldDetailForm')
            ->setItemRequestClass('DatabasebackupLogDetailForm_ItemRequest');
            //->setFormActions($model->getCMSActions());
        return $form;
    }
}
