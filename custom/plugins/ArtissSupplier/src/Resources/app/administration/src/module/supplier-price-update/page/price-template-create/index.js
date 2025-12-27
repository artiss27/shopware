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
            previewLimit: 30,
            saveSuccess: false,
            matchPreviewData: null,
            isLoadingMatchPreview: false,
            isAutoMatching: false,
            isApplyingPrices: false,
            previewPage: 1,
            previewTotal: 0,
            matchPreviewLimit: 50, // Лимит для таблицы предпросмотра товаров
            allPreviewData: null,
            parsedPriceData: null,
            equipmentTypes: [],
            equipmentTypePropertyGroupId: '20836795-aab8-97d8-c709-a2535f197268',
            hasRedirected: false,
            currencyOptions: [],
            allSelectedColumnTypes: new Set(), // Track all selected column types across all columns
            hiddenColumns: ['supplier_name', 'supplier_code'], // Hidden columns by default
            toggleColumnMenu: false // Column visibility menu state
        };
    },

    computed: {
        columnTypeOptions() {
            return [
                { value: 'product_code', label: this.$tc('supplier.priceUpdate.wizard.columnTypeProductCode') },
                { value: 'product_name', label: this.$tc('supplier.priceUpdate.wizard.columnTypeProductName') },
                { value: 'purchase_price', label: this.$tc('supplier.priceUpdate.wizard.columnTypePurchasePrice') },
                { value: 'retail_price', label: this.$tc('supplier.priceUpdate.wizard.columnTypeRetailPrice') },
                { value: 'list_price', label: this.$tc('supplier.priceUpdate.wizard.columnTypeListPrice') },
                { value: 'availability', label: this.$tc('supplier.priceUpdate.wizard.columnTypeAvailability') }
            ];
        },
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

        availabilityActionOptions() {
            return [
                { value: 'dont_change', label: this.$tc('supplier.priceUpdate.wizard.availabilityActionDontChange') },
                { value: 'set_from_price', label: this.$tc('supplier.priceUpdate.wizard.availabilityActionSetFromPrice') },
                { value: 'set_1000', label: this.$tc('supplier.priceUpdate.wizard.availabilityActionSet1000') }
            ];
        },

        isAvailabilityMapped() {
            const columnMapping = this.template?.config?.column_mapping || {};
            for (const types of Object.values(columnMapping)) {
                if (Array.isArray(types) && types.includes('availability')) {
                    return true;
                }
            }
            return false;
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
                    property: 'row_number',
                    label: '№',
                    allowResize: false,
                    width: '60px'
                },
                {
                    property: 'status',
                    label: this.$tc('supplier.priceUpdate.wizard.columnStatus'),
                    allowResize: true,
                    width: '130px'
                },
                {
                    property: 'product_name',
                    label: this.$tc('supplier.priceUpdate.wizard.columnProductName'),
                    allowResize: true,
                    primary: true
                },
                {
                    property: 'supplier_code',
                    label: this.$tc('supplier.priceUpdate.wizard.columnSupplierCode'),
                    allowResize: true,
                    width: '200px'
                },
                {
                    property: 'supplier_name',
                    label: this.$tc('supplier.priceUpdate.wizard.columnSupplierName'),
                    allowResize: true
                },
                {
                    property: 'prices',
                    label: this.$tc('supplier.priceUpdate.wizard.columnPrices'),
                    allowResize: true,
                    width: '250px'
                },
                {
                    property: 'availability',
                    label: this.$tc('supplier.priceUpdate.wizard.columnAvailability'),
                    allowResize: true,
                    width: '120px'
                }
            ];
        },

        visibleMatchPreviewColumns() {
            return this.matchPreviewColumns.filter(col => {
                return !this.hiddenColumns.includes(col.property);
            });
        },

        productRepository() {
            return this.repositoryFactory.create('product');
        },

        ...mapPropertyErrors('template', ['name', 'supplierId'])
    },

    watch: {
        'template.config.modifiers': {
            handler() {
                // Recalculate prices when modifiers change (if preview data is already loaded)
                if (this.allPreviewData && this.allPreviewData.length > 0) {
                    this.recalculateAllPrices();
                }
            },
            deep: true
        }
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
                    equipment_types: [],
                    availability_action: 'dont_change'
                }
            };
        },

        updateAvailabilityActionDefault() {
            // Set default based on whether availability is mapped
            if (this.isAvailabilityMapped) {
                if (this.template.config.filters.availability_action === 'dont_change') {
                    this.template.config.filters.availability_action = 'set_from_price';
                }
            } else {
                if (this.template.config.filters.availability_action === 'set_from_price') {
                    this.template.config.filters.availability_action = 'dont_change';
                }
            }
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

        async onCategoriesChange(selectedValues) {
            if (this.template?.config?.filters) {
                this.template.config.filters.categories = Array.isArray(selectedValues) ? selectedValues : [];
                await this.autoSaveTemplate();
            }
        },

        async onManufacturersChange(selectedValues) {
            if (this.template?.config?.filters) {
                this.template.config.filters.manufacturers = Array.isArray(selectedValues) ? selectedValues : [];
                await this.autoSaveTemplate();
            }
        },

        async onEquipmentTypesChange(selectedValues) {
            if (this.template?.config?.filters) {
                this.template.config.filters.equipment_types = Array.isArray(selectedValues) ? selectedValues : [];
                await this.autoSaveTemplate();
            }
        },

        async onMediaUploadFinish({ targetId }) {
            // Check if targetId is provided
            if (!targetId) {
                console.error('Media upload finished but targetId is missing');
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorUpload')
                });
                return;
            }

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
                console.error('Error processing uploaded media:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorUpload')
                });
            }
        },

        onMediaUploadFail(error) {
            console.error('Media upload failed:', error);
            const errorMessage = error?.response?.data?.errors?.[0]?.detail || 
                               error?.message || 
                               this.$tc('supplier.priceUpdate.wizard.errorUpload');
            this.createNotificationError({
                message: errorMessage
            });
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
            if (!this.template.config.selected_media_id) {
                this.filePreview = null;
                return;
            }

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
                // Clear preview on error to hide the table
                this.filePreview = null;
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

            // Update availability_action default based on mapping
            this.updateAvailabilityActionDefault();

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
                    modifier_type: 'percentage',
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
                // Load parsed price data for interactive lookup
                try {
                    const parseResult = await this.priceUpdateService.parseAndNormalize(
                        this.template.id,
                        this.template.config.selected_media_id,
                        false
                    );
                    this.parsedPriceData = parseResult.data || [];
                } catch (parseError) {
                    console.error('Error loading parsed price data:', parseError);
                    this.parsedPriceData = [];
                }

                const result = await this.priceUpdateService.matchPreview(this.template.id);
                const allData = result.matched || [];

                // Auto-save matched products to mapping
                const matchedItems = allData.filter(item => item.status === 'matched' && item.product_id && item.supplier_code);
                if (matchedItems.length > 0) {
                    await this.saveMatchedProducts(matchedItems);
                }

                // Store all data
                this.allPreviewData = allData;
                this.previewTotal = allData.length;
                // Reset to first page when loading new data
                this.previewPage = 1;

                // Apply pagination
                this.applyPreviewPagination();
            } catch (error) {
                console.error('Error loading match preview:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorLoadMatchPreview')
                });
            } finally {
                this.isLoadingMatchPreview = false;
            }
        },
        
        // Recalculate prices for all items when modifiers change
        recalculateAllPrices() {
            if (!this.allPreviewData || !this.parsedPriceData) {
                return;
            }

            const modifiers = this.template.config.modifiers || [];

            this.allPreviewData.forEach(item => {
                if (item.supplier_code && item.status === 'matched') {
                    // Find price data for this item
                    const priceData = this.parsedPriceData.find(p => p.code === item.supplier_code);
                    if (priceData) {
                        const recalculatedPrices = this.calculatePricesWithModifiers(priceData, modifiers);
                        item.new_prices = recalculatedPrices;
                    }
                }
            });

            // Reapply pagination to update displayed data
            this.applyPreviewPagination();
        },

        applyPreviewPagination() {
            if (!this.allPreviewData) {
                this.matchPreviewData = [];
                return;
            }

            const start = (this.previewPage - 1) * this.matchPreviewLimit;
            const end = start + this.matchPreviewLimit;
            this.matchPreviewData = this.allPreviewData.slice(start, end).map((item, index) => ({
                ...item,
                row_number: start + index + 1
            }));
        },

        onPreviewPageChange({ page, limit }) {
            this.previewPage = page;
            this.matchPreviewLimit = limit;
            this.applyPreviewPagination();
        },

        formatPrice(price) {
            if (price === null || price === undefined) {
                return '-';
            }
            return parseFloat(price).toFixed(2);
        },

        getPriceChangeClass(oldPrice, newPrice) {
            if (oldPrice === null || oldPrice === undefined) {
                return 'price-new';
            }
            const old = parseFloat(oldPrice);
            const newVal = parseFloat(newPrice);
            if (newVal > old) {
                return 'price-increase';
            } else if (newVal < old) {
                return 'price-decrease';
            }
            return 'price-same';
        },

        async onSupplierCodeChange(item, newCode) {
            if (!this.template.id || !item.product_id) {
                return;
            }

            // Normalize code
            const normalizedCode = newCode ? newCode.trim().toUpperCase() : '';

            try {
                // Find item in local data
                const itemIndex = this.allPreviewData.findIndex(i => i.product_id === item.product_id);
                if (itemIndex === -1) {
                    return;
                }

                // Look up the code in parsed price data
                let foundPriceData = null;
                if (normalizedCode && this.parsedPriceData) {
                    foundPriceData = this.parsedPriceData.find(p => p.code === normalizedCode);
                }

                if (foundPriceData) {
                    // Update item with data from price list
                    const modifiers = this.template.config.modifiers || [];
                    const prices = this.calculatePricesWithModifiers(foundPriceData, modifiers);

                    this.allPreviewData[itemIndex].supplier_code = normalizedCode;
                    this.allPreviewData[itemIndex].supplier_name = foundPriceData.name || '';
                    this.allPreviewData[itemIndex].new_prices = prices;
                    this.allPreviewData[itemIndex].availability = foundPriceData.availability ?? null;
                    this.allPreviewData[itemIndex].status = 'matched';
                    this.allPreviewData[itemIndex].confidence = 'high';
                    this.allPreviewData[itemIndex].method = 'manual';

                    // Save to matched_products
                    await this.priceUpdateService.updateMatch(
                        this.template.id,
                        item.product_id,
                        normalizedCode
                    );

                    this.createNotificationSuccess({
                        message: 'Товар найден в прайсе'
                    });
                } else {
                    // Not found in price list - clear price data and update the code
                    this.allPreviewData[itemIndex].supplier_code = normalizedCode;
                    this.allPreviewData[itemIndex].supplier_name = '';
                    this.allPreviewData[itemIndex].new_prices = {
                        purchase: null,
                        retail: null,
                        list: null
                    };
                    this.allPreviewData[itemIndex].availability = null;
                    this.allPreviewData[itemIndex].status = 'unmatched';

                    // Save to matched_products if code is not empty
                    if (normalizedCode) {
                        await this.priceUpdateService.updateMatch(
                            this.template.id,
                            item.product_id,
                            normalizedCode
                        );

                        this.createNotificationSuccess({
                            message: 'Код сохранен (товар не найден в прайсе)'
                        });
                    }
                }

                // Refresh pagination
                this.applyPreviewPagination();
            } catch (error) {
                console.error('Error updating supplier code:', error);
                this.createNotificationError({
                    message: 'Ошибка сохранения привязки'
                });
            }
        },

        calculatePricesWithModifiers(priceData, modifiers) {
            const prices = {
                purchase: priceData.purchase_price || null,
                retail: priceData.retail_price || null,
                list: priceData.list_price || null
            };

            if (!modifiers || !Array.isArray(modifiers)) {
                return prices;
            }

            // Apply modifiers (must match backend logic)
            modifiers.forEach(modifier => {
                const priceType = modifier.price_type;
                const modifierType = modifier.modifier_type || modifier.modifierType || 'none';
                // Backend uses modifier_value or value, frontend uses value
                const value = parseFloat(modifier.modifier_value || modifier.value || 0);

                if (!priceType || modifierType === 'none' || !prices[priceType] || prices[priceType] === null) {
                    return;
                }

                const originalPrice = parseFloat(prices[priceType]);

                if (modifierType === 'percentage') {
                    prices[priceType] = originalPrice * (1 + value / 100);
                } else if (modifierType === 'fixed') {
                    prices[priceType] = originalPrice + value;
                }

                // Round to 2 decimals (same as backend)
                prices[priceType] = Math.round(prices[priceType] * 100) / 100;
            });

            return prices;
        },

        async saveMatchedProducts(matchedItems) {
            if (!this.template.id || matchedItems.length === 0) {
                return;
            }

            try {
                // Save all matched products at once
                for (const item of matchedItems) {
                    if (item.method !== 'matched_products') { // Don't re-save already saved mappings
                        await this.priceUpdateService.updateMatch(
                            this.template.id,
                            item.product_id,
                            item.supplier_code
                        );
                    }
                }
            } catch (error) {
                console.error('Error saving matched products:', error);
            }
        },

        async autoMatchProducts() {
            // Show all columns when auto-matching
            this.hiddenColumns = [];

            // TODO: Implement auto-match logic
            this.isAutoMatching = true;
            try {
                await new Promise(resolve => setTimeout(resolve, 1000));
            } finally {
                this.isAutoMatching = false;
            }
        },

        toggleColumnVisibility(columnProperty) {
            const index = this.hiddenColumns.indexOf(columnProperty);
            if (index > -1) {
                // Show column
                this.hiddenColumns.splice(index, 1);
            } else {
                // Hide column
                this.hiddenColumns.push(columnProperty);
            }
        },

        isColumnVisible(columnProperty) {
            return !this.hiddenColumns.includes(columnProperty);
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
