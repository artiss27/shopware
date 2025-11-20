import template from './supplier-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('supplier-detail', {
    template,

    inject: ['repositoryFactory', 'customFieldDataProviderService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    metaInfo() {
        return {
            title: this.$createTitle(this.identifier)
        };
    },

    data() {
        return {
            supplier: null,
            isLoading: false,
            processSuccess: false,
            repository: null,
            customFieldSets: [],
            activeTab: 'contacts',
            // Field name filters for each tab
            contactsFields: [
                'supplier_contacts_city',
                'supplier_contacts_phone',
                'supplier_contacts_email',
                'supplier_contacts_website'
            ],
            commercialFields: [
                'supplier_commercial_purchase',
                'supplier_commercial_margin',
                'supplier_commercial_discount_opt',
                'supplier_commercial_discount_online'
            ],
            additionalFields: [
                'supplier_additional_details',
                'supplier_additional_note',
                'supplier_additional_comment_content',
                'supplier_additional_potencial_tm'
            ],
            filesFields: [
                'supplier_files_price_lists'
            ]
        };
    },

    computed: {
        identifier() {
            return this.supplier?.name || this.$tc('supplier.detail.titleNew');
        },

        supplierRepository() {
            return this.repositoryFactory.create('supplier');
        }
    },

    created() {
        this.repository = this.supplierRepository;
        this.getSupplier();
        this.loadCustomFieldSets();
    },

    watch: {
        activeTab(newVal, oldVal) {
            console.log('Tab changed from', oldVal, 'to', newVal);
        }
    },

    methods: {
        async loadCustomFieldSets() {
            this.customFieldSets = await this.customFieldDataProviderService.getCustomFieldSets('supplier');
        },

        getCustomField(fieldName) {
            if (!this.customFieldSets || this.customFieldSets.length === 0) {
                return null;
            }

            for (const set of this.customFieldSets) {
                if (set.customFields) {
                    const field = set.customFields.find(f => f.name === fieldName);
                    if (field) {
                        return field;
                    }
                }
            }
            return null;
        },

        async getSupplier() {
            this.isLoading = true;
            try {
                const entity = await this.repository.get(this.$route.params.id);
                this.supplier = entity;
                // Initialize customFields if null
                if (!this.supplier.customFields) {
                    this.supplier.customFields = {};
                }
            } catch (error) {
                console.error('Error loading supplier:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onClickSave() {
            this.isLoading = true;

            try {
                await this.repository.save(this.supplier);
                this.getSupplier();
                this.createNotificationSuccess({
                    message: this.$tc('supplier.detail.successSave')
                });
                this.processSuccess = true;
            } catch (error) {
                console.error('Error saving supplier:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorSave')
                });
            } finally {
                this.isLoading = false;
            }
        },

        saveFinish() {
            this.processSuccess = false;
        },

        onTabChange(tabItem) {
            console.log('onTabChange called with:', tabItem);
            // Extract name from tab item component
            if (tabItem && tabItem.name) {
                console.log('Setting activeTab to:', tabItem.name);
                this.activeTab = tabItem.name;
            } else if (tabItem && tabItem.$props && tabItem.$props.name) {
                console.log('Setting activeTab to:', tabItem.$props.name);
                this.activeTab = tabItem.$props.name;
            } else {
                console.log('Could not extract tab name from:', tabItem);
            }
        }
    }
});
