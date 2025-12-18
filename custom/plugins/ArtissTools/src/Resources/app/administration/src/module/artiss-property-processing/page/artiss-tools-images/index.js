import template from './artiss-tools-images.html.twig';

const { Component } = Shopware;

Component.register('artiss-tools-images', {
    template,
    
    data() {
        return {
            activeTab: 'cleanup'
        };
    },

    methods: {
        onTabChange(tabItem) {
            let newTab = 'cleanup';

            if (typeof tabItem === 'string') {
                newTab = tabItem;
            } else if (tabItem && typeof tabItem === 'object') {
                newTab = tabItem.name || tabItem.id || tabItem.key || 'cleanup';
            }

            this.activeTab = newTab;
        }
    }
});
