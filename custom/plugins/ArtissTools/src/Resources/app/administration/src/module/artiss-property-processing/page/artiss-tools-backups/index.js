import template from './artiss-tools-backups.html.twig';

const { Component } = Shopware;

Component.register('artiss-tools-backups', {
    template,
    data() {
        return {
            activeTab: 'create'
        };
    }
});
