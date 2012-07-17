<?php
define('DS', DIRECTORY_SEPARATOR);

class mtClientScript extends CClientScript {
	const TYPE_CSS = 'css';
	const TYPE_JS = 'js';
	private $combine = false;
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
				$this->registerScript($key, 'var ' . $key . ' = "' . str_replace('"', '\"', $value) . '";', CClientScript::POS_HEAD);
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
	 * @return int timestamp of oldest file or 0 if no file exists
	 */
	public function getMaxTime($urls) {
		$maxTime = 0;
		foreach ($urls as $url) {
			$filePath = $this->_basePath . rtrim($this->_baseUrl, '/') . '/' . $url;
			if (stream_resolve_include_path($filePath)) {
				$time = filemtime($filePath);
				if ($time > $maxTime) {
					$maxTime = $time;
				}
			}
		}
		return $maxTime;
	}

	private function combineScripts() {
		// if (count($this->cssFiles) > 0) {
		// 	$cssFiles = array();
		// 	foreach ($this->cssFiles as $url => $media) {
		// 		if (!($this->excludeAssets && $this->isAsset($url)) && !in_array(basename($url), $this->excludeFiles)) {
		// 			$cssFiles[$media ? strtolower($media) : $this->_defaultCssMedia][] = $url;
		// 		}
		// 	}
		// 
		// 	foreach ($cssFiles as $media => $urls) {
		// 		$outfile = $this->combineFiles('css', $urls);
		// 		foreach ($urls as $url) {
		// 			$this->scriptMap[basename($url)] = $this->getCoreScriptUrl() . '/' . $outfile;
		// 		}
		// 	}
		// }
		// each package gets its own minified version
		foreach ($this->packages as $name => $package) {
			$this->_baseUrl = isset($package['baseUrl']) ? $package['baseUrl'] : $this->getCoreScriptUrl();
			$files = $package[self::TYPE_JS];
			$latestChange = $this->getMaxTime($files);
			if ($latestChange === 0) { continue; }
			$outFile = $name . '_' . $latestChange;
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
	 * Inserts the scripts at the end of the body section.
	 * @param string $output the output to be inserted with scripts.
	 */
	// public function renderBodyEnd(&$output)
	// {
	// 	if(!isset($this->scriptFiles[self::POS_END]) && !isset($this->scripts[self::POS_END])
	// 	&& !isset($this->scripts[self::POS_READY]) && !isset($this->scripts[self::POS_LOAD]) && !isset($this->scriptFiles[self::POS_LOAD]))
	// 	return;
	// 	$fullPage=0;
	// 	$output=preg_replace('/(<\\/body\s*>)/is','<###end###>$1',$output,1,$fullPage);
	// 	$html='';
	// 	if(isset($this->scriptFiles[self::POS_END]))
	// 	{
	// 	foreach($this->scriptFiles[self::POS_END] as $scriptFile)
	// 		$html.=Html::scriptFile($scriptFile)."\n";
	// 	}
	// 	if(isset($this->scriptFiles[self::POS_LOAD])) {
	// 		// defer loading of scripts {@link http://code.google.com/speed/page-speed/docs/payload.html#DeferLoadingJS}
	// 		if($fullPage) {
	// 			$html.='<script>// Add a script element as a child of the body
	// 			$(function() {';
	// 			foreach($this->scriptFiles[self::POS_LOAD] as $scriptFile) {
	// 				$html.='var node = document.createElement("script");
	// 				node.type = "text/javascript";
	// 				node.async = true;
	// 				node.src = "'.$scriptFile.'";
	// 				document.body.appendChild(element);';
	// 			}
	// 			$html.='});</script>';
	// 		}
	// 	}
	// 	$scripts=isset($this->scripts[self::POS_END]) ? $this->scripts[self::POS_END] : array();
	// 	if(isset($this->scripts[self::POS_READY])) {
	// 		if($fullPage)
	// 			$scripts[]="jQuery(function($) {\n".implode("\n",$this->scripts[self::POS_READY])."\n});";
	// 		else
	// 			$scripts[]=implode("\n",$this->scripts[self::POS_READY]);
	// 	}
	// 	if(isset($this->scripts[self::POS_LOAD])) {
	// 		if($fullPage)
	// 			$scripts[]="jQuery(window).load(function() {\n".implode("\n",$this->scripts[self::POS_LOAD])."\n});";
	// 		else
	// 			$scripts[]=implode("\n",$this->scripts[self::POS_LOAD]);
	// 	}
	// 	if(!empty($scripts)) {
	// 		$html.=CHtml::script(implode("\n",$scripts))."\n";
	// 	}
	// 	if($fullPage) {
	// 		$output=str_replace('<###end###>',$html,$output);
	// 	}
	// 	else {
	// 		$output=$output.$html;
	// 	}
	// }

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
	 * @param array $urls  array of url of the files
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

		// $files = array();
		// $urlOfFile = array();
		// foreach ($urls as $url) {
		// 	$urlOfFile[$filePath] = explode('/', $url); // relative to WWWROOT without filename
		// }

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
		if (empty($urls)) throw new CException('Minification does not contain URLs for ' . $outFile);
		$cmd = sprintf('%s -jar %s --module_output_path_prefix %s --compilation_level %s --formatting=%s --module %s:%d %s 2>&1',
			$this->javaPath,
			$this->closurePath,
			$this->_assetsPath . DS,
			$this->closureConfig,
			self::FORMAT_DELIMITER,
			$outFile,
			count($urls),
			'--js ' . implode(' --js ', array_map(function($u){ return ltrim($u, '/'); }, $urls))
		);
		$return = shell_exec($cmd);
		if (!empty($return)) Yii::log($return, CLogger::LEVEL_WARNING, 'ClientScript');
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

	private function crockfordify($content, $outFile, $type) {
		require_once 'jsmin.php';
		require_once 'cssmin-v3.0.1.php';
		
		switch ($type) {
			case self::TYPE_CSS:
				$out = CSSMin::minify($content);
				break;
			case self::TYPE_JS:
				$out = JSMin::minify($content);
				break;
		}
		file_put_contents($this->_assetsPath . DS . $outFile, $out);
	}
}
