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
            supplier: {
                customFields: {}
            },
            isLoading: false,
            processSuccess: false,
            repository: null,
            customFieldSets: [],
            manufacturers: []
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
        }
    },

    created() {
        this.repository = this.supplierRepository;
        this.loadManufacturers();
        this.getSupplier();
        this.loadCustomFieldSets();
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
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoadManufacturers')
                });
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
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoadFields')
                });
            }
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

                if (!this.supplier.customFields) {
                    this.supplier.customFields = {};
                }
                if (!this.supplier.manufacturerIds || !Array.isArray(this.supplier.manufacturerIds)) {
                    this.supplier.manufacturerIds = [];
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        onClickSave() {
            this.isLoading = true;
            this.processSuccess = false;
            const isNew = !this.$route.params.id;
            const supplierId = this.supplier.id;

            return this.repository.save(this.supplier, Shopware.Context.api)
                .then(() => {
                    const criteria = new Criteria();
                    return this.repository.get(supplierId, Shopware.Context.api, criteria);
                })
                .then((loadedSupplier) => {
                    this.supplier = loadedSupplier;

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
                        return this.$router.push({
                            name: 'artiss.supplier.detail',
                            params: { id: supplierId }
                        });
                    }
                })
                .catch(() => {
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
        }
    }
});
