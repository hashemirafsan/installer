<?php

namespace Glue\Installer\Console;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class NewCommand extends Command
{
    protected $path = null;

    protected $config = array();

    protected $oldNamespace = 'GlueNamespace';

    protected $questions = array(
        'plugin_name' => 'Plugin Name',
        'plugin_version' => 'Plugin Version',
        'plugin_description' => 'Plugin description',
        'plugin_uri' => 'Plugin URI',
        'plugin_license' => 'Plugin License',
        'plugin_text_domain' => 'Plugin Text Domain',
        'author_name' => 'Author Name',
        'author_uri' => 'Author URI',
        'namespace' => 'Plugin Namespace',
    );

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
        ->setName('new')
        ->addArgument('directory', InputArgument::OPTIONAL, 'Directory name to install the plugin framework.')
        ->setDescription('
            Installs a new Glue boilerplate into the given directoey - The WordPress plugin development framework.'
        );
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
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed.');
        }

        $helper = $this->getHelper('question');
        $this->config['directory'] = $this->getPluginDirectory($input);
        $this->config['plugin_slug'] = $this->config['directory'];
        $this->config['plugin_text_domain'] = $this->config['directory'];
        $this->config['plugin_name'] = $this->getPluginName($input, $output, $helper);
        $this->config['plugin_version'] = $this->getPluginVersion($input, $output, $helper);
        $this->config['plugin_description'] = $this->getPluginDescription($input, $output, $helper);
        $this->config['plugin_uri'] = $this->getPluginUri($input, $output, $helper);
        $this->config['plugin_license'] = $this->getPluginLicense($input, $output, $helper);
        
        $this->config['author_name'] = $this->getPluginAuthorName($input, $output, $helper);
        $this->config['author_uri'] = $this->getPluginAuthorUri($input, $output, $helper);

        $this->config['namespace'] = $this->getPluginNamespace($input, $output, $helper);

        $output->writeln('<info></info>');
        foreach ($this->questions as $key => $value) {
            $output->writeln('<info>'.$value. ' : ' .$this->config[$key].'</info>');
        }

        $confirm = $helper->ask($input, $output, new ConfirmationQuestion(
            'Continue with this configuration? (y/n): ', false
        ));

        if ($confirm) {
            $this->verifyNotInstalled();
            $this->installApplication($input, $output);
        }
    }

    protected function getPluginDirectory($input)
    {
        $directory = $input->getArgument('directory');
        $directory = $directory ? getcwd().DIRECTORY_SEPARATOR.$directory : getcwd();
        $this->path = $directory;
        return trim(substr($directory, strrpos($directory, DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR);
    }

    protected function getPluginName($input, $output, $helper)
    {
        $parts = array_map(function ($item) {
            return ucfirst($item);
        }, preg_split('/\s|-|_/', $this->config['directory']));
        $directory = implode(' ', $parts);
        $question = new Question('Plugin Name ('.$directory.'): ', $directory);
        return $helper->ask($input, $output, $question);
    }

    protected function getPluginDescription($input, $output, $helper)
    {
        $desc = $this->config['plugin_name'].' WordPress Plugin';
        return $helper->ask($input, $output, new Question(
            'Plugin Short Description ('.$desc.'): ', $desc
        ));
    }

    protected function getPluginVersion($input, $output, $helper)
    {
        return $helper->ask($input, $output, new Question(
            'Plugin Version (1.0.0): ', '1.0.0'
        ));
    }

    protected function getPluginUri($input, $output, $helper)
    {
        return $helper->ask($input, $output, new Question(
            'Plugin URI: ', false
        ));
    }

    protected function getPluginAuthorName($input, $output, $helper)
    {
        $question = new Question('Author Name: ', false);
        return $helper->ask($input, $output, $question);
    }

    protected function getPluginAuthorUri($input, $output, $helper)
    {
        return $helper->ask($input, $output, new Question(
            'Author URI: ', false
        ));
    }

    protected function getPluginLicense($input, $output, $helper)
    {
        return $helper->ask($input, $output, new Question(
            'Plugin License (GPLv2 or later): ', 'GPLv2 or later'
        ));
    }

    protected function getPluginNamespace($input, $output, $helper)
    {
        $directory = $directory = $this->config['directory'];
        $parts = array_map(function ($item) {
            return ucfirst($item);
        }, preg_split('/\s|-|_/', $directory));
        $nameSpace = implode('', $parts);

        $question = new Question('Plugin Namespace ('.$nameSpace.'): ', $nameSpace);
        return $helper->ask($input, $output, $question);
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyNotInstalled()
    {
        if (file_exists($file = $this->path.DIRECTORY_SEPARATOR.'glue.json')) {
            throw new RuntimeException('App already exists!');
        }

        if(!file_exists($this->path)) {
            mkdir($this->path, 0777);
        }

        $glueJson = json_decode(file_get_contents(getcwd().'/src/glue.json'), true);
        foreach ($this->config as $key => $value) {
            if (!in_array($key, ['namespace', 'directory'])) {
                $glueJson[$key] = (string) $value;
            }
        }

        $glueJson['autoload']['namespace'] = $this->config['namespace'];
        file_put_contents($file, json_encode($glueJson, JSON_PRETTY_PRINT));
    }

    protected function installApplication($input, $output)
    {
        $output->writeln('<info>Creating plugin skeleton...</info>');

        $this
        ->download($zipFile = $this->makeFilename(), $this->getVersion($input))
        ->extractAndMoveFIles($zipFile, $input)
        ->prepareStorage($output)
        ->cleanUp($zipFile);

        $output->writeln('<comment>You are ready to build your amazing plugin!</comment>');
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/glue_source'.md5(time().uniqid()).'.zip';
    }

    /**
     * Get the version that should be downloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @return string
     */
    protected function getVersion(InputInterface $input)
    {
        return 'master';
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
     * Extract the Zip file into the given path.
     *
     * @param  string  $zipFile
     * @param  string  $path
     * @return $this
     */
    protected function extractAndMoveFIles($zipFile, $input)
    {
        $archive = new ZipArchive;
        $archive->open($zipFile);
        $archive->extractTo($this->path);
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
        $to = $this->path;
        $from = $this->path.'/framework-'.$this->getVersion($input);

        $fileSystem = new FileSystem();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from,
            RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $fileSystem->mkdir($to . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            } else {
                $newFileName = $to . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                $fileSystem->copy($item, $newFileName);
                $content = file_get_contents($newFileName);
                $content = str_replace($this->oldNamespace, $this->config['namespace'], $content);
                file_put_contents($newFileName, $content);
            }
        }
        
        $fileSystem->remove($from);

        if ($fileSystem->exists($mainFile = $to.'/main.php')) {
            $content = file_get_contents($mainFile);
            $docBlocks = array(
                '[Plugin Name]', '[Description]', '[Version]', '[Author]',
                '[Author URI]', '[Plugin URI]', '[License]', '[Text Domain]',
            );
            $content = str_replace($docBlocks, array(
                $this->config['plugin_name'],
                $this->config['plugin_description'],
                $this->config['plugin_version'],
                $this->config['author_name'],
                $this->config['author_uri'],
                $this->config['plugin_uri'],
                $this->config['plugin_license'],
                $this->config['plugin_text_domain'],
            ), $content);
            file_put_contents($mainFile, $content);

            $fileSystem->rename($mainFile, $to.'/'.$this->config['plugin_slug'].'.php');
        }
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
            $filesystem->chmod($this->path.DIRECTORY_SEPARATOR."storage", 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "storage" directory is writable.</comment>');
        }

        return $this;
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
}
