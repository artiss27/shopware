import template from './sw-product-detail-base.html.twig';

const { Component, Data: { Criteria } } = Shopware;

Component.override('sw-product-detail-base', {
    template,

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
