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
            isLoading: false,
            isSaving: false,
            currentStep: 1,
            uploadTag: 'price-template-upload',
            uploadedMedia: null,
            filePreview: null,
            isLoadingPreview: false
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

        isEdit() {
            return !!this.$route.params.id;
        },

        canProceedToNextStep() {
            switch (this.currentStep) {
                case 1:
                    return this.template.name && this.template.supplierId;
                case 2:
                    return this.uploadedMedia &&
                           this.template.config.mapping.price_column_1;
                case 3:
                    return true;
                default:
                    return false;
            }
        },

        priceModeOptions() {
            return [
                { value: 'dual', label: this.$tc('supplier.priceUpdate.wizard.priceModeDual') },
                { value: 'single_purchase', label: this.$tc('supplier.priceUpdate.wizard.priceModeSinglePurchase') },
                { value: 'single_retail', label: this.$tc('supplier.priceUpdate.wizard.priceModeSingleRetail') }
            ];
        },

        priceTypeOptions() {
            return [
                { value: 'purchase', label: this.$tc('supplier.priceUpdate.wizard.priceTypePurchase') },
                { value: 'retail', label: this.$tc('supplier.priceUpdate.wizard.priceTypeRetail') }
            ];
        },

        modifierTypeOptions() {
            return [
                { value: 'none', label: this.$tc('supplier.priceUpdate.wizard.modifierTypeNone') },
                { value: 'percentage', label: this.$tc('supplier.priceUpdate.wizard.modifierTypePercentage') },
                { value: 'fixed', label: this.$tc('supplier.priceUpdate.wizard.modifierTypeFixed') }
            ];
        },

        ...mapPropertyErrors('template', ['name', 'supplierId'])
    },

    created() {
        this.loadData();
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
            criteria.addAssociation('lastImportMedia');

            this.template = await this.templateRepository.get(
                this.$route.params.id,
                Shopware.Context.api,
                criteria
            );

            if (this.template.lastImportMedia) {
                this.uploadedMedia = this.template.lastImportMedia;
            }
        },

        createTemplate() {
            this.template = this.templateRepository.create(Shopware.Context.api);
            this.template.config = {
                filters: {
                    categories: [],
                    manufacturers: [],
                    equipment_types: []
                },
                mapping: {
                    start_row: 2,
                    code_column: 'A',
                    name_column: 'B',
                    price_column_1: 'C',
                    price_column_2: 'D'
                },
                price_rules: {
                    mode: 'dual',
                    price_1_is: 'purchase',
                    price_2_is: 'retail',
                    purchase_modifier_type: 'none',
                    purchase_modifier_value: 0,
                    retail_modifier_type: 'none',
                    retail_modifier_value: 0
                }
            };
        },

        nextStep() {
            if (this.canProceedToNextStep && this.currentStep < 4) {
                this.currentStep++;
            }
        },

        previousStep() {
            if (this.currentStep > 1) {
                this.currentStep--;
            }
        },

        onMediaUploadSidebarOpen() {
            // Handle media sidebar open
        },

        async onMediaUploadFinish({ targetId }) {
            try {
                const criteria = new Criteria();
                this.uploadedMedia = await this.mediaRepository.get(
                    targetId,
                    Shopware.Context.api,
                    criteria
                );

                this.template.lastImportMediaId = targetId;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorUpload')
                });
            }
        },

        async previewFile() {
            if (!this.uploadedMedia) return;

            this.isLoadingPreview = true;
            try {
                const response = await this.priceUpdateService.previewFile(
                    this.uploadedMedia.id
                );
                this.filePreview = response;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorPreview')
                });
            } finally {
                this.isLoadingPreview = false;
            }
        },

        columnIndexToLetter(index) {
            let letter = '';
            let num = index;
            while (num >= 0) {
                letter = String.fromCharCode((num % 26) + 65) + letter;
                num = Math.floor(num / 26) - 1;
            }
            return letter;
        },

        getSupplierName() {
            if (!this.template.supplierId) return '-';

            // If we have supplier association loaded
            if (this.template.supplier) {
                return this.template.supplier.name;
            }

            // For new templates, load supplier name via repository
            if (this.template.supplierId) {
                this.supplierRepository.get(this.template.supplierId, Shopware.Context.api)
                    .then(supplier => {
                        this.template.supplier = supplier;
                    });
            }

            return '-';
        },

        getPriceModeName() {
            const option = this.priceModeOptions.find(
                o => o.value === this.template.config.price_rules.mode
            );
            return option ? option.label : '-';
        },

        async onSave() {
            if (!this.template.name || !this.template.supplierId) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorValidation')
                });
                return;
            }

            this.isSaving = true;

            try {
                await this.templateRepository.save(this.template, Shopware.Context.api);

                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.wizard.successSave')
                });

                this.$router.push({ name: 'supplier.price.update.index' });
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.wizard.errorSave')
                });
            } finally {
                this.isSaving = false;
            }
        }
    }
});
