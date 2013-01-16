<?php defined('BASEPATH') OR exit('No direct script access allowed');

class 46Elks {

	function __construct() {
		$this->_ci =& get_instance();
		log_message('debug', '46Elks Class Initialized');
		$this->_ci->load->spark('rest/2.1.0');

		$this->load->config('46elks');
	}
}

?>
