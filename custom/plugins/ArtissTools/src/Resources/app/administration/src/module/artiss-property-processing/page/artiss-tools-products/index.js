import template from './artiss-tools-products.html.twig';

const { Component } = Shopware;

Component.register('artiss-tools-products', {
    template,
    data() {
        return {
            activeTab: 'merge'
        };
    }
});
