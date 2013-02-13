<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The 46Elks API Library.
 */
class fsElks {
	
	protected $_ci;
	protected $_db;
	
	protected $elks_server;
	
	protected $elks_database;
	protected $elks_defaults_table;
	protected $elks_messages_table;
	protected $elks_numbers_table;
	protected $elks_capabilities_table;
	
	protected $elks_api_username;
	protected $elks_api_password;
	
	protected $elks_sms_callback;
	protected $elks_voice_callback;
	
	protected $rest_config;
	
	protected $functions_numbers = "Numbers";
	protected $functions_sms = "SMS";
	protected $functions_subaccounts = "Subaccounts";
	
	private $number = array();
	
	private $subaccount = array();

	/**
	 * __construct function.
	 * 
	 * @access public
	 * @param array $config (default: array())
	 * @return void
	 */
	function __construct($config = array()) {
		$this->_ci =& get_instance();
		
		// Load the REST client spark!
		$this->_ci->load->spark('restclient/2.1.0');
		
		// Load user config, to be placed in application/config.
		$this->_ci->load->config('46elks');
		$this->set_defaults();
		
		empty($config) OR $this->initialize($config);
		
		if ($this->elks_database) {
			$this->load_database();
			// !TODO: Read stored numbers from the database!
		}
		
		log_message('debug', '46Elks Class Initialized');
	}
	
	/**
	 * initialize function.
	 * 
	 * @access public
	 * @param array $config (default: array())
	 * @return void
	 */
	function initialize($config = array()) {
		if (empty($config)) {
			// Load user config, to be placed in application/config.
			$this->_ci->load->config('46elks');
			$this->set_defaults();
			$this->_ci->rest->initialize($this->rest_config);
		}
		
		$this->_ci->rest->http_header('Content-type: application/x-www-form-urlencoded');
	}
	
	/**
	 * add_number function.
	 * 
	 * @access public
	 * @param array $capabilities (default: array())
	 * @return void
	 */
	function add_number($capabilities = array()) {
		// $capabilities is an array with any combination of "sms" and "voice"
	}
	
	/**
	 * Send an SMS to one or more recipients.
	 * 
	 * @access public
	 * @param string $from either one of your 46Elks numbers or an alphanumeric
	 *        string with < 11 characters (default: null).
	 * @param array $to a list with the recipients as strings, must be valid phone
	 *        numbers with country code, max 2000 numbers (default: array()).
	 * @param string $message the message to send, no maximum length since the
	 *        46Elks framework handles message splitting (default: '').
	 * @param string $delivery_callback an optional callback URL for delivery
	 *        reports, useful only when using the database (default: null).
	 * @return mixed an object with the results from the send action or false on
	 *         failure to send, contains the following data on success:
	 *         $data->
	 *                direction = '',
	 *                from = '',
	 *                created = 'YYYY-MM-DDTHH:MM:SS.mmmmmm',
	 *                to = '+46123456789',
	 *                cost = 3500,
	 *                message = '',
	 *                id = 's493c9609d36ccb4d18d2d1b9db08f8fe'.
	 *        The exception is when sending batches, the only data returned is then
	 *        an array with objects containing the following data:
	 *        $data->
	 *               from = '',
	 */
	function send_message($from = null, $to = array(), $message = '', $delivery_callback = null) {
		if (!is_null($from) && !empty($to) && !empty($message)) {
			// Something in all arguments, let's go.
			
			if (!$this->_is_valid_sender($from)) {
				// Bad sender, break.
				return false;
			}
			// Recipient pruning, get rid of any invalid entries.
			$recipients = array();
			foreach ($to as $recipient) {
				if ($this->_is_valid_recipient($recipient)) {
					// Valid recipient, add to list.
					array_push($recipients, $recipient);
				} else {
					// Bad recipient, log and skip.
					log_message('debug', "46elks::send_message(): Invalid recipient: $recipient, removing from recipient list.");
				}
			}
			if (sizeof($recipients) > 2000) {
				// Too many recipients, break.
				log_message('debug', "46elks::send_message(): Too many recipients, maximum is 2000.");
				return false;
			}
			
			$to = implode(',', $recipients);
			
			$query = array('from' => strval($from), 'to' => strval($to), 'message' => strval($message));
			
			if (!is_null($delivery_callback)) {
				$query['whendelivered'] = $delivery_callback;
			}
			$data = $this->_ci->rest->post($this->functions_sms, $query);
			
			
			if ($this->elks_database) {
				$this->load_database();
				if (is_array($data)) {
					foreach ($data as $idata) {
						$complete = $this->get_history_entry($idata->id);
						$this->_db->insert($this->elks_messages_table, array(
							'id' => $idata->id,
							'to' => $idata->to));
					}
				} else {
					$this->_db->insert($this->elks_messages_table, array(
						'id' => $data->id,
						'from' => $data->from,
						'to' => $data->to,
						'message' => $data->message,
						'sent' => $data->created,
						'cost' => $data->cost,
						'direction' => $data->direction));
				}
				
			}
			return $data;
		}
		return false;
	}
	
	/**
	 * message_delivery function.
	 * 
	 * @access public
	 * @param array $data (default: array())
	 * @return void
	 */
	function message_delivery($data = array()) {
		if (!empty($data)) {
			if ($this->elks_database) {
				$this->load_database();
				$this->_db->where(array('id' => $data['id']));
				$result = $this->_db->update($this->elks_messages_table, array('status' => $data['status'], 'delivered' => $data['delivered']));
				return $result;
			}
		}
		return 0;
	}
	
	/**
	 * subaccount_create function.
	 * 
	 * @access public
	 * @param string $name (default: '')
	 * @return void
	 */
	function subaccount_create($name = '') {
		$data = $this->_ci->rest->post($this->functions_subaccounts.http_build_query(array('name' => $name)));
	}
	
	/**
	 * get_history_entry function.
	 * 
	 * @access public
	 * @param string $id (default: '')
	 * @return void
	 */
	function get_history_entry($id = '') {
		// Check database first if we use it
		if ($this->elks_database) {
			$this->load_database();
			$query = $this->_db->get_where($this->elks_messages_table, array('id' => $id));
			if ($query->num_rows() > 0) {
				return $query->result_array();
			}
			// No hit in the database!
		}
		// Not getting it from the database, order the history through the API
		$history = $this->get_history();
		// Ok, lets start looping...
		$found = false;
		$hit = null;
		while (!$found) {
			foreach ($history['history'] as $data) {
				if ($data->id == $id) {
					// Found it!
					$hit = $data;
					$found = true;
				}
			}
			$this->get_history($history['next']);
			if (empty($history['history']) || is_null($history)) {
				// Nothing more to get, break loop now
				$found = true;
			}
		}
	}
	
	/**
	 * get_history function.
	 * 
	 * @access public
	 * @param mixed $start (default: null)
	 * @return void
	 */
	function get_history($start = null) {
		// Get history
		$start = null;
		if (!is_null($start)) {
			$start = array('start' => $start);
		}
		$history = $this->_ci->rest->get($this->functions_sms, $start);
		$history = $history->data;
		$next = $history->next;
		// We're using the database so let's store everything we get back!
		if ($this->elks_database) {
			$this->load_database();
			foreach ($history as $data) {
				$this->_db->where('id', $data->id);
				$entry = array();
				if ($data->direction) {
					$entry['direction'] = $data->direction;
				}
				if ($data->from) {
					$entry['from'] = $data->from;
				}
				if ($data->to) {
					$entry['to'] = $data->to;
				}
				if ($data->message) {
					$entry['message'] = $data->message;
				}
				if ($data->sent) {
					$entry['sent'] = $data->sent;
				} else if ($data->created) {
					$entry['sent'] = $data->created;
				}
				if ($data->cost) {
					$entry['cost'] = intval($data->cost);
				}
				if ($data->status) {
					$entry['status'] = $data->status;
				}
				if ($data->delivered) {
					$entry['delivered'] = $data->delivered;
				}
				
				$this->_db->update($this->elks_messages_table, $entry);
			}
			
		}
		return array('history' => $history, 'next' => $next);
	}
	
	/**
	 * set_defaults function.
	 * 
	 * @access public
	 * @return void
	 */
	public function set_defaults() {
		log_message('debug', '46Elks::set_defaults(): Setting default values from config file.');
		// Server default.
		$this->elks_server = ($this->_ci->config->item('elks_server')) ? $this->_ci->config->item('elks_server') : '';
		
		// Database defaults.
		$this->elks_database = ($this->_ci->config->item('elks_database')) ? $this->_ci->config->item('elks_database') : false;
		
		$this->elks_defaults_table = ($this->_ci->config->item('elks_defaults_table')) ? $this->_ci->config->item('elks_defaults_table') : '';
		$this->elks_messages_table = ($this->_ci->config->item('elks_messages_table')) ? $this->_ci->config->item('elks_messages_table') : '';
		$this->elks_numbers_table = ($this->_ci->config->item('elks_numbers_table')) ? $this->_ci->config->item('elks_numbers_table') : '';
		$this->elks_capabilities_table = ($this->_ci->config->item('elks_capabilities_table')) ? $this->_ci->config->item('elks_capabilities_table') : '';
		
		// Authentication defaults.
		$this->elks_api_username = ($this->_ci->config->item('elks_api_username')) ? $this->_ci->config->item('elks_api_username') : '';
		$this->elks_api_password = ($this->_ci->config->item('elks_api_password')) ? $this->_ci->config->item('elks_api_password') : '';
		
		// Callback defaults.
		$this->elks_sms_callback   = ($this->_ci->config->item('elks_sms_callback'))   ? $this->_ci->config->item('elks_sms_callback')   : '';
		$this->elks_voice_callback = ($this->_ci->config->item('elks_voice_callback')) ? $this->_ci->config->item('elks_voice_callback') : '';
		
		// Store the REST config.
		$this->rest_config = array(
			'server'    => $this->elks_server,
			'http_auth' => 'basic',
			'http_user' => $this->elks_api_username,
			'http_pass' => $this->elks_api_password
		);
	}
	
	/**
	 * _is_valid_sender function.
	 * 
	 * @access private
	 * @param mixed $from (default: null)
	 * @return void
	 */
	private function _is_valid_sender($from = null) {
		return ( is_string($from) && ( (substr($from, 0, 1) == "+") || (strlen($from < 12)) ) );
	}
	
	/**
	 * _is_valid_recipient function.
	 * 
	 * @access private
	 * @param mixed $to (default: null)
	 * @return void
	 */
	private function _is_valid_recipient($to = null) {
		return ( is_string($to) && (substr($to, 0, 1) == "+") );
	}
	
	/**
	 * load_database function.
	 * 
	 * @access private
	 * @return void
	 */
	private function load_database() {
		if (@$this->_ci->db) {
			$this->_db = $this->_ci->db;
		} elseif (@$this->db) {
			$this->_db = $this->db;
		} else {
			$this->_db = $this->_ci->load->database();
		}
	}
}

?>
