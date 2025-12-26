import template from './artiss-product-custom-fields-tabs.html.twig';
import './artiss-product-custom-fields-tabs.scss';

const { Component } = Shopware;

/**
 * Component that splits custom field sets into tabs:
 * - Tab 1: product_custom_properties - uses smart manager (only filled fields)
 * - Tab 2: product_prices - shows all fields always
 */
Component.register('artiss-product-custom-fields-tabs', {
    template,

    props: {
        entity: {
            type: Object,
            required: true
        },
        sets: {
            type: Array,
            required: true,
            default: () => []
        }
    },

    data() {
        return {
            activeTab: 'properties' // 'properties' or 'prices'
        };
    },

    computed: {
        /**
         * Get product_custom_properties set
         */
        propertiesSets() {
            return this.sets.filter(set => set.name === 'product_custom_properties');
        },

        /**
         * Get product_prices set
         */
        pricesSets() {
            return this.sets.filter(set => set.name === 'product_prices');
        },

        /**
         * Check if we have multiple tabs to show
         */
        showTabs() {
            return this.propertiesSets.length > 0 && this.pricesSets.length > 0;
        }
    }
});
