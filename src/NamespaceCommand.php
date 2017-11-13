<?php

namespace Glue\Installer\Console;

use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class NamespaceCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
        ->setName('namespace')
        ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Takes the new namespace from user.')
        ->setDescription('Sets plugin root namespace.');
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
    	if (file_exists(getcwd().'/plugin.php')) {
			$this->setNamespace($input, $output);
		} else {
			throw new RuntimeException('Could not find '.getcwd().'/plugin.php file!');
    	}
    }

    /**
     * Set the root namespace for plugin
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param string $namespace
     * @return void
     */
    protected function setNamespace(InputInterface $input, OutputInterface $output)
    {
    	$pluginFile = getcwd().'/plugin.php';
    	$config = include getcwd()."/config/app.php";
    	$oldNamespace = $config['autoload']['namespace'];

    	if ($namespace = $input->getOption('namespace')) {
    		if ($namespace != $oldNamespace) {
    			$pluginFileContent = $this->replaceAssocArrayItem(
	    			'namespace', '.*', 'namespace', $namespace, $pluginFile
	    		);
	    		file_put_contents($pluginFile, $pluginFileContent);
    		}
    	}


    	$settings = include $pluginFile;
    	$newNamespace = $settings['namespace'];

    	if ($newNamespace == $oldNamespace) {
    		$output->writeln('<info>The given namespace is already set.</info>');
    		die;
    	}

    	$output->writeln('<info>Setting the root namespace to "'.$settings['namespace'].'"...</info>');

    	$files = Finder::create()->in(getcwd())->name('*.php')->name('*.stub');

    	$countOfUpdatedFiles = 0;

        foreach ($files as $file) {
		    echo 'Scanning: ' . $file->getRelativePathname().PHP_EOL;

        	$content = file_get_contents($file->getRealPath());

        	$foundNs = $this->extractNamespaceFromFile($content);
        	$newNs = str_replace($oldNamespace, $newNamespace, $foundNs);
        	file_put_contents(
        		$file->getRealPath(),
        		$content = str_replace($foundNs, $newNs, $content)
        	);

        	if($foundUse = $this->extractUseStatements($content)) {
        		$readyUseStateMents = array();

        		foreach ($foundUse as $useStateMent) {
        			$readyUseStateMents[$useStateMent] = str_replace(
        				$oldNamespace, $newNamespace, $useStateMent
        			);
        		}
        		
        		foreach ($readyUseStateMents as $key => $value) {
        			$content = str_replace($key, $value, $content);
        		}

        		file_put_contents($file->getRealPath(), $content);
        	}
		}

		$this->changeNamespaceInAppConfig($oldNamespace, $newNamespace);

		$output->writeln(
			'<info>The root namespace is successfully changed to "'.$settings['namespace'].'".</info>'
		);
    }

    protected function extractNamespaceFromFile($src)
    {
		$i = 0;
		$namespace = '';
		$isNamespaceValid = false;
		$tokens = token_get_all($src);
		$count = count($tokens);

		while ($i < $count) {
			$token = $tokens[$i];
			if (is_array($token) && $token[0] === T_NAMESPACE) {
				// Found namespace declaration
				while (++$i < $count) {
					if ($tokens[$i] === ';') {
						$isNamespaceValid = true;
						$namespace = trim($namespace);
						break;
					}
					$namespace .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
				}
				break;
			}
			$i++;
		}
		
		return !$isNamespaceValid ? null : $namespace;
	}

	protected function extractUseStatements($src)
	{
		if (preg_match_all('#^use\s+(.+?);$#sm', $src, $m)) {
			return $m[1];
		}
		return null;
	}

	protected function changeNamespaceInAppConfig($oldNamespace, $newNamespace)
	{
		$ns = 'namespace';
    	$appConfigContent = $this->replaceAssocArrayItem(
    		$ns, $oldNamespace, $ns, $newNamespace, getcwd()."/config/app.php"
    	);
    	file_put_contents(getcwd()."/config/app.php", $appConfigContent);
	}

	protected function replaceAssocArrayItem($searchKey, $searchValue, $replaceKey, $replaceValue, $filePath)
	{
		$content = file_get_contents($filePath);
		return preg_replace(
    		'/[\'|\"]'.$searchKey.'[\'|\"][\s]*=>[\s]*[\'|\"]'.$searchValue.'[\'|\"]/',
    		'\''.$replaceKey.'\' => \''.$replaceValue.'\'',
    		$content
    	);
	}
}
