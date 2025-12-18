import template from './merge-options-tab.html.twig';
import sharedErrorHandler from '../shared-error-handler';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-merge-options-tab', {
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
            selectedGroupId: null,
            targetOptionId: null,
            sourceOptionIds: [],
            groupOptions: [],
            optionsInfo: {},
            mergeResult: null,
            dryRunMode: true,
            showConfirmModal: false,
            showProductsModal: false
        };
    },

    computed: {
        availableSourceOptions() {
            if (!this.targetOptionId) {
                return this.groupOptions;
            }
            return this.groupOptions.filter(option => option.id !== this.targetOptionId);
        },

        canExecute() {
            return this.selectedGroupId && 
                   this.targetOptionId && 
                   this.sourceOptionIds.length > 0 &&
                   !this.sourceOptionIds.includes(this.targetOptionId);
        },

        targetOptionInfo() {
            if (!this.targetOptionId || !this.optionsInfo[this.targetOptionId]) {
                return null;
            }
            return this.optionsInfo[this.targetOptionId];
        }
    },

    methods: {
        async loadGroupOptions() {
            if (!this.selectedGroupId) {
                this.groupOptions = [];
                this.targetOptionId = null;
                this.sourceOptionIds = [];
                this.optionsInfo = {};
                this.mergeResult = null;
                return;
            }

            this.isLoading = true;
            this.targetOptionId = null;
            this.sourceOptionIds = [];
            this.mergeResult = null;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/merge-options/load-group-options',
                    { groupId: this.selectedGroupId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.groupOptions = response.data.data.options || [];
                    this.optionsInfo = response.data.data.optionsInfo || {};
                } else {
                    throw new Error(response.data.error || 'Failed to load group options');
                }
            } catch (error) {
                const errorMessage = this.handleApiError(error);
                this.createNotificationError({
                    message: errorMessage
                });
                this.groupOptions = [];
                this.optionsInfo = {};
            } finally {
                this.isLoading = false;
            }
        },

        async executeDryRun() {
            if (!this.canExecute) {
                this.validateAndShowErrors();
                return;
            }

            this.isLoading = true;
            this.dryRunMode = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/merge-options/scan',
                    {
                        groupId: this.selectedGroupId,
                        targetOptionId: this.targetOptionId,
                        sourceOptionIds: this.sourceOptionIds,
                        dryRun: true
                    },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.mergeResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.mergeOptions.messages.scanComplete')
                    });
                } else {
                    throw new Error(response.data.error || 'Unknown error');
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

        executeMerge() {
            if (!this.canExecute) {
                this.validateAndShowErrors();
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
                    '/_action/artiss-tools/merge-options/merge',
                    {
                        groupId: this.selectedGroupId,
                        targetOptionId: this.targetOptionId,
                        sourceOptionIds: this.sourceOptionIds,
                        dryRun: false
                    },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.mergeResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.mergeOptions.messages.mergeSuccess')
                    });
                    await this.loadPropertyGroups();
                    this.$emit('property-groups-changed');
                    this.resetForm();
                } else {
                    throw new Error(response.data.error || 'Unknown error');
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

        validateAndShowErrors() {
            if (!this.selectedGroupId) {
                this.createNotificationWarning({
                    message: this.$tc('artissTools.propertyProcessing.mergeOptions.errors.missingGroup')
                });
                return;
            }

            if (!this.targetOptionId) {
                this.createNotificationWarning({
                    message: this.$tc('artissTools.propertyProcessing.mergeOptions.errors.missingTarget')
                });
                return;
            }

            if (this.sourceOptionIds.length === 0) {
                this.createNotificationWarning({
                    message: this.$tc('artissTools.propertyProcessing.mergeOptions.errors.missingSources')
                });
                return;
            }

            if (this.sourceOptionIds.includes(this.targetOptionId)) {
                this.createNotificationWarning({
                    message: this.$tc('artissTools.propertyProcessing.mergeOptions.errors.targetInSources')
                });
                return;
            }
        },

        resetForm() {
            this.selectedGroupId = null;
            this.targetOptionId = null;
            this.sourceOptionIds = [];
            this.groupOptions = [];
            this.optionsInfo = {};
            this.mergeResult = null;
        }
    },

    watch: {
        selectedGroupId(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.targetOptionId = null;
                this.sourceOptionIds = [];
                this.mergeResult = null;
            }
            if (newVal) {
                this.loadGroupOptions();
            } else {
                this.groupOptions = [];
                this.targetOptionId = null;
                this.sourceOptionIds = [];
                this.optionsInfo = {};
                this.mergeResult = null;
            }
        },

        targetOptionId(newVal, oldVal) {
            if (newVal && this.sourceOptionIds.includes(newVal)) {
                this.sourceOptionIds = this.sourceOptionIds.filter(id => id !== newVal);
            }
            if (newVal !== oldVal && oldVal !== undefined) {
                this.mergeResult = null;
            }
        },

        sourceOptionIds(newVal, oldVal) {
            if (JSON.stringify(newVal) !== JSON.stringify(oldVal) && oldVal !== undefined) {
                this.mergeResult = null;
            }
        }
    }
});

