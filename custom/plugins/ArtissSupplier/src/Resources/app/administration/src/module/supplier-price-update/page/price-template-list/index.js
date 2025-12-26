import template from './price-template-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('price-template-list', {
    template,

    inject: [
        'repositoryFactory',
        'priceUpdateService'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            templates: null,
            isLoading: false,
            isRecalculating: false,
            filterSupplierId: null,
            page: 1,
            limit: 25,
            total: 0
        };
    },

    computed: {
        templateRepository() {
            return this.repositoryFactory.create('art_supplier_price_template');
        },

        supplierRepository() {
            return this.repositoryFactory.create('art_supplier');
        },

        templateColumns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: this.$tc('supplier.priceUpdate.list.columnName'),
                    routerLink: 'supplier.price.update.edit',
                    allowResize: true,
                    primary: true
                },
                {
                    property: 'supplier',
                    dataIndex: 'supplier',
                    label: this.$tc('supplier.priceUpdate.list.columnSupplier'),
                    allowResize: true
                },
                {
                    property: 'status',
                    dataIndex: 'status',
                    label: this.$tc('supplier.priceUpdate.list.columnStatus'),
                    allowResize: true
                },
                {
                    property: 'appliedAt',
                    dataIndex: 'appliedAt',
                    label: this.$tc('supplier.priceUpdate.list.columnAppliedAt'),
                    allowResize: true
                },
                {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: this.$tc('supplier.priceUpdate.list.columnCreatedAt'),
                    allowResize: true
                }
            ];
        }
    },

    watch: {
        '$route.query.supplierId'(newValue) {
            this.filterSupplierId = newValue || null;
            this.page = 1;
            this.loadTemplates();
        }
    },

    created() {
        this.filterSupplierId = this.$route.query.supplierId || null;
        this.loadTemplates();
    },

    methods: {

        async loadTemplates() {
            this.isLoading = true;

            try {
                const criteria = new Criteria(this.page, this.limit);
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
                criteria.addAssociation('supplier');
                criteria.addAssociation('appliedByUser');

                if (this.filterSupplierId) {
                    criteria.addFilter(
                        Criteria.equals('supplierId', this.filterSupplierId)
                    );
                }

                const result = await this.templateRepository.search(criteria, Shopware.Context.api);
                this.templates = result;
                this.total = result.total;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.list.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        onSupplierFilterChange(supplierId) {
            this.filterSupplierId = supplierId || null;
            this.page = 1;
            
            // Update URL query parameter
            this.$router.replace({
                name: 'supplier.price.update.index',
                query: supplierId ? { supplierId: supplierId } : {}
            });
            
            this.loadTemplates();
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.loadTemplates();
        },

        onCreate() {
            this.$router.push({ name: 'supplier.price.update.create' });
        },

        onUpdatePrices(template) {
            this.$router.push({
                name: 'supplier.price.update.edit',
                params: { id: template.id }
            });
        },

        async onDelete(templateId) {
            try {
                await this.templateRepository.delete(templateId);
                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.list.successDelete')
                });
                await this.loadTemplates();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.list.errorDelete')
                });
            }
        },

        formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleString('ru-RU', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        getStatusColor(template) {
            if (!template.appliedAt) return 'warning';
            return 'success';
        },

        getStatusLabel(template) {
            if (!template.appliedAt) {
                return this.$tc('supplier.priceUpdate.list.statusNeverApplied');
            }
            return this.$tc('supplier.priceUpdate.list.statusApplied', 0, {
                date: this.formatDate(template.appliedAt)
            });
        },

        async onRecalculatePrices() {
            this.isRecalculating = true;

            try {
                const response = await this.priceUpdateService.recalculatePrices();

                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.list.successRecalculate', 0, {
                        count: response.stats?.updated || 0
                    })
                });
            } catch (error) {
                console.error('Error recalculating prices:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.list.errorRecalculate')
                });
            } finally {
                this.isRecalculating = false;
            }
        }
    }
});
