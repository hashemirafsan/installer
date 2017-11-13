<?php

namespace Glue\Installer\Console;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class InstallCommand extends Command
{
    protected $settings = null;
    protected $directory = null;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
        ->setName('install')
        ->setDescription('Install the Glue framework.')
        ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
        ->addOption('force', null, InputOption::VALUE_NONE, 'Forces install even if the directory exists');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validatePluginFile($output);

        $output->writeln('<info>Creating plugin skeleton...</info>');

        $this
        ->download($zipFile = $this->makeFilename(), $this->getVersion($input))
        ->extractAndMoveFIles($zipFile, $input)
        ->prepareStorage($output)
        ->cleanUp($zipFile);

        $this->setNamespace($output);

        $output->writeln('<comment>You are ready to build your amazing plugin!</comment>');
    }

    /**
     * Execute the namespace command
     * @param string $namespace
     * @param string $directory
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function setNamespace(OutputInterface $output)
    {
        $command = $this->getApplication()->find('namespace');

        $arguments = array('command' => 'namespace');

        return $command->run(new ArrayInput($arguments), $output);
    }

    public function validatePluginFile($output)
    {
        if (file_exists($file = getcwd().'/plugin.php')) {
            $this->directory = getcwd();
            $this->settings = include $file;

            if (file_exists($this->settings['plugin_slug'].'.php')) {
                throw new RuntimeException('Application is already installed!');
            } elseif (!isset($this->settings['namespace'])) {
                throw new RuntimeException('Invalid plugin file, namespace is required!');
            } elseif (!isset($this->settings['plugin_name'])) {
                throw new RuntimeException('Invalid plugin file, plugin_name is required!');
            } elseif (!isset($this->settings['plugin_slug'])) {
                throw new RuntimeException('Invalid plugin file, plugin_slug is required!');
            }
        } else {
            throw new RuntimeException('The plugin file not found in "'.getcwd().'"!');
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/glue_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        return $input->getOption('dev') ? 'develop' : 'master';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @param  string  $version
     * @return $this
     */
    protected function download($zipFile, $version = 'master')
    {
        $baseUrl = 'https://github.com/wpglue/framework/archive/';
        $filename = $version == 'develop' ? 'master-develop.zip' : 'master.zip';
        file_put_contents($zipFile, (new Client)->get($baseUrl.$filename)->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @return $this
     */
    protected function extractAndMoveFIles($zipFile, $input)
    {
        $archive = new ZipArchive;
        $archive->open($zipFile);
        $archive->extractTo($this->directory);
        $archive->close() && $this->moveFiles($input);

        return $this;
    }

    /**
     * Move the directories from sub-directory to root
     * @param  string $from 
     * @param  string $to
     * @return void
     */
    protected function moveFiles($input)
    {
        $to = $this->directory;
        $from = $this->directory.'/framework-'.$this->getVersion($input);

        $fileSystem = new FileSystem();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from,
            RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $fileSystem->mkdir($to . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            } else {
                $fileSystem->copy($item, $to . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
        
        $fileSystem->remove($from);

        if ($fileSystem->exists($mainFile = $to.'/glue.php')) {
            $fileSystem->rename($mainFile, $to.'/'.$this->settings['plugin_slug'].'.php');

            // TODO: Update main plugin file's doc blocks from plugin.php file
            
            $this->updateAppConfigFile(getcwd().'/config/app.php');
        }
    }

    protected function updateAppConfigFile($path)
    {
        $replaceables = array(
            'plugin_name',
            'plugin_slug',
            'plugin_uri',
            'plugin_version',
            'author_name',
            'author_email',
            'author_uri'
        );

        $appConfigContent = file_get_contents($path);

        foreach ($this->settings as $key => $value) {
            if(in_array($key, $replaceables)) {
                $appConfigContent = preg_replace(
                    '/[\'|\"]'.$key.'[\'|\"] => \'\'/',
                    '\''.$key.'\' => \''.$value.'\'',
                    $appConfigContent
                );
            }
        }

        file_put_contents($path, $appConfigContent);
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);
        @unlink($zipFile);
        return $this;
    }

    /**
     * Make sure the storage directory is writable.
     *
     * @param  string  $appDirectory
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return $this
     */
    protected function prepareStorage(OutputInterface $output)
    {
        $filesystem = new Filesystem;

        try {
            $filesystem->chmod($this->directory.DIRECTORY_SEPARATOR."storage", 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "storage" directory is writable.</comment>');
        }

        return $this;
    }
}
