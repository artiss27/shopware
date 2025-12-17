import template from './artiss-property-processing-index.html.twig';
import './artiss-property-processing-index.scss';
import './components/merge-methods.mixin';
import './components/split-methods.mixin';
import './components/cleanup-methods.mixin';
import './components/transfer-methods.mixin';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-index', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('artiss-merge-methods'),
        Mixin.getByName('artiss-split-methods'),
        Mixin.getByName('artiss-cleanup-methods'),
        Mixin.getByName('artiss-transfer-methods')
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
        this.loadCustomFieldSets();
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
        }
    }
});
