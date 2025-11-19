import template from './supplier-list.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('supplier-list', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            suppliers: null,
            isLoading: false,
            total: 0,
            page: 1,
            limit: 25
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        supplierRepository() {
            return this.repositoryFactory.create('supplier');
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
                    property: 'city',
                    dataIndex: 'city',
                    label: this.$tc('supplier.list.columnCity'),
                    allowResize: true
                },
                {
                    property: 'email',
                    dataIndex: 'email',
                    label: this.$tc('supplier.list.columnEmail'),
                    allowResize: true
                }
            ];
        }
    },

    created() {
        this.getList();
    },

    methods: {
        async getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            try {
                const result = await this.supplierRepository.search(criteria);
                this.suppliers = result;
                this.total = result.total;
            } catch (error) {
                console.error('Error loading suppliers:', error);
            } finally {
                this.isLoading = false;
            }
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.getList();
        }
    }
});
