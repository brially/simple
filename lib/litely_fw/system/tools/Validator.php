<?php
/**
 * Created by PhpStorm.
 * User: ballison
 * Date: 9/14/2017
 * Time: 8:31 AM
 */

class Validator
{
    /**
     * @var array
     */
    protected $data;
    /**
     * @var array
     */
    protected $validation_rules;
    /**
     * @var array
     */
    protected $errors;
    /**
     * @var boolean
     */
    protected $is_valid = null;

    /**
     * Validator constructor.
     * @param $data array
     * a name => value list of fields to be validated
     * @param $rules
     * a name => 'rule|rule|rule' list of validation rules
     */
    public function __construct($data, $rules)
    {
        $this->data = $data;
        $validation_rules = array();
        foreach ($rules as $key => $rule_str){
            $validation_rules[$key] = explode('|', $rule_str);
        }
        $this->validation_rules = $validation_rules;
    }

    /**
     *
     */
    public function validate(){
        $errors = array();
        foreach ($this->validation_rules as $field => $rules){
            foreach ($rules as $rule){
            	$rule_param = explode(":", $rule);
            	$rule = $rule_param[0];
				$rule_param = explode(",", $rule_param[1]);
                switch($rule){
                    case 'required':
                        if(!isset($this->data[$field]) || empty($this->data[$field]) ){
                            $errors[$field][]='The field ' . $field . ' is required';
                        }
                        if(is_array($this->data[$field]) && empty(array_filter($this->data[$field]) )){
                            $errors[$field][]='The field ' . $field . ' is required';
                        }
                        break;
					case "has":
						foreach ($rule_param as $param){
							if(is_array($this->data[$field])){
								foreach ($this->data[$field] as $field_property){
									if(!isset($field_property[$param]) || empty($field_property[$param]) ){
										$errors[$field][]='The field ' . $field . "'s property " . $param . " is required";
									}
									if(is_array($field_property[$param]) && empty(array_filter($field_property[$param]) )){
										$errors[$field][]='The field ' . $field . "'s property " . $param . " is required";
									}
								}
							}
							else {
								if(!isset($this->data[$field][$param]) || empty($this->data[$field][$param]) ){
									$errors[$field][]='The field ' . $field . "'s property " . $param . " is required";
								}
								if(is_array($this->data[$field][$param]) && empty(array_filter($this->data[$field][$param]) )){
									$errors[$field][]='The field ' . $field . "'s property " . $param . " is required";
								}
							}
						}
						break;
                    case 'date':
                        if((isset($this->data[$field]) && !empty($this->data[$field]) )){
                        	if(is_array($this->data[$field])){
                        		$error_flg = false;
                        		foreach ($this->data[$field] as $val){
									if (!(bool)strtotime($val))
										$error_flg = true;
								}
								if($error_flg) $errors[$field][]='The field ' . $field . ' must be a date';
							}
							else if (!(bool)strtotime($this->data[$field])) {
                        		$errors[$field][]='The field ' . $field . ' must be a date';
							}
                        }
                        break;
                    case 'number':
                        if((isset($this->data[$field]) && !empty($this->data[$field]) )){
                        	if(is_array($this->data[$field])){
                        		$error_flg = false;
                        		foreach ($this->data[$field] as $val){
									if (!is_numeric ( $val ))
										$error_flg = true;
								}
								if($error_flg) $errors[$field][]='The field ' . $field . ' must be a number';
							}
							else if (!is_numeric ( $this->data[$field] )) {
                        		$errors[$field][]='The field ' . $field . ' must be a number';
							}
                        }
                        break;
                    case 'email':
						if(isset($this->data[$field]) && !empty($this->data[$field]) ){
							if(is_array($this->data[$field])){
								$error_flg = false;
								foreach ($this->data[$field] as $val){
									if ( !$this->validEmail($val) )
										$error_flg = true;
								}
								if($error_flg) $errors[$field][]='The field ' . $field . ' is not a valid email';
							}
							else if (!$this->validEmail($this->data[$field])) {
								$errors[$field][]='The field ' . $field . ' is not a valid email';
							}
						}
                        break;
                }
            }
        }
        if(isEmpty($errors)) $this->is_valid = true;
        else $this->is_valid = false;
        $this->errors = $errors;

    }

    /**
     * @return boolean
     */
    public function isValid(){
        if(is_null($this->is_valid) ){
            $this->validate();
        }
        return $this->is_valid;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

	/**
	 * validate an email address
	 *
	 * @param string $email
	 * $return boolean
	 *
	 */
	public function validEmail($email) {
		$email = trim($email);

		// supports 2 - 6 characters long domain name extension
		$reg = "^([\w\-\_\.]+)@([\w\-\_\.]+)\.([a-z]{2,6})$";
		$valid = ( preg_match("/".$reg."/i", $email) && !preg_match("/noemail/i",$email) );
		return $valid;
	}
}