import template from './merge-tab.html.twig';
import sharedErrorHandler from '../shared-error-handler';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-merge-tab', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification'),
        sharedErrorHandler
    ],

    props: {
        propertyGroups: {
            type: Array,
            required: true,
            default: () => []
        },
        httpClient: {
            type: Object,
            required: true
        },
        getAuthHeaders: {
            type: Function,
            required: true
        },
        loadPropertyGroups: {
            type: Function,
            required: true
        }
    },

    emits: ['property-groups-changed'],

    data() {
        return {
            isLoading: false,
            targetId: null,
            sourceIds: [],
            mergeResult: null,
            dryRunMode: true,
            showConfirmModal: false
        };
    },

    computed: {
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

    methods: {
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
                const errorMessage = this.handleApiError(error);
                this.createNotificationError({
                    message: errorMessage
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
                    this.$emit('property-groups-changed');
                    this.resetForm();
                } else {
                    throw new Error(response.data.error);
                }
            } catch (error) {
                const errorMessage = this.handleApiError(error);
                this.createNotificationError({
                    message: errorMessage
                });
            } finally {
                this.isLoading = false;
            }
        },

        resetForm() {
            this.targetId = null;
            this.sourceIds = [];
            this.mergeResult = null;
        }
    },

    watch: {
        targetId(newVal, oldVal) {
            if (newVal && this.sourceIds.includes(newVal)) {
                this.sourceIds = this.sourceIds.filter(id => id !== newVal);
            }
            if (newVal !== oldVal && oldVal !== undefined) {
                this.mergeResult = null;
            }
        },
        sourceIds(newVal, oldVal) {
            if (JSON.stringify(newVal) !== JSON.stringify(oldVal) && oldVal !== undefined) {
                this.mergeResult = null;
            }
        }
    }
});

