// Register snippets
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import ruRU from './snippet/ru-RU.json';
import ruUA from './snippet/ru-UA.json';
import ukUA from './snippet/uk-UA.json';

// Import pages
import './page/supplier-list';
import './page/supplier-detail';
import './page/supplier-create';

const { Module } = Shopware;

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('ru-RU', ruRU);
Shopware.Locale.extend('ru-UA', ruUA);
Shopware.Locale.extend('uk-UA', ukUA);

Module.register('artiss-supplier', {
    type: 'plugin',
    name: 'supplier',
    title: 'supplier.general.mainMenuItemGeneral',
    description: 'supplier.general.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#ff3d58',
    icon: 'regular-shopping-bag',
    entity: 'supplier',

    routes: {
        index: {
            component: 'supplier-list',
            path: 'index',
        },
        detail: {
            component: 'supplier-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'artiss.supplier.index',
            }
        },
        create: {
            component: 'supplier-create',
            path: 'create',
            meta: {
                parentPath: 'artiss.supplier.index',
            }
        }
    },

    navigation: [{
        id: 'artiss-supplier',
        label: 'supplier.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'artiss.supplier.index',
        icon: 'regular-shopping-bag',
        parent: 'sw-catalogue',
        position: 100,
    }]
});
