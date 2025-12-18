import template from './artiss-tools-products.html.twig';

const { Component } = Shopware;

Component.register('artiss-tools-products', {
    template,
    
    data() {
        return {
            activeTab: 'merge'
        };
    },

    methods: {
        onTabChange(tabItem) {
            let newTab = 'merge';

            if (typeof tabItem === 'string') {
                newTab = tabItem;
            } else if (tabItem && typeof tabItem === 'object') {
                newTab = tabItem.name || tabItem.id || tabItem.key || 'merge';
            }

            this.activeTab = newTab;
        }
    }
});
