<?php

use TheNetworg\OAuth2\Client\Provider\Azure;
use TheNetworg\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * The authAzure service class.
 *
 * @package authAzure
 */
class AuthAzure
{
    public $modx;
    public $config = array();

    private $accessToken = array();

    function __construct(modX &$modx, array $config = array())
    {
        $this->modx =& $modx;
        $basePath = $this->modx->getOption('authazure.core_path', $config, $this->modx->getOption('core_path') . 'components/authazure/');
        $assetsUrl = $this->modx->getOption('authazure.assets_url', $config, $this->modx->getOption('assets_url') . 'components/authazure/');
        $this->modx->lexicon->load('authazure:default');
        $this->config = array_merge(array(
            'basePath' => $basePath,
            'corePath' => $basePath,
            'vendorPath' => $basePath . 'vendors/',
            'modelPath' => $basePath . 'model/',
            'processorsPath' => $basePath . 'processors/',
            'templatesPath' => $basePath . 'templates/',
            'chunksPath' => $basePath . 'elements/chunks/',
            'jsUrl' => $assetsUrl . 'mgr/js/',
            'cssUrl' => $assetsUrl . 'mgr/css/',
            'assetsUrl' => $assetsUrl,
            'connectorUrl' => $assetsUrl . 'connector.php',
            'ctx' => $this->modx->context->key,

            'loginResourceId' => $this->modx->getOption('authazure.login_resource_id'),
            'defaultGroups' => $this->modx->getOption('authazure.default_groups'),
            'protectedGroups' => $this->modx->getOption('authazure.protected_groups'),
            'adGroupSync' => $this->modx->getOption('authazure.enable_group_sync'),
            'profileImgDir' => $this->modx->getOption('authazure.profile_photo_dir') ?: $assetsUrl . 'web/profile_photos/',
            'profileImgFqp' => $this->modx->getOption('authazure.profile_photo_dir') ? $this->modx->getOption('base_path') . $this->modx->getOption('authazure.profile_photo_dir') : $this->modx->getOption('assets_path') . 'components/authazure/web/profile_photos/',
        ), $config);
        $this->modx->addPackage('authazure', $this->config['modelPath']);
        require_once $this->config['vendorPath'] . 'autoload.php';
    }

    /**
     * Authenticates user and logs them in. Also creates/updates user profile
     *
     * @return void
     */
    public function Login()
    {
        //TODO Refactor to handle user check | profile | login separately
        //TODO ^include better handing of logged in mgr users
        try {
            $this->init();
            $provider = $this->loadProvider();
            $id_token = $this->verifyUser($provider);
        } catch (Exception $e) {
            $this->exceptionHandler($e,true);
        }

        if (isset($id_token) && $id_token) {
            // Set critical
            $username = $id_token['email'];
            //Request 'service to service' access tokens
            try {
                //get msgraph api access token - uses code grant
                $this->accessToken['ms_graph'] = $provider->getAccessToken('authorization_code', [
                    'code' => $id_token['code']
                ]);
            } catch (Exception $e) {
                $this->exceptionHandler($e,true);
            }
            try {
                //get s2s access tokens - on_behalf_of grant
                if ($s2s = $this->modx->getOption('authazure.s2s_configs')) {
                    $s2s = json_decode($s2s);
                    foreach ($s2s as $name => $scopes) {
                        $this->accessToken[$name] = $provider->getAccessToken('jwt_bearer', [
                            'assertion' => $id_token['encoded'], //must be encoded id_token
                            'requested_token_use' => 'on_behalf_of',
                            'scope' => implode(' ',$scopes)
                        ]);
                    }
                }
            } catch (Exception $e) {
                $this->exceptionHandler($e);
            }

            // Check if new or existing user
            /** @var modUser $user */
            if ($user = $this->modx->getObject('modUser', array('username' => $id_token['email']))) {
                try {
                    //user id
                    $uid = $user->get('id');
                    //save id_token to profile
                    //TODO do we need to save id_token to profile...?
                    /** @var AazProfile $aaz_profile */
                    if ($aaz_profile = $this->modx->getObject('AazProfile', array('user_id' => $uid))) {
                        $aaz_profile->set('token', serialize($id_token));
                    } else {
                        $aaz_profile = $this->modx->newObject('AazProfile', array(
                            'user_id' => $uid,
                            'token' => serialize($id_token)
                        ));
                    }
                    $aaz_profile->save();
                    //get msgraph profile
                    $ad_profile = $this->getApi('https://graph.microsoft.com/beta/me', $this->accessToken['ms_graph'], $provider);
                    try {
                        $ad_profile['photoUrl'] = $this->getProfilePhoto($this->accessToken['ms_graph'], $provider);
                    } catch (Exception $e) {
                        $this->exceptionHandler($e);
                        $ad_profile['photoUrl'] = '';
                    }
                    $user_data = array(
                        'id' => $uid,
                        'username' => $username,
                        'fullname' => $ad_profile['givenName'] . ' ' . $ad_profile['surname'],
                        'email' => $ad_profile['mail'],
                        'photo' => $ad_profile['photoUrl'],
                        'defaultGroups' => $this->config['defaultGroups'],
                        'active' => $user->get('active') ?? 0
                    );
                    //sync active directory groups
                    if ($this->config['adGroupSync']) {
                        try {
                            $group_arr = $this->getApi('https://graph.microsoft.com/v1.0/me/memberOf?$select=displayName', $this->accessToken['ms_graph'], $provider);
                            $user_data['adGroupsParent'] = $this->config['adGroupSync'];
                            $user_data['adGroups'] = implode(',', array_column($group_arr['value'], 'displayName'));
                        } catch (Exception $e) {
                            $this->exceptionHandler($e);
                        }
                    }
                    //update user
                    /** @var modProcessorResponse $response */
                    $response = $this->runProcessor('web/user/update', $user_data);
                    if ($response->isError()) {
                        $msg = implode(', ', $response->getAllErrors());
                        throw new Exception('Update user failed: ' . print_r($user_data, true) . '. Message: ' . $msg);
                    }
                    //update profile
                    /** @var modProcessorResponse $response */
                    $response = $this->runProcessor('web/profile/update', array(
                        'id' => $aaz_profile->get('id'),
                        'data' => serialize($ad_profile)
                    ));
                    if ($response->isError()) {
                        $msg = implode(', ', $response->getAllErrors());
                        throw new Exception('Update user profile failed: ' . print_r($user_data, true) . '. Message: ' . $msg);
                    }
                    //update access tokens
                    foreach ($this->accessToken as $k => $v) {
                        //save to cache
                        $token = serialize($v);
                        $this->modx->cacheManager->set($uid . $k, $token, 0, array(xPDO::OPT_CACHE_KEY => 'aaz'));
                    }

                    if (!$user->isMember(explode(',', $this->config['protectedGroups']))) {
                        $login_data = [
                            'username' => $username,
                            'password' => md5(rand()),
                            'rememberme' => $this->modx->getOption('authazure.enable_cookie_lifetime') ?? false,
                            'login_context' => $this->config['ctx']
                        ];
                        $_SESSION['authAzure']['verified'] = true;
                        /** @var modProcessorResponse $response */
                        $response = $this->modx->runProcessor('security/login', $login_data);
                        if ($response->isError()) {
                            $msg = implode(', ', $response->getAllErrors());
                            throw new Exception('Login user failed: ' . print_r($user_data, true) . '. Message: ' . $msg);
                        }
                    } else {
                        $user->addSessionContext($this->config['ctx']);
                    }
                    //redirect after login
                    if (isset($_SESSION['authAzure']['redirectUrl'])) {
                        $this->modx->sendRedirect($_SESSION['authAzure']['redirectUrl']);
                        unset($_SESSION['authAzure']['redirectUrl']);
                    }
                } catch (Exception $e) {
                    $this->exceptionHandler($e, true);
                }
            } else {
                try {
                    //get active directory profile
                    $ad_profile = $this->getApi('https://graph.microsoft.com/beta/me', $this->accessToken['ms_graph'], $provider);
                    try {
                        $ad_profile['photoUrl'] = $this->getProfilePhoto($this->accessToken['ms_graph'], $provider);
                    } catch (Exception $e) {
                        $this->exceptionHandler($e);
                        $ad_profile['photoUrl'] = '';
                    }
                    $user_data = array(
                        'username' => $username,
                        'fullname' => $ad_profile['givenName'] . ' ' . $ad_profile['surname'],
                        'email' => $ad_profile['mail'],
                        'photo' => $ad_profile['photoUrl'],
                        'defaultGroups' => $this->config['defaultGroups'],
                        'active' => 1,
                    );
                    //sync active directory groups
                    if ($this->config['adGroupSync']) {
                        try {
                            $group_arr = $this->getApi('https://graph.microsoft.com/v1.0/me/memberOf?$select=displayName', $this->accessToken['ms_graph'], $provider);
                            $user_data['adGroupsParent'] = $this->config['adGroupSync'];
                            $user_data['adGroups'] = implode(',', array_column($group_arr['value'], 'displayName'));
                        } catch (Exception $e) {
                            $this->exceptionHandler($e);
                        }
                    }
                    //create user
                    /** @var modProcessorResponse $response */
                    $response = $this->runProcessor('web/user/create', $user_data);
                    if ($response->isError()) {
                        $msg = implode(', ', $response->getAllErrors());
                        throw new Exception('Create new user failed: ' . print_r($user_data, true) . '. Message: ' . $msg);
                    }
                    //user id
                    $uid = $response->response['object']['id'];
                    //create profile
                    /** @var modProcessorResponse $response */
                    $response = $this->runProcessor('web/profile/create', array(
                        'user_id' => $uid,
                        'data' => serialize($ad_profile),
                        'token' => serialize($id_token)
                    ));
                    if ($response->isError()) {
                        $msg = implode(', ', $response->getAllErrors());
                        throw new Exception('Create new user profile failed: ' . print_r($user_data, true) . '. Message: ' . $msg);
                    }
                    //update access tokens
                    foreach ($this->accessToken as $k => $v) {
                        //save to cache
                        $token = serialize($v);
                        $this->modx->cacheManager->set($uid . $k, $token, 0, array(xPDO::OPT_CACHE_KEY => 'aaz'));
                    }

                    $login_data = [
                        'username' => $username,
                        'password' => md5(rand()),
                        'rememberme' => $this->modx->getOption('authazure.enable_cookie_lifetime') ?? false,
                        'login_context' => $this->config['ctx']
                    ];
                    $_SESSION['authAzure']['verified'] = true;
                    /** @var modProcessorResponse $response */
                    $response = $this->modx->runProcessor('security/login', $login_data);
                    if ($response->isError()) {
                        $msg = implode(', ', $response->getAllErrors());
                        throw new Exception('Login new user failed: ' . print_r($user_data, true) . '. Message: ' . $msg);
                    }
                    //redirect AFTER login
                    if (isset($_SESSION['authAzure']['redirectUrl'])) {
                        $this->modx->sendRedirect($_SESSION['authAzure']['redirectUrl']);
                        unset($_SESSION['authAzure']['redirectUrl']);
                    }
                } catch (Exception $e) {
                    $this->exceptionHandler($e, true);
                }
            }
        }
    }

    /**
     * Initial checks before auth flow
     *
     * @return void;
     * @throws exception
     */
    public function init()
    {
        try {
            if (!$this->config['loginResourceId']) {
                throw new Exception('User authentication aborted. Login Resource ID not found system settings but is required.');
            }
            if (!$this->config['protectedGroups']) {
                throw new Exception('User authentication aborted. Protected User Groups not found in system settings but is required.');
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Instantiates the provider
     *
     * @return Azure $provider - Provider Object
     * @throws exception
     */
    public function loadProvider()
    {
        $providerConfig = [
            'clientId' => $this->modx->getOption('authazure.client_id'),
            'clientSecret' => $this->modx->getOption('authazure.client_secret'),
            'redirectUri' => $this->modx->getOption('authazure.redirect_url'),
            'metadata' => $this->modx->getOption('authazure.openid_config_url'),
            'responseType' => 'id_token code',
            'responseMode' => 'form_post',
        ];
        try {
            $provider = new TheNetworg\OAuth2\Client\Provider\Azure($providerConfig);
            return $provider;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Authenticates the user against provider and returns verified id_token
     *
     * @param Azure $provider - Provider Object
     *
     * @return array
     * @throws exception
     */
    public function verifyUser(Azure $provider)
    {
        //azure ad error check
        if (isset($_REQUEST['error']) && isset($_REQUEST['error_description'])) {
            throw new Exception($_REQUEST['error_description']);
        }
        if (!isset($_REQUEST['code'])) {
            $nonce = md5(rand());
            $authorizationUrl = $provider->getAuthorizationUrl([
                //scope here sets what available in code used to gain access token
                'scope' => explode(',',$this->modx->getOption('authazure.scopes')),
                'nonce' => $nonce
            ]);
            // Get the state generated for you and store it to the session.
            $_SESSION['authAzure']['oauth2state'] = $provider->getState();
            $_SESSION['authAzure']['oauth2nonce'] = $nonce;
            $_SESSION['authAzure']['redirectUrl'] = $this->getRedirectUrl();
            $_SESSION['authAzure']['active'] = true;
            // Redirect the user to the authorization URL.
            $this->modx->sendRedirect($authorizationUrl);
            exit;
            // Check given state against previously stored one to mitigate CSRF attack
        } elseif (!empty($_REQUEST['state']) && (isset($_SESSION['authAzure']['oauth2state']) && $_REQUEST['state'] == $_SESSION['authAzure']['oauth2state'])) {
            unset($_SESSION['authAzure']['oauth2state']);
            unset($_SESSION['authAzure']['active']);
            if (isset($_REQUEST['id_token'])) {
                //decode and validate id_token received
                $id_token = $provider->validateToken($_REQUEST['id_token']);
                //compare nonce
                if (isset($_SESSION['authAzure']['oauth2nonce']) && $id_token['nonce'] == $_SESSION['authAzure']['oauth2nonce']) {
                    unset($_SESSION['authAzure']['oauth2nonce']);
                    //add in encoded id_token
                    $id_token['encoded'] = $_REQUEST['id_token'];
                    $id_token['code'] = $_REQUEST['code'];
                    //output
                    return $id_token;
                }
                throw new Exception('OAuth2 stored nonce mismatch. User authentication failed and login aborted.');
            }
            throw new Exception('ID Token not received. User authentication failed and login aborted.');
        }
        throw new Exception('OAuth2 stored state mismatch. User authentication failed and login aborted.');
    }

    /**
     * Returns clean redirect url
     *
     * @return string
     */
    public function getRedirectUrl()
    {
        $request = preg_replace('#^' . $this->modx->getOption('base_url') . '#', '', strtok($_SERVER['REQUEST_URI'], '?'));
        $query_str = strtok(''); //gets the rest
        if ($query_str !== false) {
            parse_str($query_str, $query_arr);
            if (!empty($query_arr['authAzure_action'])) {
                unset($query_arr['authAzure_action']);
            }
            if (!empty($query_arr)) {
                $url_params = '?' . http_build_query($query_arr, '', '&amp;');
                $request .= $url_params;
            }
        }
        $url = $this->modx->getOption('site_url') . ltrim(rawurldecode($request), '/');
        $url = preg_replace('#["\']#', '', strip_tags($url));
        return $url;
    }

    /**
     * Returns clean action url
     *
     * @param string $action - authAzure action
     * @param bool $referer - use HTTP_REFERER to generate returned url
     *
     * @return string
     */
    public function getActionUrl(string $action = null, bool $referer = true)
    {
        if ($_SERVER['HTTP_REFERER'] && $referer) {
            $request = preg_replace('#^' . $this->modx->getOption('base_url') . '#', '', strtok($_SERVER['HTTP_REFERER'], '?'));
        } else {
            $request = $this->modx->getOption('site_url');
        }
        $query_str = strtok(''); //gets the rest
        if ($query_str !== false) {
            parse_str(html_entity_decode($query_str), $query_arr);
        } else {
            $query_arr = array();
        }
        if ($action) {
            $query_arr['authAzure_action'] = $action;
        }
        if (!empty($query_arr)) {
            $url_params = '?' . http_build_query($query_arr, '', '&');
            $request .= $url_params;
        }
        $url = preg_replace('#["\']#', '', strip_tags($request));

        return $url;
    }

    /**
     * Returns azure account logout url
     *
     * @param string $return_url - The destination after the user is logged out from their account.
     * @param Azure $provider - Provider Object
     *
     * @return string
     * @throws Exception
     */
    public function getLogoutUrl(string $return_url = null, Azure $provider = null)
    {
        try {
            if (!$return_url) {
                $return_url = $this->modx->getOption('site_url');
            }
            if (!$provider) {
                $provider = $this->loadProvider();
            }
            $url = $provider->getLogoutUrl($return_url);
            return $url;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Custom exception handler
     *
     * @param Throwable $e
     * @param bool|false $fatal
     *
     * @return void;
     */
    public function exceptionHandler(Throwable $e, bool $fatal = false)
    {
        $code = $e->getCode();
        if ($code <= 6 || $fatal) {
            $level = modX::LOG_LEVEL_ERROR;
        } else {
            $level = modX::LOG_LEVEL_WARN;
        }
        //error
        $message = $e->getMessage();
        $error_id = 'AAZ' . uniqid(); //todo only if fatal
        //azure ad error
        if ($e instanceof IdentityProviderException) {
            $trace = $e->getTrace();
            if ($azure_error = $trace[0]['args'][1]['error_description']) {
                $message .= ' - ' . $azure_error;
            };
        }
        $message .= PHP_EOL . 'Error ID: ' . $error_id;
        //log error
        $this->modx->log($level, '[authAzure] ' . $message, '', '', $e->getFile(),$e->getLine());
        if ($fatal) {
            unset($_SESSION['authAzure']);
            $_SESSION['authAzure']['error'] = $error_id;
            if ($id = $this->config['loginResourceId']) {
                $this->modx->sendRedirect($this->modx->makeUrl($id, '', '', 'full'));
            } else {
                $this->modx->sendUnauthorizedPage();
            }
            exit;
        }
    }

    /**
     * Returns access token
     *
     * @param string $name - Service name specified in [[++authazure.s2s_configs]] system setting
     *
     * @return string
     * @throws exception
     */
    public function fetchToken(string $name)
    {
        try {
            /** @var modUser $user */
            if (!$user = $this->modx->getAuthenticatedUser($this->config['ctx'])) {
                throw new Exception("Cannot fetch '{$name}' access token. User is not authenticated.");
            }
            $uid = $user->get('id');
            /** @var AccessToken $token */
            if ($token = unserialize($this->modx->cacheManager->get($uid . $name, array(xPDO::OPT_CACHE_KEY => 'aaz')))) {
                if ($token->hasExpired()) {
                    if ($refresh_token = $token->getRefreshToken()) {
                        try {
                            //refresh
                            $token = $this->loadProvider()->getAccessToken('refresh_token', [
                                'refresh_token' => $refresh_token
                            ]);
                            //save
                            $refreshed_token = serialize($token);
                            $this->modx->cacheManager->set($uid . $name, $refreshed_token, 0, array(xPDO::OPT_CACHE_KEY => 'aaz'));
                        } catch (Exception $e) {
                            $this->exceptionHandler($e);
                            throw new Exception("Cannot refresh '{$name}' access token. Please check error log for more details.");
                        }
                    } else {
                        throw new Exception("Cannot fetch '{$name}' access token. Token is expired and no refresh token found.");
                    }
                }

                return $token->getToken();

            } else {
                throw new Exception("Cannot fetch '{$name}' access token. Token not found.");
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Returns Microsoft Graph response
     *
     * @param string $url - API Endpoint URL
     * @param string $token - Access Token
     * @param Azure $provider - Provider Object
     *
     * @return array
     * @throws exception
     */
    public function getApi(string $url, string $token, Azure $provider)
    {
        try {
            $request = $provider->getAuthenticatedRequest('get', $url, $token);
            $response = $provider->getResponse($request);
            $body = $response->getBody();
            return json_decode($body, true);
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Returns profile photo url
     *
     * @param AccessToken $token - Access Token
     * @param Azure $provider - Provider Object
     *
     * @return string
     * @throws exception
     */
    public function getProfilePhoto(AccessToken $token, Azure $provider)
    {
        try {
            //filename
            if (!$file = $token->getIdTokenClaims()['email']) {
                throw new Exception("Cannot save user profile photo. Missing 'email' in idToken.");
            }
            //directory
            if (!is_dir($this->config['profileImgFqp'])) {
                $dir_perms = $this->modx->getOption('new_folder_permissions') ?? '0755';
                if (!mkdir($this->config['profileImgFqp'],octdec($dir_perms), true)) {
                    throw new Exception("Cannot save user profile photo. {$this->config['profileImgFqp']} directory could not be created.");
                }
            }
            $request = $provider->getAuthenticatedRequest('get', 'https://graph.microsoft.com/beta/me/photo/$value', $token);
            $response = $provider->getResponse($request);
            $body = $response->getBody();
            $path = $this->config['profileImgFqp'] . md5($file) . '.jpg';
            $url = $this->config['profileImgDir'] . md5($file) . '.jpg';
            if (!$photo = fopen($path, "w")) {
                throw new Exception("Cannot save user profile photo. {$path} could not be opened.");
            }
            fwrite($photo, $body);
            fclose($photo);
            return $url;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Shorthand for load and run custom processor
     *
     * @param string $action
     * @param array $scriptProperties
     *
     * @return mixed
     */
    public function runProcessor($action = '', $scriptProperties = [])
    {
        $this->modx->error->reset();
        return $this->modx->runProcessor($action, $scriptProperties, [
                'processors_path' => $this->config['processorsPath'],
            ]
        );
    }

    /**
     * String sanitise
     *
     * @param string $string - The string to sanitize.
     * @param bool $force_lowercase - Force the string to lowercase?
     * @param bool $strict - If set to *true*, will remove all non-alphanumeric characters.
     *
     * @return string
     */
    function sanitize($string, $force_lowercase = false, $strict = true)
    {
        $strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "_", "=", "+", "[", "{", "]",
            "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
            "â€”", "â€“", ",", "<", ".", ">", "/", "?");
        $clean = trim(str_replace($strip, "", strip_tags($string)));
        $clean = preg_replace('/[\s-]+/', "-", $clean);
        $clean = ($strict) ? preg_replace("/[^a-zA-Z0-9-]/", "", $clean) : $clean;

        $output = ($force_lowercase) ? (function_exists('mb_strtolower')) ? mb_strtolower($clean, 'UTF-8') : strtolower($clean) : $clean;

        return $output;
    }
}
