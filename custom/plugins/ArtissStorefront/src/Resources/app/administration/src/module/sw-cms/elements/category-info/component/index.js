import template from './sw-cms-el-category-info.html.twig';
import './sw-cms-el-category-info.scss';

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
            this.initElementConfig('category-info');
            this.initElementData('category-info');
        }
    }
};
