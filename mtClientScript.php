<?php
/**
 * Script to combine and shrink js and css files.
 * Using google closure compiler for js and yuicompressor for css files
 * It's mandatory to have java available
 *
 * ATTENTION:
 * =========
 * Always use relative paths in your javascript and css files
 * because the location will different later.
 *
 * For example:
 * -----------
 * background: url(../icon.png);
 *
 * @category  Yii_Extension
 * @package   mintaoHelperScripts
 * @author    Florian Fackler <florian.fackler@mintao.com>
 * @copyright 2010 mintao GmbH & Co. KG
 * @license   Proprietary. All rights reserved
 * @version   $Id: mtClientScript 2010-09-08T16:10:37+02:00florian.fackler
 * @link      http://mintao.com
 */

define('DS', DIRECTORY_SEPARATOR);

class mtClientScript extends CClientScript
{
    /**
     * @var array files to exclude from beeing combined and compressed
     */
    public $excludeFiles = array();

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

		/**
		 * @var string 'WHITESPACE_ONLY' | 'SIMPLE_OPTIMIZATIONS' | 'ADVANCED_OPTIMIZATIONS'
		 */
    public $closureConfig = 'SIMPLE_OPTIMIZATIONS';

    private $_defaultCssMedia = 'screen, projection';
    private $_baseUrl = '';
    private $_basePath = '';
    private $_assetsPath = '';

    public function init()
    {
        parent::init();
        if (!is_executable($this->javaPath)) {
            throw new Exception('Java not found or not accessable');
        }
        if (!is_readable($this->yuicPath)) {
            $this->yuicPath = dirname(__FILE__) . DS . 'yuicompressor-2.4.2.jar';
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
        $this->_assetsPath = $this->_basePath . str_replace(
            $this->_baseUrl, '', $this->getCoreScriptUrl()
        );
    }

    /**
     * Extension description
     *
     * @return string Description
     * @author Florian Fackler
     */
    public function setDescription()
    {
        return 'This pluign combines and shrinks all js and css files';
    }

    /**
     * @param string the output to be inserted with scripts.
     */
    public function renderHead(&$output)
    {
        $positions = array(
            self::POS_BEGIN, self::POS_READY, self::POS_LOAD, self::POS_HEAD, self::POS_END
        );

        // combine css files
        if (count($this->cssFiles) > 0) {
            $cssFiles = array();
            foreach ($this->cssFiles as $url => $media) {
                if (in_array($url, $this->excludeFiles)) {
                    continue;
                }
                $cssFiles[$media
                ?
                strtolower($media)
                :
                $this->_defaultCssMedia][] = $url;
            }

            foreach ($cssFiles as $media => $url) {
                $this->combineFiles('css', $url, $media);
            }
        }

        if ($this->enableJavaScript) {
            foreach($positions as $p) {
                if (isset($this->scriptFiles[$p])) {
                    $this->combineFiles('js', $this->scriptFiles[$p]);
                }
            }
        }

        $this->remapScripts();
        parent::renderHead($output);
    }


    /**
     * Combines, optimizes and compresses all given files
     *
     * @param string $type js or css
     * @param array $urls  array of url of the files
     * @param string $media optional, only relevant for css
     * @return void
     * @author Florian Fackler
     */
    private function combineFiles($type, array $urls, $media=null)
    {
        if (!in_array($type, array('js', 'css'))) {
            throw new Exception('Only js or css as file type allowed');
        }

        // Create file paths
        $files = array();

        foreach ($urls as $url) {
            $filePath =
                $this->_basePath . str_replace($this->_baseUrl, '', $url);
            if (file_exists($filePath)) {
                $files[] = $filePath;
                $urlOfFile[$filePath] = explode('/', $url); // relative to WWWROOT without filename
            }
        }
        // Generate hash over modification dates
        $_hash = null;
        foreach ($files as $file) {
            $_hash .= $file . filemtime($file);
        }

        // File name of the combined file will be...
        $outFile = sha1($_hash) . ".$type";

        // Create new if not exists ( --disable-optimizations)
        if (!file_exists($this->_assetsPath . DS . $outFile)) {
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
              $content = preg_replace(
                  '@(\'\")^/@',
                  '\\1' . $this->_baseUrl,
                  $content
              );
              $joinedContent .= $content;
            }
            $temp = $this->_basePath . DS . 'protected'
                . DS . 'runtime' . DS . $outFile;
            file_put_contents($temp, $joinedContent);
            unset($joinedContent);
            switch ($type) {
                case 'css':
                    $cmd = sprintf('%s -jar %s -o %s %s',
                        $this->javaPath,
                        $this->yuicPath,
                        $this->_assetsPath . DS . $outFile,
                        $temp);
                    break;
                case 'js':
                    $cmd = sprintf('%s -jar %s --js_output_file %s --compilation_level %s --js %s',
                        $this->javaPath,
                        $this->closurePath,
                        $this->_assetsPath . DS . $outFile,
                        $this->closureConfig,
                        $temp);
                    break;
            }
            $return = shell_exec($cmd);
        }

        foreach ($urls as $url) {
            $this->scriptMap[basename($url)]
                = $this->getCoreScriptUrl() . '/' . $outFile;
        }
    }
}
