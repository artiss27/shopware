import template from './supplier-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('supplier-detail', {
    template,

    inject: [
        'repositoryFactory',
        'customFieldDataProviderService'
    ],

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
            manufacturers: [],
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
        },

        manufacturerRepository() {
            return this.repositoryFactory.create('product_manufacturer');
        },

        manufacturerOptions() {
            return this.manufacturers.map(manufacturer => ({
                value: manufacturer.id,
                label: manufacturer.name
            }));
        },

        safeManufacturerIds: {
            get() {
                if (!this.supplier || !this.supplier.manufacturerIds) {
                    return [];
                }
                if (!Array.isArray(this.supplier.manufacturerIds)) {
                    return [];
                }
                return this.supplier.manufacturerIds;
            },
            set(value) {
                if (this.supplier) {
                    this.supplier.manufacturerIds = Array.isArray(value) ? value : [];
                }
            }
        }
    },

    created() {
        this.repository = this.supplierRepository;
        this.loadManufacturers();
        this.getSupplier();
        this.loadCustomFieldSets();
    },

    mounted() {
        // Set first tab as active after component is mounted
        this.activeTab = 'contacts';
    },

    watch: {
        activeTab(newVal, oldVal) {
            console.log('Tab changed from', oldVal, 'to', newVal);
        }
    },

    methods: {
        async loadManufacturers() {
            try {
                const criteria = new Criteria();
                criteria.addSorting(Criteria.sort('name', 'ASC'));
                criteria.setLimit(500);

                const result = await this.manufacturerRepository.search(criteria);
                this.manufacturers = result;
            } catch (error) {
                console.error('Error loading manufacturers:', error);
            }
        },

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
                if (this.$route.params.id) {
                    const entity = await this.repository.get(this.$route.params.id);
                    this.supplier = entity;
                } else {
                    this.supplier = this.repository.create();
                }

                // Initialize customFields if null
                if (!this.supplier.customFields) {
                    this.supplier.customFields = {};
                }
                // Initialize manufacturerIds if null or not an array
                if (!this.supplier.manufacturerIds || !Array.isArray(this.supplier.manufacturerIds)) {
                    this.supplier.manufacturerIds = [];
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

        onManufacturersChange(selectedValues) {
            console.log('onManufacturersChange called with:', selectedValues);
            if (this.supplier) {
                this.supplier.manufacturerIds = Array.isArray(selectedValues) ? selectedValues : [];
                console.log('Updated supplier.manufacturerIds to:', this.supplier.manufacturerIds);
            }
        },

        onTabChange(tabItem) {
            // Extract name from tab item component
            let tabName = null;

            if (tabItem && typeof tabItem === 'string') {
                tabName = tabItem;
            } else if (tabItem && tabItem.name) {
                tabName = tabItem.name;
            } else if (tabItem && tabItem.$props && tabItem.$props.name) {
                tabName = tabItem.$props.name;
            } else if (tabItem && tabItem.positionIdentifier) {
                tabName = tabItem.positionIdentifier;
            }

            if (tabName) {
                this.activeTab = tabName;
            }
        }
    }
});
