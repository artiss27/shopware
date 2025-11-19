// Простой тест для проверки загрузки
console.log('[ArtissSupplier] Plugin loaded!');
console.log('[ArtissSupplier] Shopware:', typeof Shopware);
console.log('[ArtissSupplier] Module:', typeof Shopware.Module);

// Импорт модуля
import './module/supplier';

console.log('[ArtissSupplier] Module imported');
console.log('[ArtissSupplier] Supplier module:', Shopware.Module.getModuleRegistry().get('supplier'));
