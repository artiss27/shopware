import template from './split-tab.html.twig';
import sharedErrorHandler from '../shared-error-handler';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-split-tab', {
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
            selectedSourceGroupId: null,
            selectedTargetGroupId: null,
            sourceGroupOptions: [],
            selectedOptionIds: [],
            splitResult: null,
            showSplitConfirmModal: false,
            showProductsModal: false
        };
    },

    computed: {
        canExecuteSplit() {
            return this.selectedSourceGroupId &&
                   this.selectedTargetGroupId &&
                   this.selectedSourceGroupId !== this.selectedTargetGroupId &&
                   this.selectedOptionIds.length > 0;
        },

        availableTargetGroups() {
            if (!this.selectedSourceGroupId) {
                return this.propertyGroups;
            }
            return this.propertyGroups.filter(group => group.id !== this.selectedSourceGroupId);
        },

        isAllOptionsSelected() {
            if (!this.sourceGroupOptions || this.sourceGroupOptions.length === 0) {
                return false;
            }
            return this.selectedOptionIds.length === this.sourceGroupOptions.length;
        }
    },

    methods: {
        async loadGroupOptions() {
            if (!this.selectedSourceGroupId) {
                this.sourceGroupOptions = [];
                this.selectedOptionIds = [];
                this.splitResult = null;
                return;
            }

            this.isLoading = true;
            this.selectedOptionIds = [];
            this.splitResult = null;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/split/load-group',
                    {
                        groupId: this.selectedSourceGroupId
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.sourceGroupOptions = response.data.data.options || [];
                } else {
                    throw new Error(response.data.error);
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.message || this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
                this.sourceGroupOptions = [];
            } finally {
                this.isLoading = false;
            }
        },

        toggleAllOptions(checked) {
            if (checked) {
                this.selectedOptionIds = this.sourceGroupOptions.map(opt => opt.id);
            } else {
                this.selectedOptionIds = [];
            }
        },

        async previewSplit() {
            if (!this.canExecuteSplit) {
                return;
            }

            this.isLoading = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/split/preview',
                    {
                        sourceGroupId: this.selectedSourceGroupId,
                        targetGroupId: this.selectedTargetGroupId,
                        optionIds: this.selectedOptionIds
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.splitResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.split.messages.previewComplete')
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

        executeSplit() {
            if (!this.canExecuteSplit) {
                return;
            }

            this.showSplitConfirmModal = true;
        },

        async confirmSplit() {
            this.showSplitConfirmModal = false;
            this.isLoading = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/split/execute',
                    {
                        sourceGroupId: this.selectedSourceGroupId,
                        targetGroupId: this.selectedTargetGroupId,
                        optionIds: this.selectedOptionIds
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.splitResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.split.messages.splitSuccess')
                    });
                    await this.loadPropertyGroups();
                    this.$emit('property-groups-changed');
                    this.resetSplitForm();
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

        resetSplitForm() {
            this.selectedSourceGroupId = null;
            this.selectedTargetGroupId = null;
            this.sourceGroupOptions = [];
            this.selectedOptionIds = [];
            this.splitResult = null;
        }
    },

    watch: {
        selectedSourceGroupId(newVal, oldVal) {
            if (newVal) {
                this.loadGroupOptions();
            } else {
                this.sourceGroupOptions = [];
                this.selectedOptionIds = [];
                this.splitResult = null;
            }
            if (newVal !== oldVal && oldVal !== undefined) {
                this.splitResult = null;
            }
        },
        selectedTargetGroupId(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.splitResult = null;
            }
        },
        selectedOptionIds(newVal, oldVal) {
            if (JSON.stringify(newVal) !== JSON.stringify(oldVal) && oldVal !== undefined) {
                this.splitResult = null;
            }
        }
    }
});

