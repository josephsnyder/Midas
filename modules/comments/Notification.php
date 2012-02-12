<?php
/** notification manager*/
class Comments_Notification extends MIDAS_Notification
  {
  public $moduleName = 'comments';
  public $moduleWebroot = '';

  /** init notification process */
  public function init()
    {
    $fc = Zend_Controller_Front::getInstance();
    $this->moduleWebroot = $fc->getBaseUrl().'/modules/'.$this->moduleName;
    $this->coreWebroot = $fc->getBaseUrl().'/core';

    $this->addCallBack('CALLBACK_CORE_ITEM_VIEW_JS', 'getJs');
    $this->addCallBack('CALLBACK_CORE_ITEM_VIEW_CSS', 'getCss');
    $this->addCallBack('CALLBACK_CORE_ITEM_VIEW_JSON', 'getJson');
    $this->addCallBack('CALLBACK_CORE_ITEM_VIEW_APPEND_ELEMENTS', 'getElement');
    }

  /** Get javascript for the comments */
  public function getJs($params)
    {
    return array($this->moduleWebroot.'/public/js/item/item.comments.js',
                 $this->coreWebroot.'/public/js/jquery/jquery.autogrow-textarea.js');
    }

  /** Get stylesheets for the comments */
  public function getCss($params)
    {
    return array($this->moduleWebroot.'/public/css/item/item.comments.css');
    }

  /** Get the element to render at the bottom of the item view */
  public function getElement($params)
    {
    return array('comment');
    }

  /** Get json to pass to the view */
  public function getJson($params)
    {
    $json = array();
    if($this->userSession->Dao != null)
      {
      $json['username'] = $this->userSession->Dao->getFullName();
      }
    return $json;
    }
  }
?>

