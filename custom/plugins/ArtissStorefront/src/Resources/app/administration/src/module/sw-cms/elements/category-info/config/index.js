import template from './sw-cms-el-config-category-info.html.twig';

const { Mixin } = Shopware;

export default {
    template,

    emits: ['element-update'],

    mixins: [
        Mixin.getByName('cms-element'),
    ],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('category-info');
            
            // Initialize default config if not set
            if (!this.element.config.description) {
                this.$set(this.element.config, 'description', {
                    source: 'mapped',
                    value: 'category.description'
                });
            }
            
            if (!this.element.config.media) {
                this.$set(this.element.config, 'media', {
                    source: 'mapped',
                    value: 'category.media'
                });
            }
        },

        onElementUpdate() {
            this.$emit('element-update', this.element);
        },
    },
};
