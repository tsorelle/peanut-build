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
        $topsDbSrc = $this->getSourcePath('topsdb');
        $pnutSrc = $this->getSourcePath('pnut');
        $pnutSrcRoot = realpath("$pnutSrc/modules/pnut");
        $srcRoot = realpath("$pnutSrc/modules/src");
        $peanutPhpSource=$this->concatPath($pnutSrc,"modules/src/peanut");

        $pnutTestRoot = realpath("$pnutSrc/modules/src/test");
        if ($pnutSrcRoot === false) {
            print "\nPeanut root path not found.\n";
            return;
        }
        $projectRoot = $this->getProjectRoot($project);
        $peanutAppSrc = $this->concatPath($projectRoot,'application');
        $appExcludes = array_merge(
            $this->parseIniList($this->settings['application-files'],false, 'web.root/application'),
            $this->parseIniList( $this->settings['tsfix'],false,'web.root/application'));
        $this->zipDirectory($zip,$peanutAppSrc,"web.root/application",$appExcludes);
        $this->addAppTests($zip,$peanutAppSrc,'/'.$this->settings['modules'][$project].'/');
        $zip->addFile("$srcRoot/.htaccess","web.root/$modulePath/src/.htaccess");
        $this->zipDirectory($zip,"$topsSrc","web.root/$modulePath/src/tops");
        $this->zipDirectory($zip,"$topsDbSrc","web.root/$modulePath/src/tops");
        $this->zipDirectory($zip,$pnutTestRoot,"web.root/$modulePath/src/test");
        $this->zipDirectory($zip,$pnutSrcRoot,"web.root/$modulePath/pnut");
        $this->zipDirectory($zip,$peanutPhpSource,"web.root/$modulePath/src/peanut");
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
        // $templatePath = $buildDir.'/templates';

        $distini = parse_ini_file("$sourceRoot/dist/distribution.ini",true);

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

        $zip = new ZipArchive();
        $zipFilePath = "$buildDir/dist/".$zipname;
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }
        $zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->zipDirectory($zip,$pkgSrcPath,"peanut-files/pnut/packages/$project");

        foreach ($distini['rename'] as $file=>$target) {
            $this->addFromSource($file, $sourceRoot, $zip, $excludes,$target);
        }

        foreach ($includes as $file) {
            $this->addFromSource("other-files/$file", $sourceRoot, $zip, $excludes);
        }

        foreach ($distini['libraries'] as $lib => $path) {
            $libPath = $this->getSourcePath($lib);
            $this->zipDirectory($zip,$libPath,"peanut-files/$path");
        }

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
        $source = $this->getSourcePath('tops');
        $modulePath   = $this->makeModulePath($project);
        $target = $this->makePath($modulePath, "src/tops");

        $this->copyDirectoryContents($source, $target);
        $source = $this->getSourcePath('topsdb');
        $this->copyDirectoryContents($source, $target);

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
        $pnutSourcePath = $this->getSourcePath('pnut');
        $modulePath = $this->makeModulePath($project);
        $projectRoot = $this->getProjectRoot($project);
        $appSource = "$pnutSourcePath/application";
        $appTarget = $this->makePath($projectRoot,'application');
        $moduleSource = $this->concatPath($pnutSourcePath,'modules/pnut');
        $moduleTarget = $this->makePath($modulePath, 'pnut');
        $peanutPhpSource=$this->concatPath($pnutSourcePath,"modules/src/peanut");
        $peanutPhpTarget=$this->makePath($modulePath,"src/peanut");
        $modulePhpRoot=$this->concatPath($pnutSourcePath,"modules/src");
        $modulePhpTarget=$this->concatPath($modulePath,"/src");

        $pnutDirs = $this->settings['pnut-dirs'];
        foreach ($pnutDirs as $dir => $mode) {
            $target = $this->concatPath($moduleTarget, $dir);
            $source = $this->concatPath($moduleSource,$dir);
            $this->makeDir($target,$mode);
            if ($mode != 'empty') {
                $this->copyDirectoryContents($source, $target);
            }
        }
        $target = $this->concatPath($moduleTarget, $dir);
        $source = $this->concatPath($moduleSource,$dir);
        $this->makeDir($target,$mode);

        $appIncludes = $this->parseIniList($this->settings['application-files'],true);
        foreach ($appIncludes as $targetFile) {
            if (substr($targetFile,-1) == '*') {
                $targetFile = substr($targetFile,0,strlen($targetFile)-2);
                $this->makePath($appTarget,$targetFile);
                $this->copyDirectoryContents("$appSource/$targetFile","$appTarget/$targetFile");
            }
            else {
                $this->copyFilePath($appSource, $appTarget, $targetFile);
            }
        }

        $this->copyDirectoryContents($peanutPhpSource,$peanutPhpTarget);
        copy("$modulePhpRoot/.htaccess","$modulePhpTarget/.htaccess");
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

    private function copyFilePath($srcPath,$dstPath,$filePath,$overwrite=true) {
        if (!$overwrite && file_exists("$dstPath/$filePath")) {
            return false;
        }
        $filePath = str_replace('\\','/',$filePath);
        $subdirs = explode('/',$filePath);
        array_pop($subdirs); // remove filename;
        $this->makePathFromArray($dstPath,$subdirs);
        copy("$srcPath/$filePath", "$dstPath/$filePath");
        return true;
    }


    private function makePath($root, $subpath) {
        $subpath = str_replace('\\','/',$subpath);
        $subdirs = explode('/',$subpath);
        $this->makePathFromArray($root,$subdirs);
        return $this->concatPath($root,$subpath);
    }

    private function makePathFromArray($root, array $subdirs) {
        foreach ($subdirs as $subdir) {
            $new = "$root/$subdir";
            @mkdir($new);
            $root = $new;
        }
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

    private function copyDirectory($sourceDir,$targetDir,$fileConfig = array(),$rootLength=0,$isEmpty=false) {
       // $this->makePath()

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

    private function makeModulePath($project) {
        $projectRoot = $this->getProjectRoot($project);
        $moduleSub = $this->settings['modules'][$project];
        return $this->makePath($projectRoot,$moduleSub);
    }

    private function getSourcePath($lib,$sub='') {
        $path = $this->settings['vendor'][$lib];
        $rawpath = __DIR__."/vendor/$path";
        if ($sub) {
            $rawpath .= "/$sub";
        }
        $path = realpath($rawpath);
        if ($path === false) {
            // return false;
            throw new \Exception("Sourc path '$path' not found.");
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
        if (empty($values['autoload'])) {
            $settings = str_replace('{{autoload}}','',$settings);
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
    private function parseIniList(array $config, $included, $prefix='') {
        $result = array();
        if (!empty($prefix)) {
            $prefix .= '/';
        }
        foreach ($config as $key=>$value) {
            if (empty($value == !$included)) {
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
    private function checkDir($path) {
        if (!file_exists($path)) {
            throw new \Exception("Path '$path' not found. Must pre-install peanut files from distribution zip.");
        }
    }
}