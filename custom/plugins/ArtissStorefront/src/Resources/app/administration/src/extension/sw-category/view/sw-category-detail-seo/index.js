import template from './sw-category-detail-seo.html.twig';

const { Component } = Shopware;

Component.override('sw-category-detail-seo', {
    template,

    watch: {
        category: {
            handler(newValue) {
                if (newValue && newValue.customFields === null) {
                    newValue.customFields = {};
                }
            },
            immediate: true
        }
    }
});
