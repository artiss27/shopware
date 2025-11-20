<?php declare(strict_types=1);

namespace Artiss\Supplier\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-text-field',
                        'customFieldType' => 'text',
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
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-text-field',
                        'customFieldType' => 'text',
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
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-text-field',
                        'customFieldType' => 'text',
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
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-text-field',
                        'customFieldType' => 'text',
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
                    'name' => 'supplier_commercial_discount_opt',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 13,
                        'label' => [
                            'en-GB' => 'Wholesale Discount',
                            'de-DE' => 'Großhandelsrabatt',
                            'ru-RU' => 'Оптовая скидка',
                            'ru-UA' => 'Оптовая скидка',
                            'uk-UA' => 'Оптова знижка',
                        ]
                    ]
                ],
                [
                    'name' => 'supplier_commercial_discount_online',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
                        'customFieldPosition' => 14,
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
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'componentName' => 'sw-tagged-field',
                        'customFieldType' => 'select',
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

    public function uninstall(Context $context): void
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('name', 'supplier_fields')
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
