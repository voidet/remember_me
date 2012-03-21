#CakePHP RememberMe Component

Currently this is a very basic component that will handle a lot of cookie handling when dealing with autologins and CakePHP's Auth. The component takes in an array of the user's details and sets it into a cookie to use for future login attempts.

##TODO
This component was written very quickly and requires a lot of code cleaning up, of which I will do soon. If you have any extra functions you would like just let me know and may consider integrating them in. Otherwise feel free to fork! Also this component may get renamed to BlueBoy after: http://en.wikipedia.org/wiki/Blue_Boy_(DJ)#Remember_Me

##Installation
Install the plugin:

	cd myapp
	git clone git://github.com/voidet/remember_me.git app/Plugin/RememberMe

Depending on which user controller you would like the RememberMe functions to work on, open up the controller and type in.

	public $components = array('RememberMe.RememberMe');

In order to log a user in and set the cookie information you can use something like this in your login action in your controller:

	function members_login() {
		if ($this->Auth->user()) {
			if (!empty($this->data)) {
				$this->RememberMe->setRememberMe($this->data[$this->Member->alias]);
			}
			$this->redirect($this->Auth->loginRedirect);
		}
	}

##Refresh Cookie Expiry Times

Keeping cookie expiry dates in relation to the user's last action can be done in the AppController using the checkUser method in RememberMe. For example you may like to place it in your AppController like:

	function _rememberMember() {
		if ($this->params['action'] != 'members_logout') {
			$this->RememberMe->checkUser();
		}
	}
