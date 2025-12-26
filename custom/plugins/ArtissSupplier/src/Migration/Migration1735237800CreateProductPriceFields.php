<?php declare(strict_types=1);

namespace Artiss\Supplier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1735237800CreateProductPriceFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735237800;
    }

    public function update(Connection $connection): void
    {
        $this->createCustomFieldSet($connection);
        $this->createCustomFields($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createCustomFieldSet(Connection $connection): void
    {
        $setId = Uuid::randomBytes();

        $existingSet = $connection->fetchOne(
            'SELECT id FROM custom_field_set WHERE name = :name',
            ['name' => 'product_prices']
        );

        if ($existingSet) {
            return;
        }

        $connection->insert('custom_field_set', [
            'id' => $setId,
            'name' => 'product_prices',
            'config' => json_encode([
                'label' => [
                    'ru-RU' => 'Цены поставщика',
                    'en-GB' => 'Supplier Prices',
                    'de-DE' => 'Lieferantenpreise',
                    'uk-UA' => 'Ціни постачальника'
                ],
                'customFieldPosition' => 100
            ]),
            'active' => 1,
            'global' => 0,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
        ]);

        $connection->insert('custom_field_set_relation', [
            'id' => Uuid::randomBytes(),
            'set_id' => $setId,
            'entity_name' => 'product',
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
        ]);
    }

    private function createCustomFields(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT id FROM custom_field_set WHERE name = :name',
            ['name' => 'product_prices']
        );

        if (!$setId) {
            return;
        }

        $fields = [
            [
                'name' => 'purchase_price_value',
                'type' => 'float',
                'config' => [
                    'label' => [
                        'ru-RU' => 'Закупочная цена (значение)',
                        'en-GB' => 'Purchase Price (value)',
                        'de-DE' => 'Einkaufspreis (Wert)',
                        'uk-UA' => 'Закупівельна ціна (значення)'
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'customFieldPosition' => 1,
                    'numberType' => 'float'
                ]
            ],
            [
                'name' => 'purchase_price_currency',
                'type' => 'text',
                'config' => [
                    'label' => [
                        'ru-RU' => 'Закупочная цена (валюта)',
                        'en-GB' => 'Purchase Price (currency)',
                        'de-DE' => 'Einkaufspreis (Währung)',
                        'uk-UA' => 'Закупівельна ціна (валюта)'
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'text',
                    'customFieldPosition' => 2,
                    'placeholder' => [
                        'ru-RU' => 'UAH',
                        'en-GB' => 'UAH',
                        'de-DE' => 'UAH',
                        'uk-UA' => 'UAH'
                    ]
                ]
            ],
            [
                'name' => 'retail_price_value',
                'type' => 'float',
                'config' => [
                    'label' => [
                        'ru-RU' => 'Розничная цена (значение)',
                        'en-GB' => 'Retail Price (value)',
                        'de-DE' => 'Einzelhandelspreis (Wert)',
                        'uk-UA' => 'Роздрібна ціна (значення)'
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'customFieldPosition' => 3,
                    'numberType' => 'float'
                ]
            ],
            [
                'name' => 'retail_price_currency',
                'type' => 'text',
                'config' => [
                    'label' => [
                        'ru-RU' => 'Розничная цена (валюта)',
                        'en-GB' => 'Retail Price (currency)',
                        'de-DE' => 'Einzelhandelspreis (Währung)',
                        'uk-UA' => 'Роздрібна ціна (валюта)'
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'text',
                    'customFieldPosition' => 4,
                    'placeholder' => [
                        'ru-RU' => 'UAH',
                        'en-GB' => 'UAH',
                        'de-DE' => 'UAH',
                        'uk-UA' => 'UAH'
                    ]
                ]
            ],
            [
                'name' => 'list_price_value',
                'type' => 'float',
                'config' => [
                    'label' => [
                        'ru-RU' => 'Цена в прайсе (значение)',
                        'en-GB' => 'List Price (value)',
                        'de-DE' => 'Listenpreis (Wert)',
                        'uk-UA' => 'Ціна в прайсі (значення)'
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'customFieldPosition' => 5,
                    'numberType' => 'float',
                    'helpText' => [
                        'ru-RU' => 'Цена из прайса поставщика',
                        'en-GB' => 'Price from supplier price list',
                        'de-DE' => 'Preis aus Lieferantenpreisliste',
                        'uk-UA' => 'Ціна з прайса постачальника'
                    ]
                ]
            ],
            [
                'name' => 'list_price_currency',
                'type' => 'text',
                'config' => [
                    'label' => [
                        'ru-RU' => 'Цена в прайсе (валюта)',
                        'en-GB' => 'List Price (currency)',
                        'de-DE' => 'Listenpreis (Währung)',
                        'uk-UA' => 'Ціна в прайсі (валюта)'
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'text',
                    'customFieldPosition' => 6,
                    'placeholder' => [
                        'ru-RU' => 'UAH',
                        'en-GB' => 'UAH',
                        'de-DE' => 'UAH',
                        'uk-UA' => 'UAH'
                    ]
                ]
            ]
        ];

        foreach ($fields as $index => $field) {
            $existingField = $connection->fetchOne(
                'SELECT id FROM custom_field WHERE name = :name',
                ['name' => $field['name']]
            );

            if ($existingField) {
                continue;
            }

            $connection->insert('custom_field', [
                'id' => Uuid::randomBytes(),
                'name' => $field['name'],
                'type' => $field['type'],
                'config' => json_encode($field['config']),
                'active' => 1,
                'set_id' => $setId,
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT)
            ]);
        }
    }
}
