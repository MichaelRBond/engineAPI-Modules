<?php
/**
 * EngineAPI formValidation Module
 * @package EngineAPI\modules\formValidation
 */
class formValidation {
	/**
	 * @var array
	 */
	private $fields = array();

	/**
	 * @var string
	 */
	private $type;

	/**
	 * The "type" in engine's cleanGet and cleanPort. RAW, HTML, MYSQL
	 * @var string
	 */
	public $sanType = "RAW"; // the "type" in engine's cleanGet and cleanPort. RAW, HTML, MYSQL

	/**
	 * when TRUE, validate will look at each POST and GET variable. If they are not defined it will return FALSE
	 * When true, even submit buttons and the like need to be defined. But, makes sure that nothing that isn't supposed to be passed in is.
	 * @var bool
	 */
	public $strict = TRUE;


	/**
	 * Class constructor
	 * @param string $type post,get
	 */
	function __construct($type=NULL) {

		$this->fields['get']  = array();
		$this->fields['post'] = array();
		$this->fields['all']  = array();

		if (strtolower($type) == "post") {
			$this->type = strtolower($type);
		}
		else if (strtolower($type) == "get") {
			$this->type = strtolower($type);
		}

	}

	function __destruct() {
	}

	/**
	 * Adds a field to the validation
	 *
	 * @param array $field
	 *   - type:     post or get
	 *   - var:      variable name
	 *   - validate: type of validation to perform. If set to NULL, does not perform validation
	 * @return bool
	 */
	public function addField($field) {

		if (!isset($field['type']) || (strtolower($field['type']) != "post" && strtolower($field['type']) != "get")) {
			errorHandle::newError(__METHOD__."() - type not set or invalid", errorHandle::DEBUG);
			return(FALSE);
		}

		if (!isset($field['var'])) {
			errorHandle::newError(__METHOD__."() - variable name not set", errorHandle::DEBUG);
			return(FALSE);
		}

		if (!array_key_exists('validate',$field)) {
			errorHandle::newError(__METHOD__."() - validate not set", errorHandle::DEBUG);
			return(FALSE);
		}

		if (strtolower($field['type']) == "get") {
			$field['type'] = "get";
			$this->fields['get'][$field['var']] = $field;
		}
		else if (strtolower($field['type']) == "post") {
			$field['type'] = "post";
			$this->fields['post'][$field['var']] = $field;
		}

		$this->fields["all"][$field['var']] = $field;


	}

	/**
	 * Do validation
	 *
	 * @param bool $bool
	 *        If TRUE, return is boolean for 'is valid'
	 *        else return is an array with specifics
	 * @return array|bool
	 */
	public function validate($bool=TRUE) {

		if (!$this->validateSanType()) {
			errorHandle::newError(__METHOD__."() - Invalid sanType", errorHandle::DEBUG);
			return(FALSE);
		}

		$inputs = array();
		if (isset($_POST[$this->sanType])) {
			$inputs['post'] = $_POST[$this->sanType];
		}
		if (isset($_GET[$this->sanType])) {
			$inputs['get']  = $_GET[$this->sanType];
		}

		if (!isnull($this->type) && !isset($inputs[$this->type])) {
			errorHandle::newError(__METHOD__."() - invalid type definition", errorHandle::DEBUG);
			return(FALSE);
		}

		$types = array();
		switch ($this->type) {
			case "post":
				if (isset($inputs['post'])) {
					$types[] = "post";
				}
				break;
			case "get";
				if (isset($inputs['get'])) {
					$types[] = "get";
				}
				break;
			default:
				if (isset($inputs['post'])) {
					$types[] = "post";
				}
				if (isset($inputs['get'])) {
					$types[] = "get";
				}
				break;
		}

		if (count($types) == 0) {
			return(FALSE);
		}

		$valid = array();
		foreach ($types as $I=>$type) {
			foreach ($inputs[$type] as $var=>$value) {

				if ($var == "engineCSRFCheck") {
					continue;
				}

				if (!isset($this->fields[$type][$var]) && $this->strict === TRUE) {
					if ($bool === TRUE) {
						return(FALSE);
					}

					$temp          = array();
					$temp["var"]   = $var;
					$temp["value"] = $value;
					$temp["valid"] = FALSE;
					$valid[]       = $temp;

					continue;
				}
				else if (!isset($this->fields[$type][$var]) && $this->strict === FALSE) {
					continue;
				}

				if (isnull($this->fields[$type][$var]['validate'])) {
					continue;
				}

				$return = FALSE;
				$validateObj = validate::getInstance();
				if (preg_match('/^\/(.+?)\/$/',$this->fields[$type][$var]['validate'])) {
					$return = call_user_func(array($validateObj,'regexp'),$this->fields[$type][$var]['validate'],$value);
				}
				else {
					$return = call_user_func(array($validateObj, $this->fields[$type][$var]['validate']),$value);
				}

				if ($return === FALSE) {
					if ($bool === TRUE) {
						return(FALSE);
					}

					$temp          = array();
					$temp["var"]   = $var;
					$temp["value"] = $value;
					$temp["valid"] = FALSE;
					$valid[]       = $temp;

				}
			}
		}

		if (count($valid) == 0 && $bool === TRUE) {
			return(TRUE);
		}

		return($valid);

	}

	/**
	 * Unknown
	 *
	 * @return bool
	 */
	private function validateSanType() {

		if (isset($_GET[$this->sanType]) || isset($_POST[$this->sanType])) {
			return(TRUE);
		}

		return(FALSE);
	}

}

?>