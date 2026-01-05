import template from './sw-cms-el-category-h1.html.twig';
import './sw-cms-el-category-h1.scss';

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
            this.initElementConfig('category-h1');
            this.initElementData('category-h1');
        }
    }
};
