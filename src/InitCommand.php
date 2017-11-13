<?php

namespace Glue\Installer\Console;

use ZipArchive;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
        ->setName('init')
        ->setDescription('Init a new Glue plugin config file.')
        ->addOption('force', null, InputOption::VALUE_NONE, 'Forces init even if the directory exists');
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

        $directory = getcwd();

        if (!$input->getOption('force')) {
            $this->verifyNotInitiated($directory);
        }

        $this->CreateNewPluginFile($directory);

        $output->writeln('<info>Initialized with a new plugin.php file.</info>');
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyNotInitiated($directory)
    {
        if (file_exists('plugin.php')) {
            throw new RuntimeException('A config file already exists!');
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function CreateNewPluginFile($directory)
    {
        file_put_contents($directory.'/plugin.php', $this->getContent());
    }

    protected function getContent()
    {
        return <<<EOT
<?php

return array(
    'namespace' => null,
    'plugin_name' => null,
    'plugin_slug' => null,
    'plugin_uri' => null,
    'plugin_version' => null,
    'author_name' => null,
    'author_email' => null,
    'author_uri' => null,
);
EOT;
    }
}
