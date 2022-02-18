<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Bundle\EzPublishLegacyBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class LegacySrcSymlinkCommand extends Command
{
    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private $fileSystem;

    /**
     * @var string
     */
    private $legacyRootDir;

    public function __construct(Filesystem $fileSystem, string $legacyRootDir)
    {
        parent::__construct();

        $this->fileSystem = $fileSystem;
        $this->legacyRootDir = $legacyRootDir;
    }

    protected function configure()
    {
        $this
            ->setDefinition(
                [
                    new InputArgument('src', InputArgument::OPTIONAL, 'The src directory for legacy files', 'src/legacy_files'),
                ]
            )
            ->addOption('create', 'c', InputOption::VALUE_NONE, 'DEPRECATED: Use ezpublish:legacy:init instead!')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force symlinking folders even if target already exists')
            ->setDescription('Installs legacy project settings and design files from "src" to corresponding folders in ezpublish_legacy/')
            ->setHelp(
                <<<EOT
The command <info>%command.name%</info> setups and symlinks <info>src/legacy_files</info> stored in your root project for
any design/settings project files, and symlinks these into <info>ezpublish_legacy/</info> which is installed by composer.

The benefit of this is:
1. Being able to version your design/config files in git without versioning legacy itself
2. A predefined convention for where to place these files when migrating from older versions
3. Placing these files directly in ezpublish_legacy folder will lead to them getting removed in some cases when composer
   needs to completely replace ezpublish-legacy package for different reasons.

<comment>NOTE: Look towards 'ezpublish:legacybundles:install_extensions' command for how you handle legacy extensions.</comment>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $srcArg = rtrim($input->getArgument('src'), '/');
        $create = (bool)$input->getOption('create');
        $force = (bool)$input->getOption('force');

        if (!$this->fileSystem->exists($srcArg)) {
            if (!$create) {
                $output->writeln(<<<EOT
Aborting: The src directory "$srcArg" does not exist.

You can create the directory by running <info>ezpublish:legacy:symlink -c</info>, OR by creating the folders you need
manually among the ones supported by this command:
- $srcArg/design
- $srcArg/settings/override
- $srcArg/settings/siteaccess

TIP: It is recommended that you likewise setup symlink for var/[site/]storage to a folder outside ezpublish_legacy/.

EOT
, OutputInterface::VERBOSITY_QUIET
);

                return 1;
            }

            $this->fileSystem->mkdir([
                $srcArg,
                "$srcArg/design",
                "$srcArg/settings",
                "$srcArg/settings/override",
                "$srcArg/settings/siteaccess",
            ]);

            $output->writeln("<comment>Empty legacy src folder was created in '$srcArg'.</comment>");
        }

        $symlinkFolderStr = implode(' ,', $this->linkSrcFolders($srcArg, $force, $output));

        if ($symlinkFolderStr) {
            $output->writeln("The following folders were symlinked: '$symlinkFolderStr'.");
        } else {
            $output->writeln('No folders were symlinked, use <info>--force</info> option if they need to be re-created.');
        }

        $output->writeln(<<<EOT

NOTE: If you create or move additional design or siteaccess folders to '$srcArg' from previous install, then
re-run <info>ezpublish:legacy:symlink</info> to setup symlinks to eZ Publish legacy folder for them also.

EOT
);

        return 0;
    }

    /**
     * Setup symlinks for legacy settings/design files within eZ Publish legacy folder.
     *
     * @param Filesystem $this->fileSystem
     * @param string $srcArg
     * @param bool $force
     * @param OutputInterface $output
     *
     * @return array
     */
    protected function linkSrcFolders($srcArg, $force, OutputInterface $output)
    {
        $symlinks = [];
        $legacyRootDir = rtrim($this->legacyRootDir, '/');
        $relative = true;

        // first handle override folder if it exists
        if (
            $this->fileSystem->exists("$srcArg/settings/override") &&
            ($force || !$this->fileSystem->exists("$legacyRootDir/settings/override"))
        ) {
            if ($relative) {
                try {
                    $this->fileSystem->symlink(
                        $this->fileSystem->makePathRelative(
                            realpath("$srcArg/settings/override"),
                            realpath("$legacyRootDir/settings")
                        ),
                        "$legacyRootDir/settings/override"
                    );
                } catch (IOException $e) {
                    $relative = false;
                    $output->writeln('It looks like your system doesn\'t support relative symbolic links, so will fallback to absolute symbolic links instead!');
                }
            }

            if (!$relative) {
                $this->fileSystem->symlink(
                    realpath("$srcArg/settings/override"),
                    "$legacyRootDir/settings/override"
                );
            }

            $symlinks[] = "$legacyRootDir/settings/override";
        }

        // secondly handle sub folders in design and settings/siteaccess
        $directories = ['design', 'settings/siteaccess'];
        foreach ($directories as $directory) {
            foreach (Finder::create()->directories()->depth(0)->in(["$srcArg/$directory"]) as $folder) {
                $folderName = $folder->getFilename();
                if (!$force && $this->fileSystem->exists("$legacyRootDir/$directory/$folderName")) {
                    continue;
                }

                if ($relative) {
                    try {
                        $this->fileSystem->symlink(
                            $this->fileSystem->makePathRelative(
                                realpath("$srcArg/$directory/$folderName"),
                                realpath("$legacyRootDir/$directory")
                            ),
                            "$legacyRootDir/$directory/$folderName"
                        );
                    } catch (IOException $e) {
                        $relative = false;
                        $output->writeln('It looks like your system doesn\'t support relative symbolic links, so will fallback to absolute symbolic links instead!');
                    }
                }

                if (!$relative) {
                    $this->fileSystem->symlink(
                        realpath("$srcArg/$directory/$folderName"),
                        "$legacyRootDir/$directory/$folderName"
                    );
                }

                $symlinks[] = "$legacyRootDir/$directory/$folderName";
            }
        }

        return $symlinks;
    }
}
