import template from './sw-product-detail-specifications.html.twig';

const { Component, Data: { Criteria } } = Shopware;

Component.override('sw-product-detail-specifications', {
    template,

    computed: {
        customFieldSetCriteria() {
            const criteria = this.$super('customFieldSetCriteria');

            console.log('🔍 customFieldSetCriteria - adding association');
            criteria.addAssociation('customFields');

            return criteria;
        }
    }
});

