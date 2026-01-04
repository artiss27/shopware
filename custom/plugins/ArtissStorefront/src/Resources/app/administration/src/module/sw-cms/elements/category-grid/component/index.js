import template from './sw-cms-el-category-grid.html.twig';
import './sw-cms-el-category-grid.scss';

const { Mixin } = Shopware;

export default {
    template,

    mixins: [
        Mixin.getByName('cms-element')
    ],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('category-grid');
            this.initElementData('category-grid');
        }
    }
};
