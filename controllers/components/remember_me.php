<?php

class RememberMeComponent extends Object {

	function initialize(&$controller, $settings = array()) {
		$defaults = array(
			'timeout' => '+30 days',
			'field_name' => 'remember_me'
		);

		$this->controller = &$controller;
		$this->Auth = &$controller->Auth;
		$this->Cookie = &$controller->Cookie;
		$this->Session = &$controller->Session;
		$this->settings = array_merge($defaults, $settings);
	}

	function setRememberMe($userInfo) {
		if ($this->Auth->user()) {
			if (!empty($userInfo[$this->settings['field_name']])) {
				$this->controller->data[$this->Auth->userModel][$this->settings['field_name']] = 1;
				$this->Session->write($this->Auth->sessionKey, $this->controller->data[$this->Auth->userModel]);
				$this->Cookie->write($this->Cookie->name, serialize($this->controller->data), true, $this->settings['timeout']);
			}
		}
	}

	function checkUser() {
		if ($this->Cookie->read($this->Cookie->name) && !$this->Session->check($this->Auth->sessionKey)) {
			$cookieData = unserialize($this->Cookie->read($this->Cookie->name));
			if ($this->Auth->login($cookieData)) {
				$this->Session->write($this->Auth->sessionKey, $cookieData);
			}
		} elseif ($this->Session->check($this->Auth->sessionKey.'.'.$this->settings['field_name']) && !$this->Cookie->read($this->Cookie->name)) {
			$this->Cookie->write($this->Cookie->name, serialize($this->Session->read($this->Auth->sessionKey)), true, $this->settings['timeout']);
		}

		if ($this->Cookie->read($this->Cookie->name) && $this->Session->check($this->Auth->sessionKey) ) {
			$cookieData = $this->Cookie->read($this->Cookie->name);
			$this->Cookie->write($this->Cookie->name, $cookieData, true, $this->settings['timeout']);
		}
	}

	function logout() {
		$this->Cookie->delete($this->Cookie->name);
		$this->Session->delete($this->Auth->sessionKey);
		$this->controller->redirect($this->Auth->logout());
	}

}

?>