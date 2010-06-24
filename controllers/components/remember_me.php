<?php

class RememberMeComponent extends Object {

	public $components = array('Auth', 'Cookie', 'Session');

	function initialize(&$controller, $settings = array()) {
		$defaults = array(
			'timeout' => '+30 days',
			'field_name' => 'remember_me',
			'token_field' => 'token',
			'token_salt' => 'token_salt'
		);
		$this->Controller = &$controller;
		$this->settings = array_merge($defaults, $settings);
	}

	private function initializeModel() {
		if (!isset($this->userModel)) {
			App::import('Model', $this->Auth->userModel);
			$this->userModel = new $this->Auth->userModel();
		}
	}

	public function setAuthScope() {
		if ($this->Cookie->read($this->Cookie->name) &&
				empty($this->Controller->data[$this->Auth->userModel][$this->settings['field_name']]) && $this->tokenSupports('token_field')) {
			$this->Auth->userScope += array($this->Auth->userModel.'.'.$this->settings['token_field'].' <>' => null);
		}
	}

	protected function tokenSupports($type = '') {
		$this->initializeModel();
		if ($this->userModel->schema($this->settings[$type]) && !empty($this->settings[$type])) {
			return true;
		}
	}

	public function generateHash() {
		return Security::hash(String::uuid(), null, true);
	}

	public function setRememberMe($userData) {
		if ($this->Auth->user()) {
			if (!empty($userData[$this->settings['field_name']])) {
				$this->writeCookie();
			}
		}
	}

	public function checkUser() {
		if ($this->Cookie->read($this->Cookie->name) && !$this->Session->check($this->Auth->sessionKey)) {
			if ($this->tokenSupports('token_field')) {
				$cookieData = $this->checkTokens();
				$this->Auth->login($cookieData['User']['id']);
			} else {
				$cookieData = unserialize($this->Cookie->read($this->Cookie->name));
				$this->Auth->login($cookieData);
			}
		}

		if ($this->Cookie->read($this->Cookie->name) && $this->Session->check($this->Auth->sessionKey) ) {
			$this->rewriteCookie();
		}
	}

	public function logout() {
		$this->clearTokens($this->Auth->user('id'));
		$this->Cookie->delete($this->Cookie->name);
		$this->Session->delete($this->Auth->sessionKey);
		$this->Controller->redirect($this->Auth->logout());
	}

	private function writeCookie() {
		if ($this->tokenSupports('token_field')) {
			$tokens = $this->makeToken($this->Auth->user());
			$this->userModel->id = $this->Auth->user('id');
			if ($this->userModel->save($tokens)) {
				$this->writeTokenCookie($tokens);
			}
		} else {
			foreach ($this->setBasicCookieFields as $keyField) {
				$cookieFields[] = $this->Controller->data['User'][$keyField];
			}
			$this->Cookie->write($this->Cookie->name, serialize($this->Controller->data), true, $this->settings['timeout']);
		}
	}

	public function rewriteCookie() {
		$cookieData = $this->Cookie->read($this->Cookie->name);
		$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
	}

	private function writeTokenCookie($tokens) {
		$cookieData[$this->Auth->fields['username']] = $this->Auth->user($this->Auth->fields['username']);
		$cookieData[$this->settings['token_field']] = $tokens[$this->Auth->userModel][$this->settings['token_field']];
		if ($this->tokenSupports('token_salt')) {
			$cookieData[$this->settings['token_salt']] = $tokens[$this->Auth->userModel][$this->settings['token_salt']];
		}
		$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
	}

	public function checkTokens() {
		if ($this->tokenSupports('token_field')) {
			$this->initializeModel();
			$fields = $this->setTokenFields();
			$cookieData = $this->Cookie->read($this->Cookie->name);
			if (is_array($cookieData) && array_values($fields) === array_keys($cookieData)) {
				$user = $this->getUserByTokens($cookieData);
				if (!empty($user) && $this->tokenSupports('token_salt') && $this->handleHijack($cookieData, $user)) {

				} elseif (empty($user)) {
					$this->logout();
				} else {
					return $user;
				}
			}
		}
	}

	private function setBasicCookieFields() {
		$fields = array($this->Auth->fields['username'], $this->Auth->fields['password']);
		return $fields;
	}

	private function setTokenFields() {
		$fields = array($this->Auth->fields['username'], $this->settings['token_field']);
		if ($this->tokenSupports('token_salt')) {
			$fields[] = $this->settings['token_salt'];
		}
		return $fields;
	}

	private function prepForOr($data) {
		$query['username'] = $data[$this->Auth->fields['username']];
		$query['OR'][$this->settings['token_field']] = $data[$this->settings['token_field']];
		if ($this->tokenSupports('token_salt')) {
			$query['OR'][$this->settings['token_salt']] = $data[$this->settings['token_salt']];
		}
		return $query;
	}

	public function getUserByTokens($cookieData) {
		$this->initializeModel();
		$fields = array('id');
		$fields = array_merge($fields, $this->setTokenFields());
		return $this->userModel->find('first', array('fields' => array_values($fields), 'conditions' => $this->prepForOr($cookieData), 'recursive' => -1));
	}

	private function handleHijack($cookieData, $user) {
		if (($cookieData[$this->settings['token_salt']] == $user[$this->Auth->userModel][$this->settings['token_salt']] &&
			$cookieData[$this->settings['token_field']] != $user[$this->Auth->userModel][$this->settings['token_field']]) ||
			($cookieData[$this->settings['token_salt']] != $user[$this->Auth->userModel][$this->settings['token_salt']])) {
				$this->logout();
				return true;
			}
	}

	public function clearTokens($id) {
		$this->initializeModel();
		$this->userModel->id = $id;
		$userOverride[$this->Auth->userModel][$this->settings['token_field']] = null;
		if ($this->tokenSupports('token_salt')) {
			$userOverride[$this->Auth->userModel][$this->settings['token_salt']] = null;
		}
		$this->userModel->save($userOverride);
	}

	private function makeToken($user = array()) {
		if (!empty($user) && !empty($this->settings['token_field'])) {
			$this->initializeModel();
			if ($this->tokenSupports('token_field')) {
				if ($this->tokenSupports('token_salt')) {
					$tokens[$this->Auth->userModel][$this->settings['token_salt']] = $this->generateHash();
				}
				$tokens[$this->Auth->userModel][$this->settings['token_field']] = $this->generateHash();
				return $tokens;
			}
		}
	}

}

?>