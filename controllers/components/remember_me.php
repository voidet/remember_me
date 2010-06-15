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
				$this->Session->write($this->Auth->userModel, $this->controller->data[$this->Auth->userModel]);
				$this->Cookie->write($this->Auth->userModel, serialize($this->controller->data), true, $this->settings['timeout']);
			}
		}
	}

	function checkUser() {
		if ($this->Cookie->read($this->Auth->userModel) && !$this->Session->check($this->Auth->userModel)) {
			$cookieData = unserialize($this->Cookie->read($this->Auth->userModel));
			$this->Auth->login($cookieData);
			$this->Session->write($cookieData);
		} elseif ($this->Session->check($this->Auth->userModel.'.'.$this->settings['field_name']) && !$this->Cookie->read($this->Auth->userModel)) {
			$this->Cookie->write($this->Auth->userModel, serialize($this->Session->read($this->Auth->userModel)), true, $this->settings['timeout']);
		}

		if ($this->Cookie->read($this->Auth->userModel) && $this->Session->check($this->Auth->userModel) ) {
			$cookieData = $this->Cookie->read($this->Auth->userModel);
			$this->Cookie->write($this->Auth->userModel, $cookieData, true, $this->settings['timeout']);
		}
	}

	function logout() {
		$this->Cookie->destroy();
		$this->Session->delete($this->Auth->userModel);
		$this->controller->redirect($this->Auth->logout());
	}

}

?>