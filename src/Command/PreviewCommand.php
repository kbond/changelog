<?php

namespace Zenstruck\Changelog\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Changelog\Formatter;
use Zenstruck\Changelog\GitHubApi;
use Zenstruck\Changelog\Repository;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class PreviewCommand extends Command
{
    private GitHubApi $api;

    public function __construct()
    {
        parent::__construct();

        $this->api = new GitHubApi();
    }

    protected function configure(): void
    {
        $this
            ->setName('preview')
            ->setDescription('Preview changelog for next release')
            ->addArgument('next', InputArgument::OPTIONAL, 'Next version, can use semantic type to auto-generate: major (maj), minor (min, feature, feat) or patch (bug, bugfix)')
            ->addOption('repository', 'r', InputOption::VALUE_REQUIRED, 'GitHub repository use (leave blank to detect from current directory)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Release to start changelog from (leave blank for latest)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Release to end changelog (leave blank for default branch)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repository = $this->fetchRepository($input->getOption('repository'));
        $comparison = $repository->compare($input->getOption('from'), $input->getOption('to'));
        $next = $input->getArgument('next') ? $repository->releases()->next($input->getArgument('next')) : null;

        $io->title('Changelog Generator');
        $io->comment("Generating <info>{$repository}:{$comparison}</info> changelog");

        $formatter = new Formatter();
        $commits = $this->api->commits($repository, $comparison);

        if ($next) {
            $io->write($formatter->release($next, $commits, $next->version()->compareWith($comparison->from())));
        } else {
            $io->write($formatter->releaseBody($commits));
        }

        $io->success('Done.');

        return self::SUCCESS;
    }

    private function fetchRepository(?string $name): Repository
    {
        if ($name) {
            return $this->api->repository($name);
        }

        if (!\file_exists($gitConfigFile = \getcwd().'/.git/config')) {
            // todo recursive look up dir tree (could be in a subdir)
            throw new \RuntimeException('Not able to find git config to guess repository. Use --repository option.');
        }

        $repository = $this->api->repository(self::parseRepositoryFrom($gitConfigFile));

        // use parent if exists (not a fork)
        return $repository->parent() ?? $repository;
    }

    private static function parseRepositoryFrom(string $gitConfigFile): string
    {
        $ini = \parse_ini_file($gitConfigFile, true);

        foreach ($ini as $section => $items) {
            if (!str_starts_with($section, 'remote')) {
                continue;
            }

            if (!isset($items['url'])) {
                continue;
            }

            if (!\preg_match('#github.com[:/]([\w-]+/[\w-]+)#', $items['url'], $matches)) {
                // not a github repo
                continue;
            }

            return $matches[1];
        }

        throw new \RuntimeException('Unable to find git remote urls');
    }
}
