<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Version\VersionParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ShowCommand extends Command
{
    protected $versionParser;

    protected function configure()
    {
        $this
            ->setName('show')
            ->setDescription('Show information about packages')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to inspect'),
                new InputArgument('version', InputArgument::OPTIONAL, 'Version to inspect'),
                new InputOption('installed', 'i', InputOption::VALUE_NONE, 'List installed packages only'),
                new InputOption('platform', 'p', InputOption::VALUE_NONE, 'List platform packages only'),
                new InputOption('available', 'a', InputOption::VALUE_NONE, 'List available packages only'),
                new InputOption('self', 's', InputOption::VALUE_NONE, 'Show the root package information'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'Enables display of dev-require packages.'),
                new InputOption('name-only', 'N', InputOption::VALUE_NONE, 'List package names only'),
            ))
            ->setHelp(<<<EOT
The show command displays detailed information about a package, or
lists all packages available.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->versionParser = new VersionParser;

        // init repos
        $platformRepo = new PlatformRepository;
        $getRepositories = function (Composer $composer, $dev) {
            $manager = $composer->getRepositoryManager();
            $repos = new CompositeRepository(array($manager->getLocalRepository()));
            if ($dev) {
                $repos->addRepository($manager->getLocalDevRepository());
            }

            return $repos;
        };

        if ($input->getOption('self')) {
            $package = $this->getComposer(false)->getPackage();
            $repos = $installedRepo = new ArrayRepository(array($package));
        } elseif ($input->getOption('platform')) {
            $repos = $installedRepo = $platformRepo;
        } elseif ($input->getOption('installed')) {
            $repos = $installedRepo = $getRepositories($this->getComposer(), $input->getOption('dev'));
        } elseif ($input->getOption('available')) {
            $installedRepo = $platformRepo;
            if ($composer = $this->getComposer(false)) {
                $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
            } else {
                $defaultRepos = Factory::createDefaultRepositories($this->getIO());
                $repos = new CompositeRepository($defaultRepos);
                $output->writeln('No composer.json found in the current directory, showing available packages from ' . implode(', ', array_keys($defaultRepos)));
            }
        } elseif ($composer = $this->getComposer(false)) {
            $localRepo = $getRepositories($composer, $input->getOption('dev'));
            $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
            $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));
        } else {
            $defaultRepos = Factory::createDefaultRepositories($this->getIO());
            $output->writeln('No composer.json found in the current directory, showing available packages from ' . implode(', ', array_keys($defaultRepos)));
            $installedRepo = $platformRepo;
            $repos = new CompositeRepository(array_merge(array($installedRepo), $defaultRepos));
        }

        // show single package or single version
        if ($input->getArgument('package') || !empty($package)) {
            $versions = array();
            if (empty($package)) {
                list($package, $versions) = $this->getPackage($installedRepo, $repos, $input->getArgument('package'), $input->getArgument('version'));

                if (!$package) {
                    throw new \InvalidArgumentException('Package '.$input->getArgument('package').' not found');
                }
            } else {
                $versions = array($package->getPrettyVersion() => $package->getVersion());
            }

            $this->printMeta($input, $output, $package, $versions, $installedRepo, $repos);
            $this->printLinks($input, $output, $package, 'requires');
            $this->printLinks($input, $output, $package, 'devRequires', 'requires (dev)');
            if ($package->getSuggests()) {
                $output->writeln("\n<info>suggests</info>");
                foreach ($package->getSuggests() as $suggested => $reason) {
                    $output->writeln($suggested . ' <comment>' . $reason . '</comment>');
                }
            }
            $this->printLinks($input, $output, $package, 'provides');
            $this->printLinks($input, $output, $package, 'conflicts');
            $this->printLinks($input, $output, $package, 'replaces');

            return;
        }

        // list packages
        $packages = array();
        $repos->filterPackages(function ($package) use (&$packages, $platformRepo, $installedRepo) {
            if ($platformRepo->hasPackage($package)) {
                $type = '<info>platform</info>:';
            } elseif ($installedRepo->hasPackage($package)) {
                $type = '<info>installed</info>:';
            } else {
                $type = '<comment>available</comment>:';
            }
            if (!isset($packages[$type][$package->getName()])
                || version_compare($packages[$type][$package->getName()]->getVersion(), $package->getVersion(), '<')
            ) {
                $packages[$type][$package->getName()] = $package;
            }
        }, 'Composer\Package\CompletePackage');

        $tree = !$input->getOption('platform') && !$input->getOption('installed') && !$input->getOption('available');
        $indent = $tree ? '  ' : '';
        foreach (array('<info>platform</info>:' => true, '<comment>available</comment>:' => false, '<info>installed</info>:' => true) as $type => $showVersion) {
            if (isset($packages[$type])) {
                if ($tree) {
                    $output->writeln($type);
                }
                ksort($packages[$type]);

                $nameLength = $versionLength = 0;
                foreach ($packages[$type] as $package) {
                    $nameLength = max($nameLength, strlen($package->getPrettyName()));
                    $versionLength = max($versionLength, strlen($this->versionParser->formatVersion($package)));
                }
                list($width) = $this->getApplication()->getTerminalDimensions();
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $width--;
                }

                $writeVersion = !$input->getOption('name-only') && $showVersion && ($nameLength + $versionLength + 3 <= $width);
                $writeDescription = !$input->getOption('name-only') && ($nameLength + ($showVersion ? $versionLength : 0) + 24 <= $width);
                foreach ($packages[$type] as $package) {
                    $output->write($indent . str_pad($package->getPrettyName(), $nameLength, ' '), false);

                    if ($writeVersion) {
                        $output->write(' ' . str_pad($this->versionParser->formatVersion($package), $versionLength, ' '), false);
                    }

                    if ($writeDescription) {
                        $description = strtok($package->getDescription(), "\r\n");
                        $remaining = $width - $nameLength - $versionLength - 4;
                        if (strlen($description) > $remaining) {
                            $description = substr($description, 0, $remaining - 3) . '...';
                        }
                        $output->write(' ' . $description);
                    }
                    $output->writeln('');
                }
                if ($tree) {
                    $output->writeln('');
                }
            }
        }
    }

    /**
     * finds a package by name and version if provided
     *
     * @param  RepositoryInterface       $installedRepo
     * @param  RepositoryInterface       $repos
     * @param  string                    $name
     * @param  string                    $version
     * @return array                     array(CompletePackageInterface, array of versions)
     * @throws \InvalidArgumentException
     */
    protected function getPackage(RepositoryInterface $installedRepo, RepositoryInterface $repos, $name, $version = null)
    {
        $name = strtolower($name);
        if ($version) {
            $version = $this->versionParser->normalize($version);
        }

        $match = null;
        $matches = array();
        $repos->filterPackages(function ($package) use ($name, $version, &$matches) {
            if ($package->getName() === $name) {
                $matches[] = $package;
            }
        }, 'Composer\Package\CompletePackage');

        if (null === $version) {
            // search for a locally installed version
            foreach ($matches as $package) {
                if ($installedRepo->hasPackage($package)) {
                    $match = $package;
                    break;
                }
            }

            if (!$match) {
                // fallback to the highest version
                foreach ($matches as $package) {
                    if (null === $match || version_compare($package->getVersion(), $match->getVersion(), '>=')) {
                        $match = $package;
                    }
                }
            }
        } else {
            // select the specified version
            foreach ($matches as $package) {
                if ($package->getVersion() === $version) {
                    $match = $package;
                }
            }
        }

        // build versions array
        $versions = array();
        foreach ($matches as $package) {
            $versions[$package->getPrettyVersion()] = $package->getVersion();
        }

        return array($match, $versions);
    }

    /**
     * prints package meta data
     */
    protected function printMeta(InputInterface $input, OutputInterface $output, CompletePackageInterface $package, array $versions, RepositoryInterface $installedRepo, RepositoryInterface $repos)
    {
        $output->writeln('<info>name</info>     : ' . $package->getPrettyName());
        $output->writeln('<info>descrip.</info> : ' . $package->getDescription());
        $output->writeln('<info>keywords</info> : ' . join(', ', $package->getKeywords() ?: array()));
        $this->printVersions($input, $output, $package, $versions, $installedRepo, $repos);
        $output->writeln('<info>type</info>     : ' . $package->getType());
        $output->writeln('<info>license</info>  : ' . implode(', ', $package->getLicense()));
        $output->writeln('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getSourceType(), $package->getSourceUrl(), $package->getSourceReference()));
        $output->writeln('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getDistType(), $package->getDistUrl(), $package->getDistReference()));
        $output->writeln('<info>names</info>    : ' . implode(', ', $package->getNames()));

        if ($package->getSupport()) {
            $output->writeln("\n<info>support</info>");
            foreach ($package->getSupport() as $type => $value) {
                $output->writeln('<comment>' . $type . '</comment> : '.$value);
            }
        }

        if ($package->getAutoload()) {
            $output->writeln("\n<info>autoload</info>");
            foreach ($package->getAutoload() as $type => $autoloads) {
                $output->writeln('<comment>' . $type . '</comment>');

                if ($type === 'psr-0') {
                    foreach ($autoloads as $name => $path) {
                        $output->writeln(($name ?: '*') . ' => ' . ($path ?: '.'));
                    }
                } elseif ($type === 'classmap') {
                    $output->writeln(implode(', ', $autoloads));
                }
            }
            if ($package->getIncludePaths()) {
                $output->writeln('<comment>include-path</comment>');
                $output->writeln(implode(', ', $package->getIncludePaths()));
            }
        }
    }

    /**
     * prints all available versions of this package and highlights the installed one if any
     */
    protected function printVersions(InputInterface $input, OutputInterface $output, CompletePackageInterface $package, array $versions, RepositoryInterface $installedRepo, RepositoryInterface $repos)
    {
        if ($input->getArgument('version')) {
            $output->writeln('<info>version</info>  : ' . $package->getPrettyVersion());

            return;
        }

        uasort($versions, 'version_compare');
        $versions = array_keys(array_reverse($versions));

        // highlight installed version
        if ($installedRepo->hasPackage($package)) {
            $installedVersion = $package->getPrettyVersion();
            $key = array_search($installedVersion, $versions);
            if (false !== $key) {
                $versions[$key] = '<info>* ' . $installedVersion . '</info>';
            }
        }

        $versions = implode(', ', $versions);

        $output->writeln('<info>versions</info> : ' . $versions);
    }

    /**
     * print link objects
     *
     * @param InputInterface           $input
     * @param OutputInterface          $output
     * @param CompletePackageInterface $package
     * @param string                   $linkType
     * @param string                   $title
     */
    protected function printLinks(InputInterface $input, OutputInterface $output, CompletePackageInterface $package, $linkType, $title = null)
    {
        $title = $title ?: $linkType;
        if ($links = $package->{'get'.ucfirst($linkType)}()) {
            $output->writeln("\n<info>" . $title . "</info>");

            foreach ($links as $link) {
                $output->writeln($link->getTarget() . ' <comment>' . $link->getPrettyConstraint() . '</comment>');
            }
        }
    }
}
