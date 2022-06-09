<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Bundle\EzPublishLegacyBundle\Command;

use eZ\Bundle\EzPublishLegacyBundle\LegacyBundles\LegacyExtensionsLocator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;

class LegacyBundleInstallCommand extends Command
{
    /**
     * @var \eZ\Bundle\EzPublishLegacyBundle\LegacyBundles\LegacyExtensionsLocator
     */
    private $locator;

    /**
     * @var \Symfony\Component\HttpKernel\KernelInterface
     */
    private $kernel;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private $fileSystem;

    /**
     * @var string
     */
    private $legacyRootDir;

    public function __construct(LegacyExtensionsLocator $locator, KernelInterface $kernel, Filesystem $fileSystem, string $legacyRootDir)
    {
        parent::__construct();

        $this->locator = $locator;
        $this->kernel = $kernel;
        $this->fileSystem = $fileSystem;
        $this->legacyRootDir = $legacyRootDir;
    }

    protected function configure()
    {
        $this
            ->addOption('copy', null, InputOption::VALUE_NONE, 'Creates copies of the extensions instead of using a symlink')
            ->addOption('relative', null, InputOption::VALUE_NONE, 'Make relative symlinks')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force overwriting of existing directory (will be removed)')
            ->setDescription('Installs legacy extensions (default: symlink) defined in Symfony bundles into ezpublish_legacy/extensions')
            ->setHelp(
                <<<EOT
The command <info>%command.name%</info> installs <info>legacy extensions</info> stored in a Symfony bundle
into the ezpublish_legacy/extension folder.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'copy' => (bool)$input->getOption('copy'),
            'relative' => (bool)$input->getOption('relative'),
            'force' => (bool)$input->getOption('force'),
        ];

        foreach ($this->kernel->getBundles() as $bundle) {
            foreach ($this->locator->getExtensionDirectories($bundle->getPath()) as $extensionDir) {
                $output->writeln('- ' . $this->removeCwd($extensionDir));
                try {
                    $target = $this->linkLegacyExtension($extensionDir, $options, $output);
                    $output->writeln('  <info>' . ($options['copy'] ? 'Copied' : 'linked') . "</info> to $target</info>");
                } catch (RuntimeException $e) {
                    $output->writeln('  <error>' . $e->getMessage() . '</error>');
                }
            }
        }

        foreach ($this->locator->getExtensionDirectories($this->kernel->getProjectDir() . '/src') as $extensionDir) {
            $output->writeln('- ' . $this->removeCwd($extensionDir));
            try {
                $target = $this->linkLegacyExtension($extensionDir, $options, $output);
                $output->writeln('  <info>' . ($options['copy'] ? 'Copied' : 'linked') . "</info> to $target</info>");
            } catch (RuntimeException $e) {
                $output->writeln('  <error>' . $e->getMessage() . '</error>');
            }
        }

        return 0;
    }

    /**
     * Links the legacy extension at $path into ezpublish_legacy/extensions.
     *
     * @param string $extensionPath Absolute path to a legacy extension folder
     * @param array  $options
     * @param OutputInterface $output
     *
     * @throws \RuntimeException If a target link/directory exists and $options[force] isn't set to true
     *
     * @return string The resulting link/directory
     */
    protected function linkLegacyExtension($extensionPath, array $options, OutputInterface $output)
    {
        $options += ['force' => false, 'copy' => false, 'relative' => false];
        $legacyRootDir = rtrim($this->legacyRootDir, '/');

        $relativeExtensionPath = $this->fileSystem->makePathRelative($extensionPath, realpath("$legacyRootDir/extension/"));
        $targetPath = "$legacyRootDir/extension/" . basename($extensionPath);

        if (file_exists($targetPath) && $options['copy']) {
            if (!$options['force']) {
                throw new RuntimeException("Target directory $targetPath already exists");
            }
            $this->fileSystem->remove($targetPath);
        }

        if (file_exists($targetPath) && !$options['copy']) {
            if (is_link($targetPath)) {
                $existingLinkTarget = readlink($targetPath);
                if ($existingLinkTarget == $extensionPath || $existingLinkTarget == $relativeExtensionPath) {
                    return $targetPath;
                } elseif (!$options['force']) {
                    throw new RuntimeException("Target $targetPath already exists with a different target");
                }
            } else {
                if (!$options['force']) {
                    throw new RuntimeException("Target $targetPath already exists with a different target");
                }
            }
            $this->fileSystem->remove($targetPath);
        }

        if (!$options['copy']) {
            if ($options['relative']) {
                try {
                    $this->fileSystem->symlink(
                        $relativeExtensionPath,
                        $targetPath
                    );
                } catch (IOException $e) {
                    $options['relative'] = false;
                    $output->writeln('It looks like your system doesn\'t support relative symbolic links, so will fallback to absolute symbolic links instead!');
                }
            }

            if (!$options['relative']) {
                try {
                    $this->fileSystem->symlink(
                        $extensionPath,
                        $targetPath
                    );
                } catch (IOException $e) {
                    $options['copy'] = true;
                    $output->writeln('It looks like your system doesn\'t support symbolic links, so will fallback to hard copy instead!');
                }
            }
        }

        if ($options['copy']) {
            $this->fileSystem->mkdir($targetPath, 0777);
            $this->fileSystem->mirror($extensionPath, $targetPath, Finder::create()->in($extensionPath));
        }

        return $targetPath;
    }

    /**
     * Removes the cwd from $path.
     *
     * @param string $path
     */
    private function removeCwd($path)
    {
        return str_replace(getcwd() . '/', '', $path);
    }
}
