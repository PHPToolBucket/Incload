<?php

namespace Netmosfera\Incload;

//[][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][]

use Closure;
use DateTime;
use Error;
use Exception;
use const JSON_ERROR_NONE;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function getcwd;
use const JSON_OBJECT_AS_ARRAY;

//[][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][]

class IncloadUpdate extends Command
{
    function __construct(){
        parent::__construct("update");
        $this->setDescription("Updates the main include file");
    }

    /** @inheritDoc */
    function configure(){
        $this->addOption("composer", NULL, InputOption::VALUE_OPTIONAL,
            "Specify `composer.json` path", getcwd() . "/composer.json");
        $this->addOption("file",     NULL, InputOption::VALUE_OPTIONAL,
            "Specify the main include file name (exclusive of `.php`)", "composer-includes");
        $this->addOption("devfile",  NULL, InputOption::VALUE_OPTIONAL,
            "Specify the main include-dev file name (exclusive of `.php`)", "composer-includes-dev");
        $this->addOption("ext",      NULL, InputOption::VALUE_OPTIONAL,
            "Specify the file extensions, semicolon separated", "inc.php;fn.php;function.php;class.php;const.php;constant.php;ns.php;namespace.php");
        $this->addOption("interval", NULL, InputOption::VALUE_OPTIONAL,
            "Specify interval in seconds between check for changes", "5");
        $this->addOption("errdelay", NULL, InputOption::VALUE_OPTIONAL,
            "Specify interval in seconds between an error and the consecutive retry", "30");
    }

    /** @inheritDoc */
    function execute(InputInterface $input, OutputInterface $output){
        $interval = (Int)$input->getOption("interval");
        $composerFile = $input->getOption("composer");

        $output->writeln(
            "CWD is: " . getcwd()
        );
        
        $output->writeln(
            "Looking for changes every $interval seconds in the directories " .
            "specified by the given `$composerFile` file."
        );

        $output->writeln(
            "The process is running and every modification to the involved files will be displayed here."
        );

        $output->writeln(
            "Remember that you can terminate this process by hitting Ctrl + C."
        );

        while(TRUE){
            sleep($this->executeSingle($input, $output));
        }
    }

    function executeSingle(InputInterface $input, OutputInterface $output): Int{
        $interval = (Int)$input->getOption("interval");
        $errorDelay = (Int)$input->getOption("errdelay");
        $includeFileName = $input->getOption("file");
        $includeDevFileName = $input->getOption("devfile");
        $composerFile = $input->getOption("composer");
        $composerDirectory = dirname($composerFile);
        $includeFile = $composerDirectory . "/" . $includeFileName . ".php";
        $includeDevFile = $composerDirectory . "/" . $includeDevFileName . ".php";
        $rawExtensions = explode(";", $input->getOption("ext"));
        $extensions = [];
        foreach($rawExtensions as $extension){
            $extension = trim($extension);
            if($extension !== ""){
                $extensions[] = "." . $extension;
            }
        }


        $composerSource = @file_get_contents($composerFile);

        if($composerSource === FALSE){
            $output->writeln($this->time() . "Cannot read `$composerFile`. Will retry in $errorDelay seconds.");
            return $errorDelay;
        }

        $composerData = json_decode($composerSource, JSON_OBJECT_AS_ARRAY);

        if(json_last_error() !== JSON_ERROR_NONE){
            $output->writeln($this->time() . "Cannot read `composer.json` as it contains invalid JSON. Will retry in $errorDelay seconds.");
            return $errorDelay;
        }

        $autoloadDirectories = [
            $includeFile => $this->getComposerPSR4Dirs($composerData["autoload"]["psr-4"] ?? []),
            $includeDevFile => $this->getComposerPSR4Dirs($composerData["autoload-dev"]["psr-4"] ?? []),
        ];

        $includeFileFunction = function($path) use($extensions){
            foreach($extensions as $extension){
                if(substr($path, strlen($extension) * -1) === $extension){
                    return TRUE;
                }
            }
            return FALSE;
        };

        foreach($autoloadDirectories as $mainInclude => $directories){
            $includes = [];
            try{
                foreach($directories as $directory){
                    $directoryInclusions = $this->getDirectoryInclusions($composerDirectory, $directory, $includeFileFunction);
                    $includes = array_merge($includes, $directoryInclusions);
                }
            }catch(Exception $e){
                $output->writeln($this->time() . "Cannot read the source directories. Will retry in $errorDelay seconds.");
                return $errorDelay;
            }

            $fileSource = "<?php\n\n";
            foreach($includes as $offset => $include){
                // Normalize paths to always use "/", so that they won't show up
                // in commits coming from different systems.
                $include = "/" . preg_replace("@[\\\\/]+@", "/", $include);
                $fileSource .= "require(__DIR__ . \"" . $include . "\");\n";
            }

            $oldFileSource = @file_get_contents($mainInclude);
            $oldFileSource = $oldFileSource === FALSE ? "" : $oldFileSource;
            if($oldFileSource !== $fileSource){
                $output->writeln($this->time() . "Includes list was updated.");
                file_put_contents($mainInclude, $fileSource);
            }
        }

        return $interval;
    }

    private function getComposerPSR4Dirs(array $data){
        $autoloadPaths = [];
        foreach($data as $ns => $paths){
            $paths = is_array($paths) ? $paths : [$paths];
            foreach($paths as $path){
                $autoloadPaths[] = $path;
            }
        }
        return $autoloadPaths;
    }

    private function getDirectoryInclusions(String $cwd, String $directory, Closure $includeFileFunction){
        $inclusions = $this->filterDirectoryFiles($cwd, $directory, $includeFileFunction);

        $directoryDirectories = $this->filterDirectoryFiles($cwd, $directory, function($filePath){
            return @is_dir($filePath);
        });

        foreach($directoryDirectories as $directoryDirectory){
            $childDirectoryInclusions = $this->getDirectoryInclusions($cwd, $directoryDirectory, $includeFileFunction);
            $inclusions = array_merge($inclusions, $childDirectoryInclusions);
        }

        return $inclusions;
    }

    private function filterDirectoryFiles(String $cwd, String $directory, Closure $ifMatches): array{
        $backupCWD = getcwd();
        chdir($cwd);
        $files = [];
        $directoryHandle = @opendir($directory);
        if($directoryHandle === FALSE){
            throw new Exception();
        }
        while(is_string($file = @readdir($directoryHandle))){
            $filePath = $cwd . "/" . $directory . "/" . $file;
            if(in_array($file, [".", ".."]) === FALSE && $ifMatches($filePath)){
                $files[] = $directory . "/" . $file;
            }
        }
        closedir($directoryHandle);
        chdir($backupCWD);
        return $files;
    }

    private function time(): String{
        return (new DateTime())->format("Y-m-d H:i:s") . " > ";
    }
}
