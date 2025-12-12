import template from './artiss-tools-images.html.twig';

const { Component } = Shopware;

Component.register('artiss-tools-images', {
    template,
    data() {
        return {
            activeTab: 'cleanup'
        };
    }
});
