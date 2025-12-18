import template from './products-preview-modal.html.twig';

const { Component, Mixin } = Shopware;

Component.register('artiss-products-preview-modal', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        productIds: {
            type: Array,
            required: true,
            default: () => []
        },
        title: {
            type: String,
            default: ''
        },
        httpClient: {
            type: Object,
            required: true
        },
        getAuthHeaders: {
            type: Function,
            required: true
        }
    },

    emits: ['close'],

    data() {
        return {
            isLoading: false,
            products: [],
            currentPage: 1,
            pageSize: 25,
            totalProducts: 0
        };
    },

    computed: {
        totalPages() {
            return Math.ceil(this.totalProducts / this.pageSize);
        },

        paginatedProductIds() {
            const start = (this.currentPage - 1) * this.pageSize;
            const end = start + this.pageSize;
            return this.productIds.slice(start, end);
        },

        showPagination() {
            return this.totalProducts > this.pageSize;
        }
    },

    created() {
        this.totalProducts = this.productIds.length;
        this.loadProducts();
    },

    methods: {
        async loadProducts() {
            if (this.paginatedProductIds.length === 0) {
                this.products = [];
                return;
            }

            this.isLoading = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/products/load-by-ids',
                    {
                        productIds: this.paginatedProductIds
                    },
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.products = response.data.data.products || [];
                } else {
                    throw new Error(response.data.error);
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.message || 'Failed to load products'
                });
                this.products = [];
            } finally {
                this.isLoading = false;
            }
        },

        getProductEditUrl(productId) {
            return this.$router.resolve({
                name: 'sw.product.detail',
                params: { id: productId }
            }).href;
        },

        openProductInNewTab(productId) {
            const url = this.getProductEditUrl(productId);
            window.open(url, '_blank');
        },

        onPageChange(pageInfo) {
            // Handle both number and object with page property
            const pageNumber = typeof pageInfo === 'object' ? pageInfo.page : pageInfo;
            this.currentPage = pageNumber;
            this.loadProducts();
        },

        closeModal() {
            this.$emit('close');
        }
    },

    watch: {
        productIds: {
            handler() {
                this.totalProducts = this.productIds.length;
                this.currentPage = 1;
                this.loadProducts();
            },
            deep: true
        }
    }
});

