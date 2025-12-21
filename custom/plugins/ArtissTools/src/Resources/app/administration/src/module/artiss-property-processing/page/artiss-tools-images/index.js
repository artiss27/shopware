import template from './artiss-tools-images.html.twig';
import './components/cleanup-tab';
import './components/duplicates-tab';

const { Component } = Shopware;

Component.register('artiss-tools-images', {
    template,
    
    data() {
        return {
            activeTab: 'cleanup'
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        }
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
