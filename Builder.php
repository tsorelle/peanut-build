<?php
namespace Peanut;
use PHPUnit\Runner\Exception;

/**
 * Created by PhpStorm.
 * User: terry
 * Date: 7/16/2017
 * Time: 4:37 AM
 */
class Builder
{
    private static $instance;

    private $sources;
    private $settings;

    private function copyFile($src,$dst) {
        if ((strtolower(substr($src,-4)) == '.ini') &&
            file_exists($dst) ) {
            return;
        }
        copy($src,$dst);
    }
    private function copyDir($src,$dst)
    {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                $srcPath = $this->concatPath($src,$file);
                $dstPath = $this->concatPath($dst,$file);
                if (is_dir($srcPath)) {
                    $this->copyDir($srcPath, $dstPath);
                } else {
                    $this->copyFile($srcPath, $dstPath);
                }
            }
        }
        closedir($dir);
    }

    private function copyDirectoryContents($sourceDir,$targetDir) {
        if (!file_exists("$sourceDir")) {
            throw new \Exception("Source directory '$sourceDir' not found.");
        }
        $topfiles = scandir($sourceDir);
        foreach ($topfiles as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $sourceFile = "$sourceDir/$file";
            $targetFile = "$targetDir/$file";
            if (is_dir($sourceFile)) {
                $this->copyDir($sourceFile,$targetFile);
            }
            else {
                $this->copyFile($sourceFile,$targetFile);
            }
        }
    }

    private $protectedDirs = array();

    private function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $name = "$dir/$file";
            if (is_dir($name)) {
                if (!in_array($name,$this->protectedDirs)) {
                    self::delTree($name);
                }
            } else {
                unlink($name);
            }
        }
        return rmdir($dir);
    }

    function __construct()
    {
        $this->settings = parse_ini_file(__DIR__.'/settings.ini',true);
        //$this->sources = $this->getSourcePaths();
    }

    private function makeDir($path,$mode='clear')
    {
        $clear = ($mode == 'clear');
        $exists = file_exists($path);
        if ($clear && $exists) {
            $this->delTree($path);
            $exists = false;
        }
        if (!$exists) {
            mkdir($path);
        }

    }

    private static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new Builder();
        }
        return self::$instance;
    }

    private function getSourcePaths()
    {
        $result = new \stdClass();
        $paths =  $this->settings['vendor'];
        $raw = __DIR__."/../vendor/".$this->settings['vendor']['app'];
        $result->application = realpath(__DIR__."/vendor/".$this->settings['vendor']['app']);
        $result->peanut = realpath(__DIR__."/vendor/".$this->settings['vendor']['pnut']);
        $result->tops = realpath(__DIR__."/vendor/".$this->settings['vendor']['tops']);
        return $result;
    }

    
    private function getProjectRoot($project) {
        $buildRoot = realpath(__DIR__."/..");
        $projectRoot = $this->settings['projects'][$project];
        $result = realpath("$buildRoot/$projectRoot");
        if ($result === false) {
            throw new \Exception("Project root not found at $buildRoot/$projectRoot");
        }
        return $result;
    }

    private function concatPath($root,$sub) {
        $seperator = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ?  "\\": "/";
        $root = str_replace('/',$seperator,"$root");
        $sub = str_replace('/',$seperator,"$sub");
        if (substr($root,-1) !== $seperator) {
            $root .= $seperator;
        }
        if (substr($sub,0,1) === $seperator) {
            $sub = substr($sub,1);
        }
        return $root.$sub;
    }

    private function getModulePath($project) {
        $projectRoot = $this->getProjectRoot($project);
        $moduleSub = $this->settings['modules'][$project];
        return $this->concatPath($projectRoot,$moduleSub);
    }
    private function getApplicationPath($project) {
        $projectRoot = $this->getProjectRoot($project);
        return $this->concatPath($projectRoot,'application');
    }


    private function getSourcePath($lib) {
        $path = $this->settings['vendor'][$lib];
        $rawpath = __DIR__."/vendor/$path";
        return realpath($rawpath);
    }

    private function getModuleOffset($project)
    {
        $moduleSub = $this->settings['modules'][$project];
        $offset = sizeof(explode('/',$moduleSub));
        return $offset;
    }




    public static function Build($projects=null)
    {

        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['projects']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->deployTops($project);
            $builder->deployPeanut($project);
        }

        print "\nProjects completed\n";

    }
    public static function BuildPeanut($projects=null)
    {

        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['projects']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->deployPeanut($project);
        }
        print "\nProjects completed\n";

    }
    public static function BuildTops($projects=null)
    {

        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['projects']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->deployTops($project);
        }
        print "\nProjects completed\n";


    }

    private function fixReferencePaths($project, $appPath) {
        $vmDir = $this->concatPath($appPath,'mvvm/vm');
        $moduleSub =  '/'.$this->settings['modules'][$project].'/';
        $files = scandir($vmDir);
        foreach ($files as $fileName) {
            if (substr(strtolower($fileName),-3) == '.ts') {
                $filePath = $this->concatPath($vmDir,$fileName);
                $lines = file($filePath);
                if (empty($lines)) {
                    print "No file: $filePath";
                    continue;
                }
                $lineCount = sizeof($lines);
                for ($i=0; $i < $lineCount; $i++) {
                    $line = $lines[$i];
                    if (strpos($line,'<reference path')) {
                        $lines[$i] = str_replace("/modules/", $moduleSub,$line);
                    }
                }
                file_put_contents($filePath,$lines);
            }
        }
    }

    /**
     * @param $project
     */
    private function deployPeanut($project)
    {
        if ($project == 'pnut') {
            // only deploy tops to peanut core project.
            return;
        }
        print "Updating Peanut for $project...";
        $modulePath = $this->getModulePath($project);
        $this->makeDir($modulePath, 'make');

        $pnutSourcePath = $this->getSourcePath('pnut');
        $appPath = $this->getApplicationPath($project);

        $this->makeDir($appPath, 'make');
        $appSrc = $this->concatPath($pnutSourcePath,'application');
        $this->copyDirectoryContents($appSrc, $appPath);
        $this->fixReferencePaths($project, $appPath);

        $moduleSource = $this->concatPath($pnutSourcePath,'modules/pnut');
        $moduleTarget = $this->concatPath($modulePath, 'pnut');
        $this->makeDir($moduleTarget, 'make');
        $pnutDirs = $this->settings['pnut-dirs'];
        foreach ($pnutDirs as $dir => $mode) {
            $target = $this->concatPath($moduleTarget, $dir);
            $source = $this->concatPath($moduleSource,$dir);
            $this->makeDir($target,$mode);
            $this->copyDirectoryContents($source, $target);
        }
        print "done\n";
    }

    /**
     * @param $project
     */
    private function deployTops($project)
    {
        print "Updating Tops for $project...";
        $modulePath = $this->getModulePath($project);

        $topsSourcePath = $this->getSourcePath('tops');
        $srcPath = $this->concatPath($modulePath, "src");
        if (!file_exists($srcPath)) {
            mkdir($srcPath);
        }
        $targetTops = $this->concatPath($srcPath, "tops");
        $this->makeDir($targetTops);
        $this->copyDirectoryContents($topsSourcePath, $targetTops);
        print "done\n";
    }
}