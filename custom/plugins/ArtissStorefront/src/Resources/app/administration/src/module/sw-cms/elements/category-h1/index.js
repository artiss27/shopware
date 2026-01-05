Shopware.Component.register('sw-cms-el-preview-category-h1', () => import('./preview'));
Shopware.Component.register('sw-cms-el-category-h1', () => import('./component'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'category-h1',
    label: 'H1 категорії',
    component: 'sw-cms-el-category-h1',
    previewComponent: 'sw-cms-el-preview-category-h1',
    defaultConfig: {}
});
