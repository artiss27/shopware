import template from './supplier-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

console.log('[SUPPLIER-DETAIL] Registering component, timestamp:', Date.now());

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
            supplier: {
                customFields: {}
            },
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
            return this.repositoryFactory.create('art_supplier');
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
        },

        filteredContactsSets() {
            return this.filterCustomFieldSets(this.contactsFields);
        },

        filteredCommercialSets() {
            return this.filterCustomFieldSets(this.commercialFields);
        },

        filteredAdditionalSets() {
            return this.filterCustomFieldSets(this.additionalFields);
        },

        filteredFilesSets() {
            return this.filterCustomFieldSets(this.filesFields);
        },

        contactsFieldsWithConfig() {
            return this.contactsFields
                .map(fieldName => this.getCustomField(fieldName))
                .filter(field => field && field.config);
        },

        commercialFieldsWithConfig() {
            return this.commercialFields
                .map(fieldName => this.getCustomField(fieldName))
                .filter(field => field && field.config);
        },

        additionalFieldsWithConfig() {
            return this.additionalFields
                .map(fieldName => this.getCustomField(fieldName))
                .filter(field => field && field.config);
        },

        filesFieldsWithConfig() {
            return this.filesFields
                .map(fieldName => this.getCustomField(fieldName))
                .filter(field => field && field.config);
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
        activeTab(newVal) {
            // Tab changed - test watch mode
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
            try {
                const customFieldSetRepository = this.repositoryFactory.create('custom_field_set');
                const criteria = new Criteria();
                criteria.addAssociation('customFields');
                criteria.addFilter(Criteria.equals('name', 'supplier_fields'));

                const result = await customFieldSetRepository.search(criteria);
                this.customFieldSets = Array.from(result);
            } catch (error) {
                console.error('Error loading custom field sets:', error);
            }
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
                    const criteria = new Criteria();
                    const entity = await this.repository.get(this.$route.params.id, Shopware.Context.api, criteria);
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

        onClickSave() {
            console.log('[SUPPLIER] onClickSave called, version: 2024-11-20-22:30');
            this.isLoading = true;
            this.processSuccess = false;
            const isNew = !this.$route.params.id;
            const supplierId = this.supplier.id;

            console.log('[SUPPLIER] Saving supplier:', supplierId, 'isNew:', isNew);

            return this.repository.save(this.supplier, Shopware.Context.api)
                .then(() => {
                    console.log('[SUPPLIER] Save successful, loading full data...');
                    // После успешного сохранения принудительно перезагружаем данные с сервера
                    const criteria = new Criteria();
                    return this.repository.get(supplierId, Shopware.Context.api, criteria);
                })
                .then((loadedSupplier) => {
                    console.log('[SUPPLIER] Loaded full supplier:', loadedSupplier);
                    // Обновляем локальные данные загруженными с сервера
                    this.supplier = loadedSupplier;

                    // Инициализируем поля если они null
                    if (!this.supplier.customFields) {
                        this.supplier.customFields = {};
                    }
                    if (!this.supplier.manufacturerIds || !Array.isArray(this.supplier.manufacturerIds)) {
                        this.supplier.manufacturerIds = [];
                    }

                    this.createNotificationSuccess({
                        message: this.$tc('supplier.detail.successSave')
                    });

                    this.isLoading = false;
                    this.processSuccess = true;

                    if (isNew) {
                        // Для нового поставщика перенаправляем на страницу редактирования
                        return this.$router.push({
                            name: 'artiss.supplier.detail',
                            params: { id: supplierId }
                        });
                    }
                })
                .catch((error) => {
                    console.error('Error saving supplier:', error);
                    this.createNotificationError({
                        message: this.$tc('supplier.detail.errorSave')
                    });
                    this.isLoading = false;
                });
        },

        saveFinish() {
            this.processSuccess = false;
        },

        onManufacturersChange(selectedValues) {
            if (this.supplier) {
                this.supplier.manufacturerIds = Array.isArray(selectedValues) ? selectedValues : [];
            }
        },

        onTabChange(tabItem) {
            if (tabItem && tabItem.name) {
                this.activeTab = tabItem.name;
            }
        },

        filterCustomFieldSets(fieldNames) {
            if (!this.customFieldSets || this.customFieldSets.length === 0) {
                return [];
            }

            return this.customFieldSets.map(set => {
                if (!set.customFields || set.customFields.length === 0) {
                    return null;
                }

                const filteredFields = set.customFields.filter(field =>
                    fieldNames.includes(field.name)
                );

                if (filteredFields.length === 0) {
                    return null;
                }

                return {
                    ...set,
                    customFields: filteredFields
                };
            }).filter(set => set !== null);
        }
    }
});
