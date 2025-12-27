import template from './supplier-list.html.twig';
import './supplier-list.scss';

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
            manufacturers: [],
            filterSupplierId: null,
            filterManufacturerId: null
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

        manufacturerCriteria() {
            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.setLimit(500);
            return criteria;
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

        // Helper function to safely get array from JSON field
        getSafeArray(value) {
            if (!value) {
                return [];
            }
            if (Array.isArray(value)) {
                return value;
            }
            if (typeof value === 'string') {
                try {
                    const parsed = JSON.parse(value);
                    return Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    return [];
                }
            }
            return [];
        },

        async getList() {
            this.isLoading = true;

            try {
                // If filters are selected, load all and filter on client side
                if (this.filterSupplierId || this.filterManufacturerId) {
                    // Load all suppliers in batches
                    const allSuppliers = [];
                    let page = 1;
                    const batchLimit = 500;
                    let hasMore = true;
                    
                    while (hasMore) {
                        const batchCriteria = new Criteria(page, batchLimit);
                        batchCriteria.addSorting(Criteria.sort('name', 'ASC'));
                        
                        try {
                            const batchResult = await this.supplierRepository.search(batchCriteria, Shopware.Context.api);
                            const batchSuppliers = Array.from(batchResult);
                            allSuppliers.push(...batchSuppliers);
                            
                            hasMore = batchResult.total > page * batchLimit;
                            page++;
                            
                            // Safety limit to prevent infinite loops
                            if (page > 100) break;
                        } catch (error) {
                            console.error('Error loading suppliers batch:', error);
                            hasMore = false;
                        }
                    }
                    
                    // Apply filters
                    let filteredSuppliers = allSuppliers;
                    
                    // Filter by supplier ID
                    if (this.filterSupplierId) {
                        filteredSuppliers = filteredSuppliers.filter(supplier => 
                            supplier.id === this.filterSupplierId
                        );
                    }
                    
                    // Filter by manufacturer
                    if (this.filterManufacturerId) {
                        filteredSuppliers = filteredSuppliers.filter(supplier => {
                            // Safely get arrays from JSON fields
                            const manufacturerIds = this.getSafeArray(supplier.manufacturerIds);
                            const alternativeManufacturerIds = this.getSafeArray(supplier.alternativeManufacturerIds);
                            
                            return manufacturerIds.includes(this.filterManufacturerId) || 
                                   alternativeManufacturerIds.includes(this.filterManufacturerId);
                        });
                    }
                    
                    // Apply pagination
                    const start = (this.page - 1) * this.limit;
                    const end = start + this.limit;
                    const paginatedSuppliers = filteredSuppliers.slice(start, end);
                    
                    // sw-entity-listing works with arrays, but we need to maintain the collection structure
                    // Create a new search result by doing a search with the filtered IDs
                    if (paginatedSuppliers.length > 0) {
                        const filteredIds = paginatedSuppliers.map(s => s.id);
                        const criteria = new Criteria();
                        criteria.addFilter(Criteria.equalsAny('id', filteredIds));
                        criteria.addSorting(Criteria.sort('name', 'ASC'));
                        
                        const result = await this.supplierRepository.search(criteria, Shopware.Context.api);
                        result.total = filteredSuppliers.length;
                        this.suppliers = result;
                        this.total = filteredSuppliers.length;
                    } else {
                        // No results after filtering
                        this.suppliers = [];
                        this.total = 0;
                    }
                } else {
                    // No filters - normal pagination
                    const criteria = new Criteria(this.page, this.limit);
                    criteria.addSorting(Criteria.sort('name', 'ASC'));

                    const result = await this.supplierRepository.search(criteria, Shopware.Context.api);
                    this.suppliers = result;
                    this.total = result.total;
                }
            } catch (error) {
                console.error('Error loading suppliers:', error);
                this.suppliers = null;
                this.total = 0;
            } finally {
                this.isLoading = false;
            }
        },
        
        onSupplierFilterChange(value) {
            // Handle both direct value and event object
            const supplierId = value && typeof value === 'object' ? value.id : value;
            this.filterSupplierId = supplierId || null;
            this.page = 1;
            this.getList();
        },
        
        onManufacturerFilterChange(value) {
            // Handle both direct value and event object
            const manufacturerId = value && typeof value === 'object' ? value.id : value;
            this.filterManufacturerId = manufacturerId || null;
            this.page = 1;
            this.getList();
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
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
