const { Component, Data: { Criteria } } = Shopware;

Component.override('sw-product-detail-base', {
    computed: {
        productCriteria() {
            const criteria = this.$super('productCriteria');
            criteria.addAssociation('supplier');
            return criteria;
        },

        supplierCriteria() {
            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            return criteria;
        }
    }
});
