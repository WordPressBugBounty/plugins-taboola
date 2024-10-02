<?php



class JavaScriptWrapper {

	private $_scriptFileContent;
	private $_currentScript;
	private $_fileName;
	private $_params;
	private $_plugin_directory;

	/**
	 * JavaScriptWrapper constructor.
	 *
	 * @param $_fileName "should be a file from the js folder"
	 * @param $_params "key/value array with parameters to be replaced in the script"
	 */
	public function __construct( $_fileName, $_params ) {

		$this->_plugin_directory = plugin_dir_path(__FILE__);
		$this->_fileName = $_fileName;
		$this->_params   = $_params;

		$this->loadScriptFromFile($this->_fileName, $this->_params);
	}


	function loadScriptFromFile($fileName, $params){
		$this->_scriptFileContent = file_get_contents($this->_plugin_directory.'js/'.$fileName);
		$this->_currentScript = str_replace(array_keys($params),array_values($params),$this->_scriptFileContent);
	}

	function appendScript($jscript){
		$this->_currentScript .= $jscript;
	}

	function __toString() {
		return $this->_currentScript;
	}

	function getScriptMarkupString(){
		return "<script type='text/javascript'>".$this."</script>";
	}


}