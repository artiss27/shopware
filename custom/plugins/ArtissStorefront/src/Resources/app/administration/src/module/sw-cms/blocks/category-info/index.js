Shopware.Component.register('sw-cms-block-category-info', () => import('./component'));
Shopware.Component.register('sw-cms-preview-category-info', () => import('./preview'));

Shopware.Service('cmsService').registerCmsBlock({
    name: 'category-info',
    label: 'Інформація про категорію',
    category: 'commerce',
    component: 'sw-cms-block-category-info',
    previewComponent: 'sw-cms-preview-category-info',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
    },
    slots: {
        content: {
            type: 'category-info',
            default: {
                config: {
                    description: { source: 'mapped', value: 'category.description' },
                    media: { source: 'mapped', value: 'category.media' }
                }
            }
        }
    }
});
