const { Mixin } = Shopware;

export default Mixin.register('artiss-cleanup-methods', {
    data() {
        return {
            includeCustomFields: false,
            cleanupResult: null,
            selectedPropertyOptions: {},
            selectedCustomFields: {},
            selectedCustomFieldSets: [],
            showCleanupConfirmModal: false,
            deleteEmptyGroups: true
        };
    },

    methods: {
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
        }
    }
});
