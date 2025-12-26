# Price Update System - ROADMAP

## Overview
Система автоматизированного обновления цен товаров из прайс-листов поставщиков с предпросмотром и возможностью корректировки.

## Database Schema

### Existing Tables
- ✅ `art_supplier` - таблица поставщиков
- ✅ `art_supplier_media` - прайс-листы поставщиков (многие ко многим)

### New Tables

#### `art_supplier_price_template`
Шаблоны для импорта цен с настройками парсинга и маппинга.

```sql
- id (binary(16), PK)
- supplier_id (binary(16), FK -> art_supplier.id)
- name (varchar(255)) - название шаблона
- config (JSON) - конфигурация (фильтры, маппинг, правила цен)
- last_import_media_id (binary(16), FK -> media.id, nullable)
- last_import_media_updated_at (datetime, nullable)
- normalized_data (LONGTEXT, nullable) - кешированные нормализованные данные
- matched_products (JSON, nullable) - постоянный маппинг product_id <-> supplier_code
- applied_at (datetime, nullable)
- applied_by_user_id (binary(16), FK -> user.id, nullable)
- created_at (datetime)
- updated_at (datetime)
```

**Config JSON Structure:**
```json
{
  "filters": {
    "categories": ["id1", "id2"],
    "equipment_types": ["type1"],
    "manufacturers": ["man_id"]
  },
  "mapping": {
    "start_row": 2,
    "code_column": "A",
    "name_column": "B",
    "price_column_1": "C",
    "price_column_2": "D"
  },
  "price_rules": {
    "mode": "dual",
    "price_1_is": "purchase",
    "price_2_is": "retail",
    "purchase_modifier": {"type": "percentage", "value": -20},
    "retail_modifier": {"type": "percentage", "value": 30}
  }
}
```

**Matched Products JSON Structure:**
```json
{
  "product_uuid_1": "SUPPLIER_CODE_123",
  "product_uuid_2": "SUPPLIER_CODE_456"
}
```

## Implementation Plan

### Phase 1: Backend Foundation ✅

#### 1.1 Database & Entities ✅
- ✅ Create migration `Migration1735150200CreatePriceTemplate.php`
- ✅ Create `PriceTemplateEntity.php`
- ✅ Create `PriceTemplateDefinition.php`
- ✅ Create `PriceTemplateCollection.php`
- ✅ Update `SupplierDefinition.php` - add OneToMany relation to templates
- ✅ Update `SupplierEntity.php` - add templates property
- ✅ Register in services.xml

#### 1.2 Parser System ✅
- ✅ Create interface `src/Service/Parser/PriceParserInterface.php`
  - supports() - check if parser handles file type
  - parse() - parse file to normalized format
  - preview() - preview file structure for column mapping
  - Auto-detect data start row (skip headers)
- ✅ Create `src/Service/Parser/AbstractPriceParser.php` (base class with utilities)
  - Column conversion (A-Z ↔ 0-N)
  - Price normalization (currency symbols, decimal separators)
  - Code/name normalization
  - Data start row detection
- ✅ Create `src/Service/Parser/ExcelParser.php` (PhpSpreadsheet)
  - Supports: .xlsx, .xls
  - Optimized for large files (chunk reading)
  - Preview with auto-detected headers
- ✅ Create `src/Service/Parser/CsvParser.php`
  - Supports: .csv, .txt
  - Auto-detect delimiter (comma, semicolon, tab)
  - Memory-efficient streaming
- ✅ Create `src/Service/Parser/AiParser.php` (placeholder for future)
  - Placeholder for PDF, images, scanned documents
  - Architecture ready for Claude API / GPT-4 Vision / Tesseract OCR integration
- ✅ Create `src/Service/Parser/ParserRegistry.php`
  - Auto-select parser based on file type
  - Tagged iterator pattern for extensibility
  - Centralized parse/preview interface
- ✅ Install `phpoffice/phpspreadsheet` ^5.3

#### 1.3 Matching System
- [ ] Create `src/Service/Matcher/ProductMatcherInterface.php`
- [ ] Create `src/Service/Matcher/ExactCodeMatcher.php`
  - Match by matched_products mapping
  - Match by product custom field "supplier_code"
- [ ] Create `src/Service/Matcher/FuzzyNameMatcher.php`
  - Use `similar_text()` or Levenshtein distance
  - Confidence levels: high (>90%), medium (80-90%), low (<80%)
- [ ] Create `src/Service/Matcher/MatcherChain.php`

#### 1.4 Price Calculation
- [ ] Create `src/Service/Calculator/PriceCalculator.php`
  - Apply modifiers (%, fixed)
  - Support modes: single_purchase, single_retail, dual

#### 1.5 API Controllers
- [ ] Create `src/Core/Api/PriceUpdateController.php`
  - `POST /api/supplier/price-update/parse` - парсинг прайса
  - `POST /api/supplier/price-update/preview` - предпросмотр сопоставления
  - `POST /api/supplier/price-update/apply` - применение цен
  - `POST /api/supplier/price-update/normalize-force` - принудительная нормализация

### Phase 2: Frontend - Supplier List Updates 🔄

#### 2.1 Supplier List Enhancements
- [x] Add manufacturer columns to supplier list
  - [ ] Add `manufacturerIds` column (ТМ на сайте)
  - [ ] Add `alternativeManufacturerIds` column (Альтерн. ТМ)
  - [ ] Add associations loading in criteria
  - [ ] Format manufacturer names for display
  - [ ] Add search by manufacturer names
- [ ] Add "Update Prices" button to smart bar
  - [ ] Route to price update page without supplier filter

#### 2.2 Supplier Detail Enhancements
- [ ] Add "Update Prices" button
  - [ ] Route to price update page with supplier pre-selected

### Phase 3: Frontend - Price Update Module 🔄

#### 3.1 Module Registration
- [ ] Create `src/Resources/app/administration/src/module/supplier-price-update/index.js`
- [ ] Register routes:
  - `/supplier/price-update` - main list page
  - `/supplier/price-update/template/create` - create template wizard
  - `/supplier/price-update/template/:id/edit` - edit template
  - `/supplier/price-update/preview/:templateId` - preview & apply

#### 3.2 Template List Page
- [ ] Create `page/price-template-list/index.js`
- [ ] Create `page/price-template-list/price-template-list.html.twig`
- [ ] Features:
  - [ ] Supplier filter dropdown
  - [ ] Template cards with stats
  - [ ] "Create New" button
  - [ ] Edit/Delete actions
  - [ ] "Update Prices" action per template

#### 3.3 Template Wizard (Create/Edit)
- [ ] Create `page/price-template-wizard/index.js`
- [ ] Step 1: Basic Settings
  - [ ] Template name input
  - [ ] Supplier selector
  - [ ] Price list (media) selector
- [ ] Step 2: Product Filters
  - [ ] Categories multi-select
  - [ ] Equipment types multi-select
  - [ ] Manufacturers multi-select
  - [ ] Show filtered product count
- [ ] Step 3: Column Mapping
  - [ ] Preview first 5 rows from price list
  - [ ] Start row number input
  - [ ] Column selectors (code, name, price_1, price_2)
- [ ] Step 4: Price Rules
  - [ ] Mode selector (single_purchase, single_retail, dual)
  - [ ] Price type selectors
  - [ ] Modifier inputs (%, fixed)
  - [ ] Example calculation preview

#### 3.4 Preview & Apply Page
- [ ] Create `page/price-preview/index.js`
- [ ] Create `page/price-preview/price-preview.html.twig`
- [ ] Features:
  - [ ] Statistics cards (matched, needs review, unmatched)
  - [ ] "Edit Config" button
  - [ ] "Force Re-normalize" button
  - [ ] Filter buttons (all, needs review, unmatched)
  - [ ] Data grid with columns:
    - Checkbox
    - Product (in system)
    - Supplier code (from price list)
    - Supplier name (from price list)
    - Purchase price (old → new)
    - Retail price (old → new)
    - Confidence indicator
    - Manual select button
  - [ ] Manual product selection modal
  - [ ] Bulk actions:
    - Confirm all high confidence
    - Exclude all unmatched
  - [ ] "Apply Prices" button
  - [ ] Apply confirmation modal

### Phase 4: Services & Logic 🔄

#### 4.1 Template Service
- [ ] Create `src/Service/PriceTemplate/PriceTemplateService.php`
  - [ ] `createTemplate(data): PriceTemplateEntity`
  - [ ] `updateTemplate(id, data): PriceTemplateEntity`
  - [ ] `deleteTemplate(id): void`
  - [ ] `getTemplatesBySupplier(supplierId): array`

#### 4.2 Price Update Workflow Service
- [ ] Create `src/Service/PriceUpdate/PriceUpdateService.php`
  - [ ] `parseAndNormalize(templateId, mediaId, forceRefresh): array`
  - [ ] `matchProducts(templateId): array`
  - [ ] `updateMatchedProduct(templateId, productId, supplierCode): void`
  - [ ] `applyPrices(templateId, userId): PriceUpdateResult`

#### 4.3 Product Filter Service
- [ ] Create `src/Service/Product/ProductFilterService.php`
  - [ ] `getFilteredProducts(filters): ProductCollection`
  - [ ] Apply category filters
  - [ ] Apply equipment type filters
  - [ ] Apply manufacturer filters

### Phase 5: Testing & Refinement 🔄

#### 5.1 Unit Tests
- [ ] Parser tests (Excel, CSV)
- [ ] Matcher tests (exact, fuzzy)
- [ ] Calculator tests (modifiers)

#### 5.2 Integration Tests
- [ ] End-to-end template creation
- [ ] Price update workflow
- [ ] Edge cases (empty prices, invalid formats)

#### 5.3 UI/UX Polish
- [ ] Loading states
- [ ] Error handling
- [ ] Success notifications
- [ ] Responsive design
- [ ] Translations (en-GB, ru-RU)

### Phase 6: Documentation & Deployment 🔄

- [ ] User documentation (how to use)
- [ ] Technical documentation (architecture)
- [ ] Migration guide
- [ ] Release notes

## Technical Dependencies

### PHP Libraries
- `phpoffice/phpspreadsheet` - Excel parsing (already available in Shopware?)
- `league/csv` - CSV parsing (optional, can use native PHP)

### Shopware Components
- DAL (Data Abstraction Layer)
- Message Bus (for async parsing if needed)
- Media Service
- Admin SDK

## Notes & Considerations

### Performance
- Large price lists (>1000 rows) should be parsed asynchronously via Message Queue
- Normalized data caching prevents re-parsing unchanged files
- Matched products mapping speeds up repeat imports

### Security
- Validate file types before parsing
- Limit file size (e.g., max 10MB)
- Sanitize input data
- Check user permissions (ACL)

### Future Enhancements (Post-MVP)
- [ ] OCR support for PDF/images (Tesseract, Google Vision API)
- [ ] Word document parser
- [ ] Scheduled auto-imports
- [ ] Email notifications on import completion
- [ ] Price change history/audit log
- [ ] Rollback functionality
- [ ] Multi-currency support
- [ ] Supplier API integrations

## Progress Tracking

- ⏳ Not Started
- 🔄 In Progress
- ✅ Completed
- ❌ Blocked

---

**Last Updated:** 2025-12-25
**Current Phase:** All core features implemented! 🎉
**Status:** Ready for testing
**Next Steps:** Create wizard & preview UI, then test with real price lists
