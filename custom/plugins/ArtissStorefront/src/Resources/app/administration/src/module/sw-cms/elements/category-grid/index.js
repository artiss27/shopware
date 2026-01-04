Shopware.Component.register('sw-cms-el-preview-category-grid', () => import('./preview'));
Shopware.Component.register('sw-cms-el-category-grid', () => import('./component'));
Shopware.Component.register('sw-cms-el-config-category-grid', () => import('./config'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'category-grid',
    label: 'Сітка підкатегорій',
    component: 'sw-cms-el-category-grid',
    configComponent: 'sw-cms-el-config-category-grid',
    previewComponent: 'sw-cms-el-preview-category-grid',
    defaultConfig: {
        showDescription: {
            source: 'static',
            value: true
        },
        showProductCount: {
            source: 'static',
            value: true
        },
        descriptionLength: {
            source: 'static',
            value: 120
        }
    }
});
