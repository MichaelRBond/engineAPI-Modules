<?php
class formBuilderTemplate {
	/**
	 * @var formBuilder
	 */
	private $formBuilder;

	/**
	 * @var array Render options set from formBuilder
	 */
	public $renderOptions=array();

	/**
	 * @var string The template text to use when rendering
	 */
	private $template;

	/**
	 * Array of 'data-' attributes to be included on the <form> tag at render time
	 * @var array
	 */
	public $formDataAttributes = array();

	/**
	 * Array of attributes to be included on the <form> tag at render time
	 * @var array
	 */
	public $formAttributes = array();

	/**
	 * @var string The generated formID for this form
	 */
	public $formID;

	/**
	 * @var string The type of form we're rendering (ie insertForm, updateForm, editTable)
	 */
	public $formType;

	/**
	 * @var string[] List of fields which have been rendered (used for de-duping during render)
	 */
	private $fieldsRendered = array();

	/**
	 * @var int Counts the number of rows
	 */
	private $counterRows = 0;

	/**
	 * @var int Counts the number of fields
	 */
	private $counterFields = 0;

	/**
	 * Class constructor
	 * @param formBuilder $formBuilder
	 * @param string $templateText
	 */
	function __construct($formBuilder, $templateText=''){
		$this->formBuilder = $formBuilder;
		$this->template    = $templateText;
	}

	/**
	 * Render the form template
	 * @param string $templateText
	 * @return string
	 */
	public function render($templateText=NULL){
		// Reset the list of rendered fields
		$this->fieldsRendered = array();

		// Set all primary fields as disabled for security
		foreach($this->formBuilder->fields->listPrimaryFields() as $primaryField){
			$this->formBuilder->fields->modifyField($primaryField,'disabled',TRUE);
		}

		// Make a local copy of the template's source to work with
		if(isnull($templateText)) $templateText = $this->template;

		// Process {ifFormErrors} and {formErrors}
		$patterns = array('|{ifFormErrors}(.+?){/ifFormErrors}|ism', '|{formErrors}|i');
		$templateText = preg_replace_callback($patterns, array($this, '__renderFormErrors'), $templateText);

		// Render the {form}...{/form} block
		$templateText = preg_replace_callback('|{form(\s.*?)?}(.+?){/form}|ism', array($this, '__renderFormBlock'), $templateText);

		// Process general tags
		$templateText = preg_replace_callback('|{([/\w]+)\s?(.*?)}|ism', array($this, '__renderGeneralTags'), $templateText);

		return $templateText;
	}

	/**
	 * [PREG Callback] Render the {form}...{/form} block
	 * @param array $matches
	 * @return string
	 */
	function __renderFormBlock($matches){
		$block = $matches[0];
		try{
			// Process {ifExpandable}
			if(isset($this->renderOptions['expandable']) && $this->renderOptions['expandable']){
				// Expandable enabled: only remove the {ifExpandable} and {/ifExpandable} tags
				$block = preg_replace('|{/?ifExpandable}|i', '', $block);
				$block = preg_replace('|{noShowExpandable(.*?)}(.+?){/noShowExpandable}|ism', '', $block);
			}
			else{
				// Expandable disabled: remove entire {ifExpandable} block
				$block = preg_replace('|{/?noShowExpandable}|i', '', $block);
				$block = preg_replace('|{ifExpandable(.*?)}(.+?){/ifExpandable}|ism', '', $block);
			}

			// Process {fieldsLoop}
			$block = preg_replace_callback('|{fieldsLoop(.*?)}(.+?){/fieldsLoop}|ism', array($this, '__renderFieldLoop'), $block);

			// Process {rowLoop}
			$block = preg_replace_callback('|{rowLoop(.*?)}(.+?){/rowLoop}|ism', array($this, '__renderRowLoop'), $block);

			return $block;

		}catch(Exception $e){
			return $e->getMessage();
		}

	}

	/**
	 * [PREG Callback] Process all {fieldLoop}'s
	 *
	 * @param array $matches
	 * @return string
	 */
	private function __renderFieldLoop($matches){
		$output     = '';
		$options    = attPairs($matches[1]);
		$block      = trim($matches[2]);
		$list       = isset($options['list'])       ? explode(',', $options['list'])   : $this->formBuilder->fields->listFields();
		$editStrip  = isset($options['editStrip'])  ? str2bool($options['editStrip'])  : NULL;
		$showHidden = isset($options['showHidden']) ? str2bool($options['showHidden']) : TRUE;

		if ($showHidden || $showHidden === NULL) {
			foreach ($this->formBuilder->fields->getFields() as $field) {
				// Skip all non-hidden fields
				if ($field->type != 'hidden') continue;

				// Skip fields that have already been rendered
				if (in_array($field->name, $this->fieldsRendered)) continue;

				// Skip the field if it's not in the list
				if (!in_array($field->name, $list)) continue;

				// Skip if it's not set to show in this form
				if (!is_empty($this->formType) && !in_array($this->formType, $field->showIn)) continue;

				// We only care if this is a hidden field
				$output .= $field->render();
				$this->fieldsRendered[] = $field->name;
			}
		}

		foreach ($this->formBuilder->fields->getSortedFields($editStrip) as $field) {
			// Skip any hidden fields, we've already processed them
			if ($field->type == 'hidden') continue;

			// Skip fields that have already been rendered
			if (in_array($field->name, $this->fieldsRendered)) continue;

			// Skip the field if it's not in the list
			if (!in_array($field->name, $list)) continue;

			// Skip if it's not set to show in this form
			if (!is_empty($this->formType) && !in_array($this->formType, $field->showIn)) continue;

			// Replace any unnamed field with a named version for this field
			$output .= preg_replace('/{field(?!.*name=".+".*)(.*)}/', '{field $1 name="'.$field->name.'"}', $block);
		}

		return $output;
	}

	/**
	 * [PREG Callback] Process all {rowLoop}'s
	 *
	 * @param array $matches
	 * @return string
	 * @throws Exception
	 */
	private function __renderRowLoop($matches){
		$output  = '';
		$options = attPairs($matches[1]);
		$block   = trim($matches[2]);

		// Extract db table stuff into vars
		$dbOptions    = $this->formBuilder->dbOptions;
		$dbConnection = isset($dbOptions['connection']) ? $dbOptions['connection'] : 'appDB';
		$order        = isset($dbOptions['order'])      ? $dbOptions['order']      : NULL;
		$where        = isset($dbOptions['where'])      ? $dbOptions['where']      : NULL;
		$limit        = isset($dbOptions['limit'])      ? $dbOptions['limit']      : NULL;
		$table        = $dbOptions['table'];

		// Sanity check
		if (isnull($table)) {
			errorHandle::newError(__METHOD__."() No table defined in dbTableOptions! (Did you forget to call linkToDatabase()?)", errorHandle::DEBUG);
			return '';
		}

		// Get the db connection we'll be talking to
		if (!$db = db::get($dbConnection)) {
			errorHandle::newError(__METHOD__."() Database connection failed to establish", errorHandle::DEBUG);
			return '';
		}

		// Build the SQL
		$sql = sprintf('SELECT * FROM `%s`', $db->escape($table));
		if (!is_empty($where)) $sql .= " WHERE $where";
		if (!is_empty($order)) $sql .= " ORDER BY $order";
		if (!is_empty($limit)) $sql .= " LIMIT $limit";

		// Run the SQL
		$sqlResult = $db->query($sql);

		// Catch any sql error
		if($sqlResult->errorCode()){
			errorHandle::newError(__METHOD__."() SQL Error: {$sqlResult->errorCode()}:{$sqlResult->errorMsg()}", errorHandle::HIGH);
			return 'Internal database error!';
		}

		// Save the number of rows for {rowCount}
		$this->counterRows = $sqlResult->rowCount();

		// If there's no rows, bail out!
		if(!$this->counterRows) throw new Exception('No records found');

		// Start looping
		while($dbRow = $sqlResult->fetch()){
			$rowBlock       = $block;
			$deferredFields = array();
			$primaryFields  = array();

			// Set rendered value of each field, needed or not, so that plaintext can tap into it
			foreach ($dbRow as $dbField => $dbValue) {
				$field = $this->formBuilder->fields->getField($dbField);
				if ($field) {
					// If it's a primary field, save it for use in rowID
					if ($field->isPrimary()) $primaryFields[$dbField] = $dbValue;
					$field->setRenderedValue($dbValue);
				}
			}

			/*
			 * We need to generate a unique ID for this row. This is done by hashing the row's primary values and using that as the key
			 * This is done to create the needed looping array for the processor as well and keep POST data organized should we need
			 * to re-render the form
			 */
			$rowID = md5(implode('|', $primaryFields));

			// Save this row's primary fields for later (like during processing)
			$this->formBuilder->editTableRowData[$rowID] = $primaryFields;

			// Global replacements
			$rowBlock = str_replace('{rowLoopID}', $rowID, $rowBlock);

			// Regex grabbing all fields
			preg_match_all('/{field.*?name="(\w+)".*?}/', $rowBlock, $matches);

			// Save the number of fields for {fieldCount}
			$this->counterFields = sizeof($matches[0]);

			// Loop through each field
			foreach($matches[1] as $matchID => $fieldName){
				$fieldTag = $matches[0][$matchID];
				$field = $this->formBuilder->fields->getField($fieldName);

				// Append the rowID onto the field's name
				list($fieldTag, $rowBlock) = str_replace('name="'.$fieldName.'"', 'name="'.$fieldName.'['.$rowID.']"', array($fieldTag, $rowBlock));

				// If this is a plaintext field, defer it till later
				if($field->type == 'plaintext'){
					$deferredFields[] = array('fieldTag' => $fieldTag, 'field' => $field);
					continue;
				}

				// Restore value from POST
				$value = (isset($_POST['HTML'][$fieldName][$rowID]) && is_array($_POST['HTML'][$fieldName]))
					? $_POST['HTML'][$fieldName][$rowID]
					: (isset($dbRow[$fieldName]) ? $dbRow[$fieldName] : '');

				// Render the field tag!
				$renderedField = $this->__renderFieldTag($fieldTag, $field, $value, $fieldTag);

				// Replace the field tag with it's fully rendered version
				$rowBlock = str_replace($fieldTag, $renderedField, $rowBlock);
			}

			// Now process any deferred fields
			foreach($deferredFields as $deferredField){
				$fieldTag      = $deferredField['fieldTag'];
				$field         = $deferredField['field'];
				$renderedField = $this->__renderFieldTag($fieldTag, $field, NULL, $fieldTag);
				$rowBlock      = str_replace($fieldTag, $renderedField, $rowBlock);
			}

			$output .= $rowBlock;
		}

		// Replace any field or row count tags inside our block
		$output = str_replace('{rowCount}', $this->counterRows, $output);
		$output = str_replace('{fieldCount}', $this->counterFields, $output);

		// Return the compiled block
		return $output;
	}

	/**
	 * [PREG Callback] Process all {ifFormErrors} and {formErrors}
	 * @param $matches
	 * @return string
	 */
	private function __renderFormErrors($matches){
		// Build formErrors HTML and if there's none, return an empty string
		$formErrorHTML = formBuilder::prettyPrintErrors($this->formBuilder->formName.'_'.$this->formType);
		if(!$formErrorHTML) return '';

		// If there's a block move into it, and replace {formErrors} with the formErrorsHTML from above
		if(sizeof($matches) > 1){
			$block = $matches[1];
			return str_replace('{formErrors}', $formErrorHTML, $block);
		}else{
			return $formErrorHTML;
		}
	}

	/**
	 * Fully render a given field
	 *
	 * @param              $tag
	 * @param fieldBuilder $field
	 * @param string       $value
	 * @param string       $errorReturn
	 * @return string
	 */
	function __renderFieldTag($tag, fieldBuilder $field, $value=NULL, $errorReturn=''){
		// Get the attribute pairs for this field tag
		preg_match('/^{\w+(.+)}$/', $tag, $matches);
		$attrPairs = attPairs($matches[1]);

		// set form info in Field Builder
		$field->setRenderType($this->formType);
		$field->setFormID($this->formID);

		// Restore value from POST if we weren't given it
		if(isset($value)) {
			$attrPairs['value'] = $value;
		}elseif (isset($_POST['HTML'][ $field->name ])) {
			if(is_array($_POST['HTML'][ $field->name ])){
				$keys = array_keys($_POST['HTML'][ $field->name ]);
				if(strlen($keys[0]) != 32){
					$attrPairs['value'] = $_POST['HTML'][ $field->name ];
				}
			}else{
				$attrPairs['value'] = $_POST['HTML'][ $field->name ];
			}
		}

		$display  = isset($attrPairs['display'])
			? trim(strtolower($attrPairs['display']))
			: 'full';
		$template = isset($attrPairs['template'])
			? trim(strtolower($attrPairs['template']))
			: NULL;

		// Render the field tag
		switch ($display) {
			case 'full':
				return $field->render($template, $attrPairs);
				break;
			case 'field':
				return $field->renderField($attrPairs);
				break;
			case 'label':
				return $field->renderLabel($attrPairs);
				break;
			default:
				errorHandle::newError(__METHOD__."() Invalid 'display' for field '{$attrPairs['name']}'! (only full|field|label valid)", errorHandle::DEBUG);
		}

		return $errorReturn;
	}

	/**
	 * [PREG Callback] Process general template tags
	 *
	 * @param array $matches
	 * @return string
	 */
	private function __renderGeneralTags($matches){
		$tmplTag   = $matches[0];
		$tagName   = trim($matches[1]);
		$attrPairs = attPairs($matches[2]);
		switch (strtolower($tagName)) {
			case 'formtitle':
				return $this->renderOptions['title'];

			case 'form':
				$output = '';
				$showHidden = isset($attrPairs['hidden']) ? str2bool($attrPairs['hidden']) : FALSE;
				unset($attrPairs['hidden']);

				// Build the <form> tag
				if(!isset($this->renderOptions['noFormTag']) || !$this->renderOptions['noFormTag']){

					// Add rel and rev attributes
					if(isset($this->renderOptions['rel'])) $attrPairs['rel'] = $this->renderOptions['rel'];
					if(isset($this->renderOptions['rev'])) $attrPairs['rev'] = $this->renderOptions['rev'];

					// If we have browser validation turned off, add the attribute to <form>
					if(!$this->formBuilder->browserValidation) $attrPairs['novalidate'] = '';

					// If we have browser validation turned off, add the attribute to <form>
					if($this->formBuilder->formEncoding) $this->formAttributes['enctype'] = $this->formBuilder->formEncoding;

					// Compile form attributes
					$attrs = array();
					foreach(array_merge($this->formAttributes, $attrPairs) as $attr => $value){
						$attrs[] = $attr.'="'.addslashes($value).'"';
					}

					// Compile form data attributes
					foreach($this->formDataAttributes as $attr => $value){
						$attrs[] = 'data-'.$attr.'="'.addslashes($value).'"';
					}

					// Catch formAction
					$actionText = '';
					if($this->formBuilder->formAction || isset($this->renderOptions['action'])){
						$action = isset($this->renderOptions['action'])
							? $this->renderOptions['action']
							: $this->formBuilder->formAction;
						$actionText = 'action="'.$action.'"';
					}

					// Build attrText
					$attrText = sizeof($attrs)
						? ' '.implode(' ', $attrs)
						: '';

					// Generate <form> tag
					$output .= sprintf('<form %s method="post" %s>',
						$actionText,
						$attrText);
				}

				// Include the formName
				$output .= sprintf('<input type="hidden" name="__formID" value="%s">', $this->formID);

				// Include the CSRF token
				list($csrfID, $csrfToken) = session::csrfTokenRequest();
				$output .= sprintf('<input type="hidden" name="__csrfID" value="%s">', $csrfID);
				$output .= sprintf('<input type="hidden" name="__csrfToken" value="%s">', $csrfToken);

				// Add any hidden fields (if needed)
				if($showHidden){
					foreach($this->formBuilder->fields->getFields() as $field){
						if (in_array($field->name, $this->fieldsRendered)) continue;
						if($field->type == 'hidden') {
							$output .= $field->render();
							$this->fieldsRendered[] = $field->name;
						}
					}
				}

				// Return the result
				return $output;

			case '/form':
				return (isset($this->renderOptions['noFormTag']) && $this->renderOptions['noFormTag'])
					? ''
					: '</form>';

			case 'fields':
				$output  = '';
				$display = isset($attrPairs['display'])
					? trim(strtolower($attrPairs['display']))
					: 'full';

				foreach ($this->formBuilder->fields->getFields() as $field) {
					if (in_array($field->name, $this->fieldsRendered)) continue;

					switch ($display) {
						case 'full':
							$this->fieldsRendered[] = $field->name;
							$output .= $field->render();
							break;
						case 'fields':
							$this->fieldsRendered[] = $field->name;
							$output .= $field->renderField();
							break;
						case 'labels':
							$this->fieldsRendered[] = $field->name;
							$output .= $field->renderLabel();
							break;
						case 'hidden':
							if($field->type == 'hidden'){
								$this->fieldsRendered[] = $field->name;
								$output .= $field->render();
							}
							break;
						default:
							errorHandle::newError(__METHOD__."() Invalid 'display' for {fields}! (only full|fields|labels|hidden valid)", errorHandle::DEBUG);
							return '';
					}
				}

				return $output;

			case 'field':
				if (!isset($attrPairs['name'])) {
					errorHandle::newError(__METHOD__."() 'name' is required for {field} tags", errorHandle::DEBUG);
					return '';
				}

				$field = $this->formBuilder->fields->getField($attrPairs['name']);
				if (isnull($field)) {
					errorHandle::newError(__METHOD__."() No field defined for '{$attrPairs['name']}'!", errorHandle::DEBUG);
					return '';
				}

				return $this->__renderFieldTag($tmplTag, $field);

			case 'fieldset':
				$legend = isset($attrPairs['legend']) && !is_empty($attrPairs['legend'])
					? '<legend>'.$attrPairs['legend'].'</legend>'
					: '';

				return '<fieldset>'.$legend;

			case '/fieldset':
				return '</fieldset>';

			case 'rowcount':
				return (string)$this->counterRows;

			case 'fieldcount':
				return (string)$this->counterFields;

			// By default we need to return the whole tag because it must not be one of our tags.
			default:
				return $matches[0];
		}
	}
}
