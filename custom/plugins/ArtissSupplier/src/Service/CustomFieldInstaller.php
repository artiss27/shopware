<?php declare(strict_types=1);

namespace Artiss\Supplier\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldInstaller
{
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository
    ) {
    }

    public function install(Context $context): void
    {
        $this->createSupplierFieldSet($context);
        $this->addProductSupplierField($context);
    }

    private function createSupplierFieldSet(Context $context): void
    {
        $this->customFieldSetRepository->upsert([[
            'name' => 'supplier_fields',
            'config' => [
                'label' => [
                    'en-GB' => 'Supplier Information',
                    'de-DE' => 'Lieferanteninformationen',
                    'ru-RU' => 'Информация о поставщике',
                    'ru-UA' => 'Информация о поставщике',
                    'uk-UA' => 'Інформація про постачальника',
                ]
            ],
            'customFields' => [
                // Contact Information (positions 1-4)
                [
                    'name' => 'supplier_contacts_city',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 1,
                        'label' => [
                            'en-GB' => 'City',
                            'de-DE' => 'Stadt',
                            'ru-RU' => 'Город',
                            'ru-UA' => 'Город',
                            'uk-UA' => 'Місто',
                        ],
                        'placeholder' => [
                            'en-GB' => 'Enter cities...',
                            'de-DE' => 'Städte eingeben...',
                            'ru-RU' => 'Введите города...',
                            'ru-UA' => 'Введите города...',
                            'uk-UA' => 'Введіть міста...',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_contacts_phone',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 2,
                        'label' => [
                            'en-GB' => 'Contacts',
                            'de-DE' => 'Kontakte',
                            'ru-RU' => 'Контакты',
                            'ru-UA' => 'Контакты',
                            'uk-UA' => 'Контакти',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_contacts_email',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 3,
                        'label' => [
                            'en-GB' => 'Email',
                            'de-DE' => 'E-Mail',
                            'ru-RU' => 'Email',
                            'ru-UA' => 'Email',
                            'uk-UA' => 'Email',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_contacts_website',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 4,
                        'label' => [
                            'en-GB' => 'Website',
                            'de-DE' => 'Webseite',
                            'ru-RU' => 'Сайт',
                            'ru-UA' => 'Сайт',
                            'uk-UA' => 'Сайт',
                        ]
                    ]
                ],

                // Commercial Terms (positions 11-14)
                [
                    'name' => 'supplier_commercial_purchase',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 11,
                        'label' => [
                            'en-GB' => 'Purchase',
                            'de-DE' => 'Einkauf',
                            'ru-RU' => 'Закупка',
                            'ru-UA' => 'Закупка',
                            'uk-UA' => 'Закупівля',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_commercial_margin',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 12,
                        'label' => [
                            'en-GB' => 'Margin',
                            'de-DE' => 'Marge',
                            'ru-RU' => 'Наценка',
                            'ru-UA' => 'Наценка',
                            'uk-UA' => 'Націнка',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_commercial_discount_online',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 13,
                        'label' => [
                            'en-GB' => 'Online Discount',
                            'de-DE' => 'Online-Rabatt',
                            'ru-RU' => 'Скидка онлайн',
                            'ru-UA' => 'Скидка онлайн',
                            'uk-UA' => 'Знижка онлайн',
                        ]
                    ]
                ],

                // Additional Information (positions 21-24)
                [
                    'name' => 'supplier_additional_details',
                    'type' => CustomFieldTypes::HTML,
                    'config' => [
                        'componentName' => 'sw-text-editor',
                        'customFieldType' => 'html',
                        'customFieldPosition' => 21,
                        'label' => [
                            'en-GB' => 'Details',
                            'de-DE' => 'Details',
                            'ru-RU' => 'Реквизиты',
                            'ru-UA' => 'Реквизиты',
                            'uk-UA' => 'Реквізити',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_additional_note',
                    'type' => CustomFieldTypes::HTML,
                    'config' => [
                        'componentName' => 'sw-text-editor',
                        'customFieldType' => 'html',
                        'customFieldPosition' => 22,
                        'label' => [
                            'en-GB' => 'Note',
                            'de-DE' => 'Notiz',
                            'ru-RU' => 'Комментарий',
                            'ru-UA' => 'Комментарий',
                            'uk-UA' => 'Примітка',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_additional_comment_content',
                    'type' => CustomFieldTypes::HTML,
                    'config' => [
                        'componentName' => 'sw-text-editor',
                        'customFieldType' => 'html',
                        'customFieldPosition' => 23,
                        'label' => [
                            'en-GB' => 'Import Comments',
                            'de-DE' => 'Import-Kommentare',
                            'ru-RU' => 'Комментарии импорта',
                            'ru-UA' => 'Комментарии импорта',
                            'uk-UA' => 'Коментарі імпорту',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_additional_potencial_tm',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 24,
                        'label' => [
                            'en-GB' => 'Potential Brands',
                            'de-DE' => 'Potenzielle Marken',
                            'ru-RU' => 'Потенциальные ТМ',
                            'ru-UA' => 'Потенциальные ТМ',
                            'uk-UA' => 'Потенційні ТМ',
                        ]
                    ]
                ],

                // Files (position 31)
                [
                    'name' => 'supplier_files_price_lists',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-text-field',
                        'customFieldType' => 'text',
                        'customFieldPosition' => 31,
                        'label' => [
                            'en-GB' => 'Price Lists (Media IDs)',
                            'de-DE' => 'Preislisten (Media IDs)',
                            'ru-RU' => 'Прайс-листы (Media ID)',
                            'ru-UA' => 'Прайс-листы (Media ID)',
                            'uk-UA' => 'Прайс-листи (Media ID)',
                        ],
                        'helpText' => [
                            'en-GB' => 'Temporarily: enter media IDs as JSON array. Full upload component coming soon.',
                            'de-DE' => 'Vorübergehend: Media-IDs als JSON-Array eingeben. Vollständige Upload-Komponente folgt bald.',
                            'ru-RU' => 'Временно: введите ID медиа как JSON массив. Полный компонент загрузки скоро будет готов.',
                            'ru-UA' => 'Временно: введите ID медиа как JSON массив. Полный компонент загрузки скоро будет готов.',
                            'uk-UA' => 'Тимчасово: введіть ID медіа як JSON масив. Повний компонент завантаження скоро буде готовий.',
                        ]
                    ]
                ],
            ],
            'relations' => [
                ['entityName' => 'art_supplier']
            ]
        ]], $context);
    }

    private function addProductSupplierField(Context $context): void
    {
        // Add supplier field to existing 'product_custom_properties' set
        // Find the existing custom field set
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('name', 'product_custom_properties')
        );

        $setIds = $this->customFieldSetRepository->searchIds($criteria, $context);

        if ($setIds->getTotal() === 0) {
            // If set doesn't exist, create it
            $this->customFieldSetRepository->upsert([[
                'name' => 'product_custom_properties',
                'config' => [
                    'label' => [
                        'en-GB' => 'Product Properties',
                        'uk-UA' => 'Властивості товару',
                    ]
                ],
                'customFields' => [
                    [
                        'name' => 'product_supplier_id',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'componentName' => 'sw-entity-single-select',
                            'entity' => 'art_supplier',
                            'customFieldType' => 'entity',
                            'customFieldPosition' => 1,
                            'label' => [
                                'en-GB' => 'Supplier',
                                'de-DE' => 'Lieferant',
                                'ru-RU' => 'Поставщик',
                                'uk-UA' => 'Постачальник',
                            ],
                            'placeholder' => [
                                'en-GB' => 'Select supplier...',
                                'de-DE' => 'Lieferant auswählen...',
                                'ru-RU' => 'Выберите поставщика...',
                                'uk-UA' => 'Оберіть постачальника...',
                            ]
                        ]
                    ],
                ],
                'relations' => [
                    ['entityName' => 'product']
                ]
            ]], $context);
        } else {
            // If set exists, just add the custom field
            $setId = $setIds->getIds()[0];

            $this->customFieldSetRepository->upsert([[
                'id' => $setId,
                'customFields' => [
                    [
                        'name' => 'product_supplier_id',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'componentName' => 'sw-entity-single-select',
                            'entity' => 'art_supplier',
                            'customFieldType' => 'entity',
                            'customFieldPosition' => 1,
                            'label' => [
                                'en-GB' => 'Supplier',
                                'de-DE' => 'Lieferant',
                                'ru-RU' => 'Поставщик',
                                'uk-UA' => 'Постачальник',
                            ],
                            'placeholder' => [
                                'en-GB' => 'Select supplier...',
                                'de-DE' => 'Lieferant auswählen...',
                                'ru-RU' => 'Выберите поставщика...',
                                'uk-UA' => 'Оберіть постачальника...',
                            ]
                        ]
                    ],
                ]
            ]], $context);
        }
    }

    public function uninstall(Context $context): void
    {
        // Delete supplier_fields custom field set (for supplier entity)
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('name', 'supplier_fields')
        );

        $ids = $this->customFieldSetRepository->searchIds($criteria, $context);

        if ($ids->getTotal() > 0) {
            $this->customFieldSetRepository->delete(
                array_map(fn($id) => ['id' => $id], $ids->getIds()),
                $context
            );
        }

        // Note: product_supplier_id field in product_custom_properties set is NOT deleted
        // to avoid removing other custom fields that may exist in that set
    }
}
