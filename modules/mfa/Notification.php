<?php
/*=========================================================================
 Midas Server
 Copyright Kitware SAS, 26 rue Louis Guérin, 69100 Villeurbanne, France.
 All rights reserved.
 For more information visit http://www.kitware.com/.

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

         http://www.apache.org/licenses/LICENSE-2.0.txt

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
=========================================================================*/

require_once BASE_PATH.'/modules/api/library/APIEnabledNotification.php';

/** Notification manager for the mfa module */
class Mfa_Notification extends ApiEnabled_Notification
{
    public $moduleName = 'mfa';
    public $_models = array('Setting', 'User');
    public $_moduleComponents = array('Api');

    /** init notification process */
    public function init()
    {
        $fc = Zend_Controller_Front::getInstance();
        $this->moduleWebroot = $fc->getBaseUrl().'/mfa';

        $this->addCallBack('CALLBACK_CORE_GET_CONFIG_TABS', 'getConfigTabs');
        $this->addCallBack('CALLBACK_CORE_AUTH_INTERCEPT', 'authIntercept');
        $this->addCallBack('CALLBACK_API_AUTH_INTERCEPT', 'authInterceptApi');

        $this->enableWebAPI($this->moduleName);
    }

    /**
     * Adds a tab to the user settings that will allow a user to configure
     * authentication using their one-time-password device.
     */
    public function getConfigTabs($params)
    {
        $user = $params['user'];
        $userOtpSetting = $this->Setting->GetValueByName('userOtpControl', 'mfa');
        $userOtpControl = $userOtpSetting === 'true';
        if ($userOtpControl || $this->userSession->Dao->isAdmin()) {
            return array('OTP Device' => $this->moduleWebroot.'/config/usertab?userId='.$user->getKey());
        }
    }

    /**
     * When a user logs in, if they have an OTP device we want to override the normal behavior of writing
     * them to the session, and instead write a temporary session entry that will be moved to the expected
     * place only after they successfully pass the OTP challenge.
     */
    public function authIntercept($params)
    {
        $user = $params['user'];

        /** @var Mfa_OtpdeviceModel $otpDeviceModel */
        $otpDeviceModel = MidasLoader::loadModel('Otpdevice', $this->moduleName);
        $otpDevice = $otpDeviceModel->getByUser($user);
        if ($otpDevice) {
            // write temp user into session for asynchronous confirmation
            Zend_Session::start();
            $userSession = new Zend_Session_Namespace('Mfa_Temp_User');
            $userSession->setExpirationSeconds(60 * min(10, Zend_Registry::get('configGlobal')->session->lifetime)); // "limbo" state should invalidate after 10 minutes
            $userSession->Dao = $user;
            $userSession->lock();

            $resp = JsonComponent::encode(
                array(
                    'dialog' => '/mfa/login/dialog',
                    'title' => 'Enter One-Time Password',
                    'options' => array('width' => 250),
                )
            );

            return array('override' => true, 'response' => $resp);
        } else {
            return array();
        }
    }

    /**
     * Adds support for two-factor authentication using the web api.
     */
    public function authInterceptApi($params)
    {
        $user = null;
        $tokenDao = null;
        if (isset($params['user'])) {
            $user = $params['user'];
        }

        if (isset($params['tokenDao'])) {
            $tokenDao = $params['tokenDao'];
        }

        /** @var Mfa_OtpdeviceModel $otpDeviceModel */
        $otpDeviceModel = MidasLoader::loadModel('Otpdevice', $this->moduleName);
        $otpDevice = $otpDeviceModel->getByUser($user);
        if ($otpDevice) {
            /** @var Mfa_ApitokenModel $tempTokenModel */
            $tempTokenModel = MidasLoader::loadModel('Apitoken', $this->moduleName);
            // create an intermediate token mapping to the real api token
            $mfaToken = $tempTokenModel->createTempToken($user, $tokenDao);

            return array('response' => array('mfa_token_id' => $mfaToken->getKey()));
        } else {
            return array();
        }
    }
}
