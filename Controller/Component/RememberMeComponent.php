<?php
/**
 * RememberMe Component - Token driven AutoLogin Component for CakePHP
 *
 * http://www.jotlab.com
 * http://www.github/voidet/remember_me
 *
 **/

App::uses('BaseAuthenticate', 'Controller/Component/Auth');
App::uses('Component', 'Controller');
class RememberMeComponent extends Component {

/**
 * Include the neccessary components for RememberMe to function with
 */
	public $components = array('Auth', 'Cookie', 'Session');

/**
	* @param array $settings overrides default settings for token fieldnames and data fields
	* @return false
*/
	function initialize($Controller, $settings = array()) {
		$defaults = array(
			'timeout' => '+1 month',
			'field_name' => 'remember_me',
			'token_field' => 'token',
			'token_salt' => 'token_salt'
		);

		$this->Controller = $Controller;
		$this->settings = array_merge($defaults, $settings);
	}

/**
	* initializeModel method loads the required model if not previously loaded
	* @return false
	*/
	private function initializeModel() {
		if (!isset($this->userModel)) {
			$userModel = $this->AuthSettings['userModel'];
			if (empty($userModel)) {
				die('Please specify what user model to authenticate against');
			}
			App::import('Model', $userModel);
			$this->userModel = new $userModel;
		}
	}

/**
	* tokenSupports checks to see whether or not the current setup supports tokenizing or tokeniz\ing with series
	* @param type specifies which field & setting is functional
	* @return bool
	*/
	protected function tokenSupports($type = '') {
		$this->initializeModel();
		if (@$this->userModel->schema($this->settings[$type]) && !empty($this->settings[$type])) {
			return true;
		}
	}

/**
	* generateHash is a simple uuid to SHA1 with salt handler
	* @return string(40)
	*/
	public function generateHash() {
		return Security::hash(String::uuid(), null, true);
	}

/**
	* setRememberMe checks to see if a user cookie should be initially set, if so dispatches it to the writeCookie method
	* @param array containing the models data without model as the key
	* @return false
	*/
	public function setRememberMe($userData) {
		if ($this->Auth->user()) {
			if (!empty($userData[$this->settings['field_name']])) {
				$this->writeCookie($this->Auth->user());
			}
		}
	}

/**
	* writeTokenCookie stores token information and username in a cookie for future cross referencing
	* @param tokens array holds token and token salt
	* @return false
	*/
	private function writeTokenCookie($tokens = array(), $userData = array()) {
		$cookieData[$this->AuthSettings['fields']['username']] = $userData[$this->AuthSettings['fields']['username']];
		$cookieData[$this->settings['token_field']] = $tokens[$this->settings['token_field']];
		if ($this->tokenSupports('token_salt')) {
			$cookieData[$this->settings['token_salt']] = $tokens[$this->settings['token_salt']];
		}
		$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
	}

/**
	* setupUser Simple dispatcher for setting up extra userScope params and checking the user's cookie
	* @return false
	*/
	public function setupUser() {
		$auth = $this->Auth->constructAuthenticate();
		$this->AuthSettings = reset($auth)->settings;
		$this->setUserScope();
		$this->checkUser();
	}

/**
	* setUserScope public method must be called manually in beforeFilter
	* It will then add in extra userscope conditions to authorise a user against
	* @return false
	*/
	protected function setUserScope() {
		if ($this->Cookie->read($this->Cookie->name) &&
				empty($this->Controller->data[$this->Auth->userModel][$this->settings['field_name']]) && $this->tokenSupports('token_field')) {
			$tokenField = $this->Auth->userModel.'.'.$this->settings['token_field'];
			$cookieData = $this->Cookie->read($this->Cookie->name);
			unset($this->Auth->userScope[$tokenField]);
			$this->Auth->userScope += array($tokenField => $cookieData[$this->settings['token_field']]);
		}
	}

/**
	* checkUser is used to firstly check if a valid cookie exists and if so reestablish their session
	* and secondly update the timeout expiry to stay current to the defined expiry time in relation to last user action.
	* @return false
	*/
	public function checkUser() {
		if ($this->Cookie->read($this->Cookie->name) && !$this->Session->check('Auth.'.$this->Auth->userModel)) {

			$cookieData = $this->Cookie->read($this->Cookie->name);

			if ($this->tokenSupports('token_field')) {
				$userData = $this->checkTokens();
				if ($userData) {
					$this->setUserScope();
				}
				$user = $this->getUserByTokens($cookieData, true);
				$this->Auth->login($user);
			} else {
				$this->Auth->login($cookieData);
			}
		}

		if ($this->Cookie->read($this->Cookie->name) && $this->Session->check('Auth.'.$this->Auth->userModel)) {
			$this->rewriteCookie();
		}
	}

/**
	* checkTokens A method determining whether or not the user matches the information in its RememberMe cookie
	* @return array
	*/
	public function checkTokens() {
		if ($this->tokenSupports('token_field')) {
			$this->initializeModel();
			$fields = $this->setTokenFields();
			$cookieData = $this->Cookie->read($this->Cookie->name);
			if (is_array($cookieData) && array_values($fields) === array_keys($cookieData)) {
				$user = $this->getUserByTokens($cookieData);
				if (!empty($user) && $this->tokenSupports('token_salt') && $this->handleHijack($cookieData, $user)) {
					return false;
				} elseif (empty($user)) {
					$this->logout(false);
				} else {
					$this->writeCookie($user);
					return $user;
				}
			}
		}
	}

/**
	* writeCookie Tests if a token should be used or failover to basic cookie auth
	* if token method then generate tokens and assign them to a user then save
	* @return false
	*/
	private function writeCookie($userData = array()) {
		if ($this->tokenSupports('token_field')) {
			$tokens = $this->makeToken($userData);
			$this->userModel->id = $userData[$this->userModel->primaryKey];
			if ($this->userModel->id && $this->userModel->save($tokens)) {
				$this->writeTokenCookie($tokens, $userData);
			}
		} else {
			foreach ($this->setBasicCookieFields() as $keyField) {
				$cookieFields[] = $this->Controller->data[$this->Auth->userModel][$keyField];
			}
			$this->Cookie->write($this->Cookie->name, serialize($this->Controller->data), true, $this->settings['timeout']);
		}
	}

/**
	* logout clears user Cookie, Session and flushes tokens & salt from the database then redirects to logout action.
	* @param bool Handles whether to clear out all tokens and salt (manual logout) or keep Authentic user in but kick hijackers out
	* @param array Holds user data to be used for clearing out fields
	* @return false
	*/
	public function logout($flushTokens = false, $user = array(), $redirect = true) {
		//A Manual logout called, log out all users, not just hijackers
		if ($this->tokenSupports('token_field') && $flushTokens === true) {
			if (empty($user) && $this->Auth->user()) {
				$user = $this->Auth->user();
			}
			$this->clearTokens($user[$this->userModel->primaryKey]);
		}
		$this->Cookie->destroy();
		$this->Session->destroy();
		if ($redirect == true) {
			$this->Controller->redirect($this->Auth->logout());
		} else {
			$this->Auth->logout();
		}
	}

/**
	* rewriteCookie updates the timeout of the cookie from last action
	* @return false
	*/
	public function rewriteCookie() {
		$cookieData = $this->Cookie->read($this->Cookie->name);
		$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
	}

/**
	* setBasicCookieFields a method for specifying fields used by AUth
	* @return array
	*/
	private function setBasicCookieFields() {
		$fields = array($this->AuthSettings['fields']['username'], $this->AuthSettings['fields']['password']);
		return $fields;
	}

/**
	* setTokenFields a method for specifying token based fields
	* @return array
	*/
	private function setTokenFields() {
		$fields = array($this->AuthSettings['fields']['username'], $this->settings['token_field']);
		if ($this->tokenSupports('token_salt')) {
			$fields[] = $this->settings['token_salt'];
		}
		return $fields;
	}

/**
	* prepForOr Used for turning token and authScope conditions into a queryable array
	* @return array
	*/
	private function prepForOr($data) {
		$query['username'] = $data[$this->AuthSettings['fields']['username']];
		$query['OR'][$this->settings['token_field']] = $data[$this->settings['token_field']];
		if ($this->tokenSupports('token_salt')) {
			$query['OR'][$this->settings['token_salt']] = $data[$this->settings['token_salt']];
		}
		$conditions = array_merge($query, $this->Auth->userScope);
		return $conditions;
	}

/**
	* getUserByTokens returns user information based on authScope and cookie information
	* @return array
	*/
	public function getUserByTokens($cookieData, $allFields = false) {
		$this->initializeModel();
		$fields = array();
		if ($allFields === false) {
			$fields = array($this->userModel->primaryKey);
			$fields = array_merge($fields, $this->setTokenFields());
		}
		$user = $this->userModel->find('first', array('fields' => array_values($fields), 'conditions' => $this->prepForOr($cookieData), 'recursive' => -1));
		return $user[$this->userModel->alias];
	}

/**
	* handleHijack Tests to see whether or not the presented cookie data matches that of in the database
	* if it doesnt call the logout function which will clear the thief and victim
	* @return bool
	*/
	private function handleHijack($cookieData, $user) {
		if (($cookieData[$this->settings['token_salt']] == $user[$this->settings['token_salt']] &&
			$cookieData[$this->settings['token_field']] != $user[$this->settings['token_field']]) ||
			($cookieData[$this->settings['token_salt']] != $user[$this->settings['token_salt']])) {
				$this->logout(false, $user);
				return true;
			}
	}

/**
	* clearTokens Clears user's token and token salt fields
	* @return false
	*/
	public function clearTokens($id = '') {
		$this->initializeModel();
		$this->userModel->id = $id;
		$userOverride[$this->settings['token_field']] = null;
		if ($this->tokenSupports('token_salt')) {
			$userOverride[$this->settings['token_salt']] = null;
		}
		if ($id) {
			$this->userModel->save($userOverride);
		}
	}

/**
	* makeToken sets token and token salts to an array used for future saving
	* @return array
	*/
	private function makeToken($user = array()) {
		if (!empty($user) && !empty($this->settings['token_field'])) {
			$this->initializeModel();
			if ($this->tokenSupports('token_field')) {
				if ($this->tokenSupports('token_salt')) {
					if (!empty($user['token_salt'])) {
						$tokens[$this->settings['token_salt']] = $user['token_salt'];
					} else {
						$tokens[$this->settings['token_salt']] = $this->generateHash();
					}
				}
				if (empty($this->Controller->data[$this->settings['field_name']]) && $this->Auth->user($this->settings['token_field'])) {
					$tokens[$this->settings['token_field']] = $this->Auth->user($this->settings['token_field']);
				} else {
					$tokens[$this->settings['token_field']] = $this->generateHash();
				}
				return $tokens;
			}
		}
	}

}