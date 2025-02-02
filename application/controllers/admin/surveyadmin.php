<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * LimeSurvey
 * Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
 * All rights reserved.
 * License: GNU/GPL License v2 or later, see LICENSE.php
 * LimeSurvey is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */

/**
 * survey
 *
 * @package LimeSurvey
 * @author  The LimeSurvey Project team
 * @copyright 2011
 * @version $Id: surveyaction.php 12301 2012-02-02 08:51:43Z c_schmitz $
 * @access public
 */
class SurveyAdmin extends Survey_Common_Action
{
    /**
     * Initiates the survey action, checks for superadmin permission
     *
     * @access public
     * @param CController $controller
     * @param string $id
     */
    public function __construct($controller, $id)
    {
        parent::__construct($controller, $id);
    }

    /**
     * Loads list of surveys and its few quick properties.
     *
     * @access public
     * @return void
     */
    public function index()
    {
        $this->getController()->redirect(array('admin/survey/sa/listsurveys'));
    }

    /**
     * Delete multiple survey
     * @return void
     */
    public function deleteMultiple()
    {
        $aSurveys = json_decode(Yii::app()->request->getPost('sItems'));
        $aResults = array();
        foreach ($aSurveys as $iSurveyID) {
            if (Permission::model()->hasSurveyPermission($iSurveyID, 'survey', 'delete')) {
                $oSurvey                        = Survey::model()->findByPk($iSurveyID);
                $aResults[$iSurveyID]['title']  = $oSurvey->correct_relation_defaultlanguage->surveyls_title;
                $aResults[$iSurveyID]['result'] = Survey::model()->deleteSurvey($iSurveyID);
            }
        }

        Yii::app()->getController()->renderPartial(
            'ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results',
            array(
                'aResults'     => $aResults,
                'successLabel' => gT('Deleted')
            )
        );
    }

     /**
     * render selected items for massive action
     * @return void
     */

    public function renderItemsSelected()
    {
        $aSurveys = json_decode(Yii::app()->request->getPost('$oCheckedItems'));
        $aResults = [];
        $tableLabels= array(gT('Survey ID'),gT('Survey Title') ,gT('Status'));
        foreach ($aSurveys as $iSurveyID) {
            if (Permission::model()->hasSurveyPermission($iSurveyID, 'survey', 'delete')) {
                $oSurvey                        = Survey::model()->findByPk($iSurveyID);
                $aResults[$iSurveyID]['title']  = $oSurvey->correct_relation_defaultlanguage->surveyls_title;
                $aResults[$iSurveyID]['result'] = 'selected';
            }
        }

        Yii::app()->getController()->renderPartial(
            'ext.admin.grid.MassiveActionsWidget.views._selected_items',
            array(
                'aResults'     => $aResults,
                'successLabel' => gT('Seleted'),
                'tableLabels'  => $tableLabels
            )
        );
    }

    /**
     * @todo
     */
    public function listsurveys()
    {
        Yii::app()->loadHelper('surveytranslator');
        $aData = array();
        $aData['issuperadmin'] = false;

        if (Permission::model()->hasGlobalPermission('superadmin', 'read')) {
            $aData['issuperadmin'] = true;
        }
        $aData['model'] = new Survey('search');
        $aData['groupModel'] = new SurveysGroups('search');
        $aData['fullpagebar']['button']['newsurvey'] = true;
        $this->_renderWrappedTemplate('survey', 'listSurveys_view', $aData);
    }

    /**
     * @todo
     */
    public function regenquestioncodes($iSurveyID, $sSubAction)
    {
        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'update')) {
            Yii::app()->setFlashMessage(gT("You do not have permission to access this page."), 'error');
            $this->getController()->redirect(array('admin/survey', 'sa'=>'view', 'surveyid'=>$iSurveyID));
        }
        $oSurvey = Survey::model()->findByPk($iSurveyID);
        if ($oSurvey->isActive) {
            Yii::app()->setFlashMessage(gT("You can't update question code for an active survey."), 'error');
            $this->getController()->redirect(array('admin/survey', 'sa'=>'view', 'surveyid'=>$iSurveyID));
        }
        //Automatically renumbers the "question codes" so that they follow
        //a methodical numbering method
        $iQuestionNumber = 1;
        $iGroupNumber    = 0;
        $iGroupSequence  = 0;
        $oQuestions      = Question::model()
            ->with(['group', 'questionL10ns'])
            ->findAll(
                array(
                    'select' => 't.qid,t.gid',
                    'condition' => "t.sid=:sid and questionL10ns.language=:language and parent_qid=0",
                    'order' => 'group.group_order, question_order',
                    'params' => array(':sid' => $iSurveyID, ':language' => $oSurvey->language)
                )
            );

        foreach ($oQuestions as $oQuestion) {
            if ($sSubAction == 'bygroup' && $iGroupNumber != $oQuestion->gid) {
                //If we're doing this by group, restart the numbering when the group number changes
                $iQuestionNumber = 1;
                $iGroupNumber    = $oQuestion->gid;
                $iGroupSequence++;
            }
            $sNewTitle = (($sSubAction == 'bygroup') ? ('G'.$iGroupSequence) : '')."Q".str_pad($iQuestionNumber, 5, "0", STR_PAD_LEFT);
            Question::model()->updateAll(array('title'=>$sNewTitle), 'qid=:qid', array(':qid'=>$oQuestion->qid));
            $iQuestionNumber++;
            $iGroupNumber = $oQuestion->gid;
        }
        Yii::app()->setFlashMessage(gT("Question codes were successfully regenerated."));
        LimeExpressionManager::SetDirtyFlag(); // so refreshes syntax highlighting
        $this->getController()->redirect(array('admin/survey/sa/view/surveyid/'.$iSurveyID));
    }


    /**
     * This function prepares the view for a new survey
     */
    public function newsurvey()
    {
        if (!Permission::model()->hasGlobalPermission('surveys', 'create')) {
            Yii::app()->user->setFlash('error', gT("Access denied"));
            $this->getController()->redirect(Yii::app()->request->urlReferrer);
        }
        $survey = new Survey();
        // set 'inherit' values to survey attributes
        $survey->setToInherit();

        $this->_registerScriptFiles();
        Yii::app()->loadHelper('surveytranslator');
        $esrow = $this->_fetchSurveyInfo('newsurvey');
        Yii::app()->loadHelper('admin/htmleditor');

        //$aViewUrls['output']  = PrepareEditorScript(false, $this->getController());
        $aData                = $this->_generalTabNewSurvey();
        $aData                = array_merge($aData, $this->_getGeneralTemplateData(0));
        $aData['esrow']       = $esrow;

        $aData['oSurvey'] = $survey;
        $aData['bShowAllOptions'] = true;
        $aData['bShowInherited'] = true;
        $oSurveyOptions = $survey;
        $oSurveyOptions->bShowRealOptionValues = false;
        $oSurveyOptions->setOptions();
        $aData['oSurveyOptions'] = $oSurveyOptions->oOptionLabels;

        $aData['optionsOnOff'] = array(
            'Y' => gT('On','unescaped'),
            'N' => gT('Off','unescaped'),
        );

        //Prepare the edition panes
        $aData['edittextdata']              = array_merge($aData, $this->_getTextEditData($survey));
        $aData['datasecdata']               = array_merge($aData, $this->_getDataSecurityEditData($survey));
        $aData['generalsettingsdata']       = array_merge($aData, $this->_generalTabEditSurvey($survey));
        $aData['presentationsettingsdata']  = array_merge($aData, $this->_tabPresentationNavigation($esrow));
        $aData['publicationsettingsdata']   = array_merge($aData, $this->_tabPublicationAccess($survey));
        $aData['notificationsettingsdata']  = array_merge($aData, $this->_tabNotificationDataManagement($esrow));
        $aData['tokensettingsdata']         = array_merge($aData, $this->_tabTokens($esrow));

        $aViewUrls[] = 'newSurvey_view';

        $arrayed_data                                              = array();
        $arrayed_data['oSurvey']                                   = $survey;
        $arrayed_data['data']                                      = $aData;
        $arrayed_data['title_bar']['title']                        = gT('New survey');
        $arrayed_data['fullpagebar']['savebutton']['form']         = 'addnewsurvey';
        $arrayed_data['fullpagebar']['closebutton']['url']         = 'admin/index'; // Close button

        $this->_renderWrappedTemplate('survey', $aViewUrls, $arrayed_data);
    }

    /**
     * @todo Document me
     */
    public function fakebrowser()
    {
        Yii::app()->getController()->renderPartial('/admin/survey/newSurveyBrowserMessage', array());
    }

    /**
     * Function responsible to import survey resources from a '.zip' file.
     *
     * @access public
     * @return void
     */
    public function importsurveyresources()
    {
        $iSurveyID = Yii::app()->request->getPost('surveyid');

        if (!empty($iSurveyID)) {


            if (Yii::app()->getConfig('demoMode')) {
                Yii::app()->user->setFlash('error', gT("Demo mode only: Uploading files is disabled in this system."));
                $this->getController()->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
            }

            // Create temporary directory
            // If dangerous content is unzipped
            // then no one will know the path
            Yii::import('application.helpers.common_helper', true);
            $extractdir  = createRandomTempDir();
            $zipfilename = $_FILES['the_file']['tmp_name'];
            $basedestdir = Yii::app()->getConfig('uploaddir')."/surveys";
            $destdir     = $basedestdir."/$iSurveyID/";

            Yii::app()->loadLibrary('admin.pclzip');
            $zip = new PclZip($zipfilename);

            if (!is_writeable($basedestdir)) {
                Yii::app()->user->setFlash('error', sprintf(gT("Incorrect permissions in your %s folder."), $basedestdir));
                $this->getController()->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
            }


            if (!is_dir($destdir)) {
                mkdir($destdir);
            }

            $aImportedFilesInfo = array();
            $aErrorFilesInfo = array();

            if (is_file($zipfilename)) {
                if ($zip->extract($extractdir) <= 0) {
                    Yii::app()->user->setFlash('error', gT("This file is not a valid ZIP file archive. Import failed. ").$zip->errorInfo(true));
                    $this->getController()->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
                }
                // now read tempdir and copy authorized files only
                $folders = array('flash', 'files', 'images');
                foreach ($folders as $folder) {
                    list($_aImportedFilesInfo, $_aErrorFilesInfo) = $this->_filterImportedResources($extractdir."/".$folder, $destdir.$folder);
                    $aImportedFilesInfo = array_merge($aImportedFilesInfo, $_aImportedFilesInfo);
                    $aErrorFilesInfo = array_merge($aErrorFilesInfo, $_aErrorFilesInfo);
                }

                // Deletes the temp directory
                rmdirr($extractdir);

                // Delete the temporary file
                unlink($zipfilename);

                if (is_null($aErrorFilesInfo) && is_null($aImportedFilesInfo)) {
                    Yii::app()->user->setFlash('error', gT("This ZIP archive contains no valid Resources files. Import failed."));
                    $this->getController()->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
                }
            } else {
                Yii::app()->setFlashMessage(gT("An error occurred uploading your file. This may be caused by incorrect permissions for the application /tmp folder."), 'error');
                $this->getController()->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
            }
            $aData = array(
                'aErrorFilesInfo' => $aErrorFilesInfo,
                'aImportedFilesInfo' => $aImportedFilesInfo,
                'surveyid' => $iSurveyID
            );
            $aData['display']['menu_bars']['surveysummary'] = true;
            $this->_renderWrappedTemplate('survey', 'importSurveyResources_view', $aData);
        }
    }

    /**
     * Change survey theme for multiple survey at once.
     * Called from survey list massive actions
     */
    public function changeMultipleTheme()
    {
        $sSurveys = $_POST['sItems'];
        $aSIDs = json_decode($sSurveys);
        $aResults = array();

        $sTemplate = App()->request->getPost('theme');

        foreach ($aSIDs as $iSurveyID){
            $aResults = self::changetemplate($iSurveyID, $sTemplate, $aResults, true);
        }

        Yii::app()->getController()->renderPartial('ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results', array('aResults'=>$aResults,'successLabel'=>$sTemplate));
    }

    /**
     * Change survey group for multiple survey at once.
     * Called from survey list massive actions
     */
    public function changeMultipleSurveyGroup()
    {
        $sSurveys = $_POST['sItems'];
        $aSIDs = json_decode($sSurveys);
        $aResults = array();

        $iSurveyGroupId = sanitize_int(App()->request->getPost('surveygroupid'));

        foreach ($aSIDs as $iSurveyID){
            $oSurvey = Survey::model()->findByPk($iSurveyID);
            $oSurvey->gsid = $iSurveyGroupId;
            $aResults[$iSurveyID]['title']  = $oSurvey->correct_relation_defaultlanguage->surveyls_title;
            $aResults[$iSurveyID]['result']= $oSurvey->save();
        }

        Yii::app()->getController()->renderPartial('ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results', array('aResults'=>$aResults,'successLabel'=>gT("Success")));
    }

    /**
    * Update the theme of a survey
    *
    * @access public
    * @param int     $iSurveyID
    * @param string  $template      the survey theme name
    * @param array   $aResults      if the method is called from changeMultipleTheme(), it will update its array of results
    * @param boolean $bReturn       should the method update and return aResults
    * @return mixed                 null or array
     */
    public function changetemplate($iSurveyID, $template, $aResults = null, $bReturn = false)
    {

        $iSurveyID  = sanitize_int($iSurveyID);
        $sTemplate  = sanitize_dirname($template);

        $survey           = Survey::model()->findByPk($iSurveyID);


        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveyactivation', 'update')) {

            if (!empty($bReturn)){
                $aResults[$iSurveyID]['title']  = $survey->correct_relation_defaultlanguage->surveyls_title;
                $aResults[$iSurveyID]['result'] = false;
                return $aResults;
            }else{
                die('No permission');
            }
        }


        $survey->template = $sTemplate;
        $survey->save();

        $oTemplateConfiguration = $survey->surveyTemplateConfiguration;
        $oTemplateConfiguration->template_name = $sTemplate;
        $oTemplateConfiguration->save();

        // This will force the generation of the entry for survey group
        TemplateConfiguration::checkAndcreateSurveyConfig($iSurveyID);

        if (!empty($bReturn)){
            $aResults[$iSurveyID]['title']  = $survey->correct_relation_defaultlanguage->surveyls_title;
            $aResults[$iSurveyID]['result'] = true;
            return $aResults;
        }
    }

    /**
     * @todo
     */
    public function togglequickaction()
    {
        $quickactionstate = (int) SettingsUser::getUserSettingValue('quickaction_state');

        switch ($quickactionstate) {
            //
            case null:
                $save = SettingsUser::setUserSetting('quickaction_state', 1);
                break;
            case 0:
                $save = SettingsUser::setUserSetting('quickaction_state', 1);
                break;
            case 1:
                $save = SettingsUser::setUserSetting('quickaction_state', 0);
                break;
        }
        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            array(
                'data' => [
                    'success' => $save,
                    'newState'=> SettingsUser::getUserSettingValue('quickaction_state')
                ],
            ),
            false,
            false
        );
    }

    /**
     * Load complete view of survey properties and actions specified by $iSurveyID
     *
     * @access public
     * @param mixed $iSurveyID
     * @param mixed $gid
     * @param mixed $qid
     * @return void
     */
    public function view($iSurveyID, $gid = null, $qid = null)
    {
        $beforeSurveyAdminView = new PluginEvent('beforeSurveyAdminView');
        $beforeSurveyAdminView->set('surveyId', $iSurveyID);
        App()->getPluginManager()->dispatchEvent($beforeSurveyAdminView);

        // We load the panel packages for quick actions
        $iSurveyID = sanitize_int($iSurveyID);
        $survey    = Survey::model()->findByPk($iSurveyID);
        $baselang  = $survey->language;

        $aData = array('aAdditionalLanguages' => $survey->additionalLanguages);

        // Reinit LEMlang and LEMsid: ensure LEMlang are set to default lang, surveyid are set to this survey id
        // Ensure Last GetLastPrettyPrintExpression get info from this sid and default lang
        LimeExpressionManager::SetEMLanguage($baselang);
        LimeExpressionManager::SetSurveyId($iSurveyID);
        LimeExpressionManager::StartProcessingPage(false, true);

        $aData['title_bar']['title'] = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        $aData['surveyid'] = $iSurveyID;
        $aData['display']['surveysummary'] = true;

        // Last survey visited
        $setting_entry = 'last_survey_'.App()->user->getId();
        SettingGlobal::setSetting($setting_entry, $iSurveyID);

        $aData['surveybar']['buttons']['view'] = true;
        $aData['surveybar']['returnbutton']['url'] = $this->getController()->createUrl("admin/survey/sa/listsurveys");
        $aData['surveybar']['returnbutton']['text'] = gT('Return to survey list');
        $aData['sidemenu']["survey_menu"] = true;

        // We get the last question visited by user for this survey
        $setting_entry = 'last_question_'.App()->user->getId().'_'.$iSurveyID;
        // TODO: getGlobalSetting() DEPRECATED
        $lastquestion = getGlobalSetting($setting_entry);
        $setting_entry = 'last_question_'.App()->user->getId().'_'.$iSurveyID.'_gid';

        // We get the list of templates

        //$setting_entry = 'last_question_gid'.Yii::app()->user->getId().'_'.$iSurveyID;
        // TODO: getGlobalSetting() DEPRECATED
        $lastquestiongroup = getGlobalSetting($setting_entry);

        if ($lastquestion != null && $lastquestiongroup != null) {
            $aData['showLastQuestion'] = true;
            $iQid = $lastquestion;
            $iGid = $lastquestiongroup;
            $qrrow = Question::model()->findByAttributes(array('qid' => $iQid, 'gid' => $iGid, 'sid' => $iSurveyID));

            $aData['last_question_name'] = $qrrow['title'];
            if (!empty($qrrow->questionL10ns[$baselang]['question'])) {
                $aData['last_question_name'] .= ' : '.$qrrow->questionL10ns[$baselang]['question'];
            }

            $aData['last_question_link'] = $this->getController()->createUrl("admin/questions/sa/view/surveyid/$iSurveyID/gid/$iGid/qid/$iQid");
        } else {
            $aData['showLastQuestion'] = false;
        }
        $aData['templateapiversion'] = Template::model()->getTemplateConfiguration(null, $iSurveyID)->getApiVersion();

        $user = User::model()->findByPk(App()->session['loginID']);
        $aData['owner'] = $user->attributes;
        $this->_renderWrappedTemplate('survey', array(), $aData);
    }

    /**
     * Ajaxified get questiongroup with containing questions
     * @todo
     */
    public function getAjaxQuestionGroupArray($surveyid)
    {
        $iSurveyID = sanitize_int($surveyid);

        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'read')) {
            Yii::app()->user->setFlash('error', gT("Access denied"));
            $this->getController()->redirect(Yii::app()->createUrl('/admin'));
        }

        $survey    = Survey::model()->findByPk($iSurveyID);
        $baselang  = $survey->language;
        $setting_entry = 'last_question_'.Yii::app()->user->getId().'_'.$iSurveyID;
        $lastquestion = getGlobalSetting($setting_entry);
        $setting_entry = 'last_question_'.Yii::app()->user->getId().'_'.$iSurveyID.'_gid';
        $lastquestiongroup = getGlobalSetting($setting_entry);


        $aGroups = QuestionGroup::model()->findAllByAttributes(array('sid' => $iSurveyID), array('order'=>'group_order ASC'));
        $aGroupViewable = array();
        if (count($aGroups)) {
            foreach ($aGroups as $group) {
                $curGroup = $group->attributes;
                $curGroup['group_name'] = $group->questionGroupL10ns[$baselang]->group_name;
                $curGroup['link'] = $this->getController()->createUrl("admin/questiongroups/sa/view", ['surveyid' => $surveyid, 'gid' => $group->gid]);
                $group->aQuestions = Question::model()->findAllByAttributes(array("sid"=>$iSurveyID, "gid"=>$group['gid'], 'parent_qid'=>0), array('order'=>'question_order ASC'));
                $curGroup['questions'] = array();
                foreach ($group->aQuestions as $question) {
                    if (is_object($question)) {
                        $curQuestion = $question->attributes;
                        $curQuestion['link'] = $this->getController()->createUrl("admin/questioneditor/sa/view", ['surveyid' => $surveyid, 'gid' => $group->gid, 'qid'=>$question->qid]);
                        $curQuestion['editLink'] = $this->getController()->createUrl("admin/questioneditor/sa/view", ['surveyid' => $surveyid, 'gid' => $group->gid, 'qid'=>$question->qid]);
                        $curQuestion['hidden'] = isset($question->questionAttributes['hidden']) && !empty($question->questionAttributes['hidden']->value);
                        $questionText = isset($question->questionL10ns[$baselang])
                            ? $question->questionL10ns[$baselang]->question
                            : '';
                        $curQuestion['question_flat'] = viewHelper::flatEllipsizeText($questionText, true);
                        $curGroup['questions'][] = $curQuestion;
                    }

                }
                $aGroupViewable[] = $curGroup;
            }
        }

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            array(
                'data' => array(
                    'groups' => $aGroupViewable,
                    'settings' => array(
                        'lastquestion' => $lastquestion,
                        'lastquestiongroup' => $lastquestiongroup,
                    ),
                    // 'debug' => [
                    //     $iSurveyID,
                    //     $survey,
                    //     $baselang,
                    //     $setting_entry,
                    //     $lastquestion,
                    //     $setting_entry,
                    //     $lastquestiongroup
                    // ]
                )
            ),
            false,
            false
        );
    }


    /**
     * Ajaxified get MenuItems with containing questions
     * @todo
     */
    public function getAjaxMenuArray($surveyid, $position = '')
    {
        $iSurveyID = sanitize_int($surveyid);

        $survey    = Survey::model()->findByPk($iSurveyID);
        $menus = $survey->getSurveyMenus($position);
        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            array(
                'data' => [
                    'menues'=> $menus,
                    'settings' => array(
                        'extrasettings' => false,
                        'parseHTML' => false,
                    )
                ]
            ),
            false,
            false
        );
    }

    /**
     * Load list question groups view for a specified by $iSurveyID
     *
     * @access public
     * @param mixed $surveyid The survey ID
     * @return void
     */
    public function listquestiongroups($surveyid)
    {
        $iSurveyID = sanitize_int($surveyid);
        $survey = Survey::model()->findByPk($iSurveyID);

        // Reinit LEMlang and LEMsid: ensure LEMlang are set to default lang, surveyid are set to this survey id
        // Ensure Last GetLastPrettyPrintExpression get info from this sid and default lang
        LimeExpressionManager::SetEMLanguage(Survey::model()->findByPk($iSurveyID)->language);
        LimeExpressionManager::SetSurveyId($iSurveyID);
        LimeExpressionManager::StartProcessingPage(false, true);

        $aData = array();

        $aData['surveyid']                                   = $iSurveyID;
        $aData['display']['menu_bars']['listquestiongroups'] = true;
        $aData['sidemenu']['questiongroups']                 = true;
        $aData['sidemenu']['listquestiongroups']             = true;
        $aData['surveybar']['buttons']['newgroup']           = true;
        $aData['title_bar']['title']                         = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        $aData['subaction']                                  = gT("Question groups in this survey");

        $baselang = $survey->language;
        $model    = new QuestionGroup('search');

        if (isset($_GET['QuestionGroup'])) {
            $model->attributes = $_GET['QuestionGroup'];
        }

        if (isset($_GET['pageSize'])) {
            Yii::app()->user->setState('pageSize', (int) $_GET['pageSize']);
        }

        $model['sid']      = $iSurveyID;
        $model['language'] = $baselang;
        $aData['model']    = $model;

        $this->_renderWrappedTemplate('survey', array(), $aData);
    }


    /**
     * Load list questions view for a specified survey by $surveyid
     *
     * @access public
     * @param mixed $surveyid
     * @return string
     * @todo php warning (Missing return statement)
     */
    public function listquestions($surveyid)
    {
        $iSurveyID = sanitize_int($surveyid);
        // Reinit LEMlang and LEMsid: ensure LEMlang are set to default lang, surveyid are set to this survey id
        // Ensure Last GetLastPrettyPrintExpression get info from this sid and default lang
        LimeExpressionManager::SetEMLanguage(Survey::model()->findByPk($iSurveyID)->language);
        LimeExpressionManager::SetSurveyId($iSurveyID);
        LimeExpressionManager::StartProcessingPage(false, true);

        // Set number of page
        $pageSize = App()->request->getParam('pageSize', null);
        if ($pageSize != null) {
            App()->user->setState('pageSize', (int) $pageSize);
        }

        $oSurvey = Survey::model()->findByPk($iSurveyID);
        $aData   = array();

        $aData['oSurvey']                               = $oSurvey;
        $aData['surveyid']                              = $iSurveyID;
        $aData['display']['menu_bars']['listquestions'] = true;
        $aData['sidemenu']['listquestions']             = true;
        $aData['surveybar']['returnbutton']['url']      = $this->getController()->createUrl(
            "admin/survey/sa/listsurveys"
        );
        $aData['surveybar']['returnbutton']['text']     = gT('Return to survey list');
        $aData['surveybar']['buttons']['newquestion']   = true;

        $aData["surveyHasGroup"]        = $oSurvey->groups;
        $aData['subaction']             = gT("Questions in this survey");
        $aData['title_bar']['title']    = $oSurvey->currentLanguageSettings->surveyls_title.
            " (".gT("ID").":".$iSurveyID.")";

        $this->_renderWrappedTemplate('survey', array(), $aData);
    }

    /**
     * Function responsible to deactivate a survey.
     *
     * @access public
     * @param int $iSurveyID
     * @return void
     */
    public function deactivate($iSurveyID = null)
    {
        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveyactivation', 'update')) {
            die('No permission');
        }

        $iSurveyID  = Yii::app()->request->getPost('sid', $iSurveyID);
        $iSurveyID  = sanitize_int($iSurveyID);
        $survey = Survey::model()->findByPk($iSurveyID);
        $date       = date('YmdHis'); //'His' adds 24hours+minutes to name to allow multiple deactiviations in a day
        $aData      = array();

        $aData['aSurveysettings']                 = getSurveyInfo($iSurveyID);
        $aData['surveyid']                        = $iSurveyID;
        $aData['title_bar']['title']              = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        $aData['surveybar']['closebutton']['url'] = 'admin/survey/sa/view/surveyid/'.$iSurveyID; // Close button

        // Fire event beforeSurveyDeactivate
        $beforeSurveyDeactivate = new PluginEvent('beforeSurveyDeactivate');
        $beforeSurveyDeactivate->set('surveyId', $iSurveyID);
        App()->getPluginManager()->dispatchEvent($beforeSurveyDeactivate);
        $success = $beforeSurveyDeactivate->get('success');
        $message = $beforeSurveyDeactivate->get('message');
        if (!empty($message)) {
            Yii::app()->user->setFlash('error', $message);
        }
        if ($success === false) {
            // @todo: What if two plugins change this?
            $aData['nostep'] = true;
            $this->_renderWrappedTemplate('survey', 'deactivateSurvey_view', $aData);
            return;
        }

        if (Yii::app()->request->getPost('ok') == '') {
            if (!tableExists('survey_'.$iSurveyID)) {
                $_SESSION['flashmessage'] = gT("Error: Response table does not exist. Survey cannot be deactivated.");
                $this->getController()->redirect($this->getController()->createUrl("admin/survey/sa/view/surveyid/{$iSurveyID}"));
            }
            $aData['surveyid'] = $iSurveyID;
            $aData['date']     = $date;
            $aData['dbprefix'] = Yii::app()->db->tablePrefix;
            $aData['step1']    = true;
        } else {
            //See if there is a tokens table for this survey
            if (tableExists("{{tokens_{$iSurveyID}}}")) {
                $toldtable = Yii::app()->db->tablePrefix."tokens_{$iSurveyID}";
                $tnewtable = Yii::app()->db->tablePrefix."old_tokens_{$iSurveyID}_{$date}";
                if (Yii::app()->db->getDriverName() == 'pgsql') {
                    $tidDefault = Yii::app()->db->createCommand("SELECT pg_attrdef.adsrc FROM pg_attribute JOIN pg_class ON (pg_attribute.attrelid=pg_class.oid) JOIN pg_attrdef ON(pg_attribute.attrelid=pg_attrdef.adrelid AND pg_attribute.attnum=pg_attrdef.adnum) WHERE pg_class.relname='$toldtable' and pg_attribute.attname='tid'")->queryScalar();
                    if (preg_match("/nextval\('(tokens_\d+_tid_seq\d*)'::regclass\)/", $tidDefault, $matches)) {
                        $oldSeq = $matches[1];
                        Yii::app()->db->createCommand()->renameTable($oldSeq, $tnewtable.'_tid_seq');
                        $setsequence = "ALTER TABLE ".Yii::app()->db->quoteTableName($toldtable)." ALTER COLUMN tid SET DEFAULT nextval('{$tnewtable}_tid_seq'::regclass);";
                        Yii::app()->db->createCommand($setsequence)->query();
                    }
                }

                Yii::app()->db->createCommand()->renameTable($toldtable, $tnewtable);

                $aData['tnewtable'] = $tnewtable;
                $aData['toldtable'] = $toldtable;
            }
            // Reset the session of the survey when deactivating it
            killSurveySession($iSurveyID);
            //Remove any survey_links to the CPDB
            SurveyLink::model()->deleteLinksBySurvey($iSurveyID);


            // IF there are any records in the saved_control table related to this survey, they have to be deleted
            SavedControl::model()->deleteSomeRecords(array('sid' => $iSurveyID)); //Yii::app()->db->createCommand($query)->query();
            $sOldSurveyTableName = Yii::app()->db->tablePrefix."survey_{$iSurveyID}";
            $sNewSurveyTableName = Yii::app()->db->tablePrefix."old_survey_{$iSurveyID}_{$date}";
            $aData['sNewSurveyTableName'] = $sNewSurveyTableName;

            $query = "SELECT id FROM ".Yii::app()->db->quoteTableName($sOldSurveyTableName)." ORDER BY id desc";
            $sLastID = Yii::app()->db->createCommand($query)->limit(1)->queryScalar();
            //Update the autonumber_start in the survey properties
            $new_autonumber_start = $sLastID + 1;
            $survey = Survey::model()->findByAttributes(array('sid' => $iSurveyID));
            $survey->autonumber_start = $new_autonumber_start;
            $survey->save();
            if (Yii::app()->db->getDriverName() == 'pgsql') {
                $idDefault = Yii::app()->db->createCommand("SELECT pg_get_expr(pg_attrdef.adbin, pg_attrdef.adrelid) FROM pg_attribute JOIN pg_class ON (pg_attribute.attrelid=pg_class.oid) JOIN pg_attrdef ON(pg_attribute.attrelid=pg_attrdef.adrelid AND pg_attribute.attnum=pg_attrdef.adnum) WHERE pg_class.relname='$sOldSurveyTableName' and pg_attribute.attname='id'")->queryScalar();
                if (preg_match("/nextval\('(survey_\d+_id_seq\d*)'::regclass\)/", $idDefault, $matches)) {
                    $oldSeq = $matches[1];
                    Yii::app()->db->createCommand()->renameTable($oldSeq, $sNewSurveyTableName.'_id_seq');
                    $setsequence = "ALTER TABLE ".Yii::app()->db->quoteTableName($sOldSurveyTableName)." ALTER COLUMN id SET DEFAULT nextval('{{{$sNewSurveyTableName}}}_id_seq'::regclass);";
                    Yii::app()->db->createCommand($setsequence)->query();
                }
            }

            Yii::app()->db->createCommand()->renameTable($sOldSurveyTableName, $sNewSurveyTableName);

            $survey->active = 'N';
            $survey->save();

            $prow = Survey::model()->find('sid = :sid', array(':sid' => $iSurveyID));
            if ($prow->savetimings == "Y") {
                $sOldTimingsTableName = Yii::app()->db->tablePrefix."survey_{$iSurveyID}_timings";
                $sNewTimingsTableName = Yii::app()->db->tablePrefix."old_survey_{$iSurveyID}_timings_{$date}";
                Yii::app()->db->createCommand()->renameTable($sOldTimingsTableName, $sNewTimingsTableName);
                $aData['sNewTimingsTableName'] = $sNewTimingsTableName;
            }

            $event = new PluginEvent('afterSurveyDeactivate');
            $event->set('surveyId', $iSurveyID);
            App()->getPluginManager()->dispatchEvent($event);

            $aData['surveyid'] = $iSurveyID;
            Yii::app()->db->schema->refresh();
        }

        $aData['sidemenu']['state'] = false;
        $aData['surveybar']['closebutton'] = false;
        $this->_renderWrappedTemplate('survey', 'deactivateSurvey_view', $aData);
    }

    /**
     * Function responsible to activate survey.
     *
     * @access public
     * @param int $iSurveyID
     * @return void
     */
    public function activate($iSurveyID)
    {
        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveyactivation', 'update')) {
            die();
        }

        $iSurveyID = (int) $iSurveyID;
        $survey = Survey::model()->findByPk($iSurveyID);
        $surveyActivator = new SurveyActivator($survey);

        Yii::app()->user->setState('sql_'.$iSurveyID, ''); // If user has set some filters for responses from statistics on a previous activation, it must be wiped out
        $aData = array();
        $aData['sidemenu']['state'] = false;
        $aData['aSurveysettings'] = getSurveyInfo($iSurveyID);
        $aData['surveyid'] = $iSurveyID;
        $aData['title_bar']['title'] = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        // Redirect if this is not possible
        if (!isset($aData['aSurveysettings']['active']) || $aData['aSurveysettings']['active'] == 'Y') {
            Yii::app()->setFlashMessage(gT("This survey is already active."), 'error');
            $this->getController()->redirect(array('admin/survey', 'sa'=>'view', 'surveyid'=>$iSurveyID));

        }        $qtypes = Question::typeList();
        Yii::app()->loadHelper("admin/activate");

        if (Yii::app()->request->getPost('ok') == '') {
            if (isset($_GET['fixnumbering']) && $_GET['fixnumbering']) {
                fixNumbering($_GET['fixnumbering'], $iSurveyID);
            }

            // Check consistency for groups and questions
            $failedgroupcheck = checkGroup($iSurveyID);
            $failedcheck = checkQuestions($iSurveyID, $iSurveyID, $qtypes);

            $aData['failedcheck'] = $failedcheck;
            $aData['failedgroupcheck'] = $failedgroupcheck;
            $aData['aSurveysettings'] = getSurveyInfo($iSurveyID);

            $this->_renderWrappedTemplate('survey', 'activateSurvey_view', $aData);
        } else {
            if (!is_null($survey)) {
                $survey->anonymized = Yii::app()->request->getPost('anonymized');
                $survey->datestamp = Yii::app()->request->getPost('datestamp');
                $survey->ipaddr = Yii::app()->request->getPost('ipaddr');
                $survey->refurl = Yii::app()->request->getPost('refurl');
                $survey->savetimings = Yii::app()->request->getPost('savetimings');
                $survey->save();

                // Make sure the saved values will be picked up
                Survey::model()->resetCache();
                $survey->setOptions();
            }

            $aResult = $surveyActivator->activate();

            $aViewUrls = array();
            if ((isset($aResult['error']) && $aResult['error'] == 'plugin')
                || (isset($aResult['blockFeedback']) && $aResult['blockFeedback'])) {
                // Got false from plugin, redirect to survey front-page
                $this->getController()->redirect(array('admin/survey', 'sa'=>'view', 'surveyid'=>$iSurveyID));
            } else if (isset($aResult['pluginFeedback'])) {
                // Special feedback from plugin
                $aViewUrls['output'] = $aResult['pluginFeedback'];
            } else if (isset($aResult['error'])) {
                $data['result'] = $aResult;
                $aViewUrls['output'] = $this->getController()->renderPartial('/admin/survey/_activation_error', $data, true);
            } else {
                $warning = (isset($aResult['warning'])) ?true:false;
                $allowregister = $survey->isAllowRegister;
                $onclickAction = convertGETtoPOST(Yii::app()->getController()->createUrl("admin/tokens/sa/index/surveyid/".$iSurveyID));
                $closedOnclickAction = convertGETtoPOST(Yii::app()->getController()->createUrl("admin/tokens/sa/index/surveyid/".$iSurveyID));
                $noOnclickAction = "window.location.href='".(Yii::app()->getController()->createUrl("admin/survey/sa/view/surveyid/".$iSurveyID))."'";

                $activationData = array(
                    'iSurveyID'=>$iSurveyID,
                    'warning'=>$warning,
                    'allowregister'=>$allowregister,
                    'onclickAction'=>$onclickAction,
                    'closedOnclickAction'=>$closedOnclickAction,
                    'noOnclickAction'=>$noOnclickAction,
                );
                $aViewUrls['output'] = $this->getController()->renderPartial('/admin/survey/_activation_feedback', $activationData, true);

            }
            $this->_renderWrappedTemplate('survey', $aViewUrls, $aData);
        }
    }

    /**
     * Function responsible to delete a survey.
     *
     * @access public
     * @param int $iSurveyID
     * @return string
     */
    public function delete($iSurveyID)
    {
        $aData = $aViewUrls = array();
        $aData['surveyid'] = $iSurveyID = (int) $iSurveyID;
        $survey = Survey::model()->findByPk($iSurveyID);

        $aData['sidemenu']['state'] = false;
        $aData['title_bar']['title'] = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        $aData['sidemenu']['state'] = false;
        $aData['survey'] = $survey;


        if (Permission::model()->hasSurveyPermission($iSurveyID, 'survey', 'delete')) {
            if (Yii::app()->request->getPost("delete") == 'yes') {
                $aData['issuperadmin'] = Permission::model()->hasGlobalPermission('superadmin', 'read');
                Survey::model()->deleteSurvey($iSurveyID);
                Yii::app()->session['flashmessage'] = gT("Survey deleted.");
                $this->getController()->redirect(array("admin/index"));
            } else {
                $aViewUrls[] = 'deleteSurvey_view';
            }
        } else {
            Yii::app()->user->setFlash('error', gT("Access denied"));
            $this->getController()->redirect(Yii::app()->request->urlReferrer);
        }


        $this->_renderWrappedTemplate('survey', $aViewUrls, $aData);
    }

    /**
     * Remove files not deleted properly.
     * Purge is only available for surveys that were already deleted but for some reason
     * left files behind.
     * @param int $purge_sid
     * @return void
     */
    public function purge($purge_sid)
    {
        $purge_sid = (int) $purge_sid;
        if (Permission::model()->hasGlobalPermission('superadmin', 'delete')) {
            $survey = Survey::model()->findByPk($purge_sid);
            if (empty($survey)) {
                $result = rmdirr(Yii::app()->getConfig('uploaddir').'/surveys/'.$purge_sid);
                if ($result) {
                    Yii::app()->user->setFlash('success', gT('Survey files deleted.'));
                } else {
                    Yii::app()->user->setFlash('error', gT('Error: Could not delete survey files.'));
                }
            } else {
                // Should not be possible.
                Yii::app()->user->setFlash(
                    'error',
                    gT('Error: Cannot purge files for a survey that is not deleted. Please delete the survey normally in the survey view.')
                );
            }
        } else {
            Yii::app()->user->setFlash('error', gT('Access denied'));
        }

        $this->getController()->redirect(
            $this->getController()->createUrl('admin/globalsettings')
        );
    }

    /**
     * Takes the edit call from the detailed survey view, which either deletes the survey information
     */
    public function editSurvey_json()
    {
        $operation = Yii::app()->request->getPost('oper');
        $iSurveyIDs = Yii::app()->request->getPost('id');
        if ($operation == 'del') {
            // If operation is delete , it will delete, otherwise edit it
            foreach (explode(',', $iSurveyIDs) as $iSurveyID) {
                if (Permission::model()->hasSurveyPermission($iSurveyID, 'survey', 'delete')) {
                    Survey::model()->deleteSurvey($iSurveyID);
                }
            }
        }
    }

    /**
     * New system of rendering content
     * Based on yii submenu rendering
     *
     * @uses self::_generalTabEditSurvey()
     * @uses self::_pluginTabSurvey()
     * @uses self::_tabPresentationNavigation()
     * @uses self::_tabPublicationAccess()
     * @uses self::_tabNotificationDataManagement()
     * @uses self::_tabTokens()
     * @uses self::_tabPanelIntegration()
     * @uses self::_tabResourceManagement()
     *
     * @param int $iSurveyID
     * @param string $subaction
     * @return void
     */
    public function rendersidemenulink($iSurveyID, $subaction)
    {
        $aViewUrls = $aData = [];
        $menuaction = (string) $subaction;
        $iSurveyID = (int) $iSurveyID;
        $survey = Survey::model()->findByPk($iSurveyID);
        // set values from database to survey attributes

        if (empty($survey)) {
            throw new Exception('Found no survey with id ' . $iSurveyID);
        }

        $survey->setOptionsFromDatabase();

        //Get all languages
        $grplangs = $survey->additionalLanguages;
        $baselang = $survey->language;
        array_unshift($grplangs, $baselang);

        //@TODO add language checks here
        $menuEntry = SurveymenuEntries::model()->find('name=:name', array(':name'=>$menuaction));

        if (!(Permission::model()->hasSurveyPermission($iSurveyID, $menuEntry->permission, $menuEntry->permission_grade))) {
            Yii::app()->setFlashMessage(gT("You do not have permission to access this page."), 'error');
            $this->getController()->redirect(array('admin/survey', 'sa'=>'view', 'surveyid'=>$iSurveyID));
        }

        $templateData = is_array($menuEntry->data) ? $menuEntry->data : [];

        if (!empty($menuEntry->getdatamethod)) {
            $templateData = array_merge($templateData, call_user_func_array(array($this, $menuEntry->getdatamethod), array('survey'=>$survey)));
        }

        $templateData = array_merge($this->_getGeneralTemplateData($iSurveyID), $templateData);
        $this->_registerScriptFiles();

        // override survey settings if global settings exist
        $templateData['showqnumcode'] = getGlobalSetting('showqnumcode') !=='choose'?getGlobalSetting('showqnumcode'):$survey->showqnumcode;
        $templateData['shownoanswer'] = getGlobalSetting('shownoanswer') !=='choose'?getGlobalSetting('shownoanswer'):$survey->shownoanswer;
        $templateData['showgroupinfo'] = getGlobalSetting('showgroupinfo') !=='2'?getGlobalSetting('showgroupinfo'):$survey->showgroupinfo;
        $templateData['showxquestions'] = getGlobalSetting('showxquestions') !=='choose'?getGlobalSetting('showxquestions'):$survey->showxquestions;

        //Start collecting aData
        $aData['surveyid'] = $iSurveyID;
        $aData['menuaction'] = $menuaction;
        $aData['template'] = $menuEntry->template;
        $aData['templateData'] = $templateData;
        $aData['surveyls_language'] = $baselang;
        $aData['action'] = $menuEntry->action;
        $aData['entryData'] = $menuEntry->attributes;
        $aData['dateformatdetails'] = getDateFormatData(Yii::app()->session['dateformat']);
        $aData['subaction'] = $menuEntry->title;
        $aData['display']['menu_bars']['surveysummary'] = $menuEntry->title;
        $aData['title_bar']['title'] = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        $aData['surveybar']['buttons']['view'] = true;
        $aData['surveybar']['savebutton']['form'] = 'globalsetting';
        $aData['surveybar']['savebutton']['useformid'] = 'true';
        $aData['surveybar']['saveandclosebutton']['form'] = true;
        $aData['topBar']['closeButtonUrl'] = $this->getController()->createUrl("admin/survey/sa/view/", ['surveyid' => $iSurveyID]); // Close button
        $aData['topBar']['showSaveButton'] = true;

        $aData['optionsOnOff'] = array(
            'Y' => gT('On','unescaped'),
            'N' => gT('Off','unescaped'),
        );

        $aViewUrls[] = $menuEntry->template;

        $this->_renderWrappedTemplate('survey', $aViewUrls, $aData);
    }

    /**
     * Function responsible to import/copy a survey based on $action.
     *
     * @access public
     * @return void
     */
    public function copy()
    {
        $action = Yii::app()->request->getParam('action');
        $iSurveyID = sanitize_int(Yii::app()->request->getParam('sid'));
        $aData = [];

        if ($action == "importsurvey" || $action == "copysurvey") {
            // Start the HTML
            $sExtension = "";

            if ($action == 'importsurvey') {
                $aData['sHeader'] = gT("Import survey data");
                $aData['sSummaryHeader'] = gT("Survey structure import summary");
                $aPathInfo = pathinfo($_FILES['the_file']['name']);

                if (isset($aPathInfo['extension'])) {
                    $sExtension = $aPathInfo['extension'];
                }

            } elseif ($action == 'copysurvey') {
                $aData['sHeader'] = gT("Copy survey");
                $aData['sSummaryHeader'] = gT("Survey copy summary");
            }

            // Start traitment and messagebox
            $aData['bFailed'] = false; // Put a var for continue
            $sFullFilepath = '';
            if ($action == 'importsurvey') {

                $sFullFilepath = Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.randomChars(30).'.'.$sExtension;
                if ($_FILES['the_file']['error'] == 1 || $_FILES['the_file']['error'] == 2) {
                    $aData['sErrorMessage'] = sprintf(gT("Sorry, this file is too large. Only files up to %01.2f MB are allowed."), getMaximumFileUploadSize() / 1024 / 1024).'<br>';
                    $aData['bFailed'] = true;
                } elseif (!in_array(strtolower($sExtension), array('lss', 'txt', 'tsv', 'lsa'))) {
                    $aData['sErrorMessage'] = sprintf(gT("Import failed. You specified an invalid file type '%s'."), CHtml::encode($sExtension));
                    $aData['bFailed'] = true;
                } elseif ($aData['bFailed'] || !@move_uploaded_file($_FILES['the_file']['tmp_name'], $sFullFilepath)) {
                    $aData['sErrorMessage'] = gT("An error occurred uploading your file. This may be caused by incorrect permissions for the application /tmp folder.");
                    $aData['bFailed'] = true;
                }
            } elseif ($action == 'copysurvey') {
                $iSurveyID = sanitize_int(Yii::app()->request->getParam('copysurveylist'));
                $aExcludes = array();

                $sNewSurveyName = Yii::app()->request->getPost('copysurveyname');

                if (Yii::app()->request->getPost('copysurveyexcludequotas') == "1") {
                    $aExcludes['quotas'] = true;
                }

                if (Yii::app()->request->getPost('copysurveyexcludepermissions') == "1") {
                    $aExcludes['permissions'] = true;
                }

                if (Yii::app()->request->getPost('copysurveyexcludeanswers') == "1") {
                    $aExcludes['answers'] = true;
                }

                if (Yii::app()->request->getPost('copysurveyresetconditions') == "1") {
                    $aExcludes['conditions'] = true;
                }

                if (Yii::app()->request->getPost('copysurveyresetstartenddate') == "1") {
                    $aExcludes['dates'] = true;
                }

                if (Yii::app()->request->getPost('copysurveyresetresponsestartid') == "1") {
                    $aExcludes['reset_response_id'] = true;
                }

                if (!$iSurveyID) {
                    $aData['sErrorMessage'] = gT("No survey ID has been provided. Cannot copy survey");
                    $aData['bFailed'] = true;
                } elseif (!Survey::model()->findByPk($iSurveyID)) {
                    $aData['sErrorMessage'] = gT("Invalid survey ID");
                    $aData['bFailed'] = true;
                } elseif (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'export') && !Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'export')) {
                    $aData['sErrorMessage'] = gT("We are sorry but you don't have permissions to do this.");
                    $aData['bFailed'] = true;
                } else {
                    Yii::app()->loadHelper('export');
                    $copysurveydata = surveyGetXMLData($iSurveyID, $aExcludes);
                }
            }

            // Now, we have the survey : start importing
            Yii::app()->loadHelper('admin/import');

            if ($action == 'importsurvey' && !$aData['bFailed']) {
                $aImportResults = importSurveyFile($sFullFilepath, (Yii::app()->request->getPost('translinksfields') == '1'));
                if (is_null($aImportResults)) {
                    $aImportResults = array(
                        'error'=>gT("Unknown error while reading the file, no survey created.")
                    );
                }
            } elseif ($action == 'copysurvey' && !$aData['bFailed']) {

                $aImportResults = XMLImportSurvey('', $copysurveydata, $sNewSurveyName, sanitize_int(App()->request->getParam('copysurveyid')), (Yii::app()->request->getPost('copysurveytranslinksfields') == '1'));
                if (isset($aExcludes['conditions'])) {
                    Question::model()->updateAll(array('relevance'=>'1'), 'sid='.$aImportResults['newsid']);
                    QuestionGroup::model()->updateAll(array('grelevance'=>'1'), 'sid='.$aImportResults['newsid']);
                }

                if (isset($aExcludes['reset_response_id'])) {
                    $oSurvey = Survey::model()->findByPk($aImportResults['newsid']);
                    $oSurvey->autonumber_start = 0;
                    $oSurvey->save();
                }

                if (!isset($aExcludes['permissions'])) {
                    Permission::model()->copySurveyPermissions($iSurveyID, $aImportResults['newsid']);
                }

            } else {
                $aData['bFailed'] = true;
            }

            if ($action == 'importsurvey' && isset($sFullFilepath) && file_exists($sFullFilepath)) {
                unlink($sFullFilepath);
            }

            if (!$aData['bFailed'] && isset($aImportResults)) {
                $aData['aImportResults'] = $aImportResults;
                $aData['action'] = $action;
                if (isset($aImportResults['newsid'])) {
                    $aData['sLink'] = $this->getController()->createUrl('admin/survey/sa/view/surveyid/'.$aImportResults['newsid']);
                    $aData['sLinkApplyThemeOptions'] = 'admin/survey/sa/applythemeoptions/surveyid/'.$aImportResults['newsid'];
                }
            }
        }
        if (!empty($aImportResults['newsid'])) {
            $oSurvey = Survey::model()->findByPk($aImportResults['newsid']);
            LimeExpressionManager::SetDirtyFlag();
            LimeExpressionManager::singleton();
            // Why this @ !
            LimeExpressionManager::SetSurveyId($aImportResults['newsid']);
            LimeExpressionManager::RevertUpgradeConditionsToRelevance($aImportResults['newsid']);
            LimeExpressionManager::UpgradeConditionsToRelevance($aImportResults['newsid']);
            @LimeExpressionManager::StartSurvey($oSurvey->sid, 'survey', $oSurvey->attributes, true);
            LimeExpressionManager::StartProcessingPage(true, true);
            $aGrouplist = QuestionGroup::model()->findAllByAttributes(['sid'=>$aImportResults['newsid']]);
            foreach ($aGrouplist as $aGroup) {
                LimeExpressionManager::StartProcessingGroup($aGroup['gid'], $oSurvey->anonymized != 'Y', $aImportResults['newsid']);
                LimeExpressionManager::FinishProcessingGroup();
            }
            LimeExpressionManager::FinishProcessingPage();
        }

        $this->_renderWrappedTemplate('survey', 'importSurvey_view', $aData);
    }

    /**
     * questiongroup::organize()
     * Load ordering of question group screen.
     *
     * @param int $iSurveyID
     * @return void
     */
    public function organize($iSurveyID)
    {
        $request = Yii::app()->request;

        $iSurveyID = (int) $iSurveyID;

        $thereIsPostData = $request->getPost('orgdata') !== null;
        $userHasPermissionToUpdate = Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'update');

        if (!$userHasPermissionToUpdate) {
            Yii::app()->user->setFlash('error', gT("Access denied"));
            $this->getController()->redirect(Yii::app()->request->urlReferrer);
        }

        if ($thereIsPostData) {
            // Save the new ordering
            $this->_reorderGroup($iSurveyID);

            $closeAfterSave = $request->getPost('close-after-save') === 'true';
            if ($closeAfterSave) {
                $this->getController()->redirect(array('admin/survey/sa/view/surveyid/'.$iSurveyID));
            } else {
                $this->_showReorderForm($iSurveyID);
            }
        } else {
            $this->_showReorderForm($iSurveyID);
        }
    }


    /**
     * Called via ajax request from survey summary quick action "Show questions group by group"
     */
    public function changeFormat($iSurveyID, $format)
    {
        if (Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'update')) {
            if (in_array($format, array('S', 'G', 'A'))) {
                $survey = Survey::model()->findByPk($iSurveyID);
                $survey->format = $format;
                $survey->save();
                echo $survey->format;
            }
        }
    }

    /**
     * Show the form for Organize question groups/questions
     *
     * @todo Change function name to _showOrganizeGroupsAndQuestions?
     * @param int $iSurveyID
     * @return void
     */
    private function _showReorderForm($iSurveyID)
    {
        $survey = Survey::model()->findByPk($iSurveyID);
        $aData = [];
        $aData['title_bar']['title'] = $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";

        // Prepare data for the view
        $sBaseLanguage = Survey::model()->findByPk($iSurveyID)->language;
        LimeExpressionManager::StartSurvey($iSurveyID, 'survey');
        LimeExpressionManager::StartProcessingPage(true, Yii::app()->baseUrl);

        $aGrouplist = QuestionGroup::model()->findAllByAttributes(['sid' => $iSurveyID]);
        $initializedReplacementFields = false;

        $aData['organizebar']['savebuttonright'] = true;
        $aData['organizebar']['closebuttonright']['url'] = $this->getController()->createUrl("admin/survey/sa/view/", array('surveyid' => $iSurveyID));
        $aData['organizebar']['saveandclosebuttonright']['url'] = true;
        $aData['surveybar']['buttons']['view'] = true;
        $aData['surveybar']['savebutton']['form'] = 'frmOrganize';


        foreach ($aGrouplist as $iGID => $aGroup) {
            LimeExpressionManager::StartProcessingGroup($aGroup['gid'], false, $iSurveyID);
            if (!$initializedReplacementFields) {
                templatereplace("{SITENAME}"); // Hack to ensure the EM sets values of LimeReplacementFields
                $initializedReplacementFields = true;
            }

            $oQuestionData = Question::model()->getQuestions($iSurveyID, $aGroup['gid']);

            $qs = array();

            foreach ($oQuestionData->readAll() as $q) {
                $relevance = ($q['relevance'] == '') ? 1 : $q['relevance'];
                $question = '[{'.$relevance.'}] '.$q['title'];
                LimeExpressionManager::ProcessString($question, $q['qid']);
                $q['question'] = viewHelper::stripTagsEM(LimeExpressionManager::GetLastPrettyPrintExpression());
                $q['gid'] = $aGroup['gid'];
                $qs[] = $q;
            }
            $aGrouplist[$iGID]['questions'] = $qs;
            LimeExpressionManager::FinishProcessingGroup();
        }
        LimeExpressionManager::FinishProcessingPage();

        $aData['aGroupsAndQuestions'] = $aGrouplist;
        $aData['surveyid'] = $iSurveyID;

        $this->_renderWrappedTemplate('survey', 'organizeGroupsAndQuestions_view', $aData);
    }

    /**
     * Reorder groups and questions
     * @param int $iSurveyID
     * @return void
     */
    private function _reorderGroup($iSurveyID)
    {
        $grouporder = 1;
        $orgdata = $this->getOrgdata();
        foreach ($orgdata as $ID => $parent) {
            if ($parent == 'root' && $ID[0] == 'g') {
                QuestionGroup::model()->updateAll(array('group_order' => $grouporder), 'gid=:gid', array(':gid' => (int) substr($ID, 1)));
                $grouporder++;
            } elseif ($ID[0] == 'q') {
                $qid = (int) substr($ID, 1);
                $gid = (int) substr($parent, 1);
                if (!isset($aQuestionOrder[$gid])) {
                    $aQuestionOrder[$gid] = 0;
                }

                $sBaseLanguage = Survey::model()->findByPk($iSurveyID)->language;
                $oQuestion = Question::model()->findByPk(array("qid"=>$qid, 'language'=>$sBaseLanguage));
                $oldGid = $oQuestion['gid'];

                if ($oldGid != $gid) {
                    fixMovedQuestionConditions($qid, $oldGid, $gid, $iSurveyID);
                }
                Question::model()->updateAll(array('question_order' => $aQuestionOrder[$gid], 'gid' => $gid), 'qid=:qid', array(':qid' => $qid));
                Question::model()->updateAll(array('gid' => $gid), 'parent_qid=:parent_qid', array(':parent_qid' => $qid));
                $aQuestionOrder[$gid]++;
            }
        }
        LimeExpressionManager::SetDirtyFlag(); // so refreshes syntax highlighting
        Yii::app()->session['flashmessage'] = gT("The new question group/question order was successfully saved.");
    }

    /**
     * Get the new question organization from the post data.
     * This function replaces parse_str, since parse_str
     * is bound by max_input_vars.
     * @return array
     */
    protected function getOrgdata()
    {
        $request = Yii::app()->request;
        $orgdata = $request->getPost('orgdata');
        $ex = explode('&', $orgdata);
        $vars = array();
        foreach ($ex as $str) {
            list($list, $target) = explode('=', $str);
            $list = str_replace('list[', '', $list);
            $list = str_replace(']', '', $list);
            $vars[$list] = $target;
        }

        return $vars;
    }

    /**
     * survey::_fetchSurveyInfo()
     * Load survey information based on $action.
     * @param string $action
     * @param mixed $iSurveyID
     * @deprecated use Survey objects instead
     * @return
     */
    private function _fetchSurveyInfo($action, $iSurveyID = null)
    {
        if ($action == 'newsurvey') {
            $oSurvey = new Survey;
        } elseif ($action == 'editsurvey' && $iSurveyID) {
            $oSurvey = Survey::model()->findByPk($iSurveyID);
        }

        if (isset($oSurvey)) {
            $attribs = $oSurvey->attributes;
            $attribs['googleanalyticsapikeysetting'] = $oSurvey->getGoogleanalyticsapikeysetting();
            return $attribs;
        }
    }

    /**
     * survey::_generalTabNewSurvey()
     * Load "General" tab of new survey screen.
     * @return array
     */
    private function _generalTabNewSurvey()
    {
        // use survey option inheritance
        $user = User::model()->findByPk(Yii::app()->session['loginID']);
        $owner = $user->attributes;
        $owner['full_name'] = 'inherit';
        $owner['email'] = 'inherit';
        $owner['bounce_email'] = 'inherit';

        $aData = [];
        $aData['action'] = "newsurvey";
        $aData['owner'] = $owner;
        $aLanguageDetails = getLanguageDetails(Yii::app()->session['adminlang']);
        $aData['sRadixDefault'] = $aLanguageDetails['radixpoint'];
        $aData['sDateFormatDefault'] = $aLanguageDetails['dateformat'];
        $aRadixPointData = [];
        foreach (getRadixPointData() as $index => $radixptdata) {
            $aRadixPointData[$index] = $radixptdata['desc'];
        }
        $aData['aRadixPointData'] = $aRadixPointData;

        foreach (getDateFormatData(0, Yii::app()->session['adminlang']) as $index => $dateformatdata) {
            $aDateFormatData[$index] = $dateformatdata['dateformat'];
        }
        $aData['aDateFormatData'] = $aDateFormatData;

        return $aData;
    }

    /**
     * @param integer $iSurveyID
     * @return array
     */
    private function _getGeneralTemplateData($iSurveyID)
    {
        $aData = [];
        $aData['surveyid'] = $iSurveyID;
        $oSurvey = Survey::model()->findByPk($iSurveyID);
        if (empty($oSurvey)) {
            $oSurvey = new Survey;
        }
        $inheritOwner = empty($oSurvey->oOptions->ownerLabel) ? $oSurvey->owner_id : $oSurvey->oOptions->ownerLabel;
        $users = getUserList();
        $aData['users'] = array();
        $aData['users']['-1'] = gT('Inherit').' ['. $inheritOwner . ']';
        foreach ($users as $user) {
            $aData['users'][$user['uid']] = $user['user'].($user['full_name'] ? ' - '.$user['full_name'] : '');
        }
        // Sort users by name
        asort($aData['users']);
        $aData['aSurveyGroupList'] = SurveysGroups::getSurveyGroupsList();
        return $aData;
    }

    /**
     * @param Survey $survey
     * @return array
     * tab_edit_view_datasecurity
     * editDataSecurityLocalSettings_view
     */
    private function _getDataSecurityEditData($survey)
    {

        Yii::app()->getClientScript()->registerScript("DataSecTextEditDataGlobal",
            "window.DataSecTextEditData = {
                connectorBaseUrl: '".Yii::app()->getController()->createUrl('admin/survey', ['sid' => $survey->sid, 'sa' => ''])."',
                isNewSurvey: ".($survey->getIsNewRecord() ? "true" : "false").",
                i10N: {
                    'Survey data policy checkbox label:' : '".gT('Survey data policy checkbox label:')."',
                    'Survey data policy error message:' : '".gT('Survey data policy error message:')."',
                    'Survey data policy message:' : '".gT('Survey data policy message:')."',
                    'Don\'t show:' : '".gT('Don\'t show')."',
                    'Inline text' : '".gT('Inline text')."',
                    'Collapsible text' : '".gT('Collapsible text')."',
                    '__INFOTEXT' : '".gT('If you want to specify a link to the survey data policy, set "Show survey policy text with mandatory checkbox" to "Collapsible text" and use the placeholders {STARTPOLICYLINK} and {ENDPOLICYLINK} in the "Survey data policy checkbox label" field to define the link that opens the policy popup. If there is no placeholder given, there will be an appendix.')."',
                    'Deactivated' : '".gT('Deactivated')."',
                    'Activated' : '".gT('Activated')."'
                }
            };", LSYii_ClientScript::POS_BEGIN);

        App()->getClientScript()->registerPackage('ace');
        App()->getClientScript()->registerPackage('datasectextelements');
        $aData = $aTabTitles = $aTabContents = array();
        return $aData;
    }
    /**
     * @param Survey $survey
     * @return array
     */
    private function _getTextEditData($survey)
    {
        Yii::app()->getClientScript()->registerScript("TextEditDataGlobal",
            "window.TextEditData = {
                connectorBaseUrl: '".Yii::app()->getController()->createUrl('admin/survey/', ['sid' => $survey->sid, 'sa' => ''])."',
                isNewSurvey: ".($survey->getIsNewRecord() ? "true" : "false").",
                i10N: {
                    'Survey title' : '".gT('Survey title')."',
                    'Date format' : '".gT('Date format')."',
                    'Decimal mark' : '".gT('Decimal mark')."',
                    'End url' : '".gT('End url')."',
                    'URL description (link text)' : '".gT('URL description (link text)')."',
                    'Description' : '".gT('Description')."',
                    'Welcome' : '".gT('Welcome')."',
                    'End message' : '".gT('End message')."'
                }
            };", LSYii_ClientScript::POS_BEGIN);

        App()->getClientScript()->registerPackage('ace');
        App()->getClientScript()->registerPackage('textelements');
        $aData = $aTabTitles = $aTabContents = array();
        return $aData;
    }

    /**
     * survey::_generalTabEditSurvey()
     * Load "General" tab of edit survey screen.
     * @param Survey $survey
     * @return mixed
     */
    private function _generalTabEditSurvey($survey)
    {
        $aData['survey'] = $survey;
        return $aData;
    }

    /**
     *
     * survey::_pluginTabSurvey()
     * Load "Simple Plugin" page in specific survey.
     * @param Survey $survey
     * @return mixed
     *
     * This method is called via call_user_func in self::rendersidemenulink()
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function _pluginTabSurvey($survey)
    {
        $aData = array();
        $beforeSurveySettings = new PluginEvent('beforeSurveySettings');
        $beforeSurveySettings->set('survey', $survey->sid);
        App()->getPluginManager()->dispatchEvent($beforeSurveySettings);
        $aData['pluginSettings'] = $beforeSurveySettings->get('surveysettings');
        return $aData;
    }
    /**
     * survey::_tabPresentationNavigation()
     * Load "Presentation & navigation" tab.
     * @param mixed $esrow
     * @return array
     */
    private function _tabPresentationNavigation($esrow)
    {
        $aData = [];
        $aData['esrow'] = $esrow;
        return $aData;
    }

    /**
     * survey::_tabPublicationAccess()
     * Load "Publication * access control" tab.
     * @param Survey $survey
     * @return
     */
    private function _tabPublicationAccess($survey)
    {
        $aDateFormatDetails = getDateFormatData(Yii::app()->session['dateformat']);
        $aData = [];
        $aData['dateformatdetails'] = $aDateFormatDetails;
        $aData['survey'] = $survey;
        return $aData;
    }

    /**
     * survey::_tabNotificationDataManagement()
     * Load "Notification & data management" tab.
     * @param mixed $esrow
     * @return array
     */
    private function _tabNotificationDataManagement($esrow)
    {
        $aData = [];
        $aData['esrow'] = $esrow;
        return $aData;
    }

    /**
     * survey::_tabTokens()
     * Load "Tokens" tab.
     * @param mixed $esrow
     * @return array
     */
    private function _tabTokens($esrow)
    {
        $aData = [];
        $aData['esrow'] = $esrow;
        return $aData;
    }

    /**
     * @param Survey $survey
     * @return array
     */
    private function _tabPanelIntegration($survey, $sLang = null)
    {
        $aData = [];
        $oResult = Question::model()->findAll("sid={$survey->sid} AND (type = 'T'  OR type = 'Q'  OR  type = 'T' OR type = 'S')");
        $aQuestions = [];
        foreach ($oResult as $aRecord) {
            $aQuestions[] = array_merge($aRecord->attributes, $aRecord->questionL10ns[$survey->language]->attributes);
        }

        $aData['jsData'] = [
            'i10n' => [
                'ID' => gT('ID'),
                'Action' => gT('Action'),
                'Parameter' => gT('Parameter'),
                'Target question' => gT('Target question'),
                'Survey ID' => gT('Survey id'),
                'Question ID' => gT('Question id'),
                'Subquestion ID' => gT('Subquestion ID'),
                'Add URL parameter' => gT('Add URL parameter'),
                'Edit URL parameter' => gT('Edit URL parameter'),
                'Add URL parameter' => gT('Add URL parameter'),
                'Parameter' => gT('Parameter'),
                'Target question' => gT('Target question'),
                'No target question' => gT('(No target question)'),
                'Are you sure you want to delete this URL parameter?' => gT('Are you sure you want to delete this URL parameter?'),
                'No, cancel' => gT('No, cancel'),
                'Yes, delete' => gT('Yes, delete'),
                'Save' => gT('Save'),
                'Cancel' => gT('Cancel'),
            ],
            "questionList" => $aQuestions,
            "surveyid" => $survey->sid,
            "getParametersUrl" => Yii::app()->createUrl('admin/survey/sa/getUrlParamsJson', array('surveyid' => $survey->sid)),
        ];

        App()->getClientScript()->registerPackage('panelintegration');
        return $aData;
    }

    /**
     * survey::_tabResourceManagement()
     * Load "Resources" tab.
     * @param Survey $survey survey
     * @return mixed
     */
    private function _tabResourceManagement($oSurvey)
    {
        global $sCKEditorURL;

        // TAB Uploaded Resources Management
        $ZIPimportAction = " onclick='if (window.LS.validatefilename(this.form,\"".gT('Please select a file to import!', 'js')."\")) { this.form.submit();}'";
        if (!function_exists("zip_open")) {
            $ZIPimportAction = " onclick='alert(\"".gT("The ZIP library is not activated in your PHP configuration thus importing ZIP files is currently disabled.", "js")."\");'";
        }

        $disabledIfNoResources = '';
        if (hasResources($oSurvey->sid, 'survey') === false) {
            $disabledIfNoResources = " disabled='disabled'";
        }
        $aData = [];
        $aData['ZIPimportAction'] = $ZIPimportAction;
        $aData['disabledIfNoResources'] = $disabledIfNoResources;
        $aData['sCKEditorURL'] = $sCKEditorURL;
        $aData['noform'] = true;

        //KCFINDER SETTINGS
        Yii::app()->session['FileManagerContext'] = "edit:survey:{$oSurvey->sid}";
        Yii::app()->loadHelper('admin.htmleditor');
        initKcfinder();

        return $aData;
    }

    /**
     * @todo
     */
    public function expire($iSurveyID)
    {
        $iSurveyID = (int) $iSurveyID;
        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveysettings', 'update')) {
            Yii::app()->setFlashMessage(gT("You do not have permission to access this page."), 'error');
            $this->getController()->redirect(array('admin/survey', 'sa'=>'view', 'surveyid'=>$iSurveyID));
        }
        Yii::app()->session['flashmessage'] = gT("The survey was successfully expired by setting an expiration date in the survey settings.");
        Survey::model()->expire($iSurveyID);
        $this->getController()->redirect(array('admin/survey/sa/view/surveyid/'.$iSurveyID));
    }

    /**
     * @todo
     */
    public function datetimesettings()
    {
        if (Permission::model()->hasGlobalPermission('surveys', 'read')) {
            $data = array(
                'dateformatsettings'=>getDateFormatData(Yii::app()->session['dateformat']),
                'showClear' => true,
                'allowInputToggle' => true,
            );
            echo json_encode($data);
        }
    }

    /**
     * Action to set expiry date to multiple surveys
     */
    public function expireMultipleSurveys()
    {
        $sSurveys = $_POST['sItems'];
        $aSIDs = json_decode($sSurveys);
        $aResults = array();
        $expires = App()->request->getPost('expires');
        $formatdata = getDateFormatData(Yii::app()->session['dateformat']);
        Yii::import('application.libraries.Date_Time_Converter', true);
        if (trim($expires) == "") {
            $expires = null;
        } else {
            $datetimeobj = new date_time_converter($expires, $formatdata['phpdate'].' H:i'); //new Date_Time_Converter($expires, $formatdata['phpdate'].' H:i');
            $expires = $datetimeobj->convert("Y-m-d H:i:s");
        }

        foreach ($aSIDs as $sid) {
            $survey = Survey::model()->findByPk($sid);
            $survey->expires = $expires;
            $aResults[$survey->primaryKey]['title'] = ellipsize($survey->correct_relation_defaultlanguage->surveyls_title, 30);
            if ($survey->save()) {
                $aResults[$survey->primaryKey]['result'] = true;
            } else {
                $aResults[$survey->primaryKey]['result'] = false;
            }
        }
        Yii::app()->getController()->renderPartial('ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results', array('aResults'=>$aResults, 'successLabel'=>gT('OK')));
    }

    /**
     * @todo
     */
    public function getUrlParamsJSON($iSurveyID)
    {
        $iSurveyID = (int) $iSurveyID;
        $sBaseLanguage = Survey::model()->findByPk($iSurveyID)->language;
        $aSurveyParameters = SurveyURLParameter::model()->findAll('sid=:sid', [':sid' => $iSurveyID]);
        $aData = array(
            'rows' => []
        );
        foreach ($aSurveyParameters as $oSurveyParameter) {
            $row = $oSurveyParameter->attributes;

            if ($oSurveyParameter->targetqid != '') {
                $row['questionTitle'] = $oSurveyParameter->question->title;
            }

            if ($oSurveyParameter->targetsqid != '') {
                $row['subQuestionTitle'] = $oSurveyParameter->subquestion->title;
            }

            $row['qid'] = $oSurveyParameter->targetqid;
            $row['sqid'] = $oSurveyParameter->targetsqid;
            $aData['rows'][] = $row;
        }

        $aData['page'] = 1;
        $aData['records'] = count($aSurveyParameters);
        $aData['total'] = 1;

        echo ls_json_encode($aData);
    }

    /**
     * Executes registerScriptFile for all needed script/style files
     *
     * @return void
     */
    private function _registerScriptFiles()
    {
        App()->getClientScript()->registerPackage('jquery-json');
        App()->getClientScript()->registerPackage('bootstrap-switch');

    }

    /**
     * Saves the new survey after the creation screen is submitted
     *
     * @param integer $iSurveyID The survey id to be used for the new survey. If already taken a new random one will be used.
     * @return string
     */
    public function insert($iSurveyID = null)
    {
        if (Permission::model()->hasGlobalPermission('surveys', 'create')) {
            // Check if survey title was set
            if (Yii::app()->request->getPost('surveyls_title') == '') {
                $alertError = gT("Survey could not be created because it did not have a title");
    
                return Yii::app()->getController()->renderPartial(
                    '/admin/super/_renderJson',
                    array(
                        'data' => array(
                            'alertData' => $alertError,
                            'missingField' => 'surveyls_title'
                        )
                    ),
                    false,
                    false
                );
            }

            Yii::app()->loadHelper("surveytranslator");
            // If start date supplied convert it to the right format
            $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
            $sStartDate = Yii::app()->request->getPost('startdate');
            if (trim($sStartDate) != '') {
                Yii::import('application.libraries.Date_Time_Converter');
                $converter = new Date_Time_Converter($sStartDate, $aDateFormatData['phpdate'].' H:i:s');
                $sStartDate = $converter->convert("Y-m-d H:i:s");
            }

            // If expiry date supplied convert it to the right format
            $sExpiryDate = Yii::app()->request->getPost('expires');
            if (trim($sExpiryDate) != '') {
                Yii::import('application.libraries.Date_Time_Converter');
                $converter = new Date_Time_Converter($sExpiryDate, $aDateFormatData['phpdate'].' H:i:s');
                $sExpiryDate = $converter->convert("Y-m-d H:i:s");
            }

            $iTokenLength = (int) Yii::app()->request->getPost('tokenlength');
            //token length has to be at least 5, otherwise set it to default (15)
            if ($iTokenLength !== -1){
                if ($iTokenLength < 5) {
                    $iTokenLength = 15;
                }
                if ($iTokenLength > 36) {
                    $iTokenLength = 36;
                }
            }

            $aInsertData = array(
                'expires' => $sExpiryDate,
                'startdate' => $sStartDate,
                'template' => App()->request->getPost('template'),
                'admin' => App()->request->getPost('admin'),
                'active' => 'N',
                'anonymized' => App()->request->getPost('anonymized'),
                'faxto' => App()->request->getPost('faxto'),
                'format' => App()->request->getPost('format'),
                'savetimings' => App()->request->getPost('savetimings'),
                'language' => App()->request->getPost('language', Yii::app()->session['adminlang']),
                'datestamp' => App()->request->getPost('datestamp'),
                'ipaddr' => App()->request->getPost('ipaddr'),
                'refurl' => App()->request->getPost('refurl'),
                'usecookie' => App()->request->getPost('usecookie'),
                'emailnotificationto' => App()->request->getPost('emailnotificationto'),
                'allowregister' => App()->request->getPost('allowregister'),
                'allowsave' => App()->request->getPost('allowsave'),
                'navigationdelay' => App()->request->getPost('navigationdelay'),
                'autoredirect' => App()->request->getPost('autoredirect'),
                'showxquestions' => App()->request->getPost('showxquestions'),
                'showgroupinfo' => App()->request->getPost('showgroupinfo'),
                'showqnumcode' => App()->request->getPost('showqnumcode'),
                'shownoanswer' => App()->request->getPost('shownoanswer'),
                'showwelcome' => App()->request->getPost('showwelcome'),
                'allowprev' => App()->request->getPost('allowprev'),
                'questionindex' => App()->request->getPost('questionindex'),
                'nokeyboard' => App()->request->getPost('nokeyboard'),
                'showprogress' => App()->request->getPost('showprogress'),
                'printanswers' => App()->request->getPost('printanswers'),
                'listpublic' => App()->request->getPost('listpublic'),
                'htmlemail' => App()->request->getPost('htmlemail'),
                'sendconfirmation' => App()->request->getPost('sendconfirmation'),
                'tokenanswerspersistence' => App()->request->getPost('tokenanswerspersistence'),
                'alloweditaftercompletion' => App()->request->getPost('alloweditaftercompletion'),
                'usecaptcha' => Survey::saveTranscribeCaptchaOptions(),
                'publicstatistics' => App()->request->getPost('publicstatistics'),
                'publicgraphs' => App()->request->getPost('publicgraphs'),
                'assessments' => App()->request->getPost('assessments'),
                'emailresponseto' => App()->request->getPost('emailresponseto'),
                'tokenlength' => $iTokenLength,
                'gsid'  => App()->request->getPost('gsid', '1'),
                'adminemail' => Yii::app()->request->getPost('adminemail'),
                'bounce_email' => Yii::app()->request->getPost('bounce_email'),

            );

            $warning = '';

            if (!is_null($iSurveyID)) {
                $aInsertData['sid'] = $iSurveyID;
            }

            $newSurvey = Survey::model()->insertNewSurvey($aInsertData);
            if (!$newSurvey->sid) {
                safeDie('Survey could not be created.'); // No error management ?
            }
            $iNewSurveyid = $newSurvey->sid;
            // Prepare locale data for surveys_language_settings table
            $sTitle          = Yii::app()->request->getPost('surveyls_title');
            $sDescription    = Yii::app()->request->getPost('description');
            $sWelcome        = Yii::app()->request->getPost('welcome');

            $sTitle          = html_entity_decode($sTitle, ENT_QUOTES, "UTF-8");
            $sDescription    = html_entity_decode($sDescription, ENT_QUOTES, "UTF-8");
            $sWelcome        = html_entity_decode($sWelcome, ENT_QUOTES, "UTF-8");

            // Fix bug with FCKEditor saving strange BR types
            $sTitle       = fixCKeditorText($sTitle);
            $sDescription = fixCKeditorText($sDescription);
            $sWelcome     = fixCKeditorText($sWelcome);


            // Insert base language into surveys_language_settings table
            $aInsertData = array(
                'surveyls_survey_id'           => $iNewSurveyid,
                'surveyls_title'               => $sTitle,
                'surveyls_description'         => $sDescription,
                'surveyls_welcometext'         => $sWelcome,
                'surveyls_language'            => Yii::app()->request->getPost('language'),
                'surveyls_urldescription'      => Yii::app()->request->getPost('urldescrip', ''),
                'surveyls_endtext'             => Yii::app()->request->getPost('endtext', ''),
                'surveyls_url'                 => Yii::app()->request->getPost('url', ''),
                'surveyls_dateformat'          => (int)Yii::app()->request->getPost('dateformat'),
                'surveyls_numberformat'        => (int)Yii::app()->request->getPost('numberformat'),
                'surveyls_policy_notice'       => App()->request->getPost('surveyls_policy_notice'),
                'surveyls_policy_notice_label' => App()->request->getPost('surveyls_policy_notice_label')
            );

            $langsettings = new SurveyLanguageSetting;
            $langsettings->insertNewSurvey($aInsertData);
            // Update survey permissions
            Permission::model()->giveAllSurveyPermissions(Yii::app()->session['loginID'], $iNewSurveyid);

            // This will force the generation of the entry for survey group
            TemplateConfiguration::checkAndcreateSurveyConfig($iNewSurveyid);
            $createSample = App()->request->getPost('createsample');
            $createSampleChecked = ($createSample === 'on');

            // Figure out destination
            if ($createSampleChecked) {
                $iNewGroupID = $this->_createSampleGroup($iNewSurveyid);
                $iNewQuestionID = $this->_createSampleQuestion($iNewSurveyid, $iNewGroupID);

                Yii::app()->setFlashMessage($warning.gT("Your new survey was created. We also created a first question group and an example question for you."), 'info');
                $landOnSideMenuTab   = 'structure';
                $redirecturl = $this->getSurveyAndSidemenueDirectionURL($iNewSurveyid, $iNewGroupID, $iNewQuestionID, $landOnSideMenuTab);
            } else {
                $redirecturl = $this->getController()->createUrl(
                    'admin/survey/sa/view/',
                    ['surveyid'=>$iNewSurveyid]
                );
                Yii::app()->setFlashMessage($warning.gT("Your new survey was created."), 'info');
            }
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array(
                    'data' => array(
                        'redirecturl' => $redirecturl,
                    )
                ),
                false,
                false
            );
        }
        $this->getController()->redirect(Yii::app()->request->urlReferrer);

    }
    
    /**
     * This method will return the url for the current survey and set the direction for 
     * the sidemenue. 
     * 
     * @param integer $sid 
     * @param integer $gid  
     * @param integer $qid 
     * @return string
     */
    public function getSurveyAndSidemenueDirectionURL($sid, $gid, $qid, $landOnSideMenuTab) {
        $url = 'admin/questioneditor/sa/view/';
        $params = [
            'surveyid' => $sid, 
            'gid'      => $gid, 
            'qid'      => $qid, 
            'landOnSideMenuTab'   => $landOnSideMenuTab];
        return $this->getController()->createUrl($url, $params);
    }

    /**
     * Function to call current Editor Values by Ajax
     *
     * @param integer $sid
     * @return JSON
     */
    public function getCurrentEditorValues($sid){
        $iSurveyId = (int) $sid;
        $oSurvey = Survey::model()->findByPk($iSurveyId);

        $updatePermission = $oSurvey == null
            ? Permission::model()->hasGlobalPermission('surveys', 'create')
            : Permission::model()->hasSurveyPermission($iSurveyId,'surveylocale','update');

        $aLanguages = [];
        $aReturner = [
            "surveyTitle" => [],
            "welcome" => [],
            "description" => [],
            "endText" => [],
            "endUrl" => [],
            "endUrlDescription" => [],
            "dateFormat" => [],
            "decimalDivider" => [],
            "permissions" => [
                "update" => $updatePermission,
                "editorpreset" => Yii::app()->session['htmleditormode'],
            ]
        ];

        if($oSurvey == null ) {
            $defaultLanguage = App()->getConfig('defaultlang');
            $aLanguageDetails = getLanguageDetails($defaultLanguage);
            $aLanguages = [$defaultLanguage => getLanguageCodefromLanguage($defaultLanguage)];
            $aReturner["surveyTitle"][$defaultLanguage] = "";
            $aReturner["welcome"][$defaultLanguage] = "";
            $aReturner["description"][$defaultLanguage] = "";
            $aReturner["endText"][$defaultLanguage] = "";
            $aReturner["endUrl"][$defaultLanguage] = "";
            $aReturner["endUrlDescription"][$defaultLanguage] = "";
            $aReturner["dateFormat"][$defaultLanguage] = $aLanguageDetails['dateformat'];
            $aReturner["decimalDivider"][$defaultLanguage] = $aLanguageDetails['radixpoint'];

            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                ['data' => [
                    "textdata" => $aReturner,
                    "languages" => $aLanguages
                ]],
                false,
                false
            );
        }

        foreach ($oSurvey->allLanguages as $sLanguage) {
            $aLanguages[$sLanguage] = getLanguageNameFromCode($sLanguage,false);
            $aReturner["surveyTitle"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_title;
            $aReturner["welcome"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_welcometext;
            $aReturner["description"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_description;
            $aReturner["endText"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_endtext;
            $aReturner["endUrl"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_url;
            $aReturner["endUrlDescription"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_urldescription;
            $aReturner["dateFormat"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_dateformat;
            $aReturner["decimalDivider"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_numberformat;
        }

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "textdata" => $aReturner,
                "languages" => $aLanguages
            ]],
            false,
            false
        );
    }

    /**
     * Method to call current date information by ajax
     *
     * @param [type] $sid
     * @return JSON
     */
    public function getDateFormatOptions($sid){
        $iSurveyId = (int) $sid;
        $aRawDateFormats = getDateFormatData();

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            ['data' => array_map(function($aDateFormats) {return $aDateFormats['dateformat']; }, $aRawDateFormats)],
            false,
            false
        );
    }

    /**
     * Method to store data edited in the the text editor component
     *
     * @param integer $sid
     * @return JSON
     */
    public function saveTextData($sid){
        $iSurveyId = (int) $sid;
        $changes = Yii::app()->request->getPost('changes');
        $aSuccess = [];
        foreach ($changes as $sLanguage => $contentChange) {
            $oSurveyLanguageSetting = SurveyLanguageSetting::model()->findByPk(["surveyls_survey_id" => $iSurveyId, "surveyls_language" => $sLanguage]);
            if($oSurveyLanguageSetting == null) {
                $oSurveyLanguageSetting = new SurveyLanguageSetting();
                $oSurveyLanguageSetting->surveyls_survey_id = $iSurveyId;
                $oSurveyLanguageSetting->surveyls_language = $sLanguage;
            }
            $oSurveyLanguageSetting->surveyls_title = $contentChange['surveyTitle'];
            $oSurveyLanguageSetting->surveyls_welcometext = $contentChange['welcome'];
            $oSurveyLanguageSetting->surveyls_description = $contentChange['description'];
            $oSurveyLanguageSetting->surveyls_endtext = $contentChange['endText'];
            $oSurveyLanguageSetting->surveyls_url = $contentChange['endUrl'];
            $oSurveyLanguageSetting->surveyls_urldescription = $contentChange['endUrlDescription'];
            $oSurveyLanguageSetting->surveyls_dateformat = $contentChange['dateFormat'];
            $oSurveyLanguageSetting->surveyls_numberformat = $contentChange['decimalDivider'];
            $aSuccess[$sLanguage] = $oSurveyLanguageSetting->save();
            unset($oSurveyLanguageSetting);
        }

        $success = array_reduce($aSuccess, function ($carry, $subsuccess) {
            return $carry = $carry && $subsuccess;
        }, true);

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "success" => $success,
                "message" => ($success ? gT("Successfully stored survey texts") : gT("Error in storing survey texts"))
                ]
            ],
            false,
            false
        );
    }

    /**
     * Collect the data necessary for the data security settings and return a JSON document
     *
     * @param integer $sid
     * @return JSON
     */
    public function getDataSecTextSettings($sid=null) {
        $iSurveyId = (int) $sid;
        $oSurvey = Survey::model()->findByPk($iSurveyId);

        $aLanguages = [];
        $aReturner = [
            "dataseclabel" => [],
            "datasecmessage" => [],
            "datasecerror" => [],
        ];

        if($oSurvey == null ) {

            $defaultLanguage = App()->getConfig('defaultlang');
            $aLanguages = [$defaultLanguage => getLanguageCodefromLanguage($defaultLanguage)];
            $aReturner["datasecmessage"][$defaultLanguage] = "";
            $aReturner["datasecerror"][$defaultLanguage] = "";
            $aReturner["dataseclabel"][$defaultLanguage] = "";

            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                ['data' => [
                    "showsurveypolicynotice" => 0,
                    "textdata" => $aReturner,
                    "languages" => $aLanguages,
                    "permissions" => [
                        "update" => Permission::model()->hasGlobalPermission('surveys','create'),
                        "editorpreset" => Yii::app()->session['htmleditormode'],
                    ]
                ]],
                false,
                false
            );
        }

        foreach ($oSurvey->allLanguages as $sLanguage) {
            $aLanguages[$sLanguage] = getLanguageNameFromCode($sLanguage,false);
            $aReturner["datasecmessage"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_policy_notice;
            $aReturner["datasecerror"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_policy_error;
            $aReturner["dataseclabel"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_policy_notice_label;
        }

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "showsurveypolicynotice" => $oSurvey->showsurveypolicynotice,
                "textdata" => $aReturner,
                "languages" => $aLanguages,
                "permissions" => [
                    "update" => Permission::model()->hasSurveyPermission($iSurveyId,'surveysecurity','update'),
                    "editorpreset" => Yii::app()->session['htmleditormode'],
                ]
            ]],
            false,
            false
        );
    }

    /**
     * Method to store data edited in the the data security text editor component
     *
     * @param integer $sid
     * @return JSON
     */
    public function saveDataSecTextData($sid){
        $iSurveyId = (int) $sid;
        $oSurvey = Survey::model()->findByPk($iSurveyId);
        $changes = Yii::app()->request->getPost('changes', []);
        $aSuccess = [];

        $oSurvey->showsurveypolicynotice = isset($changes['showsurveypolicynotice']) ? $changes['showsurveypolicynotice'] : 0;
        $aSuccess[] = $oSurvey->save();
        foreach ($oSurvey->allLanguages as $sLanguage ) {
            $oSurveyLanguageSetting = SurveyLanguageSetting::model()->findByPk(["surveyls_survey_id" => $iSurveyId, "surveyls_language" => $sLanguage]);
            if($oSurveyLanguageSetting == null) {
                $oSurveyLanguageSetting = new SurveyLanguageSetting();
                $oSurveyLanguageSetting->surveyls_title = "";
                $oSurveyLanguageSetting->surveyls_survey_id = $iSurveyId;
                $oSurveyLanguageSetting->surveyls_language = $sLanguage;
            }

            $oSurveyLanguageSetting->surveyls_policy_notice = isset($changes['datasecmessage'][$sLanguage]) ? $changes['datasecmessage'][$sLanguage] : '';
            $oSurveyLanguageSetting->surveyls_policy_error = isset($changes['datasecerror'][$sLanguage]) ? $changes['datasecerror'][$sLanguage] : '';
            $oSurveyLanguageSetting->surveyls_policy_notice_label = isset($changes['dataseclabel'][$sLanguage]) ? $changes['dataseclabel'][$sLanguage] : '';
            $aSuccess[$sLanguage] = $oSurveyLanguageSetting->save();
            unset($oSurveyLanguageSetting);
        }

        $success = array_reduce($aSuccess, function ($carry, $subsuccess) {
            return $carry = $carry && $subsuccess;
        }, true);

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "success" => $success,
                "message" => ($success ? gT("Successfully stored data security texts") : gT("Error in storing data security texts"))
                ]
            ],
            false,
            false
        );
    }

    /**
     * This private function creates a sample group
     *
     * @param integer $iSurveyID  The survey ID that the sample group will belong to
     */
    private function _createSampleGroup($iSurveyID)
    {
        // Now create a new dummy group
        $sLanguage = Survey::model()->findByPk($iSurveyID)->language;
        $oGroup = new QuestionGroup();
        $oGroup->sid = $iSurveyID;
        $oGroup->group_order = 1;
        $oGroup->grelevance = '1';
        $oGroup->save();
        $oGroupL10ns = new QuestionGroupL10n();
        $oGroupL10ns->gid = $oGroup->gid;
        $oGroupL10ns->group_name = gt('My first question group', 'html', $sLanguage);
        $oGroupL10ns->language = $sLanguage;
        $oGroupL10ns->save();
        LimeExpressionManager::SetEMLanguage($sLanguage);
        return $oGroup->gid;
    }

    /**
     * This private function creates a sample question
     *
     * @param integer $iSurveyID  The survey ID that the sample question will belong to
     * @param mixed $iGroupID  The group ID that the sample question will belong to
     */
    private function _createSampleQuestion($iSurveyID, $iGroupID)
    {
        // Now create a new dummy question
        $sLanguage = Survey::model()->findByPk($iSurveyID)->language;
        $oQuestion = new Question;
        $oQuestion->sid = $iSurveyID;
        $oQuestion->gid = $iGroupID;
        $oQuestion->type = Question::QT_T_LONG_FREE_TEXT;
        $oQuestion->title = 'Q00';
        $oQuestion->mandatory = 'N';
        $oQuestion->relevance = '1';
        $oQuestion->question_order = 1;
        $oQuestion->save();
        $oQuestionLS = new QuestionL10n();
        $oQuestionLS->question = gt('A first example question. Please answer this question:', 'html', $sLanguage);
        $oQuestionLS->help = gt('This is a question help text.', 'html', $sLanguage);
        $oQuestionLS->language = $sLanguage;
        $oQuestionLS->qid = $oQuestion->qid;
        $oQuestionLS->save();
        return $oQuestion->qid;
    }

    /**
     * Renders template(s) wrapped in header and footer
     *
     * @param string $sAction Current action, the folder to fetch views from
     * @param string|array $aViewUrls View url(s)
     * @param array $aData Data to be passed on. Optional.
     */
    protected function _renderWrappedTemplate($sAction = 'survey', $aViewUrls = array(), $aData = array(), $sRenderFile = false)
    {
        parent::_renderWrappedTemplate($sAction, $aViewUrls, $aData, $sRenderFile);
    }

    /**
     * Apply current theme options for imported survey theme
     *
     * @param integer $iSurveyID  The survey ID of imported survey
     */
    public function applythemeoptions($iSurveyID = 0)
    {
        if ((int)$iSurveyID > 0 && Yii::app()->request->isPostRequest) {
            $oSurvey = Survey::model()->findByPk($iSurveyID);
            $sTemplateName = $oSurvey->template;
            $aThemeOptions = json_decode(App()->request->getPost('themeoptions', ''));

            if (!empty($aThemeOptions)){
                $oSurveyConfig = TemplateConfiguration::getInstance($sTemplateName, null, $iSurveyID);
                if ($oSurveyConfig->options === 'inherit'){
                    $oSurveyConfig->setOptionKeysToInherit();
                }

                foreach ($aThemeOptions as $key => $value) {
                        $oSurveyConfig->setOption($key, $value);
                }

                $oSurveyConfig->save();
            }
        }
        $this->getController()->redirect(array('admin/survey/sa/view/surveyid/'.$iSurveyID));
    }

    /**
     * Upload an image in directory
     * @return json
     */
    public function uploadimagefile()
    {
        $iSurveyID = Yii::app()->request->getPost('surveyid');
        $success = false;
        $debug = [];
        if(!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'update')) {
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => gT("You don't have sufficient permissions to upload images in this survey"), 'debug' => $debug]),
                false,
                false
            );
        }
        $debug[] = $_FILES;
        if(empty($_FILES)) {
            $uploadresult = gT("No file was uploaded.");
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
                false,
                false
            );
        }
        if ($_FILES['file']['error'] == 1 || $_FILES['file']['error'] == 2) {
            $uploadresult = sprintf(gT("Sorry, this file is too large. Only files up to %01.2f MB are allowed."), getMaximumFileUploadSize() / 1024 / 1024);
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
                false,
                false
            );
        }
        $checkImage = LSYii_ImageValidator::validateImage($_FILES["file"]);
        if ($checkImage['check'] === false) {
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $checkImage['uploadresult'], 'debug' => $checkImage['debug']]),
                false,
                false
            );
        }
        $surveyDir = Yii::app()->getConfig('uploaddir')."/surveys/".$iSurveyID;
        if (!is_dir($surveyDir)) {
            @mkdir($surveyDir);
        }
        if (!is_dir($surveyDir."/images")) {
            @mkdir($surveyDir."/images");
        }
        $destdir = $surveyDir."/images/";
        if (!is_writeable($destdir)) {
            $uploadresult = sprintf(gT("Incorrect permissions in your %s folder."), $destdir);
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
                false,
                false
            );
        }

        $filename = sanitize_filename($_FILES['file']['name'], false, false, false); // Don't force lowercase or alphanumeric
        $fullfilepath = $destdir.$filename;
        $debug[] = $destdir;
        $debug[] = $filename;
        $debug[] = $fullfilepath;
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $fullfilepath)) {
            $uploadresult = gT("An error occurred uploading your file. This may be caused by incorrect permissions for the application /tmp folder.");
        } else {
            $uploadresult = sprintf(gT("File %s uploaded"), $filename);
            $success = true;
        };
        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
            false,
            false
        );



    }

    public function getTokenTopBar($sid, $onlyclose = false) {
        $oSurvey   = Survey::model()->findByPk($sid);

        return Yii::app()->getController()->renderPartial(
                '/admin/survey/topbar/token_bar',
                array(
                'oSurvey'     => $oSurvey,
                'sid'     => $sid,
                'onlyclose' => !$oSurvey->hasTokensTable
            ),
            false,
            false
      );
    }

    /**
     * This Method is returning the Data for Survey Top Bar Component for Vue JS as JSON.
     * @param $sid
     * @param bool $saveButton
     * @return string|string[]|null
     * @throws CException
     */
    public function getSurveyTopbar($sid, $saveButton = false)
    {
        $oSurvey                       = Survey::model()->findByPk($sid);
        $hasSurveyContentPermission    = Permission::model()->hasSurveyPermission($sid, 'surveycontent', 'update');
        $hasSurveyActivationPermission = Permission::model()->hasSurveyPermission($sid, 'surveyactivation', 'update');
        $hasDeletePermission           = Permission::model()->hasSurveyPermission($sid, 'survey', 'delete');
        $hasSurveyTranslatePermission  = Permission::model()->hasSurveyPermission($sid, 'translations', 'read');
        $hasSurveyReadPermission       = Permission::model()->hasSurveyPermission($sid, 'surveycontent', 'read');
        $hasSurveyTokensPermission     = Permission::model()->hasSurveyPermission($sid, 'surveysettings', 'update')
                                            || Permission::model()->hasSurveyPermission($sid, 'tokens', 'create');
        $hasResponsesCreatePermission  = Permission::model()->hasSurveyPermission($sid, 'responses', 'create');
        $hasResponsesReadPermission    = Permission::model()->hasSurveyPermission($sid, 'responses', 'read');
        $hasResponsesStatisticsReadPermission = Permission::model()->hasSurveyPermission($sid, 'statistics', 'read');

        $isActive  = $oSurvey->active == 'Y';
        $condition = array('sid' => $sid, 'parent_qid' => 0);
        $sumcount  = Question::model()->countByAttributes($condition);
        $countLanguage = count($oSurvey->allLanguages);
        $hasAdditionalLanguages = (count($oSurvey->additionalLanguages) > 0);
        $canactivate = $sumcount > 0 && $hasSurveyActivationPermission;
        $expired     = $oSurvey->expires != '' && ($oSurvey->expires < dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust')));
        $notstarted  = ($oSurvey->startdate != '') && ($oSurvey->startdate > dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust')));

        if (!$isActive) {
            $context = gT("Preview survey");
            $contextbutton = 'preview_survey';
        } else {
            $context =  gT("Execute survey");
            $contextbutton = 'execute_survey';
        }

        $language = $oSurvey->language;
        $conditionsCount = Condition::model()->with(array('questions'=>array('condition'=>'sid ='.$sid)))->count();
        $oneLanguage     = (count($oSurvey->allLanguages) == 1);

        return Yii::app()->getController()->renderPartial(
            '/admin/survey/topbar/survey_topbar',
            array(
                'sid' => $sid,
                'canactivate' => $canactivate,
                'expired' => $expired,
                'notstarted' => $notstarted,
                'context' => $context,
                'contextbutton' => $contextbutton,
                'language' => $language,
                'sumcount' => $sumcount,
                'hasSurveyContentPermission' => $hasSurveyContentPermission,
                'countLanguage' => $countLanguage,
                'hasDeletePermission' => $hasDeletePermission,
                'hasSurveyTranslatePermission' => $hasSurveyTranslatePermission,
                'hasAdditionalLanguages' => $hasAdditionalLanguages,
                'conditionsCount' => $conditionsCount,
                'hasSurveyReadPermission' => $hasSurveyReadPermission,
                'oneLanguage' => $oneLanguage,
                'hasSurveyTokensPermission'    => $hasSurveyTokensPermission,
                'hasResponsesCreatePermission' => $hasResponsesCreatePermission,
                'hasResponsesReadPermission'   => $hasResponsesReadPermission,
                'hasSurveyActivationPermission'   => $hasSurveyActivationPermission,
                'hasResponsesStatisticsReadPermission' => $hasResponsesStatisticsReadPermission,
                'addSaveButton'  => $saveButton,
            ),
            false,
            false
        );
    }
}
