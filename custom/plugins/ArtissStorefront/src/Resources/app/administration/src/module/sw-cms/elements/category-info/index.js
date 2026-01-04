Shopware.Component.register('sw-cms-el-preview-category-info', () => import('./preview'));
Shopware.Component.register('sw-cms-el-category-info', () => import('./component'));
Shopware.Component.register('sw-cms-el-config-category-info', () => import('./config'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'category-info',
    label: 'Інформація про категорію',
    component: 'sw-cms-el-category-info',
    configComponent: 'sw-cms-el-config-category-info',
    previewComponent: 'sw-cms-el-preview-category-info',
    defaultConfig: {
        description: {
            source: 'mapped',
            value: 'category.description'
        },
        media: {
            source: 'mapped',
            value: 'category.media'
        }
    }
});
