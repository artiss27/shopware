# ArtissSupplier Plugin

Плагин для управления поставщиками в Shopware 6.

## Описание

Плагин добавляет новую сущность "Supplier" (Поставщик) со следующими возможностями:
- Управление поставщиками
- Связь поставщиков с производителями (ManyToMany)
- Связь поставщиков с категориями (ManyToMany)
- Связь поставщиков с товарами (OneToMany)
- Хранение ID из Битрикс для миграции данных

## Структура базы данных

### Таблица `supplier`
- `id` - UUID поставщика
- `name` - Название поставщика
- `code` - Символьный код
- `active` - Активность (boolean)
- `sort` - Сортировка
- `bitrix_id` - ID элемента из Битрикс (для миграции)
- `custom_fields` - JSON поле для дополнительных данных
- `created_at`, `updated_at` - Временные метки

### Таблица `supplier_manufacturer`
Связующая таблица Many-to-Many между поставщиками и производителями

### Таблица `supplier_category`
Связующая таблица Many-to-Many между поставщиками и категориями

### Расширение таблицы `product`
Добавлено поле `supplier_id` для связи товара с поставщиком

## Установка

```bash
# Обновить список плагинов
bin/console plugin:refresh

# Установить и активировать плагин
bin/console plugin:install --activate ArtissSupplier

# Очистить кэш
bin/console cache:clear
```

## Импорт данных из Битрикс

Скрипт для импорта поставщиков:
```bash
docker compose exec web php /var/www/html/bitrix-export/scripts/import/import_suppliers.php
```

## Структура сущности Supplier

### Поля
- **name** (string, required) - Название поставщика
- **code** (string, optional) - Символьный код
- **active** (boolean) - Активность
- **sort** (int) - Сортировка (по умолчанию 500)
- **bitrixId** (int, optional) - ID из Битрикс
- **customFields** (array, optional) - Дополнительные поля

### Связи
- **manufacturers** (ManyToMany) - Связанные производители
- **categories** (ManyToMany) - Связанные категории товаров
- **products** (OneToMany) - Связанные товары

## API

Сущность доступна через Shopware API:

### Получить список поставщиков
```http
GET /api/supplier
```

### Создать поставщика
```http
POST /api/supplier
Content-Type: application/json

{
  "name": "Название поставщика",
  "code": "supplier-code",
  "active": true,
  "sort": 500
}
```

### Обновить поставщика
```http
PATCH /api/supplier/{id}
Content-Type: application/json

{
  "name": "Новое название"
}
```

### Удалить поставщика
```http
DELETE /api/supplier/{id}
```

## Следующие шаги

1. Импорт связей поставщик <-> производитель
2. Импорт связей поставщик <-> категория
3. Связывание товаров с поставщиками
4. Создание админ-интерфейса для управления поставщиками в админке Shopware

## Техническая информация

- **Namespace**: `Artiss\Supplier`
- **Entity Name**: `supplier`
- **Plugin Class**: `Artiss\Supplier\ArtissSupplier`
- **Version**: 1.0.0
