<?php
declare(strict_types=1);

namespace ArtissTools\Command;

use ArtissTools\Service\MediaHashService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'artiss:media:update-hashes',
    description: 'Update media file hashes for duplicate detection'
)]
class UpdateMediaHashesCommand extends Command
{
    public function __construct(
        private readonly MediaHashService $mediaHashService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', 100)
            ->addOption('folder-entity', 'f', InputOption::VALUE_OPTIONAL, 'Filter by folder entity (e.g., product, category)', null)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force regeneration of all hashes (even if already exist)')
            ->addOption('only-missing', null, InputOption::VALUE_NONE, 'Only process media without hashes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $batchSize = (int)$input->getOption('batch-size');
        $folderEntity = $input->getOption('folder-entity');
        $force = (bool)$input->getOption('force');
        $onlyMissing = (bool)$input->getOption('only-missing');

        $io->title('Updating Media Hashes');

        if ($force && $onlyMissing) {
            $io->error('Options --force and --only-missing cannot be used together');
            return Command::FAILURE;
        }

        if ($folderEntity) {
            $io->info("Filtering by folder entity: $folderEntity");
        }

        if ($force) {
            $io->warning('Force mode: All hashes will be regenerated');
        }

        if ($onlyMissing) {
            $io->info('Only processing media without hashes');
        }

        $offset = 0;
        $totalProcessed = 0;
        $progressBar = null;

        do {
            $result = $this->mediaHashService->updateMediaHashesBatch(
                $batchSize,
                $offset,
                $folderEntity,
                $context,
                $force,
                $onlyMissing
            );

            if ($progressBar === null && $result['total'] > 0) {
                $progressBar = new ProgressBar($output, $result['total']);
                $progressBar->start();
            }

            if ($progressBar !== null) {
                $progressBar->advance($result['processed']);
            }

            $totalProcessed += $result['processed'];
            $offset += $batchSize;

            // Add small delay to avoid overwhelming the database
            usleep(10000); // 10ms

        } while ($result['hasMore'] && $result['processed'] > 0);

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine(2);
        }

        $io->success("Successfully processed $totalProcessed media files");

        // Show final statistics
        $lastUpdate = $this->mediaHashService->getLastHashUpdate($context);
        if ($lastUpdate) {
            $io->info("Total hashed media: {$lastUpdate['totalHashed']}");
        }

        return Command::SUCCESS;
    }
}
