import template from './price-template-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('price-template-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            templates: null,
            suppliers: null,
            isLoading: false,
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

        supplierOptions() {
            if (!this.suppliers) return [];

            return this.suppliers.map(supplier => ({
                value: supplier.id,
                label: supplier.name
            }));
        }
    },

    created() {
        this.filterSupplierId = this.$route.query.supplierId || null;
        this.loadSuppliers();
        this.loadTemplates();
    },

    methods: {
        async loadSuppliers() {
            try {
                const criteria = new Criteria();
                criteria.addSorting(Criteria.sort('name', 'ASC'));
                criteria.setLimit(500);

                const result = await this.supplierRepository.search(criteria, Shopware.Context.api);
                this.suppliers = Array.from(result);
            } catch (error) {
                console.error('Error loading suppliers:', error);
                this.suppliers = [];
            }
        },

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

                const result = await this.templateRepository.search(criteria);
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
            this.filterSupplierId = supplierId;
            this.page = 1;
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
                name: 'supplier.price.update.apply',
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
        }
    }
});
