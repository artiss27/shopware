<?php declare(strict_types=1);

namespace Artiss\MediaOptimizer\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldInstaller
{
    private const CUSTOM_FIELD_SET_NAME = 'artiss_media_optimizer';
    private const CUSTOM_FIELD_ORIGINAL_PATH = 'artiss_original_path';

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository
    ) {
    }

    public function install(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME));
        $existingSet = $this->customFieldSetRepository->search($criteria, $context);

        if ($existingSet->getTotal() > 0) {
            return;
        }

        $this->customFieldSetRepository->upsert([
            [
                'name' => self::CUSTOM_FIELD_SET_NAME,
                'config' => [
                    'label' => [
                        'en-GB' => 'Media Optimizer',
                        'de-DE' => 'Media Optimizer',
                        'ru-RU' => 'Media Optimizer',
                        'uk-UA' => 'Media Optimizer',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => self::CUSTOM_FIELD_ORIGINAL_PATH,
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'componentName' => 'sw-field',
                            'customFieldType' => 'text',
                            'customFieldPosition' => 1,
                            'label' => [
                                'en-GB' => 'Original file path',
                                'de-DE' => 'Originaldateipfad',
                                'ru-RU' => 'Путь к оригиналу',
                                'uk-UA' => 'Шлях до оригіналу',
                            ],
                            'helpText' => [
                                'en-GB' => 'Path to the archived original file (before WebP conversion)',
                                'de-DE' => 'Pfad zur archivierten Originaldatei (vor der WebP-Konvertierung)',
                                'ru-RU' => 'Путь к архивированному оригинальному файлу (до конвертации в WebP)',
                                'uk-UA' => 'Шлях до архівованого оригінального файлу (до конвертації в WebP)',
                            ],
                        ],
                    ],
                ],
                'relations' => [
                    ['entityName' => 'media'],
                ],
            ],
        ], $context);
    }

    public function uninstall(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME));

        $ids = $this->customFieldSetRepository->searchIds($criteria, $context);

        if ($ids->getTotal() > 0) {
            $this->customFieldSetRepository->delete(
                array_map(fn($id) => ['id' => $id], $ids->getIds()),
                $context
            );
        }
    }
}
