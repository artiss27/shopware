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
            path: 'products'
        },
        backups: {
            component: 'artiss-tools-backups',
            path: 'backups'
        },
        properties: {
            component: 'artiss-property-processing-index',
            path: 'properties'
        },
        images: {
            component: 'artiss-tools-images',
            path: 'images'
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
    }]
});
