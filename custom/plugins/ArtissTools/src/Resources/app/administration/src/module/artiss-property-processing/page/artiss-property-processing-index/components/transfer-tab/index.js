import template from './transfer-tab.html.twig';
import sharedErrorHandler from '../shared-error-handler';

const { Component, Mixin } = Shopware;

Component.register('artiss-property-processing-transfer-tab', {
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
            showTransferConfirm: false,
            dryRunMode: false
        };
    },

    computed: {
        customFieldSetRepository() {
            return this.repositoryFactory.create('custom_field_set');
        }
    },

    created() {
        this.loadCustomFieldSets();
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
                // Convert EntityCollection to array
                // result is an EntityCollection which can be iterated
                const setsArray = [];
                result.forEach((entity) => {
                    setsArray.push({
                        id: entity.id,
                        name: entity.name || entity.translated?.name || ''
                    });
                });
                this.customFieldSets = setsArray;
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
                const groupId = typeof this.transferPropertyGroupId === 'string' 
                    ? this.transferPropertyGroupId 
                    : this.transferPropertyGroupId?.id || this.transferPropertyGroupId;
                
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-group-options',
                    { groupId: groupId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferPropertyOptions = response.data.data;
                } else {
                    throw new Error(response.data.error || 'Failed to load property options');
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

        async loadTransferSourceOptions() {
            if (!this.transferSourceGroupId) {
                this.transferSourceOptions = [];
                return;
            }

            this.isLoading = true;
            try {
                const groupId = typeof this.transferSourceGroupId === 'string' 
                    ? this.transferSourceGroupId 
                    : this.transferSourceGroupId?.id || this.transferSourceGroupId;
                
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-group-options',
                    { groupId: groupId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferSourceOptions = response.data.data;
                } else {
                    throw new Error(response.data.error || 'Failed to load source options');
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

        async loadTransferTargetFields() {
            if (!this.transferTargetSetId) {
                this.transferTargetFields = [];
                return;
            }

            this.isLoading = true;
            try {
                // Ensure we pass a string ID, not an object
                const setId = typeof this.transferTargetSetId === 'string' 
                    ? this.transferTargetSetId 
                    : this.transferTargetSetId?.id || this.transferTargetSetId;
                
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: setId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferTargetFields = response.data.data;
                } else {
                    throw new Error(response.data.error || 'Failed to load custom fields');
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

        async loadTransferCFSourceFields() {
            if (!this.transferCFSourceSetId) {
                this.transferCFSourceFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const setId = typeof this.transferCFSourceSetId === 'string' 
                    ? this.transferCFSourceSetId 
                    : this.transferCFSourceSetId?.id || this.transferCFSourceSetId;
                
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: setId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferCFSourceFields = response.data.data;
                } else {
                    throw new Error(response.data.error || 'Failed to load custom fields');
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

        async loadTransferCF2SourceFields() {
            if (!this.transferCF2SourceSetId) {
                this.transferCF2SourceFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const setId = typeof this.transferCF2SourceSetId === 'string' 
                    ? this.transferCF2SourceSetId 
                    : this.transferCF2SourceSetId?.id || this.transferCF2SourceSetId;
                
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: setId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferCF2SourceFields = response.data.data;
                } else {
                    throw new Error(response.data.error || 'Failed to load custom fields');
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

        async loadTransferCF2TargetFields() {
            if (!this.transferCF2TargetSetId) {
                this.transferCF2TargetFields = [];
                return;
            }

            this.isLoading = true;
            try {
                const setId = typeof this.transferCF2TargetSetId === 'string' 
                    ? this.transferCF2TargetSetId 
                    : this.transferCF2TargetSetId?.id || this.transferCF2TargetSetId;
                
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/transfer/load-custom-fields',
                    { setId: setId },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.transferCF2TargetFields = response.data.data;
                } else {
                    throw new Error(response.data.error || 'Failed to load custom fields');
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

        toggleAllTransferOptions(checked) {
            if (this.transferMode === 'property_to_custom_field') {
                this.transferSelectedOptions = checked ? this.transferPropertyOptions.map(o => o.id) : [];
            } else if (this.transferMode === 'property_to_property') {
                this.transferSourceSelectedOptions = checked ? this.transferSourceOptions.map(o => o.id) : [];
            }
        },

        async previewTransfer() {
            // Validate required fields before sending
            if (!this.validateTransferParams()) {
                return;
            }

            this.isLoading = true;
            this.dryRunMode = true;

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

        executeTransfer() {
            this.showTransferConfirm = true;
        },

        async confirmTransfer() {
            // Validate required fields before sending
            if (!this.validateTransferParams()) {
                this.showTransferConfirm = false;
                return;
            }

            this.showTransferConfirm = false;
            this.isLoading = true;
            this.dryRunMode = false;

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
                    await this.loadPropertyGroups();
                    this.$emit('property-groups-changed');
                    this.resetTransferForm();
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

        validateTransferParams() {
            switch (this.transferMode) {
                case 'property_to_custom_field':
                    if (!this.transferPropertyGroupId) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingSourceGroup')
                        });
                        return false;
                    }
                    if (!this.transferSelectedOptions || this.transferSelectedOptions.length === 0) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingOptions')
                        });
                        return false;
                    }
                    if (!this.transferTargetFieldName) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingTargetField')
                        });
                        return false;
                    }
                    break;

                case 'property_to_property':
                    if (!this.transferSourceGroupId) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingSourceGroup')
                        });
                        return false;
                    }
                    if (!this.transferTargetGroupId) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingTargetGroup')
                        });
                        return false;
                    }
                    if (!this.transferSourceSelectedOptions || this.transferSourceSelectedOptions.length === 0) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingOptions')
                        });
                        return false;
                    }
                    break;

                case 'custom_field_to_property':
                    if (!this.transferCFSourceFieldName) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingSourceField')
                        });
                        return false;
                    }
                    if (!this.transferCFTargetGroupId) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingTargetGroup')
                        });
                        return false;
                    }
                    break;

                case 'custom_field_to_custom_field':
                    if (!this.transferCF2SourceFieldName) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingSourceField')
                        });
                        return false;
                    }
                    if (!this.transferCF2TargetFieldName) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.missingTargetField')
                        });
                        return false;
                    }
                    // Validate that source and target fields are different
                    if (this.transferCF2SourceSetId === this.transferCF2TargetSetId && 
                        this.transferCF2SourceFieldName === this.transferCF2TargetFieldName) {
                        this.createNotificationWarning({
                            message: this.$tc('artissTools.propertyProcessing.transfer.errors.sameSourceAndTargetField')
                        });
                        return false;
                    }
                    break;
            }
            return true;
        },

        buildTransferParams() {
            const params = {
                mode: this.transferMode,
                move: this.transferMove,
                deleteEmptySource: this.transferDeleteEmpty
            };

            // Helper to extract ID string from value
            const extractId = (value) => {
                if (!value) return null;
                if (typeof value === 'string') return value;
                if (typeof value === 'object' && value.id) return value.id;
                return String(value);
            };

            // Helper to ensure optionIds is array of strings
            const normalizeOptionIds = (options) => {
                if (!Array.isArray(options)) return [];
                return options.map(opt => {
                    if (typeof opt === 'string') return opt;
                    if (typeof opt === 'object' && opt.id) return opt.id;
                    return String(opt);
                }).filter(id => id && id.length > 0);
            };

            switch (this.transferMode) {
                case 'property_to_custom_field':
                    params.sourceGroupId = extractId(this.transferPropertyGroupId);
                    params.optionIds = normalizeOptionIds(this.transferSelectedOptions);
                    params.targetFieldName = this.transferTargetFieldName || null;
                    break;

                case 'property_to_property':
                    params.sourceGroupId = extractId(this.transferSourceGroupId);
                    params.targetGroupId = extractId(this.transferTargetGroupId);
                    params.optionIds = normalizeOptionIds(this.transferSourceSelectedOptions);
                    break;

                case 'custom_field_to_property':
                    params.sourceFieldName = this.transferCFSourceFieldName || null;
                    params.targetGroupId = extractId(this.transferCFTargetGroupId);
                    break;

                case 'custom_field_to_custom_field':
                    params.sourceFieldName = this.transferCF2SourceFieldName || null;
                    params.targetFieldName = this.transferCF2TargetFieldName || null;
                    break;
            }

            // Don't remove optionIds even if empty - let backend validate it
            // But remove other null/undefined values
            Object.keys(params).forEach(key => {
                if (key === 'optionIds') {
                    // Always keep optionIds array, even if empty
                    return;
                }
                if (params[key] === null || params[key] === undefined) {
                    delete params[key];
                }
            });

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
        transferPropertyGroupId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.transferSelectedOptions = [];
                this.transferResult = null;
                this.loadTransferPropertyOptions();
            } else if (!newVal) {
                this.transferPropertyOptions = [];
                this.transferSelectedOptions = [];
                this.transferResult = null;
            }
        },

        transferSelectedOptions() {
            this.transferResult = null;
        },

        transferSourceGroupId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.transferSourceSelectedOptions = [];
                this.transferResult = null;
                this.loadTransferSourceOptions();
            } else if (!newVal) {
                this.transferSourceOptions = [];
                this.transferSourceSelectedOptions = [];
                this.transferResult = null;
            }
        },

        transferSourceSelectedOptions() {
            this.transferResult = null;
        },

        transferTargetGroupId(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
            }
        },

        transferTargetSetId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.transferTargetFieldName = null;
                this.transferResult = null;
                this.loadTransferTargetFields();
            } else if (!newVal) {
                this.transferTargetFields = [];
                this.transferTargetFieldName = null;
                this.transferResult = null;
            }
        },

        transferTargetFieldName(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
            }
        },

        transferCFSourceSetId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.transferCFSourceFieldName = null;
                this.transferResult = null;
                this.loadTransferCFSourceFields();
            } else if (!newVal) {
                this.transferCFSourceFields = [];
                this.transferCFSourceFieldName = null;
                this.transferResult = null;
            }
        },

        transferCFSourceFieldName(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
            }
        },

        transferCFTargetGroupId(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
            }
        },

        transferCF2SourceSetId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.transferCF2SourceFieldName = null;
                this.transferResult = null;
                // Clear target field if same set and same field name
                if (this.transferCF2TargetSetId === newVal && 
                    this.transferCF2SourceFieldName === this.transferCF2TargetFieldName) {
                    this.transferCF2TargetFieldName = null;
                }
                this.loadTransferCF2SourceFields();
            } else if (!newVal) {
                this.transferCF2SourceFields = [];
                this.transferCF2SourceFieldName = null;
                this.transferResult = null;
            }
        },

        transferCF2SourceFieldName(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
                // Clear target field if same set and same field name
                if (this.transferCF2SourceSetId === this.transferCF2TargetSetId && 
                    newVal === this.transferCF2TargetFieldName) {
                    this.transferCF2TargetFieldName = null;
                }
            }
        },

        transferCF2TargetSetId(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.transferCF2TargetFieldName = null;
                this.transferResult = null;
                // Clear target field if same set and same field name
                if (this.transferCF2SourceSetId === newVal && 
                    this.transferCF2SourceFieldName === this.transferCF2TargetFieldName) {
                    this.transferCF2TargetFieldName = null;
                }
                this.loadTransferCF2TargetFields();
            } else if (!newVal) {
                this.transferCF2TargetFields = [];
                this.transferCF2TargetFieldName = null;
                this.transferResult = null;
            }
        },

        transferCF2TargetFieldName(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
                // Clear source field if same set and same field name
                if (this.transferCF2SourceSetId === this.transferCF2TargetSetId && 
                    newVal === this.transferCF2SourceFieldName) {
                    this.transferCF2SourceFieldName = null;
                }
            }
        },

        transferMove(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
            }
        },

        transferDeleteEmpty(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
            }
        },

        transferMode(newVal, oldVal) {
            if (newVal !== oldVal && oldVal !== undefined) {
                this.transferResult = null;
                this.dryRunMode = false;
                // Reset only result and selections when mode changes, not the entire form
                this.transferSelectedOptions = [];
                this.transferSourceSelectedOptions = [];
                this.transferPropertyOptions = [];
                this.transferSourceOptions = [];
                this.transferTargetFields = [];
                this.transferCFSourceFields = [];
                this.transferCF2SourceFields = [];
                this.transferCF2TargetFields = [];
            }
        }
    }
});

