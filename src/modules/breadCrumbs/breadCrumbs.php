<?php
/**
 * EngineAPI Breadcrumbs module
 * @package EngineAPI\modules\breadcrumbs
 */
class breadCrumbs {
	private $engine  = NULL;
	public $pattern  = "/\{breadCrumbs\s+(.+?)\}/";
	public $function = "breadCrumbs::templateMatches";

	function __construct() {
		templates::defTempPatterns($this->pattern,$this->function,$this);
	}

	/**
	 * Engine template tag callback
	 * @param $matches
	 * @return mixed
	 */
	public static function templateMatches($matches) {
		$obj      = templates::retTempObj("breadCrumbs");
		$attPairs = attPairs($matches[1]);
		return($obj->breadCrumbs($attPairs));
	}

	/**
	 * Generate HTML breadcrumbs
	 *
	 * @todo Fix use of deprecated use of webHelper_errorMsg()
	 * @todo Remove use of deprecated global $engineVars
	 * @param array $attPairs
	 *        -titlecase  - Automatically convert cases of output to Title Case
	 *        -ellipse    - Define the ellipse char to use
	 *        -spacer     - Define the spacer char to use
	 *        -type       - hierarchical or actual
	 *        -displayNum - Limit number of crumbs to show
	 *        -prefixNum  - Unknown
	 * @return bool|string
	 */
	public function breadCrumbs($attPairs) {
		$engine   = EngineAPI::singleton();
		

		$callingFunction        = array("breadCrumbs","breadCrumbs");
		$tempParams             = array();
		$tempParams['attPairs'] = $attPairs;
		$trail                  = functions::getInstance()->execFunctionExtension($callingFunction,$tempParams,"before");

		if($trail) return($trail);

		/* setup initial variables */
		$str2upper = (isset($attPairs['titlecase']) and str2bool($attPairs['titlecase']));

		$ellipse = (enginevars::is_set("breadCrumbsEllipse"))?enginevars::get("breadCrumbsEllipse"):" &#133; ";
		$ellipse = (isset($attPairs['ellipse']))?$attPairs['ellipse']:$ellipse;

		$spacer = (enginevars::is_set("breadCrumbsSpacer"))?enginevars::get("breadCrumbsSpacer"):">>";
		$spacer = (isset($attPairs['spacer']))?$attPairs['spacer']:$spacer;

		$type   = (isset($attPairs['type']))?$attPairs['type']:"hierarchical";
		$displayNum = (enginevars::is_set("breadCrumbsDisplayNum"))?enginevars::get("breadCrumbsDisplayNum"):0;
		$displayNum = (isset($attPairs['displayNum']))?$attPairs['displayNum']:$displayNum;

		$start  = 0;
		$prefix = 1;

		if ($type != "hierarchical" && $type != "actual") {
			return(webHelper_errorMsg("Breadcrumbs: type == '$type' not supported."));
		}

		$trailArray = array();

		if ($type == "hierarchical") {
			$url = explode("/",$_SERVER["SCRIPT_NAME"]);
			$urlCount = count($url);
			unset($url[--$urlCount]);

			if ($displayNum) {
				if ($displayNum < $urlCount) {
					$start = $urlCount - $displayNum;
				}
				if (isset($attPairs['prefixNum'])) {
					$prefix = $attPairs['prefixNum'];
				}
			}

			$path = enginevars::get("documentRoot");
			$href = enginevars::get("WVULSERVER");
			for ($I = 0;$I < $urlCount;$I++) {

				$path .= "/$url[$I]";
				$href .= "$url[$I]/";

				//Handles empty first case
				if (empty($url[$I])) {
					continue;
				}

				if ($start != 0) {
					if ($I == $prefix+1) {
						$trailArray[] = "$ellipse";
					}
					if ($I > $prefix && $I <= (($prefix > 1)?$start+($prefix-1):$start)) {
						continue;
					}
				}

				$displayName = $this->displayName($path,$url[$I],$str2upper);

				if ($I == $urlCount-1) {
					$trailArray[] = $displayName;
				}
				else {
					$trailArray[] = sprintf('<a href="%s" class="breadCrumbLink">%s</a>',$href,$displayName);
				}
			}

		}
		if ($type == "actual") {
			return(webHelper_errorMsg("type == actual not coded yet"));	
		}

		$trail = implode(" <span class=\"breadCrumbSpacer\">$spacer</span> ",$trailArray);



		return($trail);

	}
	
		private function displayName($path,$url,$str2upper) {

		$displayName = "";
		if (file_exists($path ."/.breadCrumbs")) {
			$lines = file($path ."/.breadCrumbs");
			$displayName = $lines[0];
		}
		else {
			$displayName = $url;
		}

		return (($str2upper === TRUE)?str2TitleCase($displayName):$displayName);

	}
}
?>
