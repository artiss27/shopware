Shopware.Component.register('sw-cms-block-category-grid', () => import('./component'));
Shopware.Component.register('sw-cms-preview-category-grid', () => import('./preview'));

Shopware.Service('cmsService').registerCmsBlock({
    name: 'category-grid',
    label: 'Сітка підкатегорій',
    category: 'commerce',
    component: 'sw-cms-block-category-grid',
    previewComponent: 'sw-cms-preview-category-grid',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
    },
    slots: {
        content: {
            type: 'category-grid',
            default: {
                config: {
                    showDescription: { source: 'static', value: true },
                    showProductCount: { source: 'static', value: true },
                    descriptionLength: { source: 'static', value: 120 }
                }
            }
        }
    }
});
