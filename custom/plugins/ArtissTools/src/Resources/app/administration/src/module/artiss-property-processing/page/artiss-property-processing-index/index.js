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
            activeTab: 'merge',
            // Cleanup tab data
            includeCustomFields: false,
            cleanupResult: null,
            selectedPropertyOptions: {},
            selectedCustomFields: {},
            selectedCustomFieldSets: [],
            showCleanupConfirmModal: false,
            deleteEmptyGroups: true,
            // Split/Transfer tab data
            selectedSourceGroupId: null,
            selectedTargetGroupId: null,
            sourceGroupOptions: [],
            selectedOptionIds: [],
            splitResult: null,
            showSplitConfirmModal: false
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
        },

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

    created() {
        this.loadPropertyGroups();
    },

    methods: {
        onTabChange(tabItem) {
            // Handle different possible formats
            let newTab = 'merge'; // default

            if (typeof tabItem === 'string') {
                newTab = tabItem;
            } else if (tabItem && typeof tabItem === 'object') {
                // Try different properties
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
        },

        // Cleanup tab methods
        async scanUnused() {
            this.isLoading = true;
            this.cleanupResult = null;
            this.selectedPropertyOptions = {};
            this.selectedCustomFields = {};
            this.selectedCustomFieldSets = [];

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/cleanup/scan',
                    {
                        includeCustomFields: this.includeCustomFields
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.cleanupResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.cleanup.messages.scanComplete')
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

        toggleAllGroupOptions(groupId, checked) {
            if (!this.cleanupResult) return;

            const group = this.cleanupResult.unusedPropertyOptions.find(g => g.groupId === groupId);
            if (!group) return;

            const updated = { ...this.selectedPropertyOptions };
            group.unusedOptions.forEach(option => {
                updated[option.optionId] = checked;
            });
            this.selectedPropertyOptions = updated;
        },

        toggleAllSetFields(setId, checked) {
            if (!this.cleanupResult) return;

            const set = this.cleanupResult.unusedCustomFields.find(s => s.setId === setId);
            if (!set) return;

            const updated = { ...this.selectedCustomFields };
            set.unusedFields.forEach(field => {
                updated[field.fieldId] = checked;
            });
            this.selectedCustomFields = updated;
        },

        isGroupFullySelected(groupId) {
            if (!this.cleanupResult) return false;

            const group = this.cleanupResult.unusedPropertyOptions.find(g => g.groupId === groupId);
            if (!group || !group.unusedOptions.length) return false;

            return group.unusedOptions.every(option => this.selectedPropertyOptions[option.optionId]);
        },

        isSetFullySelected(setId) {
            if (!this.cleanupResult) return false;

            const set = this.cleanupResult.unusedCustomFields.find(s => s.setId === setId);
            if (!set || !set.unusedFields.length) return false;

            return set.unusedFields.every(field => this.selectedCustomFields[field.fieldId]);
        },

        getSelectedCount() {
            const propertyCount = Object.values(this.selectedPropertyOptions).filter(v => v).length;
            const customFieldCount = Object.values(this.selectedCustomFields).filter(v => v).length;
            const setCount = this.selectedCustomFieldSets.length;

            return propertyCount + customFieldCount + setCount;
        },

        openCleanupConfirmModal() {
            if (this.getSelectedCount() === 0) {
                this.createNotificationWarning({
                    message: this.$tc('artissTools.propertyProcessing.cleanup.messages.nothingSelected')
                });
                return;
            }

            this.showCleanupConfirmModal = true;
        },

        async confirmCleanup() {
            this.showCleanupConfirmModal = false;
            this.isLoading = true;

            try {
                const propertyOptionIds = Object.keys(this.selectedPropertyOptions)
                    .filter(id => this.selectedPropertyOptions[id]);

                const customFieldIds = Object.keys(this.selectedCustomFields)
                    .filter(id => this.selectedCustomFields[id]);

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/cleanup/delete',
                    {
                        propertyOptionIds,
                        customFieldIds,
                        customFieldSetIds: this.selectedCustomFieldSets,
                        deleteEmptyGroups: this.deleteEmptyGroups
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    const result = response.data.data;

                    let message = this.$tc('artissTools.propertyProcessing.cleanup.messages.deleteSuccess');

                    if (result.deletedPropertyOptions.length > 0) {
                        message += ` ${this.$tc('artissTools.propertyProcessing.cleanup.messages.deletedOptions', result.deletedPropertyOptions.length)}`;
                    }

                    if (result.deletedPropertyGroups.length > 0) {
                        message += ` ${this.$tc('artissTools.propertyProcessing.cleanup.messages.deletedGroups', result.deletedPropertyGroups.length)}`;
                    }

                    if (result.deletedCustomFields.length > 0) {
                        message += ` ${this.$tc('artissTools.propertyProcessing.cleanup.messages.deletedFields', result.deletedCustomFields.length)}`;
                    }

                    if (result.deletedCustomFieldSets.length > 0) {
                        message += ` ${this.$tc('artissTools.propertyProcessing.cleanup.messages.deletedSets', result.deletedCustomFieldSets.length)}`;
                    }

                    this.createNotificationSuccess({ message });

                    // Rescan after deletion
                    await this.scanUnused();

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

        resetCleanupForm() {
            this.cleanupResult = null;
            this.selectedPropertyOptions = {};
            this.selectedCustomFields = {};
            this.selectedCustomFieldSets = [];
            this.includeCustomFields = false;
        },

        // Split/Transfer tab methods
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
                this.createNotificationError({
                    message: error.message || this.$tc('artissTools.propertyProcessing.errors.loadFailed')
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
                    this.resetSplitForm();
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

        resetSplitForm() {
            this.selectedSourceGroupId = null;
            this.selectedTargetGroupId = null;
            this.sourceGroupOptions = [];
            this.selectedOptionIds = [];
            this.splitResult = null;
        }
    },

    watch: {
        targetId(newVal) {
            // Remove target from sourceIds if it was selected there
            if (newVal && this.sourceIds.includes(newVal)) {
                this.sourceIds = this.sourceIds.filter(id => id !== newVal);
            }
        },

        selectedSourceGroupId(newVal) {
            if (newVal) {
                this.loadGroupOptions();
            } else {
                this.sourceGroupOptions = [];
                this.selectedOptionIds = [];
                this.splitResult = null;
            }
        }
    }
});
