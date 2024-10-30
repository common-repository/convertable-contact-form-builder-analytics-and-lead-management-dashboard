<?php
class ConvertableAPI {

	private static $_api_url;

	public function __construct($args) {
		//error_log('ConvertableAPI->__construct');
		$defaults = array(
			//'api-url' => 'http://convertabledev.info',
			//'api-url' => 'http://convertable.hess.com',
			'api-url' => 'http://convertable.com',
			'api-version' => '1.0'
		);
		$args = wp_parse_args($args, $defaults);
		self::$_api_url = $args['api-url'];
		if (!class_exists('WP_Http')) {
			include_once(ABSPATH.WPINC.'/class-http.php');
		}
	}

	public static function get_api_endpoint($method, $version = '1.0') {
		if (!empty($version))
			$version .= '/';
		return self::$_api_url.'/api/'.$version.$method;
	}

	public function authenticate($username = '', $password = '') {
		//error_log('Convertable API->authenticate('.$username.', '.$password.')');
		$request_url = self::get_api_endpoint('authenticate.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$body = array(
			'convertable_user' => $username,
			'convertable_password' => $password
		);
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $body));
		//error_log('ConvertableAPI->authenticate->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function delete_lead($account_id = 0, $secret_key = '', $lead_id = 0) {
		$request_url = self::get_api_endpoint('delete_lead.php');
		//error_log('ConvertableAPI->delete_lead->request_url: '.$request_url);
		$lead['accountID'] = $account_id;
		$lead['secretKey'] = $secret_key;
		$lead['leadID'] = $lead_id;
		//error_log('ConvertableAPI->delete_lead->lead: '.print_r($lead, true));
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $lead));
		//error_log('ConvertableAPI->delete_lead->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function form($account_id = 0, $secret_key = '') {
		$request_url = self::get_api_endpoint('form.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$body = array(
			'convertable_id' => $account_id,
			'convertable_secret' => $secret_key
		);
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $body));
		//error_log('Convertable API->form->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function lead_data($account_id = 0, $secret_key = '', $lead_id = 0) {
		$request_url = self::get_api_endpoint('lead_data.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$body = array(
			'convertable_id' => $account_id,
			'convertable_secret' => $secret_key,
			'lead_id' => $lead_id
		);
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $body));
		//error_log('Convertable API->form->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function report_data($account_id = 0, $secret_key = '', $page_num = 0, $filter = '', $medium = '') {
		$request_url = self::get_api_endpoint('report_data.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$body = array(
			'convertable_id' => $account_id,
			'convertable_secret' => $secret_key,
			'page_num' => $page_num,
			'filter' => $filter,
			'medium' => $medium
		);
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $body));
		//error_log('Convertable API->form->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function settings($account_id = 0, $secret_key = '', $settings = array()) {
		$request_url = self::get_api_endpoint('settings.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$settings['accountID'] = $account_id;
		$settings['secretKey'] = $secret_key;
		//error_log('Convertable API->settings('.print_r($settings, true).')');
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $settings));
		//error_log('Convertable API->settings->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function signup($username = '', $password = '', $password_conf = '', $site_url = '') {
		//error_log('Convertable API->account('.$username.', '.$password.')');
		$request_url = self::get_api_endpoint('signup.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$body = array(
			'convertable_user' => $username,
			'convertable_password' => $password,
			'convertable_password_conf' => $password_conf,
			'convertable_site_url' => $site_url
		);
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $body));
		//error_log('Convertable API->signup->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

	public function update_form($account_id = 0, $secret_key = '', $thank_you_url = '', $data = array()) {
		$request_url = self::get_api_endpoint('update_form.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$data['accountID'] = $account_id;
		$data['secretKey'] = $secret_key;
		$data['thankYouURL'] = $thank_you_url;
		//error_log('Convertable API->update_form->thank_you_url('.$thank_you_url.')');
		//error_log('Convertable API->update_form->data('.print_r($data, true).')');
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $data));
		//error_log('Convertable API->update_form->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'form' => array(),
				'messages' => array($response->get_error_message())
			));
		} else {
			return $response['body'];
		}
	}

	public function update_lead($account_id = 0, $secret_key = '', $lead_id = 0, $lead = array()) {
		$request_url = self::get_api_endpoint('update_lead.php');
		//error_log('Convertable API->request_url: '.$request_url);
		$lead['accountID'] = $account_id;
		$lead['secretKey'] = $secret_key;
		$lead['leadID'] = $lead_id;
		//error_log('Convertable API->settings('.print_r($settings, true).')');
		$request = new WP_Http;
		$response = $request->request($request_url, array('method' => 'POST', 'timeout' => 90, 'body' => $lead));
		//error_log('Convertable API->settings->response: '.print_r($response, true));
		if (is_wp_error($response)) {
			return json_encode(array(
				'success' => false,
				'message' => $response->get_error_message()
			));
		} else {
			return $response['body'];
		}
	}

}