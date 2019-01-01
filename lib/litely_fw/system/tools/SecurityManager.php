<?php
/**
 * Created by PhpStorm.
 * User: ballison
 * Date: 10/30/2017
 * Time: 3:24 PM
 */

class SecurityManager {

	/**
	 * @var
	 */
	protected $auth;
	public $authenticated = false;

	private $override_dos_cox_ids = [
		'00159697',
		'00150229',
		'00134402',
		'00124096',
		'00451634',
		'00167371'
	];

	/**
	 * SecurityManager constructor.
	 * @param null $auth
	 */
	public function __construct($auth=null) {
		global $s;
		if( isset($auth) ) {
			$this->auth = $auth;
		}
	}

	/**
	 * @return mixed
	 */
	public function getAuth() {
		return $this->auth;
	}

	/**
	 * @param mixed $auth
	 */
	public function setAuth($auth) {
		$this->auth = $auth;
	}

	/**
	 * @param $field_name
	 * @return null
	 */
	public function get($field=null) {
		if( !is_string($field) || !strlen($field) ) return $this->auth;
		return $this->auth[$field];
	}

	/**
	 * @param $field_name
	 * @param $value
	 * @return mixed
	 */
	public function set($field_name, $value) {
		return $this->auth[$field_name] = $value;
	}

	/**
	 * @param $auth
	 * @return mixed
	 */
	public function update($auth) {
		foreach ($auth as $key => $value) {
			$this->auth[$key] = $value;
		}
		return $this->auth;
	}

	/**
	 * @return SecurityManager
	 */
	public static function getSecurityManager() {
		global $_auth;
		if(!isset($_auth)) $_auth = new self();
		return $_auth; 
	}

	/**
	 * log into tool using current authentication methods. redirect if needed.
	 * @param $auth array
	 */
	public function surveyAuthorize() {
		global $r, $s, $dbSurvey, $apiDarwin, $tformat, $auth_ip, $log_dir;

		if( $r["_survey_auth_type"] ) $s["survey_auth_type"] = $r["_survey_auth_type"];
		$auth_type = $s["survey_auth_type"] ? $s["survey_auth_type"] : 1;

		$auth = $this->auth;
		if( empty($auth) ) $auth = array("id"=>0, "authorized"=>0, "type"=>$auth_type);

		if( is_object($auth) ) $auth = get_object_vars($auth);
		if( !is_object($dbSurvey) || !is_object($apiDarwin) ) {
			$auth["authorized"] = 0;
			unset($auth["id"]);
			return;
		}
		if( $auth["ip"] && $auth["ip"] != md5("__".$_SERVER["REMOTE_ADDR"]) ) {
			$auth["authorized"] = 0;
			unset($auth["id"]);
		}

		if( preg_match("@^/(favicon|robots|sitemap|static|testing)@i",$_SERVER["PHP_SELF"]) ) {
			return;
		} else if( ( !$auth["authorized"] || preg_match("@\/login@i",$_SERVER["PHP_SELF"]) ) && $_SERVER["PHP_SELF"] != "/survey/login/" ) {
			$s["redir_url"] = preg_replace("@\/login\/?@i", "", $_SERVER["REQUEST_URI"]);
			header("Location: /survey/login/");
			exit;
		} else if( preg_match("@\/logout@i",$_SERVER["PHP_SELF"]) && $_SERVER["PHP_SELF"] != "/survey/logout/" ) {
			$s["redir_url"] = preg_replace("@\/logout\/?@i", "", $_SERVER["REQUEST_URI"]);
			unset($auth);
			session_destroy();
			header("Location: /survey/logout/");
			exit;
		} else if( $auth["authorized"] && $_SERVER["PHP_SELF"] == "/survey/login/" ) {
			$redir_url = $s["redir_url"] ? $s["redir_url"] : "http://".$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
			$redir_url = preg_replace("/\/(favicon\.ico|bg.*|includes.*|controllers.*|login.*|logout.*|home.*)/", "/", $redir_url);
			if( !preg_match("@\/$@",$redir_url) ) $redir_url .= "/";
			header("Location: ".$redir_url);
			$s["redir_url"] = "";
			exit;
		} else if( $auth["authorized"] == 1 ) {
			$this->authenticated = true;
			return; // all good.
		}

		// anything past this means the user is not authorized
		$this->authenticated = false;

		// just in case check... if not on the login screen, go there
		if( $_SERVER["PHP_SELF"] != "/survey/login/" ) {
			$s["redir_url"] = preg_replace("@/login/?@i", "", $_SERVER["REQUEST_URI"]);
			header("Location: /survey/login/");
			exit;
		}

		$uname = $auth["type"] == "1" ? trim($r["_survey_uname"]) : trim($r["_survey_email"]);
		$pwd = $auth["type"] == "1" ? trim($r["_survey_pwd"]) : trim($r["_survey_token"]);
		if( $_SERVER["REQUEST_METHOD"] == "POST" && !empty($uname) && !empty($pwd) ) {
			$info = getBrowserInfo();
			if( !is_numeric($s["login_attempts"][$uname]) ) $s["login_attempts"][$uname] = 0;
			$s["login_attempts"][$uname]++;

			// prevent too many retries
			if( $s["login_attempts"][$uname] > 5 ) {
				$message = '<b style="color:#f36;">You have failed to log in too many times.<br>Please contact <a href="mailto:helpmedarwin@coxreps.com">CoxReps Support</a> for help.</b>';
			} else if( $auth["type"] == "1" ) { // For employees, validate via Active Directory
				$uname = preg_replace("/@.*/", "", $uname); // don't allow emails
				$ldap = new LDAP($uname, $pwd);
				$ad_user = $ldap->getAuthorizedUser(true);
				//$ad_user = $ldap->getUserByEmail("nwelte@coxreps.com",true); // test account with three roles
				//$ad_user = $ldap->getUserByEmployeeId("00443503",true); // test account Alan Lubash
				//$ad_user = $ldap->getUserByEmployeeId("00408069",true); // Adam Deverell (no darwin user, only AD)
				$auth = $this->getAuthFromLDAP($ad_user);

				if( empty($ad_user) ) {
					$message = '<b style="color:#f36;">The username or password is not valid</b>';
				} else if( !$auth["authorized"] ) {
					$message = '<b style="color:#f36;">Please contact <a href="mailto:survey@coxreps.com">CoxReps Support</a> for access</b>';
				} else if( !$auth["darwin_operator_id"] ) {
					$message = '<b style="color:#f36;">Please contact <a href="mailto:survey@coxreps.com">CoxReps Support</a> for access (D)</b>';
				} else {
					$message = '<b style="color:#f36;">The username or password is not valid</b>';
				}
			} else if( validEmail($uname) ) { // station
				$local_user = $dbSurvey->getSingleObj(array("type"=>"2","email"=>$uname, "auth_token"=>$pwd), "operator");
				if( $local_user["id"] ) {
					$darwin_users = $apiDarwin->getFromObj(array("email_1"=>$uname,"survey"=>"1"), "contact", null, 0);
					if( is_array($darwin_users) && count($darwin_users) > 0 && $darwin_users[0]["id"] ) {
						$auth["id"] = $local_user["id"];
						$auth["authorized"] = 1;
						$auth["type"] = "2";
						$auth["name"] = $local_user["name"];
						$auth["first_name"] = firstName($local_user["name"]);
						$auth["last_name"] = lastName($local_user["name"]);
						$auth["email"] = $local_user["email"];
						$auth["phone"] = $local_user["phone"];
						$auth["cox_id"] = NULL;
						$auth["is_admin"] = 0;
						$auth["is_manager"] = 0; 
						$auth["darwin_operator_id"] = NULL;
						$auth["darwin_role_id"] = NULL;
						$auth["darwin_role_name"] = "";
						$auth["darwin_contact_id"] = $darwin_users[0]["id"];
						$auth["darwin_contact_ids"] = array_filter(array_column($darwin_users,"id"));
						$auth["darwin_role_ids"] = array();
						$auth["darwin_role_names"] = array();
						$auth["darwin_contacts"] = $darwin_users;
					} else {
						$message = '<b style="color:#f36;">Please contact <a href="mailto:helpmedarwin@coxreps.com">CoxReps Support</a> for access (D)</b>';
					}
				} else {
					$message = '<b style="color:#f36;">The username or password is not valid</b>';
				}
			} else {
				//$message = '<b style="color:#f36;">The username or password is not valid</b>';
			}

			if( $auth["authorized"] ) {
				$this->authenticated = true;
				$s["_user"] = $auth;
				$s["login_attempts"][$uname] = 0;
				$log = $uname." logged into web interface (".$info["browser"]." ver ".$info["version"].") (IP ".$auth_ip.")";

				// reload & redirect, gets rid of posted form variables
				$redir_url = $s["redir_url"] ? $s["redir_url"] : "http://".$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
				$redir_url = preg_replace("/\/(favicon\.ico|bg.*|includes.*|controllers.*|login.*|logout.*|home.*)/", "/", $redir_url);
				if( !preg_match("@/$@",$redir_url) ) $redir_url .= "/";
				header("Location: ".$redir_url);
				$s["redir_url"] = "";
				exit;
			}
			$GLOBALS["_auth_message"] = $message;
		}

		if( $_SERVER["REQUEST_METHOD"] == "POST" ) {
			$s["_auth_message"] = $GLOBALS["_auth_message"];
			header("Location: /survey/login/");
			exit;
		}
	}

	/**
	* Given a Active Directory (LDAP) user results, merge in other system data
	*
	* @param $ad_user array of user info retrieved from LDAP class
	* @return array $auth - array of all operator/ldap data
	*/
	public function getAuthFromLDAP($ad_user) {
		global $dbSurvey, $apiDarwin;
		$auth = array("id"=>0, "authorized"=>0, "type"=>1); // start clean

		if( is_array($ad_user) && $ad_user["dn"] && $ad_user["email"] ) { // check email to avoid service account from being able to login
			$auth["name"] = $ad_user["first_name"]." ".$ad_user["last_name"];
			$auth["first_name"] = $ad_user["first_name"];
			$auth["last_name"] = $ad_user["last_name"];
			$auth["email"] = $ad_user["email"];
			$auth["phone"] = $ad_user["phone"];
			$auth["cox_id"] = $ad_user["cox_id"];
			$auth["ad_job_title"] = $ad_user["job_title"];
			$auth["ad_location"] = $ad_user["location"];
			$auth["ad_dn"] = $ad_user["dn"];
			$auth["ad_manager_dn"] = $ad_user["manager"];
			$auth["is_admin"] = 0;

			if( in_array("CN=!CMG - IT Application Development,OU=Groups,OU=CMG-CORP,OU=CMG,DC=cmg,DC=int",$ad_user["groups"]) 
					|| preg_match("/OU=CMG-REPS,OU=CMG,DC=cmg/i",$ad_user["dn"]) 
					|| strtolower($ad_user["department"]) == "corporate-executive") { // Reps only, and people like Brett Fennell

				if( in_array("CN=!CMG - IT Application Development,OU=Groups,OU=CMG-CORP,OU=CMG,DC=cmg,DC=int",$ad_user["groups"]) 
						|| $ad_user["email"] == "kjanand@coxreps.com" ) { // admin no matter what
					$auth["authorized"] = 1;
					$auth["is_admin"] = 1;
				}

				if( $ad_user["cox_id"] ) { // employee (not contractor)
					$darwin_operators = $apiDarwin->getFromObj(array("enabled"=>1,"cox_id"=>$ad_user["cox_id"]), "operator", null, 0);
				} else if( $ad_user["email"] ) { // contractor
					$darwin_operators = $apiDarwin->getFromObj(array("enabled"=>1,"email"=>$ad_user["email"]), "operator", null, 0);
				}
				if( is_array($darwin_operators) && count($darwin_operators) > 0 && $darwin_operators[0]["id"] ) {
					$auth["authorized"] = 1;
					$auth["type"] = $darwin_operators[0]["role_id"] == "1" ? "0" : "1";
					$auth["is_admin"] = ( $auth["is_admin"] || $darwin_operators[0]["role_id"] == "1" ) ? 1 : 0;
					$auth["is_manager"] = 0; // manager role id's, inly SM, GSM, RSD (per spec)
					$auth["darwin_operator_id"] = $darwin_operators[0]["id"];
					$auth["darwin_role_id"] = $darwin_operators[0]["role_id"];
					$auth["darwin_role_name"] = $darwin_operators[0]["short_role_name"];
					$auth["darwin_office_location"] = $darwin_operators[0]["office_location"];
					$auth["darwin_contact_id"] = NULL;
					$auth["darwin_operator_ids"] = array_filter(array_column($darwin_operators,"id"));
					$auth["darwin_role_ids"] = array_filter(array_column($darwin_operators,"role_id"));
					$auth["darwin_role_names"] = array_filter(array_column($darwin_operators,"short_role_name"));
					$auth["darwin_station_contacts"] = array();
					$auth["darwin_operators"] = array();
					$auth["darwin_stations"] = array();

					if( $ad_user["cox_id"] ) { // employee (not contractor)
						$darwin_users = $apiDarwin->getFromObj(array("cox_id"=>$ad_user["cox_id"],"contact_survey"=>"1"), "operator-station-contact", null, 0);
					} else if( $ad_user["email"] ) { // contractor
						$darwin_users = $apiDarwin->getFromObj(array("email"=>$ad_user["email"],"contact_survey"=>"1"), "operator-station-contact", null, 0);
					}
					if( !$darwin_users ) $darwin_users = array();

					foreach($darwin_users as $kd=>$darwin_user) {
						if( $darwin_user["role_id"] == "1" ) {
							$auth["type"] = "0";
							$auth["is_admin"] = 1;
						}
						if( in_array($darwin_user["role_id"],array(2,6,7,8)) ) { // manager role id's, inly SM, GSM, RSD (per spec)
							$auth["is_manager"] = 1;
						}
						$darwin_role_priority = array(0=>"3", 1=>"2", 2=>"6", 3=>"7"); // in order of priority... AE, SM, GSM, RSD
						foreach($darwin_role_priority as $r_idx=>$role_id) {
							if( $darwin_user["role_id"] == $role_id ) {
								$curr_r_idx = array_search($auth["darwin_role_id"], $darwin_role_priority);
								$this_r_idx = array_search($darwin_user["role_id"], $darwin_role_priority);
								if( !$auth["darwin_role_id"] || $this_r_idx > $curr_r_idx ) {
									$auth["darwin_operator_id"] = $darwin_user["id"];
									$auth["darwin_role_id"] = $darwin_user["role_id"];
									$auth["darwin_role_name"] = $darwin_user["short_role_name"];
									$auth["darwin_office_location"] = $darwin_user["office_location"];
								}
							}
						}
						if( is_array($darwin_user["stations"]) ) {
							foreach($darwin_user["stations"] as $ks=>$station) {
								if( !is_array($station["contacts"]) || !count($station["contacts"]) ) { // skip those with no contacts
									unset($darwin_users[$kd]["stations"][$ks]);
									continue;
								}
								$valid = false;
								foreach($station["contacts"] as $kc=>$contact) {
									if( $contact["survey"] == "1" && validEmail($contact["email_1"]) ) {
										$auth["darwin_station_contacts"][] = trim(strtolower($contact["email_1"]));
										$valid = true;
									} else {
										unset($darwin_users[$kd]["stations"][$ks]["contacts"][$kc]);
										unset($station["contacts"][$kc]);
									}
								}
								if( $valid ) { // only add a station if it has valid contacts that should be surveyed
									$auth["darwin_stations"][$station["code"]] = $station;
								} else {
									unset($darwin_users[$kd]["stations"][$ks]);
								}
							}
						}
					}
					$auth["darwin_operators"] = is_array($darwin_users) ? $darwin_users : array(); // if modified, put at end
					$auth["darwin_station_contacts"] = array_values(array_unique($auth["darwin_station_contacts"]));
					$auth["darwin_role_names"] = array_values(array_unique($auth["darwin_role_names"]));
					sort($auth["darwin_station_contacts"]);
					sort($auth["darwin_role_names"]);

					// integrate local
					$local_user = $dbSurvey->getSingleObj(array("darwin_operator_id"=>$auth["darwin_operator_id"]), "operator");
					if( $local_user["id"] ) {
						$auth["id"] = $local_user["id"];
						$auth["notes"] = $local_user["notes"];
					}
				} else { // no darwin user, but may have a local account (i.e. manager MBO)
					$local_user = $dbSurvey->getSingleObj(array("cox_id"=>$auth["cox_id"]), "operator");
					if( $local_user["id"] ) {
						//$auth["authorized"] = 1;
						$auth["id"] = $local_user["id"];
						$auth["type"] = "1";
						$auth["is_manager"] = ( count($ad_user["reports"]) > 0 ); // manager role id's, inly SM, GSM, RSD (per spec)
						$auth["darwin_role_id"] = 0;
						$auth["darwin_role_name"] = $ad_user["job_title"];
						$auth["darwin_office_location"] = $ad_user["location"];
						$auth["darwin_contact_id"] = NULL;
						$auth["darwin_operator_ids"] = array();
						$auth["darwin_role_ids"] = array();
						$auth["darwin_role_names"] = array();
						$auth["darwin_station_contacts"] = array();
						$auth["darwin_operators"] = array();
						$auth["darwin_stations"] = array();
						$auth["notes"] = $local_user["notes"];
					}
				}
			}
		}
		return $auth;
	}

	/**
	* Given a Darwin Operator Id, retrieve active directory info (if possible) and then all other accounts linked to that user.
	* This method will try to find user by cox_id and then email, and will return only the darwin operator info if nothing found in LDAP.
	*
	* @param int $darwin_operator_id operator ID of user in Darwin to look up. 
	* @return array $auth - array of all operator/ldap data
	*/
	public function getAuthFromDarwinOperaterId($darwin_operator_id) {
		global $dbSurvey, $apiDarwin;
		$auth = array("id"=>0, "authorized"=>0, "type"=>1); // start clean

		if( is_numeric($darwin_operator_id) ) {
			$operator = $apiDarwin->getSingleObj(array("enabled"=>1,"id"=>$darwin_operator_id), "operator", null, 1);
			if( $operator["id"] ) {
				$auth["authorized"] = 0;
				$auth["type"] = $operator["role_id"] == "1" ? "0" : "1";
				$auth["is_admin"] = $operator["role_id"] == "1" ? 1 : 0;
				$auth["is_manager"] = in_array($operator["role_id"],array(2,6,7,8)); // manager role id's, inly SM, GSM, RSD (per spec)
				$auth["darwin_operator_id"] = $operator["id"];
				$auth["darwin_role_id"] = $operator["role_id"];
				$auth["darwin_role_name"] = $operator["short_role_name"];
				$auth["darwin_office_location"] = $operator["office_location"];
				$auth["darwin_contact_id"] = NULL;
				$auth["darwin_operator_ids"] = array($operator["id"]);
				$auth["darwin_role_ids"] = array($operator["role_id"]);
				$auth["darwin_role_names"] = array($operator["short_role_name"]);
				$auth["darwin_operators"] = array($operator);
				$auth["darwin_station_contacts"] = array();

				$ldap = new LDAP(); // uses service account
				if( $operator["cox_id"] ) {
					$ad_user = $ldap->getUserByEmployeeId($operator["cox_id"],true);
				} else {
					$ad_user = $ldap->getUserByEmail($operator["email"],true);
				}
				// if user found, retrieve full data (will reset values above)
				if( $ad_user["dn"] ) $auth = $this->getAuthFromLDAP($ad_user);
			} 
		}

		return $auth;
	}

	/**
	 * @param $role
	 * @param bool $exit_on_fail
	 */
	public function hasRole($role, $exit_on_fail=false) {

	}

	/**
	 * @param bool $exit_on_fail
	 * @return bool
	 */
	public function isAdmin($exit_on_fail=false) {
		if( $this->auth["is_admin"] ) return true;
		if( $exit_on_fail ) {
			$message = "Admin access is required for this function";
			$this->securityFail($message);
		}
		return false;
	}

	/**
	 * @param bool $exit_on_fail
	 * @return bool
	 */
	public function isManager($exit_on_fail=false) {
		if( $this->auth["is_manager"] ) return true;
		if( $exit_on_fail ) {
			$message = "Manager access is required for this function";
			$this->securityFail($message);
		}
		return false;
	}


	/**
	 * @param bool $exit_on_fail
	 * @return bool
	 */
	public function isEmployee($exit_on_fail=false) {
		if( isset($this->auth["cox_id"] ) && !empty($this->auth["cox_id"] )) return true;
		if( $exit_on_fail ) {
			$message = "Employee access is required for this function";
			$this->securityFail($message);
		}
		return false;
	}


	/**
	 * @param bool $exit_on_fail
	 * @return bool
	 */
	public function isDOS($exit_on_fail=false) {
		if( isset($this->auth["darwin_role_id"]) && $this->auth["darwin_role_id"] == 8 ) return true;
		if(isset($this->auth["cox_id"]) && in_array($this->auth["cox_id"], $this->override_dos_cox_ids) ) return true;
		if( $exit_on_fail ) {
			$message = "DOS access is required for this function";
			$this->securityFail($message);
		}
		return false;
	}

	/**
	 * @param bool $exit_on_fail
	 * @return bool
	 */
	public function isAuthorized($exit_on_fail = false) {
		if($this->auth['authorized']) return true;
		if($exit_on_fail) {
			$message = "You nust be logged in for this function";
			$this->securityFail($message);
		}
		return false;
	}

	/**
	 *
	 */
	public function getCurrentOperator() {

	}

	/**
	 *
	 */
	public function securityFail($message) {
		if(SystemFactory::isAjax())
			$this->securityFailAjax($message);
		else
			$this->securityFailExit($message);
	}

	/**
	 * @param string $location
	 */
	public function securityFailRedirect($location = "/survey/login/") {
		// if not on the login screen, go there
		if( $_SERVER["PHP_SELF"] != $location ) {
			$key = explode('/', $location);
			if(!empty($key)) $key = $key[(count($key) > 1 ? 1 : 0)];
			$s["redir_url"] = preg_replace("@/$key/?@i", "", $_SERVER["REQUEST_URI"]);
			header("Location: " . $location);
			exit;
		}
	}

	/**
	 * @param $message
	 */
	public function securityFailExit($message) {
		//Todo: add exit code possibly borrowed from above surveyAuthorize
	}

	/**
	 * If not on the login screen, go there
	 */
	public function securityFailRedirectLogin() {
		if( $_SERVER["PHP_SELF"] != "/survey/login/" ) {
			$s["redir_url"] = preg_replace("@/login/?@i", "", $_SERVER["REQUEST_URI"]);
			header("Location: /survey/login/");
			exit;
		}
	}

	/**
	 * exit the code and respond with an ajax JSON security fail response
	 * @param $message
	 */
	public function securityFailAjax($message) {
		json_error_response($message, 401  );
	}

	private function generateAuthorizationToken($length=10) {
		$token = "";
		$codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
		$codeAlphabet.= "abcdefghijklmnopqrstuvwxyz";
		$codeAlphabet.= "0123456789";
		$max = strlen($codeAlphabet); // edited

		for ($i=0; $i < $length; $i++) {
			$token .= $codeAlphabet[crypto_rand_secure(0, $max-1)];
		}

		return $token;
	}

	public function setupAuthToken(DateTime $expires=null) {
		if(!$expires){
			$expires = (new DateTime('now'))->add(new DateInterval('P30D'));
		}
		$token = $this->generateAuthorizationToken(8);
		$this->auth['auth_token'] = $token;
		$this->auth['auth_token_expires'] = $expires->format('Y-m-d H:i:s');
		SurveyFactory::updateOperatorAuthToken($this->auth['id'], $this->auth['auth_token'], $this->auth['auth_token_expires']);
		return $token;
	}

	public function isMyEmployee($cox_id){

	}
}
