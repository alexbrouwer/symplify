<?php

declare(strict_types=1);

namespace Symplify\ChangelogLinker\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symplify\ChangelogLinker\Application\ChangelogLinkerApplication;
use Symplify\ChangelogLinker\Configuration\HighestMergedIdResolver;
use Symplify\ChangelogLinker\Console\Input\PriorityResolver;
use Symplify\ChangelogLinker\FileSystem\ChangelogFileSystem;
use Symplify\ChangelogLinker\FileSystem\ChangelogPlaceholderGuard;
use Symplify\ChangelogLinker\Github\GithubApi;
use Symplify\ChangelogLinker\ValueObject\Option;
use Symplify\PackageBuilder\Console\Command\AbstractSymplifyCommand;
use Symplify\PackageBuilder\Console\ShellCode;

/**
 * @inspired by https://github.com/weierophinney/changelog_generator
 */
final class DumpMergesCommand extends AbstractSymplifyCommand
{
    /**
     * @inspiration markdown comment: https://gist.github.com/jonikarppinen/47dc8c1d7ab7e911f4c9#gistcomment-2109856
     * @var string
     */
    public const CHANGELOG_PLACEHOLDER_TO_WRITE = '<!-- changelog-linker -->';

    /**
     * @var GithubApi
     */
    private $githubApi;

    /**
     * @var ChangelogFileSystem
     */
    private $changelogFileSystem;

    /**
     * @var PriorityResolver
     */
    private $priorityResolver;

    /**
     * @var ChangelogPlaceholderGuard
     */
    private $changelogPlaceholderGuard;

    /**
     * @var ChangelogLinkerApplication
     */
    private $changelogLinkerApplication;

    /**
     * @var HighestMergedIdResolver
     */
    private $highestMergedIdResolver;

    public function __construct(
        GithubApi $githubApi,
        ChangelogFileSystem $changelogFileSystem,
        PriorityResolver $priorityResolver,
        ChangelogPlaceholderGuard $changelogPlaceholderGuard,
        ChangelogLinkerApplication $changelogLinkerApplication,
        HighestMergedIdResolver $highestMergedIdResolver
    ) {
        parent::__construct();

        $this->githubApi = $githubApi;
        $this->changelogFileSystem = $changelogFileSystem;
        $this->priorityResolver = $priorityResolver;
        $this->changelogPlaceholderGuard = $changelogPlaceholderGuard;
        $this->changelogLinkerApplication = $changelogLinkerApplication;
        $this->highestMergedIdResolver = $highestMergedIdResolver;
    }

    protected function configure(): void
    {
        $this->setDescription(
            'Scans repository merged PRs, that are not in the CHANGELOG.md yet, and dumps them in changelog format.'
        );
        $this->addOption(
            Option::IN_CATEGORIES,
            null,
            InputOption::VALUE_NONE,
            'Print in Added/Changed/Fixed/Removed - detected from "Add", "Fix", "Removed" etc. keywords in merge title.'
        );

        $this->addOption(
            Option::IN_PACKAGES,
            null,
            InputOption::VALUE_NONE,
            'Print in groups in package names - detected from "[PackageName]" in merge title.'
        );

        $this->addOption(
            Option::DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'Print out to the output instead of writing directly into CHANGELOG.md.'
        );

        $this->addOption(
            Option::SINCE_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'Include pull-request with provided ID and higher. The ID is detected in CHANGELOG.md otherwise.'
        );

        $this->addOption(
            Option::BASE_BRANCH,
            null,
            InputOption::VALUE_OPTIONAL,
            'Base branch towards which the pull requests are targeted'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $content = $this->changelogFileSystem->readChangelog();

        $this->changelogPlaceholderGuard->ensurePlaceholderIsPresent($content, self::CHANGELOG_PLACEHOLDER_TO_WRITE);

        $sinceId = $this->highestMergedIdResolver->resolveFromInputAndChangelogContent($input, $content);

        /** @var string $baseBranch */
        $baseBranch = $input->getOption(Option::BASE_BRANCH);

        $pullRequests = $this->githubApi->getMergedPullRequestsSinceId($sinceId, $baseBranch);
        if (count($pullRequests) === 0) {
            $message = sprintf('There are no new pull requests to be added since ID "%d".', $sinceId);
            $this->symfonyStyle->note($message);

            return ShellCode::SUCCESS;
        }

        $sortPriority = $this->priorityResolver->resolveFromInput($input);
        $inCategories = (bool) $input->getOption(Option::IN_CATEGORIES);
        $inPackages = (bool) $input->getOption(Option::IN_PACKAGES);

        $content = $this->changelogLinkerApplication->createContentFromPullRequestsBySortPriority(
            $pullRequests,
            $sortPriority,
            $inCategories,
            $inPackages
        );

        $dryRun = $input->getOption(Option::DRY_RUN);
        if ((bool) $dryRun) {
            $this->symfonyStyle->writeln($content);

            return ShellCode::SUCCESS;
        }

        $this->changelogFileSystem->addToChangelogOnPlaceholder($content, self::CHANGELOG_PLACEHOLDER_TO_WRITE);
        $this->symfonyStyle->success('The CHANGELOG.md was updated');

        return ShellCode::SUCCESS;
    }
}
