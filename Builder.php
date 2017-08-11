<?php
namespace Peanut;
use PHPUnit\Runner\Exception;
use ZipArchive;

/**
 * Created by PhpStorm.
 * User: terry
 * Date: 7/16/2017
 * Time: 4:37 AM
 */



class Builder
{
    private $settings;

    function __construct()
    {
        $this->settings = parse_ini_file(__DIR__.'/settings.ini',true);
    }

    // **************************
    //  Top level methods
    //****************************

    public static function BuildDistribution($projects=null)
    {
        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['distribution']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->buildZip($project);
        }
        print "\nProjects completed\n";
    }
    private function buildZip($project) {
        print "Building distribution files for $project...";
        $sourceRoot = $this->getSourcePath($project);
        $distini = parse_ini_file("$sourceRoot/dist/distribution.ini",true);
        $version = $distini['values']['appVersion'];
        $dateStamp = date('Y-m-d');
        $zipname = $this->settings['distribution'][$project];
        $zipname = "$zipname-v$version-$dateStamp.zip";
        // print "\n$zipname\n";

        $zip = new ZipArchive();
        $buildDir = __DIR__;


        $includes = array();
        $excludes = array();
        foreach ($distini['files'] as $key => $value) {
            if (empty($value)) {
                $excludes[] = str_replace('\\','/',$key);
            }
            else {
                $includes[] = str_replace('\\','/',$key);
            }
        }
        $zipFilePath = "$buildDir/dist/".$zipname;
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }
        $zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($distini['rename'] as $file=>$target) {
            $this->addFromSource($file, $sourceRoot, $zip, $excludes,$target);
        }

        foreach ($includes as $file) {
            $this->addFromSource($file, $sourceRoot, $zip, $excludes);
        }

        $modulePath = $this->settings['modules'][$project];
        $topsSrc = $this->getSourcePath('tops');
        $pnutSrc = $this->getSourcePath('pnut');
        $pnutSrcRoot = realpath("$pnutSrc/modules/pnut");
        $pnutTestRoot = realpath("$pnutSrc/modules/src/test");
        if ($pnutSrcRoot === false) {
            print "\nPeanut root path not found.\n";
            return;
        }
        $peanutAppSrc = $this->getApplicationPath('pnut');
        $appExcludes = array_merge(
            $this->getExcludedFiles( $this->settings['application-files'],'web.root/application'),
            $this->getExcludedFiles( $this->settings['tsfix'],'web.root/application'));
        $this->zipDirectory($zip,$peanutAppSrc,"web.root/application",$appExcludes);
        $this->addAppTests($zip,$peanutAppSrc,'/'.$this->settings['modules'][$project].'/');
        $this->zipDirectory($zip,"$topsSrc","web.root/$modulePath/src/tops");
        $this->zipDirectory($zip,$pnutTestRoot,"web.root/$modulePath/src/test");
        $this->zipDirectory($zip,$pnutSrcRoot,"web.root/$modulePath/pnut");
        $this->addConfigFiles($zip,$distini["values"]);
        if (!empty($distini['js'])) {
            $this->addJsLibraries($zip, $distini['js']);
        }
        $this->zipDirectory($zip,realpath(__DIR__.'/files/typings'),"web.root/$modulePath/typings");
        $zip->close();
        $this->cleanup();
        print "done\n";
    }

    public static function BuildPackages($projects=null)
    {
        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['packages']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->buildPackage($project);
        }
        print "\nProjects completed\n";
    }
    private function buildPackage($project) {
        print "Building distribution files for package $project...";
        $buildDir = __DIR__;
        $sourceRoot = $this->getSourcePath($project);
        $moduleSub = $this->settings['modules'][$project];
        $pkgDir = "web.root/$moduleSub/pnut/packages/$project";
        $pkgSrcPath =  $this->concatPath($sourceRoot,$pkgDir);;
        $pkgSettingsFile = "$pkgSrcPath/package.ini";
        $pkgSettings = parse_ini_file($pkgSettingsFile,true);
        if ($pkgSettings === false) {
            print "\nSource settings for $project not found.";
            return;
        }
        $version = $pkgSettings['package']['version'];
        $dateStamp = date('Y-m-d');
        $zipname = $this->settings['packages'][$project];
        $zipname = "$zipname-v$version-$dateStamp.zip";
        $templatePath = $buildDir.'/templates';
        $readme = file_get_contents("$templatePath/package-readme.txt");
        $readme = str_replace('{{pkg-name}}',$project,$readme);
        $tempFile = "$templatePath/package-readme.tmp";
        file_put_contents($tempFile,$readme);

        $zip = new ZipArchive();
        $excludes = array();
        $zipFilePath = "$buildDir/dist/".$zipname;
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }
        $zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($tempFile,'readme.txt');
        $this->zipDirectory($zip,$pkgSrcPath,$project);
        $zip->close();
        $this->cleanup();
        print "done\n";
    }

    public static function UpdateCore($projects=null)
    {
        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['projects']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->updateTops($project);
            $builder->updatePeanut($project);
        }

        print "\nProjects completed\n";
    }

    public static function UpdateTopsProjects($projects=null)
    {
        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['projects']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->updateTops($project);
        }
        print "\nProjects completed\n";
    }
    private function updateTops($project)
    {
        print "Updating Tops for $project...";
        $modulePath = $this->getModulePath($project);
        $topsSourcePath = $this->getSourcePath('tops');
        $targetPhpDir = $this->concatPath($modulePath, "src");
        if (!file_exists($targetPhpDir)) {
            mkdir($targetPhpDir);
        }
        $targetTops = $this->concatPath($targetPhpDir, "tops");
        $this->makeDir($targetTops);
        $this->copyDirectoryContents($topsSourcePath, $targetTops);
        print "done\n";
    }

    public static function UpdatePeanutProjects($projects=null)
    {
        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['projects']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->updatePeanut($project);
        }
        print "\nProjects completed\n";
    }
    private function updatePeanut($project)
    {
        if ($project == 'pnut') {
            // only deploy tops to peanut core project.
            return;
        }
        print "Updating Peanut for $project...";
        $modulePath = $this->getModulePath($project);
        $this->makeDir($modulePath, 'make');

        $pnutSourcePath = $this->getSourcePath('pnut');
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

    public static function UpdateWordpressProjects($projects=null) {

        $builder = new Builder();
        $projects = (empty($projects)) ?
            array_keys($builder->settings['wordpress']) :
            explode(',', $projects);
        foreach ($projects as $project) {
            $builder->updateWordpress($project);
        }
        print "\nWordpress updates completed\n";

    }
    private function updateWordpress($project) {
        print "Updating Peanut/Wordpress for $project...";
        $modulePath = $this->getModulePath($project);
        if (!file_exists($modulePath.'/src')) {
            print "Please update Tops for project '$project'.\n";
            return;
        }
        $sourcePath = $this->getSourcePath('pnutwp','web.root');
        $srcModulePath = $this->concatPath($sourcePath,'wp-content/plugins/peanut');

        $srcFile = $srcModulePath.'/peanut.php';
        $targetFile = $modulePath.'/peanut.php';
        copy($srcFile,$targetFile);
        $targetPath  = $modulePath.'/src/wordpress';
        $this->makeDir($targetPath);
        $this->copyDirectoryContents($srcModulePath.'/src/wordpress',$targetPath);

        print "done\n";

    }

    //*******************
    //  File/Directory handling
    //*************************
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
    private function copyDirectoryContents($sourceDir,$targetDir,$fileConfig = array(),$rootLength=0,$isEmpty=false) {
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
            $disposition =  'include';
            $path = substr($targetFile,$rootLength);
            if (!empty($fileConfig[$path])) {
                $disposition= $fileConfig[$path];
            }
            if (is_dir($sourceFile)) {
                if ($disposition != 'exclude') {
                    if (!file_exists($targetFile)) {
                        @mkdir($targetFile);
                    }
                    $this->copyDirectoryContents($sourceFile,$targetFile, $fileConfig, $rootLength, ($disposition == 'empty'));
                }
            }
            else {
                if ($disposition == 'required' || $file == 'readme.txt' || $file != '.htaccess') {
                    $disposition = 'include';
                }
                else if ( $isEmpty ) {
                    $disposition = 'exclude';
                }
                if ($disposition=='include') {
                    $this->copyFile($sourceFile,$targetFile);
                }
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

    //****************************************
    // Path management
    //**********************************
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
    private function getSourcePath($lib,$sub='') {
        $path = $this->settings['vendor'][$lib];
        $rawpath = __DIR__."/vendor/$path";
        if ($sub) {
            $rawpath .= "/$sub";
        }
        $path = realpath($rawpath);
        if ($path === false) {
            return false;
        }
        if (strpos($path,':') == 1) {
            $path = substr($path,2);
        }
        return $path;
    }

    //****************************
    // Zip file routines
    //***************************
    private function addConfigFiles(ZipArchive $zip, array $values) {
        $templatePath = __DIR__.'/templates';
        $tmpFilePath = __DIR__.'/tmp';
        $settings = file_get_contents("$templatePath/settings.ini");
        foreach ($values as $key=>$value) {
            $settings = str_replace('{{'.$key.'}}',$value,$settings);
        }
        file_put_contents("$tmpFilePath/settings.tmp",$settings);
        $configPath = 'web.root/application/config';
        $zip->addFile("$tmpFilePath/settings.tmp","$configPath/settings.ini");
        $zip->addFile("$templatePath/database.ini","$configPath/database.ini");
        $zip->addFile("$templatePath/viewmodels.ini","$configPath/viewmodels.ini");

    }
    private function zipDirectory(ZipArchive $zip, $srcPath, $directory,array $excludes=array()) {
        $zip->addEmptyDir($directory);
        $topfiles = scandir($srcPath); //"$srcPath/$directory");
        foreach ($topfiles as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $targetFile = "$directory/$file";
            if (in_array($targetFile,$excludes)) {
                continue;
            }
            $sourceFile = "$srcPath/$file";
            if (is_dir($sourceFile)) {
                $this->zipDirectory($zip,$sourceFile,$targetFile,$excludes);
            }
            else {
                $zip->addFile($sourceFile,$targetFile);
            }
        }


    }
    private function addFromSource($file, $sourceRoot,ZipArchive $zip, $excludes=array(), $targetFile=null) {
        $file = str_replace('\\', '/', $file);
        if ($targetFile == null) {
            $targetFile = $file;
        }
        else {
            $targetFile = str_replace('\\', '/', $targetFile);

        }
        $srcFile = realpath("$sourceRoot/$file");
        if ($srcFile === FALSE) {
            throw new \Exception("\nWARNING: Source file '$sourceRoot/$file' not found.");
        }

        if (is_dir($srcFile)) {
            $this->zipDirectory($zip, $srcFile, $targetFile, $excludes);
        }
        else {
            $zip->addFile($srcFile, $targetFile);
        }
    }
    private function addAppTests(ZipArchive $zip,$peanutAppSrc,$moduleSub) {
        $fixFiles = array_keys($this->settings['tsfix']);
        foreach ($fixFiles as $file) {
            $tempFile = __DIR__."/tmp/".str_replace('/','-',$file).'.tmp';
            $targetFile = "web.root/application/$file";
            $srcFile = "$peanutAppSrc/$file";
            $this->fixReferencePaths("$peanutAppSrc/$file",$tempFile,$moduleSub);
            $zip->addFile($tempFile,$targetFile);
        }
    }
    private function addJsLibraries(ZipArchive $zip, array $config) {
        $srcPath = realpath(__DIR__.'/files/js');
        $names = array_keys($config);
        $files = scandir($srcPath);
        foreach ($files as $fileName) {
            $key = $this->partialNameInArray($fileName,$names);
            if ($key !== false) {
                $path = $config[$key];
                $zip->addFile("$srcPath/$fileName","web.root/$path/$fileName");
            }
        }
    }

    //****************************
    // Sub--routines
    //***************************
    private function fixReferencePaths($filePath, $outFile, $moduleSub) {
        $lines = file($filePath);
        $result = array();
        if (empty($lines)) {
            print "No file: $filePath";
            return;
        }
        $lineCount = sizeof($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            if (strpos($line, '<reference path')) {
                $lines[$i] = str_replace('/modules/', $moduleSub, $line);
                //$fixed = str_replace('/modules/', $moduleSub, $line);
                // $line = $fixed;
            }
            // $result[] = $line;
        }
        file_put_contents($outFile, $lines);
    }
    private function cleanup($dir = 'tmp') {
        $path = __DIR__."/$dir";
        $files = scandir($path);
        foreach ($files as $file) {
            if (strlen($file) > 3 && substr($file,-4) == '.tmp') {
                unlink("$path/$file");
            }
        }
    }
    private function getExcludedFiles(array $config, $prefix='') {
        $result = array();
        if (!empty($prefix)) {
            $prefix .= '/';
        }
        foreach ($config as $key=>$value) {
            if (empty($falue)) {
                $result[] = $prefix.$key;
            }
        }
        return $result;
    }
    private function partialNameInArray($name, $list) {
        if ($name != '.' && $name != '..') {
            foreach ($list as $partial) {
                if (strpos($name, $partial) === 0) {
                    return $partial;
                }
            }
        }
        return false;
    }
}