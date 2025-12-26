import template from './price-template-create.html.twig';
import './price-template-create.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { mapPropertyErrors } = Component.getComponentHelper();

Component.register('price-template-create', {
    template,

    inject: ['repositoryFactory', 'priceUpdateService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            template: null,
            supplier: null,
            isLoading: false,
            isSaving: false,
            currentStep: 1,
            uploadTag: 'price-template-upload',
            mediaFolderId: null,
            filePreview: null,
            isLoadingPreview: false,
            previewOffset: 0,
            previewLimit: 50,
            saveSuccess: false,
            matchPreviewData: null,
            isLoadingMatchPreview: false,
            isAutoMatching: false,
            isApplyingPrices: false,
            equipmentTypes: [],
            equipmentTypePropertyGroupId: '20836795-aab8-97d8-c709-a2535f197268',
            hasRedirected: false,
            columnTypeOptions: [
                { value: 'product_code', label: this.$tc('supplier.priceUpdate.wizard.columnTypeProductCode') },
                { value: 'product_name', label: this.$tc('supplier.priceUpdate.wizard.columnTypeProductName') },
                { value: 'purchase_price', label: this.$tc('supplier.priceUpdate.wizard.columnTypePurchasePrice') },
                { value: 'retail_price', label: this.$tc('supplier.priceUpdate.wizard.columnTypeRetailPrice') },
                { value: 'list_price', label: this.$tc('supplier.priceUpdate.wizard.columnTypeListPrice') }
            ],
            currencyOptions: [],
            allSelectedColumnTypes: new Set() // Track all selected column types across all columns
        };
    },

    computed: {
        templateRepository() {
            return this.repositoryFactory.create('art_supplier_price_template');
        },

        supplierRepository() {
            return this.repositoryFactory.create('art_supplier');
        },

        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        mediaFolderRepository() {
            return this.repositoryFactory.create('media_folder');
        },

        categoryRepository() {
            return this.repositoryFactory.create('category');
        },

        manufacturerRepository() {
            return this.repositoryFactory.create('product_manufacturer');
        },

        currencyRepository() {
            return this.repositoryFactory.create('currency');
        },

        categoryCriteria() {
            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            return criteria;
        },

        manufacturerCriteria() {
            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            return criteria;
        },

        isEdit() {
            return !!this.$route.params.id;
        },

        canProceedToNextStep() {
            switch (this.currentStep) {
                case 1:
                    return this.template?.name && this.template?.supplierId;
                case 2:
                    return true; // Always allow proceeding from step 2
                case 3:
                    return true;
                default:
                    return false;
            }
        },

        supplierMediaItems() {
            if (!this.supplier || !this.supplier.media) {
                return [];
            }
            return Array.from(this.supplier.media);
        },

        mediaColumns() {
            return [
                {
                    property: 'isActive',
                    label: this.$tc('supplier.priceUpdate.wizard.columnActive'),
                    allowResize: false,
                    width: '60px'
                },
                {
                    property: 'fileName',
                    label: this.$tc('supplier.priceUpdate.wizard.columnFileName'),
                    allowResize: true,
                    primary: true
                },
                {
                    property: 'uploadedAt',
                    label: this.$tc('supplier.priceUpdate.wizard.columnUploadDate'),
                    allowResize: true
                },
                {
                    property: 'fileSize',
                    label: this.$tc('supplier.priceUpdate.wizard.columnFileSize'),
                    allowResize: true
                }
            ];
        },

        priceModeOptions() {
            return [
                { value: 'dual', label: this.$tc('supplier.priceUpdate.wizard.priceModeDual') },
                { value: 'single_purchase', label: this.$tc('supplier.priceUpdate.wizard.priceModeSinglePurchase') },
                { value: 'single_retail', label: this.$tc('supplier.priceUpdate.wizard.priceModeSingleRetail') }
            ];
        },

        modifierTypeOptions() {
            return [
                { value: 'none', label: this.$tc('supplier.priceUpdate.wizard.modifierTypeNone') },
                { value: 'percentage', label: this.$tc('supplier.priceUpdate.wizard.modifierTypePercentage') },
                { value: 'fixed', label: this.$tc('supplier.priceUpdate.wizard.modifierTypeFixed') }
            ];
        },

        equipmentTypeOptions() {
            return this.equipmentTypes.map(option => ({
                value: option.id,
                label: option.translated?.name || option.name || option.id
            }));
        },

        safeEquipmentTypeIds: {
            get() {
                if (!this.template?.config?.filters?.equipment_types) {
                    return [];
                }
                if (!Array.isArray(this.template.config.filters.equipment_types)) {
                    return [];
                }
                return this.template.config.filters.equipment_types;
            },
            set(value) {
                if (this.template?.config?.filters) {
                    this.template.config.filters.equipment_types = Array.isArray(value) ? value : [];
                }
            }
        },

        canAddModifier() {
            const modifiers = this.template?.config?.modifiers || [];
            const usedPriceTypes = modifiers.map(m => m.price_type);
            const allPriceTypes = ['purchase', 'retail', 'list'];
            return usedPriceTypes.length < allPriceTypes.length;
        },

        hasPendingMatches() {
            if (!this.matchPreviewData) return false;
            return this.matchPreviewData.some(item => item.status === 'pending');
        },

        matchPreviewColumns() {
            return [
                {
                    property: 'code',
                    label: this.$tc('supplier.priceUpdate.apply.columnCode'),
                    allowResize: true
                },
                {
                    property: 'name',
                    label: this.$tc('supplier.priceUpdate.apply.columnName'),
                    allowResize: true
                },
                {
                    property: 'product',
                    label: this.$tc('supplier.priceUpdate.apply.columnProduct'),
                    allowResize: true
                },
                {
                    property: 'price1',
                    label: this.$tc('supplier.priceUpdate.apply.columnPrice1'),
                    allowResize: true
                },
                {
                    property: 'price2',
                    label: this.$tc('supplier.priceUpdate.apply.columnPrice2'),
                    allowResize: true
                }
            ];
        },

        ...mapPropertyErrors('template', ['name', 'supplierId'])
    },

    created() {
        this.loadData();
        this.loadMediaFolder();
        this.loadEquipmentTypes();
        this.loadCurrencies();
    },

    methods: {
        async loadData() {
            this.isLoading = true;
            try {
                if (this.isEdit) {
                    await this.loadTemplate();
                } else {
                    this.createTemplate();
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTemplate() {
            const criteria = new Criteria();
            criteria.addAssociation('supplier');
            criteria.addAssociation('supplier.media');
            criteria.addAssociation('lastImportMedia');

            this.template = await this.templateRepository.get(
                this.$route.params.id,
                Shopware.Context.api,
                criteria
            );

            if (this.template.supplier) {
                this.supplier = this.template.supplier;
            }

            // Ensure column_mapping is an object (it might come as array from DB)
            if (Array.isArray(this.template.config.column_mapping)) {
                this.template.config.column_mapping = {};
            } else if (!this.template.config.column_mapping) {
                this.template.config.column_mapping = {};
            }

            // Rebuild allSelectedColumnTypes from existing config
            this.rebuildSelectedColumnTypes();
            
            // Mark as already redirected since this is an existing template
            this.hasRedirected = true;
            
            // Auto-load preview if active media is selected
            if (this.template.config.selected_media_id) {
                await this.loadPreview();
            }
        },

        createTemplate() {
            this.template = this.templateRepository.create(Shopware.Context.api);
            this.template.config = {
                selected_media_id: null,
                start_row: 2,
                column_mapping: {},
                modifiers: [],
                price_currencies: {
                    purchase: 'UAH',
                    retail: 'UAH',
                    list: 'UAH'
                },
                filters: {
                    categories: [],
                    manufacturers: [],
                    equipment_types: []
                }
            };
        },

        async nextStep() {
            if (!this.canProceedToNextStep) return;

            // Auto-save before moving to next step
            await this.autoSaveTemplate();

            // Load supplier data when moving to step 2
            if (this.currentStep === 1) {
                await this.loadSupplierData();
                // Auto-load preview if active media is selected
                if (this.template.config.selected_media_id) {
                    await this.loadPreview();
                }
            }

            this.currentStep++;
        },

        async previousStep() {
            if (this.currentStep > 1) {
                this.currentStep--;
            }
        },

        async autoSaveTemplate() {
            if (!this.template) return;

            // Only save if we have minimum required fields
            if (!this.template.name || !this.template.supplierId) {
                return;
            }

            try {
                // Ensure column_mapping is an object, not an array
                if (!this.template.config.column_mapping || Array.isArray(this.template.config.column_mapping)) {
                    this.template.config.column_mapping = {};
                }

                // Check if this is a new template (not in edit mode)
                const isNew = !this.isEdit;
                
                // If template has ID but we're in create mode, check if it exists in DB
                if (this.template.id && isNew) {
                    try {
                        // Try to load it - if it exists, we're actually editing
                        await this.templateRepository.get(this.template.id, Shopware.Context.api);
                        // Template exists, so we're editing - update the route
                        this.$router.replace({
                            name: 'supplier.price.update.edit',
                            params: { id: this.template.id }
                        });
                        this.hasRedirected = true;
                    } catch (error) {
                        // Template doesn't exist, it's truly new - clear ID to force insert
                        delete this.template.id;
                    }
                }

                // Save the template
                await this.templateRepository.save(this.template, Shopware.Context.api);
                
                // If this is a new template and we haven't redirected yet, redirect to edit mode
                if (isNew && this.template.id && !this.hasRedirected) {
                    this.hasRedirected = true;
                    this.$router.replace({
                        name: 'supplier.price.update.edit',
                        params: { id: this.template.id }
                    });
                }
            } catch (error) {
                console.error('Auto-save error:', error);
                // If error is about InsertCommand vs UpdateCommand, clear ID and retry
                if (error.response?.data?.errors?.[0]?.code === 'FRAMEWORK__WRITE_TYPE_INTEND_ERROR') {
                    const templateId = this.template.id;
                    delete this.template.id;
                    try {
                        // Ensure column_mapping is an object before retry
                        if (!this.template.config.column_mapping || Array.isArray(this.template.config.column_mapping)) {
                            this.template.config.column_mapping = {};
                        }
                        await this.templateRepository.save(this.template, Shopware.Context.api);
                        if (this.template.id && !this.hasRedirected) {
                            this.hasRedirected = true;
                            this.$router.replace({
                                name: 'supplier.price.update.edit',
                                params: { id: this.template.id }
                            });
                        }
                    } catch (retryError) {
                        if (templateId) {
                            this.template.id = templateId;
                        }
                        console.error('Retry save error:', retryError);
                    }
                }
            }
        },

        async loadSupplierData() {
            if (!this.template.supplierId) return;

            try {
                const criteria = new Criteria();
                criteria.addAssociation('media');

                this.supplier = await this.supplierRepository.get(
                    this.template.supplierId,
                    Shopware.Context.api,
                    criteria
                );
            } catch (error) {
                console.error('Error loading supplier:', error);
            }
        },

        async loadMediaFolder() {
            try {
                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('name', 'Suppliers Prices'));

                const result = await this.mediaFolderRepository.search(criteria, Shopware.Context.api);
                if (result.length > 0) {
                    this.mediaFolderId = result.first().id;
                }
            } catch (error) {
                console.error('Error loading media folder:', error);
            }
        },

        async loadEquipmentTypes() {
            try {
                const propertyGroupRepository = this.repositoryFactory.create('property_group');
                const allCriteria = new Criteria();
                allCriteria.setLimit(100);

                const allGroups = await propertyGroupRepository.search(allCriteria, Shopware.Context.api);

                const equipmentGroup = Array.from(allGroups).find(group =>
                    group.name === 'Тип обладнання' ||
                    group.name === 'Equipment Type' ||
                    group.id === this.equipmentTypePropertyGroupId
                );

                if (equipmentGroup) {
                    const criteria = new Criteria();
                    criteria.addAssociation('options');
                    criteria.setIds([equipmentGroup.id]);

                    const result = await propertyGroupRepository.search(criteria, Shopware.Context.api);

                    if (result && result.length > 0) {
                        const propertyGroup = result.first();
                        if (propertyGroup?.options) {
                            const options = Array.from(propertyGroup.options);
                            options.sort((a, b) => {
                                const posA = a.position || 0;
                                const posB = b.position || 0;
                                if (posA !== posB) {
                                    return posA - posB;
                                }
                                const nameA = a.translated?.name || a.name || '';
                                const nameB = b.translated?.name || b.name || '';
                                return nameA.localeCompare(nameB);
                            });
                            this.equipmentTypes = options;
                        }
                    }
                }
            } catch (error) {
                console.error('Error loading equipment types:', error);
            }
        },

        async loadCurrencies() {
            try {
                const criteria = new Criteria();
                criteria.addSorting(Criteria.sort('name', 'ASC'));
                criteria.setLimit(500);

                const result = await this.currencyRepository.search(criteria, Shopware.Context.api);
                this.currencyOptions = Array.from(result).map(currency => ({
                    value: currency.isoCode,
                    label: `${currency.name || currency.isoCode} (${currency.isoCode})`
                }));
            } catch (error) {
                console.error('Error loading currencies:', error);
                this.currencyOptions = [];
            }
        },

        onEquipmentTypesChange(selectedValues) {
            if (this.template?.config?.filters) {
                this.template.config.filters.equipment_types = Array.isArray(selectedValues) ? selectedValues : [];
            }
        },

        async onMediaUploadFinish({ targetId }) {
            try {
                const media = await this.mediaRepository.get(targetId, Shopware.Context.api);

                // Add media to supplier
                if (this.supplier?.media) {
                    this.supplier.media.add(media);
                    await this.supplierRepository.save(this.supplier, Shopware.Context.api);
                }

                // Set as active media in template
                await this.setActiveMedia(media);

                // Reload supplier data
                await this.loadSupplierData();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorUpload')
                });
            }
        },

        async setActiveMedia(media) {
            if (!this.template) return;

            this.template.config.selected_media_id = media.id;
            
            // Only auto-save if template already exists in database
            if (this.template.id && this.isEdit) {
                try {
                    await this.templateRepository.save(this.template, Shopware.Context.api);
                } catch (error) {
                    console.error('Error saving active media:', error);
                    this.createNotificationError({
                        message: this.$tc('supplier.priceUpdate.wizard.errorSetActiveMedia')
                    });
                    return;
                }
            } else {
                // For new templates, just update the config without saving
                // It will be saved when user proceeds to next step
            }

            // Auto-load preview when changing active media
            if (this.template.config.selected_media_id) {
                await this.loadPreview();
            } else {
                this.filePreview = null;
                this.previewOffset = 0;
            }
        },

        async removeMediaFromSupplier(media) {
            if (!this.supplier?.media) return;

            try {
                this.supplier.media.remove(media.id);
                await this.supplierRepository.save(this.supplier, Shopware.Context.api);

                // If removed media was active, clear it
                if (this.template.config.selected_media_id === media.id) {
                    this.template.config.selected_media_id = null;
                    this.filePreview = null;
                    await this.autoSaveTemplate();
                }

                // Reload supplier data
                await this.loadSupplierData();

                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.wizard.successRemoveFile')
                });
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorRemoveFile')
                });
            }
        },

        async loadPreview() {
            if (!this.template.config.selected_media_id) return;

            this.isLoadingPreview = true;
            this.previewOffset = 0;

            try {
                const response = await this.priceUpdateService.previewFile(
                    this.template.config.selected_media_id,
                    this.previewLimit
                );
                this.filePreview = response;
                this.canLoadMore = false; // Disable load more button
                this.previewOffset = this.previewLimit;

                // Rebuild selected column types from saved config
                this.rebuildSelectedColumnTypes();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorPreview')
                });
            } finally {
                this.isLoadingPreview = false;
            }
        },


        getColumnMapping(colLetter) {
            return this.template?.config?.column_mapping?.[colLetter] || [];
        },

        async updateColumnMapping(colLetter, values) {
            // Ensure column_mapping is an object, not an array
            if (!this.template.config.column_mapping || Array.isArray(this.template.config.column_mapping)) {
                this.template.config.column_mapping = {};
            }

            // Remove old selections from Set
            const oldValues = this.template.config.column_mapping[colLetter] || [];
            oldValues.forEach(v => this.allSelectedColumnTypes.delete(v));

            // Add new selections to Set
            values.forEach(v => this.allSelectedColumnTypes.add(v));

            // Update mapping - always as array
            this.template.config.column_mapping[colLetter] = values;

            // Clean up empty mappings
            if (values.length === 0) {
                delete this.template.config.column_mapping[colLetter];
            }

            await this.autoSaveTemplate();
        },

        getAvailableColumnTypes(currentColLetter) {
            const currentSelection = this.getColumnMapping(currentColLetter);

            return this.columnTypeOptions.filter(option => {
                // Always allow currently selected options for this column
                if (currentSelection.includes(option.value)) {
                    return true;
                }

                // Disallow options that are selected in other columns
                return !this.allSelectedColumnTypes.has(option.value);
            });
        },

        rebuildSelectedColumnTypes() {
            this.allSelectedColumnTypes.clear();
            if (this.template?.config?.column_mapping) {
                Object.values(this.template.config.column_mapping).forEach(values => {
                    if (Array.isArray(values)) {
                        values.forEach(v => this.allSelectedColumnTypes.add(v));
                    }
                });
            }
        },

        getRowClass(rowNumber) {
            const startRow = this.template?.config?.start_row || 2;

            if (rowNumber < startRow) {
                return 'row-header';
            }
            return 'row-data';
        },

        async onStartRowChange() {
            await this.autoSaveTemplate();
        },

        addModifier() {
            if (!this.canAddModifier) return;

            const usedTypes = (this.template.config.modifiers || []).map(m => m.price_type);
            const allTypes = ['purchase', 'retail', 'list'];
            const availableType = allTypes.find(t => !usedTypes.includes(t));

            if (availableType) {
                this.template.config.modifiers.push({
                    price_type: availableType,
                    modifier_type: 'none',
                    value: 0
                });
                this.autoSaveTemplate();
            }
        },

        async removeModifier(index) {
            this.template.config.modifiers.splice(index, 1);
            await this.autoSaveTemplate();
        },

        getAvailablePriceTypes(currentIndex) {
            const allTypes = [
                { value: 'purchase', label: this.$tc('supplier.priceUpdate.wizard.priceTypePurchase') },
                { value: 'retail', label: this.$tc('supplier.priceUpdate.wizard.priceTypeRetail') },
                { value: 'list', label: this.$tc('supplier.priceUpdate.wizard.priceTypeList') }
            ];

            const currentType = this.template.config.modifiers[currentIndex]?.price_type;
            const usedTypes = this.template.config.modifiers
                .map((m, idx) => idx !== currentIndex ? m.price_type : null)
                .filter(t => t !== null);

            return allTypes.filter(t => t.value === currentType || !usedTypes.includes(t.value));
        },

        async loadMatchPreview() {
            if (!this.template.id || !this.template.config.selected_media_id) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorNoTemplateOrMedia')
                });
                return;
            }

            this.isLoadingMatchPreview = true;
            try {
                const result = await this.priceUpdateService.matchPreview(this.template.id);
                this.matchPreviewData = result.matched || [];
            } catch (error) {
                console.error('Error loading match preview:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorLoadMatchPreview')
                });
            } finally {
                this.isLoadingMatchPreview = false;
            }
        },

        async autoMatchProducts() {
            // TODO: Implement auto-match logic
            this.isAutoMatching = true;
            try {
                await new Promise(resolve => setTimeout(resolve, 1000));
            } finally {
                this.isAutoMatching = false;
            }
        },

        async confirmAllMatches() {
            // TODO: Implement confirm all matches
        },

        async applyPrices() {
            if (!this.template.id) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorNoTemplate')
                });
                return;
            }

            this.isApplyingPrices = true;
            try {
                await this.priceUpdateService.applyPrices(this.template.id);
                
                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.wizard.successApplyPrices')
                });
                
                // Reload template to get updated appliedAt
                await this.loadTemplate();
            } catch (error) {
                console.error('Error applying prices:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorApplyPrices')
                });
            } finally {
                this.isApplyingPrices = false;
            }
        },

        async onSave() {
            if (!this.template.name || !this.template.supplierId) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorValidation')
                });
                return;
            }

            this.isSaving = true;
            this.saveSuccess = false;

            try {
                // Ensure column_mapping is an object, not an array
                if (!this.template.config.column_mapping || Array.isArray(this.template.config.column_mapping)) {
                    this.template.config.column_mapping = {};
                }

                await this.templateRepository.save(this.template, Shopware.Context.api);
                this.saveSuccess = true;
                
                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.wizard.successSave')
                });
            } catch (error) {
                console.error('Error saving template:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorSave')
                });
            } finally {
                this.isSaving = false;
            }
        },

        saveFinish() {
            this.saveSuccess = false;
        }
    }
});
