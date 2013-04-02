<?php
define('DS', DIRECTORY_SEPARATOR);

class mtClientScript extends CClientScript {
	const TYPE_CSS = 'css';
	const TYPE_JS = 'js';
	public $combine = false;
	/**
	 * @var array files to exclude from beeing combined and compressed
	 */
	public $excludeFiles = array();

	/**
	 * @var bool exclude asset files like core scripts
	 */
	public $excludeAssets = false;

	/**
	 * @var string Absolute file path to java
	 */
	public $javaPath = '/usr/bin/java';

	/**
	 * @var string Absolute file path to yui compressor
	 */
	public $yuicPath = null;

	/**
	 * @var string Absolute file path to google closure compiler
	 */
	public $closurePath = null;

	const CLOSURE_WHITESPACE = 'WHITESPACE_ONLY';
	const CLOSURE_SIMPLE = 'SIMPLE_OPTIMIZATIONS';
	const CLOSURE_ADVANCED = 'ADVANCED_OPTIMIZATIONS';

	const FORMAT_PRETTY = 'PRETTY_PRINT';
	const FORMAT_DELIMITER = 'PRINT_INPUT_DELIMITER';

	/**
	 * @var string 'WHITESPACE_ONLY' | 'SIMPLE_OPTIMIZATIONS' | 'ADVANCED_OPTIMIZATIONS'
	 */
	public $closureConfig = self::CLOSURE_SIMPLE;

	/**
	 * @var array list of cdn hosts
	 */
	public $cdn = array();
	/**
	 * @var array list of file => host
	 */
	public $fileToHost;

	private $_defaultCssMedia = 'screen, projection';
	private $_baseUrl = '';
	private $_basePath = '';
	private $_assetsPath = '';

	public function registerCoreScript($name) {
		if (isset($this->packages[$name], $this->packages[$name]['globals'])) {
			foreach ($this->packages[$name]['globals'] as $key => $value) {
				if (strpos($value,'js:')!==0) {
					$this->registerScript($key, 'var ' . $key . ' = "' . str_replace('"', '\"', $value) . '";', CClientScript::POS_HEAD);
				}
				else {
					// if the value starts with js: it will not be wrapped in quotes, as we might want to register JS objects, booleans or other data types
					$value = substr($value, 3);//remove js: from the string
					$this->registerScript($key, 'var ' . $key . ' = ' . $value . ';', CClientScript::POS_HEAD);
				}
			}
		}
		return parent::registerCoreScript($name);
	}

	public function init() {
		parent::init();
		if (!is_executable($this->javaPath)) {
			throw new Exception('Java not found or not accessable');
		}
		if (!is_readable($this->yuicPath)) {
			$this->yuicPath = dirname(__FILE__) . DS . 'yuicompressor-2.4.8pre.jar';
		}
		if (!is_readable($this->closurePath)) {
			$this->closurePath = dirname(__FILE__) . DS . 'compiler.jar';
		}
		if (!file_exists($this->yuicPath)) {
			throw new Exception('YUI compressor not found');
		}
		if (!file_exists($this->closurePath)) {
			throw new Exception('Google closure compiler not found');
		}

		$this->_baseUrl = Yii::app()->baseUrl;
		$this->_basePath = YiiBase::getPathOfAlias('webroot');
		$this->_assetsPath = $this->_basePath . str_replace($this->_baseUrl, '', $this->getCoreScriptUrl());
	}

	public function setDescription() {
		return 'This plugin combines and shrinks all js and css files';
	}

	public function getPositions() {
		return array(self::POS_BEGIN, self::POS_READY, self::POS_LOAD, self::POS_HEAD, self::POS_END);
	}

	public function isAsset($url) {
		return strpos($url, $this->getCoreScriptUrl()) === 0;
	}

	/**
	 * @param array $urls
	 * @return array of timestamps
	 */
	private function filesmtimes($urls) {
		$return = array();
		foreach ($urls as $url) {
			$return[] = $this->filemtimeCheck($url);
		}
		return $return;
	}

	private function filemtimeCheck($url) {
		$filePath = $this->_basePath . rtrim($this->_baseUrl, '/') . '/' . $url;
		if (stream_resolve_include_path($filePath)) {
			return filemtime($filePath);
		}
	}

	public function registerCssFileWithTimestamp($name) {
		$name .= '?t=' . $this->filemtimeCheck($name);
		return parent::registerCssFile($name);
	}

	public function registerScriptFileWithTimestamp($name) {
		$name .= '?t=' . $this->filemtimeCheck($name);
		return parent::registerScriptFile($name);
	}

	private function combineScripts() {
		// each package gets its own minified version
		foreach ($this->packages as $name => $package) {
			$this->_baseUrl = isset($package['baseUrl']) ? $package['baseUrl'] : $this->getCoreScriptUrl();
			$files = $package[self::TYPE_JS];
			$mtimes = $this->filesmtimes($files);
			if (empty($mtimes)) { continue; }
			$outFile = $name . '_' . md5(implode('', $mtimes));
			$urls = array();
			foreach ($files as $f) {
				$base = basename($f);
				// don't remap already mapped files
				if (!array_key_exists($base, $this->scriptMap)) {
					$this->scriptMap[$base] = $this->getCoreScriptUrl() . '/' . $outFile . '.' . self::TYPE_JS;
					$urls[] = $this->_baseUrl . '/' . $f;
				}
			}
			// happens if all package files were already mapped
			if (empty($urls)) { continue; }
			$this->combineFiles(self::TYPE_JS, $urls, $outFile);

		}
		$this->remapScripts();
	}

	/**
	 * @param string the output to be inserted with scripts.
	 */
	public function renderHead(&$output) {
		if ($this->combine) {
			$this->combineScripts();
		}
		parent::renderHead($output);
	}

	/**
	 * Returns one host per file, iterating through the hosts to distribute them evenly
	 *
	 * @param string $file
	 * @return string
	 */
	public function fileToHost($file) {
		if(!isset($this->fileToHost[$file])) {
			$this->fileToHost[$file] = current($this->cdn);
			if(!next($this->cdn)) reset($this->cdn);
		}
		return $this->fileToHost[$file];
	}

	/**
	 * Combines, optimizes and compresses all given files
	 *
	 * @param string $type js or css
	 * @param array $urls	array of url of the files
	 * @param string $media optional, only relevant for css
	 * @return string name of the resulting file
	 * @author Florian Fackler
	 */
	private function combineFiles($type, $files, $outFile='out') {
		if (!in_array($type, array('js', 'css'))) {
			throw new Exception('Only js or css as file type allowed');
		}

		if (file_exists($this->_assetsPath . DS . $outFile . '.' . $type)) {
			return;
		}

		switch ($type) {
			case self::TYPE_CSS:
				return;
				$joinedContent = '';
				foreach ($files as $file) {
					if(isset($urlOfFile[$file][1]) && $urlOfFile[$file][1] === 'themes') {
						$theme = $urlOfFile[$file][1] . '/' . $urlOfFile[$file][2] . '/';
					} else {
						$theme = '';
					}
					// Correct file path in css/js files :: MUST BE RELATIVE
					$content = file_get_contents($file);
					$content = str_replace('../', '../../' . $theme, $content);

					$search = array('/(\'\")^\//');
					$replace = array('$1' . '/var/www');

					if(!empty($this->cdn)) {
						$search[] = '/url\([\'"]?(\/[^)\'"]+)[\'"]?\)/e'; // adds a host to absolute urls
						$replace[] = '"url(http://" . $this->fileToHost("$1") . "$1)"';
					}

					$content = preg_replace($search, $replace, $content);
					$joinedContent .= $content;
				}
				$this->closurify($joinedContent, $outFile, $type);
			break;
			case self::TYPE_JS:
				$this->minifyJs($files, $outFile);
			break;
		}
		return $outFile;
	}

	private function minifyJs($urls, $outFile) {
		if (empty($urls))
			throw new CException('Minification does not contain URLs for ' . $outFile);
		$cmd = implode(' ', array(
			$this->javaPath, '-jar' , $this->closurePath,
			'--module_output_path_prefix', $this->_assetsPath . DS,
			'--compilation_level', $this->closureConfig,
			// '--warning_level', 'QUIET', // 'QUIET|DEFAULT|VERBOSE'
			'--formatting', self::FORMAT_DELIMITER,
			sprintf('--module %s:%d %s',
				$outFile,
				count($urls),
				'--js ' . implode(' --js ', array_map(function($u) {
					return ltrim($u, '/');
				}, $urls))
			),
			'2>&1',
		));
		$return = shell_exec($cmd);
		if (!empty($return))
			Yii::log($return, CLogger::LEVEL_ERROR, 'ClientScript');
	}

	private function closurify($content, $outFile, $type) {
		$temp = $this->_basePath . DS . 'protected' . DS . 'runtime' . DS . $outFile;
		file_put_contents($temp, $content);
		unset($content);
		switch ($type) {
			case self::TYPE_CSS:
				$cmd = sprintf('%s -jar %s -o %s %s',
					$this->javaPath,
					$this->yuicPath,
					$this->_assetsPath . DS . $outFile,
					$temp);
				break;
			case self::TYPE_JS:
				$cmd = sprintf('%s -jar %s --js_output_file %s --compilation_level %s --js %s --formatting=PRETTY_PRINT',
					$this->javaPath,
					$this->closurePath,
					$this->_assetsPath . DS . $outFile,
					$this->closureConfig,
					$temp);
				break;
		}
		$return = shell_exec($cmd);
	}
}
