<?php

namespace Netmosfera\Incload;

//[][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][][]

use Closure;
use DateTime;
use const JSON_ERROR_NONE;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
        $this->setDefinition([
            new InputArgument(
                "composer", InputArgument::OPTIONAL,
                "Specify composer.json path",
                getcwd() . "/composer.json"
            ),
            new InputArgument(
                "file", InputArgument::OPTIONAL,
                "Specify the main include file name (exclusive of .php)",
                "composer-includes"
            ),
            new InputArgument(
                "devfile", InputArgument::OPTIONAL,
                "Specify the main include-dev file name (exclusive of .php)",
                "composer-includes-dev"
            ),
            new InputArgument(
                "ext", InputArgument::OPTIONAL,
                "Specify the file extensions, semicolon separated; e.g. inc.php;fn.php;class.php",
                "inc.php;fn.php;function.php;class.php;const.php;constant.php;ns.php;namespace.php"
            ),
            new InputArgument(
                "interval", InputArgument::OPTIONAL,
                "Specify interval in seconds between check for changes",
                "5"
            ),
            new InputArgument(
                "errdelay", InputArgument::OPTIONAL,
                "Specify interval in seconds between an error and the consecutive retry",
                "30"
            )

        ]);
    }

    /** @inheritDoc */
    function execute(InputInterface $input, OutputInterface $output){
        while(TRUE){
            sleep($this->executeSingle($input, $output));
        }
    }

    function executeSingle(InputInterface $input, OutputInterface $output): Int{
        $composerFile = $input->getArgument("composer");

        $composerSource = file_get_contents($composerFile);

        if($composerSource === FALSE){
            $output->writeln("Unable to read `$composerFile`; please check that the provided path is correct.");
        }

        $composerData = json_decode($composerSource, JSON_OBJECT_AS_ARRAY);

        if(json_last_error() !== JSON_ERROR_NONE){
            $output->writeln($this->time() . " > ");
            return (Int)$input->getArgument("errdelay");
        }

        $composerDirectory = dirname($composerFile);

        $includeFile = $composerDirectory . "/" . $input->getArgument("file") . ".php";

        $includeDevFile = $composerDirectory . "/" . $input->getArgument("devfile") . ".php";

        $autoloadDirectories = [
            $includeFile => $this->getComposerPSR4Dirs($composerData["autoload"]["psr-4"] ?? []),
            $includeDevFile => $this->getComposerPSR4Dirs($composerData["autoload-dev"]["psr-4"] ?? []),
        ];

        $rawExtensions = explode(";", $input->getArgument("ext"));
        $extensions = [];
        foreach($rawExtensions as $extension){
            $extension = trim($extension);
            if($extension !== ""){
                $extensions[] = "." . $extension;
            }
        }

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
            foreach($directories as $directory){
                $includes = array_merge($includes, $this->getDirectoryInclusions($composerDirectory, $directory, $includeFileFunction));
            }

            $fileSource = "<?php\n\n";
            foreach($includes as $offset => $include){
                // Normalize paths to always use "/", so that they won't show up
                // in commits coming from different systems.
                $include = "/" . preg_replace("@[\\\\/]+@", "/", $include);
                $fileSource .= "require(__DIR__ . \"" . $include . "\");\n";
            }

            file_put_contents($mainInclude, $fileSource);
        }

        return (Int)$input->getArgument("interval");
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
            return is_dir($filePath);
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
        $directoryHandle = opendir($directory);
        while(is_string($file = readdir($directoryHandle))){
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
        return (new DateTime())->format("Y-m-d H:i:s");
    }
}
