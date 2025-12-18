import template from './artiss-tools-backups.html.twig';

const { Component } = Shopware;

Component.register('artiss-tools-backups', {
    template,
    
    data() {
        return {
            activeTab: 'create'
        };
    },

    methods: {
        onTabChange(tabItem) {
            let newTab = 'create';

            if (typeof tabItem === 'string') {
                newTab = tabItem;
            } else if (tabItem && typeof tabItem === 'object') {
                newTab = tabItem.name || tabItem.id || tabItem.key || 'create';
            }

            this.activeTab = newTab;
        }
    }
});
