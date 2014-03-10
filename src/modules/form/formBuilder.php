<?php

class formBuilder extends formFields{
	const DEFAULT_FORM_NAME       = '';
	const DEFAULT_FORM_TIMEOUT    = 300;
	const SESSION_SAVED_FORMS_KEY = 'formBuilderForms';

	/**
	 * @var string The base URL where our form assets are located at
	 */
	private $formAssetsURL;

	/**
	 * @var self[] An array of all defined forms
	 */
	private static $formObjects = array();

	/**
	 * @var formProcessor[] An array of all created formProcessor objects (keyed off their formID's)
	 */
	private static $formProcessorObjects = array();

	/**
	 * @var string The name given to this form
	 */
	private $formName;

	/**
	 * @var string Filepath to form templates
	 */
	public $templateDir;

	/**
	 * @var array Array containing metadata linking this form to an underlying database table
	 */
	public $dbOptions;

	/**
	 * @var string The template to use for insertForm
	 */
	public $insertFormTemplate = 'default';

	/**
	 * @var string The template to use for editTable
	 */
	public $editTableTemplate = 'default';

	/**
	 * @var string Location of insertForm Ajax Callback
	 */
	public $insertFormURL;

	/**
	 * @var string Javascript function to process Ajax call
	 */
	public $insertFormCallback;

	/**
	 * Class constructor
	 *
	 * @param string $formName
	 */
	public function __construct($formName){
		$this->templateDir = __DIR__.DIRECTORY_SEPARATOR.'formTemplates'.DIRECTORY_SEPARATOR;
		$this->formName    = trim(strtolower($formName));

		$engineVars = enginevars::getInstance();
		$this->formAssetsURL = $engineVars->get('formAssetsURL', $engineVars->get('engineInc').DIRECTORY_SEPARATOR.'formBuilderAssets');

		errorHandle::registerPrettyPrintCallback(array($this,'prettyPrintFormErrors'));
		templates::defTempPatterns('/\{form\s+(.+?)\}/', __CLASS__.'::templateMatches', $this);
	}

	/**
	 * Class destructor
	 */
	public function __destruct(){
		unset(self::$formObjects[$this->formName]);
	}

	/**
	 * [Magic Method] Read-only getter for our instance variables
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name){
		return isset($this->$name)
			? $this->$name
			: NULL;
	}

	/**
	 * [Callback] Our custom callback for prettyPrint()
	 * @see errorHandle::prettyPrint()
	 * @param array $errorStack
	 * @param string $type
	 * @return string
	 */
	public function prettyPrintFormErrors($errorStack, $type){
		// If there's no errorStack, return
		if(!sizeof($errorStack) || !isset($errorStack[$type])) return '';

		// Setup vars
		$engineErrors = array();
		$formErrors   = array();
		$errorStack   = (array)$errorStack[$type];

		// Loop through each error
		foreach ($errorStack as $v) {
			// If $v is a string, then it's a regular engine error
			if (is_string($v)) {
				$engineErrors[] = array(
					'msg'  => $v,
					'type' => $type,
				);
				continue;
			}

			// If $type is 'all' then this is a nested engine error
			if($type == 'all'){
				$engineErrors[] = $v;
				continue;
			}

			// If $v is an array, and it has a key with our form name, then it's our form errors
			if(is_array($v) && isset($v[ $this->formName ])){
				$formErrors = $v[ $this->formName ];
			}
		}

		$output = '';
		if(sizeof($engineErrors) || sizeof($formErrors)){
			// Start building the errors <ul>
			$output .= '<ul class="errorPrettyPrint">';

			// Loop through all the engine errors
			foreach($engineErrors as $engineError){
				// Pull out the msg and type
				$msg  = $engineError['message'];
				$type = $engineError['type'];

				// Map the type to it's CSS class
				switch ($type) {
					case errorHandle::ERROR:
						$class = errorHandle::$uiClassError;
						break;
					case errorHandle::SUCCESS:
						$class = errorHandle::$uiClassSuccess;
						break;
					case errorHandle::WARNING:
						$class = errorHandle::$uiClassWarning;
						break;
					default:
						$class = '';
						break;
				}

				// Generate <li> HTML
				$output .= sprintf('<li><span class="%s">%s</span>', $class, htmlentities($msg));
			}

			// Loop through all the form errors and generate their <li> HTML
			foreach($formErrors as $formError){
				$output .= sprintf('<li><span class="%s">%s</span>', errorHandle::$uiClassError, htmlentities($formError));
			}

			// Finish the <ul> and return
			$output .= '</ul>';
		}

		return $output;
	}

	public function addField($field){
		$result = parent::addField($field);
		if($result){
			$field = array_peak($this->fields,'end');
			if($field->type == 'file'){
				$this->formEncoding = '';
			}
		}
		return $result;
	}

	/**
	 * Process a form submission
	 *
	 * @param string $formID
	 * @return int Result code from formProcessor object
	 */
	public static function process($formID=NULL){
		$processor = self::createProcessor($formID);
		return ($processor instanceof formProcessor)
			? $processor->processPost()
			: $processor;
	}

	/**
	 * Create a formProcessor object for a given formID
	 *
	 * This can be useful if you need to manipulate the formProcessor object before actually processing it
	 *
	 * @param string $formID
	 * @return formProcessor|int formProcessor object or formProcessor error code
	 */
	public static function createProcessor($formID=NULL){
		// If no formID was passed, try and find it
		if (!isset($formID)) {
			$sessionPost = session::get('POST');
			if (isset($sessionPost['MYSQL']) && isset($sessionPost['MYSQL']['__formID'])) {
				$formID = $sessionPost['MYSQL']['__formID'];
			} elseif (isset($_POST['MYSQL']['__formID'])) {
				$formID = $_POST['MYSQL']['__formID'];
			} else {
				return formProcessor::ERR_NO_ID;
			}
		}

		if(!isset(self::$formProcessorObjects[$formID])){
			// Make sure the formID is valid and retrieve the saved form
			$savedForm = session::get(self::SESSION_SAVED_FORMS_KEY.".$formID");
			if(!$savedForm) return formProcessor::ERR_INVALID_ID;

			// Extract the formBuilder and formType
			$savedFormBuilder = unserialize($savedForm['formBuilder']);
			$savedFormType    = $savedForm['formType'];

			// Make sure we are linked to a backend db
			if(!sizeof($savedFormBuilder->dbOptions)){
				errorHandle::newError(__METHOD__."() No database link defined for this form! (must process manually)", errorHandle::DEBUG);
				return FALSE;
			}

			// Create the form processor
			$formProcessor = new formProcessor($savedFormBuilder->dbOptions['table'], $savedFormBuilder->dbOptions['connection']);

			// Set the processorType
			$formProcessor->setProcessorType($savedFormType);

			// Add our fields to the form processor
			foreach ($savedFormBuilder->fields as $field) {
				$formProcessor->addField($field);
			}

			// Save the formProcessor to the cache
			self::$formProcessorObjects[$formID] = $formProcessor;
		}

		return self::$formProcessorObjects[$formID];
	}

	/**
	 * [Factory] Create a new form object
	 *
	 * @param string $formName
	 * @param string $dbOptions
	 * @return bool|formBuilder
	 */
	public static function createForm($formName = NULL, $dbOptions = NULL){
		if (isnull($formName)) $formName = self::DEFAULT_FORM_NAME;
		$formName = trim(strtolower($formName));

		// Dupe checking
		if (in_array($formName, self::$formObjects)) {
			errorHandle::newError(__METHOD__."() Form already created with given name!", errorHandle::DEBUG);
			return FALSE;
		}

		// Create the form!
		self::$formObjects[$formName] = new self($formName);

		// link dbTableOptions if it's passed in
		if (!isnull($dbOptions) && !self::$formObjects[$formName]->linkToDatabase($dbOptions)) return FALSE;

		return self::$formObjects[$formName];
	}

	/**
	 * [Factory] Creates formBuilderTemplate instance and returns it
	 * @return formBuilderTemplate
	 */
	private function createFormTemplate(){
		$template = new formBuilderTemplate($this);
		return $template;
	}

	/**
	 * Link the form to a backend database table
	 *
	 * @param $dbOptions
	 * @return bool
	 */
	public function linkToDatabase($dbOptions){
		// If only a string is passed, make it the table name
		if (is_string($dbOptions)) $dbOptions = array('table' => $dbOptions);

		// Determine the db connection
		$dbOptions['connection'] = isset($dbOptions['connection']) ? db::get($dbOptions['connection']) : db::get('appDB');

		// Make sure that at least the table name is present
		if (!isset($dbOptions['table'])) {
			errorHandle::newError(__METHOD__."() You must pass at least a 'name' element with dbTableOptions!", errorHandle::DEBUG);
			return FALSE;
		}

		$this->dbOptions = $dbOptions;
		return TRUE;
	}

	/**
	 * EngineAPI Template tag callback
	 *
	 * @param array $matches
	 * @return string
	 */
	public static function templateMatches($matches){
		$attrPairs = attPairs($matches[1]);

		// Determine form name
		$formName = isset($attrPairs['name']) ? $attrPairs['name'] : self::DEFAULT_FORM_NAME;
		$formName = trim(strtolower($formName));

		// Locate the form, and if it's not defined return empty string
		if(isset(self::$formObjects[$formName])){
			$form = self::$formObjects[$formName];
		}else{
			errorHandle::newError(__METHOD__."() Form '$formName' not defined", errorHandle::DEBUG);
			return '';
		}

		if (!isset($attrPairs['display'])) $attrPairs['display'] = '';
		return $form->display($attrPairs['display'], $attrPairs);
	}
	
	/**
	 * Remove all fields, and reset back to initial state
	 */
	public function reset(){
		$this->fields      = array();
		$this->fieldLabels = array();
		$this->fieldIDs    = array();
	}

	/**
	 * Returns an array of JS/CSS asset files needed by this form and its fields
	 *
	 * This array will follow the convention: assetName => assetFile
	 *
	 * @return array
	 */
	private function getAssets(){
		$assets = array();
		// Form assets
		$assets[] = __DIR__.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'formEvents.js';
		// Get, and merge-in, all field assets
		foreach ($this->fields as $field) {
			$assets = array_merge($assets, (array)$field->getAssets());
		}
		// Return the final array
		return array_unique($assets);
	}

	/**
	 * Main display method for the form
	 *
	 * @param string $formType
	 * @param array $options
	 * @return string
	 */
	public function display($formType, $options){
		switch (trim(strtolower($formType))) {
			case 'insert':
			case 'insertform':
			case 'update':
			case 'updateform':
				return $this->displayInsertForm($options);

			case 'edittable':
				return $this->displayEditTable($options);

			case 'assets':
				$assetFiles = array();
				foreach (self::$formObjects as $form) {
					$assetFiles = array_merge($assetFiles, $form->getAssets());
				}

				$jsAssetBlob  = '';
				$cssAssetBlob = '';
				foreach ($assetFiles as $file) {
					$ext = pathinfo($file, PATHINFO_EXTENSION);
					switch ($ext) {
						case 'less':
						case 'sass':
						case 'css':
							$cssAssetBlob .= minifyCSS($file);
							break;
						case 'js':
//							$jsAssetBlob .= minifyJS($file);
							$jsAssetBlob .= file_get_contents($file);
							break;
						default:
							errorHandle::newError(__METHOD__."() Unknown asset file type '$ext'. Ignoring file!", errorHandle::DEBUG);
							break;
					}
				}

				$output = '';
				if (!is_empty($jsAssetBlob))  $output .= "<!-- engine Instruction displayTemplateOff --><script>".$jsAssetBlob."</script><!-- engine Instruction displayTemplateOn -->";
				if (!is_empty($cssAssetBlob)) $output .= "<style>".$cssAssetBlob."</style>";
				return $output;

			case 'errors':
				return errorHandle::prettyPrint();

			default:
				errorHandle::newError(__METHOD__."() Unsupported display type '{$options['display']}' for form '{$this->formName}'", errorHandle::DEBUG);
				return '';
		}
	}

	/**
	 * Make sure rendered form is submittable by ensuring there is a submit field defined
	 */
	private function ensureFormSubmit(){
		foreach($this->fields as $field){
			if($field->type == 'submit') return;
		}
		$this->addField(array(
			'type' => 'submit',
			'name' => 'submit',
			'value' => 'Submit'
		));
	}

	/**
	 * Save the current form in the session and return its formID
	 * @param string $formType The type of form being saved
	 * @return string
	 */
	private function saveForm($formType){
		$formID         = md5(uniqid().mt_rand());
		$sessionOptions = array('timeout' => enginevars::getInstance()->get('formBuilderTimeout', self::DEFAULT_FORM_TIMEOUT));
		$sessionData    = array(
			'formBuilder' => serialize($this),
			'formType'    => $formType,
		);
		session::set(self::SESSION_SAVED_FORMS_KEY.".$formID", $sessionData, $sessionOptions);
		return $formID;
	}

	/**
	 * Displays an Insert Form using a given template
	 *
	 * @param array $options
	 * @return string
	 */
	public function displayInsertForm($options = array()){
		// Catch the use case of using insertForm when you mean updateForm
		if(isset($options['id'])) return $this->displayUpdateForm($options);

		// Create the savedForm record for this form
		$formID = $this->saveForm('insertForm');

		// Create the template object
		$template = $this->createFormTemplate();
		$template->formID = $formID;

		// Set the template
		$templatePath = isset($options['template']) ? $options['template'] : $this->insertFormTemplate;
		$template->loadTemplate($templatePath, 'insert');

		// Apply any options
		$template->formAction = isset($options['formAction']) ? $options['formAction'] : NULL;

		// Render time!
		$this->ensureFormSubmit();
		return $template->render();
	}

	public function displayUpdateForm($options = array()){
		$primaryKeys          = array();
		$primaryFields        = $this->listPrimaryFields();
		$optionsPrimaryFields = array_intersect($primaryFields, array_keys($options));
		foreach ($optionsPrimaryFields as $optionsPrimaryField) {
			$primaryFields[$optionsPrimaryField] = $options[$optionsPrimaryField];
		}

		// Create the savedForm record for this form
		$formID = $this->saveForm('updateForm');

		// Create the template object
		$template = $this->createFormTemplate();
		$template->formID = $formID;

		// Set the template
		$templatePath = isset($options['template']) ? $options['template'] : $this->insertFormTemplate;
		$template->loadTemplate($templatePath, 'insert');

		// Apply any options
		$template->formAction = isset($options['formAction']) ? $options['formAction'] : NULL;

		// Render time!
		$this->ensureFormSubmit();
		return $template->render();
	}

	/**
	 * Displays an Edit Table using a given template
	 *
	 * @param array $options
	 * @return string
	 */
	public function displayEditTable($options = array()){
		// Create the template object
		$template = $this->createFormTemplate();

		// Set the template
		$templatePath = isset($options['template']) ? $options['template'] : $this->editTableTemplate;
		$template->loadTemplate($templatePath, 'edit');

		// Apply any options
		$template->formAction         = isset($options['formAction'])         ? $options['formAction']         : NULL;
		$template->insertFormURL      = isset($options['insertFormURL'])      ? $options['insertFormURL']      : NULL;
		$template->insertFormCallback = isset($options['insertFormCallback']) ? $options['insertFormCallback'] : NULL;

		// Render time!
		$this->ensureFormSubmit();
		return $template->render();
	}
}
