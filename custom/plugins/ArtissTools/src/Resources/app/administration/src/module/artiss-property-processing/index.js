import './artiss-tools.scss';
import './page/artiss-tools-landing';
import './page/artiss-tools-products';
import './page/artiss-tools-backups';
import './page/artiss-tools-images';
import './page/artiss-property-processing-index';

const { Module } = Shopware;

Module.register('artiss-tools', {
    type: 'plugin',
    name: 'artiss-tools',
    title: 'artissTools.general.title',
    description: 'artissTools.general.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#ff3d58',
    icon: 'regular-cog',
    routePrefixName: 'artiss.tools',
    routePrefixPath: 'artiss/tools',

    routes: {
        index: {
            component: 'artiss-tools-landing',
            path: 'index'
        },
        products: {
            component: 'artiss-tools-products',
            path: 'products',
            meta: {
                parentPath: 'artiss.tools.index'
            }
        },
        backups: {
            component: 'artiss-tools-backups',
            path: 'backups',
            meta: {
                parentPath: 'artiss.tools.index'
            }
        },
        properties: {
            component: 'artiss-property-processing-index',
            path: 'properties',
            meta: {
                parentPath: 'artiss.tools.index'
            }
        },
        images: {
            component: 'artiss-tools-images',
            path: 'images',
            meta: {
                parentPath: 'artiss.tools.index'
            }
        }
    },

    navigation: [{
        id: 'artiss-tools',
        label: 'artissTools.general.mainMenuItem',
        color: '#ff3d58',
        icon: 'regular-cog',
        path: 'artiss.tools.index',
        parent: 'sw-catalogue',
        position: 100
    }, {
        id: 'artiss-tools-products',
        label: 'artissTools.products.menuItem',
        color: '#ff3d58',
        icon: 'regular-products',
        path: 'artiss.tools.products',
        parent: 'artiss-tools',
        position: 10
    }, {
        id: 'artiss-tools-backups',
        label: 'artissTools.backups.menuItem',
        color: '#ff3d58',
        icon: 'regular-database',
        path: 'artiss.tools.backups',
        parent: 'artiss-tools',
        position: 20
    }, {
        id: 'artiss-tools-properties',
        label: 'artissTools.propertyProcessing.menuItem',
        color: '#ff3d58',
        icon: 'regular-tag',
        path: 'artiss.tools.properties',
        parent: 'artiss-tools',
        position: 30
    }, {
        id: 'artiss-tools-images',
        label: 'artissTools.images.menuItem',
        color: '#ff3d58',
        icon: 'regular-image',
        path: 'artiss.tools.images',
        parent: 'artiss-tools',
        position: 40
    }]
});
