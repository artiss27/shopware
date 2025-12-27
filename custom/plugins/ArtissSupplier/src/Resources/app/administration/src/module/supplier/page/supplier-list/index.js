import template from './supplier-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('supplier-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            suppliers: null,
            isLoading: false,
            total: 0,
            page: 1,
            limit: 25,
            selection: {},
            term: '',
            manufacturers: []
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        supplierRepository() {
            return this.repositoryFactory.create('art_supplier');
        },

        manufacturerRepository() {
            return this.repositoryFactory.create('product_manufacturer');
        },

        supplierColumns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: this.$tc('supplier.list.columnName'),
                    routerLink: 'artiss.supplier.detail',
                    inlineEdit: 'string',
                    allowResize: true,
                    primary: true
                },
                {
                    property: 'manufacturerIds',
                    dataIndex: 'manufacturerIds',
                    label: this.$tc('supplier.list.columnManufacturers'),
                    allowResize: true,
                    sortable: false
                },
                {
                    property: 'alternativeManufacturerIds',
                    dataIndex: 'alternativeManufacturerIds',
                    label: this.$tc('supplier.list.columnAlternativeManufacturers'),
                    allowResize: true,
                    sortable: false
                },
                {
                    property: 'updatedAt',
                    dataIndex: 'updatedAt',
                    label: this.$tc('supplier.list.columnUpdatedAt'),
                    allowResize: true,
                    sortable: true
                }
            ];
        },

        selectionCount() {
            return Object.keys(this.selection).length;
        }
    },

    created() {
        this.loadManufacturers();
        this.getList();
    },

    methods: {
        async loadManufacturers() {
            try {
                const allManufacturers = [];
                let page = 1;
                const limit = 500;
                let hasMore = true;

                while (hasMore) {
                    const criteria = new Criteria(page, limit);
                    criteria.addSorting(Criteria.sort('name', 'ASC'));

                    const result = await this.manufacturerRepository.search(criteria);
                    allManufacturers.push(...result);

                    hasMore = result.total > page * limit;
                    page++;

                    if (page > 20) break;
                }

                this.manufacturers = allManufacturers;
            } catch (error) {
                console.error('Error loading manufacturers:', error);
            }
        },

        async getList() {
            this.isLoading = true;

            try {
                // Load all suppliers (or with high limit) to filter on client side
                // This is needed because JSON array filtering doesn't work well with Criteria
                const allCriteria = new Criteria(1, 10000);
                allCriteria.addSorting(Criteria.sort('name', 'ASC'));
                
                const allSuppliers = await this.supplierRepository.search(allCriteria, Shopware.Context.api);
                let filteredSuppliers = Array.from(allSuppliers);
                
                // Filter by search term if provided
                if (this.term) {
                    const searchTerm = this.term.toLowerCase().trim();
                    
                    // Find manufacturers matching the search term
                    const manufacturerCriteria = new Criteria();
                    manufacturerCriteria.addFilter(Criteria.contains('name', this.term));
                    manufacturerCriteria.setLimit(100);
                    
                    let manufacturerIds = [];
                    try {
                        const matchingManufacturers = await this.manufacturerRepository.search(manufacturerCriteria, Shopware.Context.api);
                        manufacturerIds = Array.from(matchingManufacturers).map(m => m.id);
                    } catch (error) {
                        console.error('Error searching manufacturers:', error);
                    }
                    
                    // Filter suppliers
                    filteredSuppliers = filteredSuppliers.filter(supplier => {
                        // Check supplier name
                        const nameMatch = supplier.name && supplier.name.toLowerCase().includes(searchTerm);
                        
                        // Check manufacturer IDs
                        const manufacturerIdsArray = supplier.manufacturerIds || [];
                        const alternativeManufacturerIdsArray = supplier.alternativeManufacturerIds || [];
                        const hasMatchingManufacturer = manufacturerIds.length > 0 && (
                            manufacturerIds.some(id => manufacturerIdsArray.includes(id)) ||
                            manufacturerIds.some(id => alternativeManufacturerIdsArray.includes(id))
                        );
                        
                        return nameMatch || hasMatchingManufacturer;
                    });
                }
                
                // Apply pagination
                const start = (this.page - 1) * this.limit;
                const end = start + this.limit;
                const paginatedSuppliers = filteredSuppliers.slice(start, end);
                
                // Create collection from array - sw-entity-listing expects EntityCollection
                const collection = this.supplierRepository.createCollection(paginatedSuppliers);
                collection.total = filteredSuppliers.length;
                
                this.suppliers = collection;
                this.total = filteredSuppliers.length;
            } finally {
                this.isLoading = false;
            }
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.getList();
        },

        onSearch(searchTerm) {
            this.term = searchTerm;
            this.page = 1;
            this.getList();
        },

        onSelectionChanged(selection) {
            this.selection = selection;
        },

        async onDelete() {
            if (this.selectionCount === 0) {
                return;
            }

            this.isLoading = true;

            try {
                const deletePromises = Object.keys(this.selection).map(id => {
                    return this.supplierRepository.delete(id, Shopware.Context.api);
                });

                await Promise.all(deletePromises);

                this.createNotificationSuccess({
                    message: this.$tc('supplier.list.successDelete', this.selectionCount, {
                        count: this.selectionCount
                    })
                });

                this.selection = {};
                await this.getList();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.list.errorDelete')
                });
            } finally {
                this.isLoading = false;
            }
        },

        formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            const options = {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            };
            return date.toLocaleString('ru-RU', options);
        },

        formatManufacturers(manufacturerIds) {
            if (!manufacturerIds || !Array.isArray(manufacturerIds) || manufacturerIds.length === 0) {
                return '-';
            }

            const manufacturerNames = manufacturerIds
                .map(id => {
                    const manufacturer = this.manufacturers.find(m => m.id === id);
                    return manufacturer ? manufacturer.name : null;
                })
                .filter(name => name !== null);

            if (manufacturerNames.length === 0) {
                return '-';
            }

            return manufacturerNames.join(', ');
        }
    }
});
