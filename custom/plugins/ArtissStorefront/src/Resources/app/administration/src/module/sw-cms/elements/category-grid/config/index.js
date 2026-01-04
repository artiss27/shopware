import template from './sw-cms-el-config-category-grid.html.twig';

const { Mixin } = Shopware;

export default {
    template,

    emits: ['element-update'],

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('category-grid');
        },

        onElementUpdate() {
            this.$emit('element-update', this.element);
        }
    }
};
