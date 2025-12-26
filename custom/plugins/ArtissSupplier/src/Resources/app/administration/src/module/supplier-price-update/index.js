import './page/price-template-list';
import './page/price-template-create';
import './page/price-template-apply';

const { Module } = Shopware;

Module.register('supplier-price-update', {
    type: 'plugin',
    name: 'supplier-price-update',
    title: 'supplier.priceUpdate.general.mainMenuItemGeneral',
    description: 'supplier.priceUpdate.general.description',
    color: '#ff3d58',
    icon: 'regular-sync',

    routes: {
        index: {
            component: 'price-template-list',
            path: 'index'
        },
        create: {
            component: 'price-template-create',
            path: 'create'
        },
        edit: {
            component: 'price-template-create',
            path: 'edit/:id',
            meta: {
                parentPath: 'supplier.price.update.index'
            }
        },
        apply: {
            component: 'price-template-apply',
            path: 'apply/:id',
            meta: {
                parentPath: 'supplier.price.update.index'
            }
        }
    },

    navigation: [{
        id: 'supplier-price-update',
        label: 'supplier.priceUpdate.general.mainMenuItemGeneral',
        color: '#ff3d58',
        icon: 'regular-sync',
        path: 'supplier.price.update.index',
        parent: 'artiss-supplier'
    }]
});
