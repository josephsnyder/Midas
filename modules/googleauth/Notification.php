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

/** Notification manager for the googleauth module */
class Googleauth_Notification extends MIDAS_Notification
{
    public $moduleName = 'googleauth';
    public $_models = array('Setting', 'User', 'Userapi');
    public $_moduleModels = array('User');

    /** init notification process */
    public function init()
    {
        $this->addCallBack('CALLBACK_CORE_LOGIN_EXTRA_HTML', 'googleAuthLink');
        $this->addCallBack('CALLBACK_CORE_USER_DELETED', 'handleUserDeleted');
        $this->addCallBack('CALLBACK_CORE_USER_COOKIE', 'checkUserCookie');
    }

    /**
     * Constructs the link that is used to initiate a google oauth authentication.
     * This link redirects the user to google so they can approve of the requested
     * oauth scopes, and in turn google will redirect them back to our callback
     * url with an authorization code.
     */
    public function googleAuthLink()
    {
        $clientId = $this->Setting->getValueByName(GOOGLE_AUTH_CLIENT_ID_KEY, $this->moduleName);
        $scheme = (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS']) ? 'https://' : 'http://';
        $fc = Zend_Controller_Front::getInstance();

        /** @var RandomComponent $randomComponent */
        $randomComponent = MidasLoader::loadComponent('Random');
        $csrfToken = $randomComponent->generateString(30);
        $redirectUri = $scheme.$_SERVER['HTTP_HOST'].$fc->getBaseUrl().'/'.$this->moduleName.'/callback';
        $scopes = array('profile', 'email');

        $href = 'https://accounts.google.com/o/oauth2/auth?response_type=code'.'&client_id='.urlencode(
                $clientId
            ).'&redirect_uri='.urlencode($redirectUri).'&scope='.urlencode(
                implode(
                    ' ',
                    $scopes
                )
            ).'&state='.urlencode($csrfToken);

        $userNs = new Zend_Session_Namespace('Auth_User');
        $userNs->oauthToken = $csrfToken;
        session_write_close();

        return '<div style="margin-top: 10px; display: inline-block;">Or '.'<a class="googleauth-login" style="text-decoration: underline;" href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'">'.'Login with your Google account</a></div><script type="text/javascript"'.' src="'.$fc->getBaseUrl(
        ).'/modules/'.$this->moduleName.'/public/js/login/googleauth.login.js"></script>';
    }

    /**
     * If a user is deleted, we must delete any corresponding google auth user.
     */
    public function handleUserDeleted($params)
    {
        $this->Googleauth_User->deleteByUser($params['userDao']);
    }

    /** Check user cookie */
    public function checkUserCookie($args)
    {
        $cookie = $args['value'];

        if (strpos($cookie, 'googleauth') === 0) {
            list(, $userId, $apikey) = preg_split('/:/', $cookie);
            $userDao = $this->User->load($userId);

            if (!$userDao) {
                return false;
            }

            $userapi = $this->Userapi->getByAppAndUser('Default', $userDao);

            if (!$userapi) {
                return false;
            }
            if (md5($userapi->getApikey()) === $apikey) {
                return $userDao;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
}
