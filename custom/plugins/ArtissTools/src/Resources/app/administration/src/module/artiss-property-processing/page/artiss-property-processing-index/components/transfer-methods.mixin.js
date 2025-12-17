const { Mixin } = Shopware;

export default Mixin.register('artiss-transfer-methods', {
    data() {
        return {
            transferMode: 'property_to_custom_field',
            customFieldSets: [],
            // Mode 1: Property → Custom field
            transferPropertyGroupId: null,
            transferPropertyOptions: [],
            transferSelectedOptions: [],
            transferTargetSetId: null,
            transferTargetFieldName: null,
            transferTargetFields: [],
            // Mode 2: Property → Property
            transferSourceGroupId: null,
            transferSourceOptions: [],
            transferSourceSelectedOptions: [],
            transferTargetGroupId: null,
            // Mode 3: Custom field → Property
            transferCFSourceSetId: null,
            transferCFSourceFieldName: null,
            transferCFSourceFields: [],
            transferCFTargetGroupId: null,
            // Mode 4: Custom field → Custom field
            transferCF2SourceSetId: null,
            transferCF2SourceFieldName: null,
            transferCF2SourceFields: [],
            transferCF2TargetSetId: null,
            transferCF2TargetFieldName: null,
            transferCF2TargetFields: [],
            // Common
            transferMove: false,
            transferDeleteEmpty: false,
            transferResult: null,
            showTransferConfirm: false
        };
    },

    computed: {
        customFieldSetRepository() {
            return this.repositoryFactory.create('custom_field_set');
        }
    },

    methods: {
        async loadCustomFieldSets() {
            try {
                const criteria = new Shopware.Data.Criteria();
                criteria.addAssociation('customFields');
                criteria.addFilter(
                    Shopware.Data.Criteria.equals('relations.entityName', 'product')
                );

                const result = await this.customFieldSetRepository.search(criteria, Shopware.Context.api);
                this.customFieldSets = result;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            }
        },

        async loadTransferPropertyOptions() {
            if (!this.transferPropertyGroupId) {
                this.transferPropertyOptions = [];
                return;
            }

            this.isLoading = true;
            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-group-options',
                    { groupId: this.transferPropertyGroupId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferPropertyOptions = response.data.data;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTransferSourceOptions() {
            if (!this.transferSourceGroupId) {
                this.transferSourceOptions = [];
                return;
            }

            this.isLoading = true;
            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-group-options',
                    { groupId: this.transferSourceGroupId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferSourceOptions = response.data.data;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTransferTargetFields() {
            if (!this.transferTargetSetId) {
                this.transferTargetFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: this.transferTargetSetId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferTargetFields = response.data.data;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTransferCFSourceFields() {
            if (!this.transferCFSourceSetId) {
                this.transferCFSourceFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: this.transferCFSourceSetId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferCFSourceFields = response.data.data;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTransferCF2SourceFields() {
            if (!this.transferCF2SourceSetId) {
                this.transferCF2SourceFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: this.transferCF2SourceSetId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferCF2SourceFields = response.data.data;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTransferCF2TargetFields() {
            if (!this.transferCF2TargetSetId) {
                this.transferCF2TargetFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: this.transferCF2TargetSetId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferCF2TargetFields = response.data.data;
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('artissTools.propertyProcessing.errors.loadFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        toggleAllTransferOptions(checked) {
            if (this.transferMode === 'property_to_custom_field') {
                this.transferSelectedOptions = checked ? this.transferPropertyOptions.map(o => o.id) : [];
            } else if (this.transferMode === 'property_to_property') {
                this.transferSourceSelectedOptions = checked ? this.transferSourceOptions.map(o => o.id) : [];
            }
        },

        async previewTransfer() {
            this.isLoading = true;

            try {
                const params = this.buildTransferParams();
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/preview',
                    params,
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.transfer.messages.previewComplete')
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

        executeTransfer() {
            this.showTransferConfirm = true;
        },

        async confirmTransfer() {
            this.showTransferConfirm = false;
            this.isLoading = true;

            try {
                const params = this.buildTransferParams();
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/execute',
                    params,
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferResult = response.data.data;
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.propertyProcessing.transfer.messages.executeSuccess')
                    });
                    this.resetTransferForm();
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

        buildTransferParams() {
            const params = {
                mode: this.transferMode,
                move: this.transferMove,
                deleteEmptySource: this.transferDeleteEmpty
            };

            switch (this.transferMode) {
                case 'property_to_custom_field':
                    params.sourceGroupId = this.transferPropertyGroupId;
                    params.optionIds = this.transferSelectedOptions;
                    params.targetFieldName = this.transferTargetFieldName;
                    break;

                case 'property_to_property':
                    params.sourceGroupId = this.transferSourceGroupId;
                    params.targetGroupId = this.transferTargetGroupId;
                    params.optionIds = this.transferSourceSelectedOptions;
                    break;

                case 'custom_field_to_property':
                    params.sourceFieldName = this.transferCFSourceFieldName;
                    params.targetGroupId = this.transferCFTargetGroupId;
                    break;

                case 'custom_field_to_custom_field':
                    params.sourceFieldName = this.transferCF2SourceFieldName;
                    params.targetFieldName = this.transferCF2TargetFieldName;
                    break;
            }

            return params;
        },

        resetTransferForm() {
            this.transferPropertyGroupId = null;
            this.transferPropertyOptions = [];
            this.transferSelectedOptions = [];
            this.transferTargetSetId = null;
            this.transferTargetFieldName = null;
            this.transferTargetFields = [];
            this.transferSourceGroupId = null;
            this.transferSourceOptions = [];
            this.transferSourceSelectedOptions = [];
            this.transferTargetGroupId = null;
            this.transferCFSourceSetId = null;
            this.transferCFSourceFieldName = null;
            this.transferCFSourceFields = [];
            this.transferCFTargetGroupId = null;
            this.transferCF2SourceSetId = null;
            this.transferCF2SourceFieldName = null;
            this.transferCF2SourceFields = [];
            this.transferCF2TargetSetId = null;
            this.transferCF2TargetFieldName = null;
            this.transferCF2TargetFields = [];
            this.transferMove = false;
            this.transferDeleteEmpty = false;
            this.transferResult = null;
        }
    },

    watch: {
        transferPropertyGroupId(newVal) {
            if (newVal) {
                this.loadTransferPropertyOptions();
            }
        },

        transferSourceGroupId(newVal) {
            if (newVal) {
                this.loadTransferSourceOptions();
            }
        },

        transferTargetSetId(newVal) {
            if (newVal) {
                this.loadTransferTargetFields();
            }
        },

        transferCFSourceSetId(newVal) {
            if (newVal) {
                this.loadTransferCFSourceFields();
            }
        },

        transferCF2SourceSetId(newVal) {
            if (newVal) {
                this.loadTransferCF2SourceFields();
            }
        },

        transferCF2TargetSetId(newVal) {
            if (newVal) {
                this.loadTransferCF2TargetFields();
            }
        },

        transferMode() {
            this.transferResult = null;
        }
    }
});
