import template from './artiss-property-processing-index.html.twig';
import './artiss-property-processing-index.scss';
import './components/merge-tab';
import './components/split-tab';
import './components/transfer-tab';
import './components/cleanup-tab';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-index', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            propertyGroups: [],
            activeTab: 'merge'
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        }
    },

    created() {
        this.loadPropertyGroups();
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

        async loadPropertyGroups() {
            this.isLoading = true;

            try {
                const response = await this.httpClient.get(
                    '/_action/artiss-tools/property-groups',
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data && response.data.success) {
                    this.propertyGroups = response.data.data || [];
                }
            } catch (error) {
                console.error('Failed to load property groups:', error);
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
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
        },

        onPropertyGroupsChanged() {
            this.loadPropertyGroups();
        }
    }
});
