import template from './artiss-property-processing-index.html.twig';
import './artiss-property-processing-index.scss';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-index', {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            propertyGroups: [],
            targetId: null,
            sourceIds: [],
            mergeResult: null,
            dryRunMode: true,
            showConfirmModal: false,
            activeTab: 'merge'
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        availableSourceGroups() {
            if (!this.targetId) {
                return this.propertyGroups;
            }
            return this.propertyGroups.filter(group => group.id !== this.targetId);
        },

        canExecute() {
            return this.targetId && this.sourceIds.length > 0;
        }
    },

    created() {
        this.activeTab = 'merge';
        this.loadPropertyGroups();
    },

    methods: {
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

        async executeDryRun() {
            if (!this.canExecute) {
                return;
            }

            this.isLoading = true;
            this.dryRunMode = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/merge',
                    {
                        entityType: 'property_group',
                        targetId: this.targetId,
                        sourceIds: this.sourceIds,
                        dryRun: true
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.mergeResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.messages.dryRunComplete')
                    });
                } else {
                    throw new Error(response.data.error);
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.message || this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async executeMerge() {
            if (!this.canExecute) {
                return;
            }

            this.showConfirmModal = true;
        },

        async confirmExecute() {
            this.showConfirmModal = false;
            this.isLoading = true;
            this.dryRunMode = false;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/merge',
                    {
                        entityType: 'property_group',
                        targetId: this.targetId,
                        sourceIds: this.sourceIds,
                        dryRun: false
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.mergeResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.messages.mergeSuccess')
                    });
                    await this.loadPropertyGroups();
                    this.resetForm();
                } else {
                    throw new Error(response.data.error);
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.message || this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        resetForm() {
            this.targetId = null;
            this.sourceIds = [];
            this.mergeResult = null;
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
    },

    watch: {
        targetId(newVal) {
            // Remove target from sourceIds if it was selected there
            if (newVal && this.sourceIds.includes(newVal)) {
                this.sourceIds = this.sourceIds.filter(id => id !== newVal);
            }
        }
    }
});
