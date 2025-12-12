<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Command;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Description:
 *   Synchronizes media records in database with actual files on disk.
 *   Useful after database restore when files were converted to WebP but DB records still reference old formats.
 *   Updates mime_type, file_extension, path, file_size and removes old thumbnails.
 *   Run `bin/console media:generate-thumbnails` after synchronization.
 *
 * Usage:
 *   bin/console artiss:media:sync-from-files [options]
 *
 * Options:
 *   --limit=VALUE, -l VALUE    Number of media items to process per batch (default: 200)
 *   --dry-run                  Only show what would be changed without making changes
 *   --folder=VALUE, -f VALUE   Only process media from a specific folder (by folder ID)
 *
 * Example:
 *   bin/console artiss:media:sync-from-files --limit=50 --dry-run --folder=0188b4a2c3e87a5e9d8c2e3f4a5b6c7d
 */
#[AsCommand(
    name: 'artiss:media:sync-from-files',
    description: 'Синхронизирует записи медиа в БД с реальными файлами на диске (после восстановления БД)'
)]
class SyncMediaFromFilesCommand extends Command
{
    private const IMAGE_EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly FilesystemOperator $filesystemPublic,
        private readonly FilesystemOperator $filesystemPrivate,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Количество медиа для обработки за один батч',
                '200'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Только показать что будет изменено, без реальных изменений'
            )
            ->addOption(
                'folder',
                'f',
                InputOption::VALUE_REQUIRED,
                'Обработать только медиа из определенной папки (ID папки)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');
        $folderId = $input->getOption('folder');

        $context = Context::createDefaultContext();

        if ($dryRun) {
            $io->warning('РЕЖИМ СУХОГО ПРОГОНА - Изменения не будут применены');
        }

        $io->title('Artiss Media Optimizer - Синхронизация медиа с файлами');

        $totalCount = $this->countMediaToProcess($folderId, $context);
        $io->text(sprintf('Найдено %d медиа записей для проверки', $totalCount));

        if ($totalCount === 0) {
            $io->success('Нет медиа записей для обработки');
            return Command::SUCCESS;
        }

        $io->progressStart($totalCount);

        $processed = 0;
        $synced = 0;
        $notFound = 0;
        $alreadyOk = 0;
        $errors = 0;
        $offset = 0;

        $changes = []; // Для вывода в dry-run режиме

        while ($offset < $totalCount) {
            $mediaItems = $this->fetchMediaBatch($folderId, $limit, $offset, $context);

            foreach ($mediaItems as $media) {
                $processed++;

                try {
                    $result = $this->processMediaEntity($media, $dryRun, $io);

                    switch ($result['status']) {
                        case 'synced':
                            $synced++;
                            if ($dryRun) {
                                $changes[] = $result;
                            }
                            break;
                        case 'not_found':
                            $notFound++;
                            $this->logger->warning('Media file not found', [
                                'mediaId' => $media->getId(),
                                'expectedPath' => $result['expectedPath'],
                                'testedPaths' => $result['testedPaths'] ?? [],
                            ]);
                            break;
                        case 'ok':
                            $alreadyOk++;
                            break;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $io->error(sprintf(
                        'Ошибка при обработке медиа %s: %s',
                        $media->getId(),
                        $e->getMessage()
                    ));
                    $this->logger->error('Media sync failed', [
                        'mediaId' => $media->getId(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                $io->progressAdvance();
            }

            $offset += $limit;
        }

        $io->progressFinish();

        $io->newLine(2);

        if ($dryRun && !empty($changes)) {
            $io->section('Изменения, которые будут применены:');
            $tableData = [];
            foreach (array_slice($changes, 0, 20) as $change) {
                $tableData[] = [
                    $change['mediaId'],
                    $change['oldExtension'],
                    $change['newExtension'],
                    $change['oldPath'],
                    $change['newPath'],
                ];
            }

            $io->table(
                ['Media ID', 'Старый формат', 'Новый формат', 'Старый путь', 'Новый путь'],
                $tableData
            );

            if (count($changes) > 20) {
                $io->text(sprintf('... и еще %d изменений', count($changes) - 20));
            }
        }

        $io->table(
            ['Метрика', 'Количество'],
            [
                ['Обработано', $processed],
                ['Синхронизировано', $synced],
                ['Уже корректно', $alreadyOk],
                ['Файлы не найдены', $notFound],
                ['Ошибки', $errors],
            ]
        );

        if ($synced > 0 && !$dryRun) {
            $io->note('Рекомендуется запустить "bin/console media:generate-thumbnails" для пересоздания миниатюр');
        }

        if ($notFound > 0) {
            $io->warning(sprintf(
                '%d медиа файлов не найдены ни в одном из проверенных форматов. Проверьте логи.',
                $notFound
            ));
        }

        if ($errors > 0) {
            $io->warning(sprintf('%d медиа записей не удалось обработать. Проверьте логи для деталей.', $errors));
            return Command::FAILURE;
        }

        if ($dryRun && $synced > 0) {
            $io->info(sprintf(
                'Сухой прогон завершен. Запустите команду без --dry-run для применения %d изменений.',
                $synced
            ));
        } elseif (!$dryRun && $synced > 0) {
            $io->success(sprintf('Успешно синхронизировано %d медиа записей', $synced));
        } else {
            $io->success('Все медиа записи уже синхронизированы с файлами');
        }

        return Command::SUCCESS;
    }

    private function countMediaToProcess(?string $folderId, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        if ($folderId !== null) {
            $criteria->addFilter(new EqualsFilter('mediaFolderId', $folderId));
        }

        return $this->mediaRepository->search($criteria, $context)->getTotal();
    }

    private function fetchMediaBatch(?string $folderId, int $limit, int $offset, Context $context): iterable
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        if ($folderId !== null) {
            $criteria->addFilter(new EqualsFilter('mediaFolderId', $folderId));
        }

        return $this->mediaRepository->search($criteria, $context)->getEntities();
    }

    private function processMediaEntity(MediaEntity $media, bool $dryRun, SymfonyStyle $io): array
    {
        $mediaPath = $media->getPath();
        if ($mediaPath === null) {
            return ['status' => 'not_found', 'expectedPath' => 'null'];
        }

        $filesystem = $media->isPrivate() ? $this->filesystemPrivate : $this->filesystemPublic;

        // Проверяем, существует ли файл по текущему пути
        if ($filesystem->fileExists($mediaPath)) {
            // Файл существует, проверяем соответствие метаданных
            $actualFileSize = $filesystem->fileSize($mediaPath);
            $dbFileSize = $media->getFileSize();

            // Если размер совпадает (или близок), считаем что все OK
            if ($actualFileSize === $dbFileSize || abs($actualFileSize - $dbFileSize) < 1024) {
                return ['status' => 'ok'];
            }

            // Размер не совпадает, обновим размер
            if (!$dryRun) {
                $this->updateFileSize($media->getId(), $actualFileSize);
            }

            return [
                'status' => 'synced',
                'mediaId' => $media->getId(),
                'oldExtension' => $media->getFileExtension(),
                'newExtension' => $media->getFileExtension(),
                'oldPath' => $mediaPath,
                'newPath' => $mediaPath,
                'reason' => 'Обновлен размер файла',
            ];
        }

        // Файл не существует по указанному пути
        // Попробуем найти файл с другим расширением
        $pathInfo = pathinfo($mediaPath);
        $directory = $pathInfo['dirname'] ?? '';
        $filename = $pathInfo['filename'] ?? '';
        $currentExtension = $pathInfo['extension'] ?? '';

        $testedPaths = [$mediaPath];
        $foundFile = null;
        $foundExtension = null;

        // Определяем какие расширения пробовать
        $extensionsToTry = [];

        if (in_array(strtolower($currentExtension), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'heic', 'heif'])) {
            // Если в БД не-WebP формат, проверяем WebP
            $extensionsToTry[] = 'webp';
        }

        if (strtolower($currentExtension) === 'webp') {
            // Если в БД WebP, проверяем оригинальные форматы
            $extensionsToTry = ['jpg', 'jpeg', 'png', 'gif'];
        }

        foreach ($extensionsToTry as $ext) {
            $testPath = rtrim($directory, '/') . '/' . $filename . '.' . $ext;
            $testedPaths[] = $testPath;

            if ($filesystem->fileExists($testPath)) {
                $foundFile = $testPath;
                $foundExtension = $ext;
                break;
            }
        }

        if ($foundFile === null) {
            return [
                'status' => 'not_found',
                'expectedPath' => $mediaPath,
                'testedPaths' => $testedPaths,
            ];
        }

        // Нашли файл с другим расширением!
        $mimeType = self::IMAGE_EXTENSIONS[$foundExtension] ?? 'application/octet-stream';
        $fileSize = $filesystem->fileSize($foundFile);

        $result = [
            'status' => 'synced',
            'mediaId' => $media->getId(),
            'oldExtension' => $currentExtension,
            'newExtension' => $foundExtension,
            'oldPath' => $mediaPath,
            'newPath' => $foundFile,
            'oldMimeType' => $media->getMimeType(),
            'newMimeType' => $mimeType,
            'oldFileSize' => $media->getFileSize(),
            'newFileSize' => $fileSize,
        ];

        if (!$dryRun) {
            $this->updateMediaRecord(
                $media->getId(),
                $foundFile,
                $foundExtension,
                $mimeType,
                $fileSize
            );

            // Удаляем старые thumbnails - их нужно будет пересоздать
            $this->deleteExistingThumbnails($media);
        }

        return $result;
    }

    private function updateMediaRecord(
        string $mediaId,
        string $newPath,
        string $newExtension,
        string $newMimeType,
        int $fileSize
    ): void {
        $this->connection->executeStatement(
            'UPDATE media SET
                mime_type = :mimeType,
                file_extension = :extension,
                path = :path,
                file_size = :fileSize,
                updated_at = :updatedAt
             WHERE id = :id',
            [
                'mimeType' => $newMimeType,
                'extension' => $newExtension,
                'path' => $newPath,
                'fileSize' => $fileSize,
                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                'id' => hex2bin($mediaId),
            ]
        );

        $this->logger->info('Media record synchronized', [
            'mediaId' => $mediaId,
            'newPath' => $newPath,
            'newExtension' => $newExtension,
            'newMimeType' => $newMimeType,
            'fileSize' => $fileSize,
        ]);
    }

    private function updateFileSize(string $mediaId, int $fileSize): void
    {
        $this->connection->executeStatement(
            'UPDATE media SET
                file_size = :fileSize,
                updated_at = :updatedAt
             WHERE id = :id',
            [
                'fileSize' => $fileSize,
                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                'id' => hex2bin($mediaId),
            ]
        );
    }

    private function deleteExistingThumbnails(MediaEntity $media): void
    {
        $this->connection->executeStatement(
            'DELETE FROM media_thumbnail WHERE media_id = :mediaId',
            ['mediaId' => hex2bin($media->getId())]
        );

        $this->logger->info('Deleted thumbnails for media', [
            'mediaId' => $media->getId(),
        ]);
    }
}

