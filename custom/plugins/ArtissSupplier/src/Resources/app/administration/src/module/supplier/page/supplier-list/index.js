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
                    property: 'code',
                    dataIndex: 'code',
                    label: this.$tc('supplier.list.columnCode'),
                    allowResize: true
                },
                {
                    property: 'active',
                    dataIndex: 'active',
                    label: this.$tc('supplier.list.columnActive'),
                    allowResize: true,
                    align: 'center'
                },
                {
                    property: 'bitrixId',
                    dataIndex: 'bitrixId',
                    label: this.$tc('supplier.list.columnBitrixId'),
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
