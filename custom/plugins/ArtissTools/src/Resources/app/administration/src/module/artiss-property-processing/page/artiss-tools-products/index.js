import template from './artiss-tools-products.html.twig';
import './components/product-merge-tab';

const { Component } = Shopware;

Component.register('artiss-tools-products', {
    template,

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    data() {
        return {
            activeTab: 'merge'
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        }
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
        },

        getAuthHeaders() {
            const loginService = Shopware.Service('loginService');
            const token = loginService.getToken();

            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };

            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }

            return headers;
        }
    }
});
