<?php declare(strict_types=1);

namespace ArtissStorefront\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldInstaller
{
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldRepository
    ) {
    }

    public function install(Context $context): void
    {
        $this->createCategorySeoFieldSet($context);
    }

    private function createCategorySeoFieldSet(Context $context): void
    {
        // Check if set already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'category_seo_fields'));
        $existingSet = $this->customFieldSetRepository->search($criteria, $context);

        if ($existingSet->getTotal() > 0) {
            // Set exists, skip creation
            return;
        }

        $this->customFieldSetRepository->upsert([[
            'name' => 'category_seo_fields',
            'config' => [
                'label' => [
                    'en-GB' => 'Category SEO Fields',
                    'de-DE' => 'Kategorie SEO-Felder',
                    'ru-RU' => 'SEO поля категории',
                    'uk-UA' => 'SEO поля категорії',
                ]
            ],
            'customFields' => [
                [
                    'name' => 'category_h1_tag',
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-field',
                        'customFieldType' => 'text',
                        'customFieldPosition' => 1,
                        'label' => [
                            'en-GB' => 'H1 Tag',
                            'de-DE' => 'H1 Tag',
                            'ru-RU' => 'H1 тег',
                            'uk-UA' => 'H1 тег',
                        ],
                        'placeholder' => [
                            'en-GB' => 'Enter H1 tag for this category...',
                            'de-DE' => 'H1-Tag für diese Kategorie eingeben...',
                            'ru-RU' => 'Введите H1 тег для категории...',
                            'uk-UA' => 'Введіть H1 тег для категорії...',
                        ],
                        'helpText' => [
                            'en-GB' => 'Custom H1 heading for SEO optimization. If empty, category name will be used on frontend.',
                            'de-DE' => 'Benutzerdefinierte H1-Überschrift für SEO-Optimierung. Wenn leer, wird der Kategoriename im Frontend verwendet.',
                            'ru-RU' => 'Пользовательский H1 заголовок для SEO оптимизации. Если пусто, на фронтенде будет использовано название категории.',
                            'uk-UA' => 'Кастомний H1 заголовок для SEO оптимізації. Якщо порожньо, на фронтенді буде використано назву категорії.',
                        ]
                    ]
                ],
            ],
            'relations' => [
                ['entityName' => 'category']
            ]
        ]], $context);
    }

    public function uninstall(Context $context): void
    {
        // Delete category_seo_fields custom field set
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('name', 'category_seo_fields')
        );

        $ids = $this->customFieldSetRepository->searchIds($criteria, $context);

        if ($ids->getTotal() > 0) {
            $this->customFieldSetRepository->delete(
                array_map(fn($id) => ['id' => $id], $ids->getIds()),
                $context
            );
        }
    }
}
