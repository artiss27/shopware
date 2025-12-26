import template from './price-template-apply.html.twig';
import './price-template-apply.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('price-template-apply', {
    template,

    inject: ['repositoryFactory', 'priceUpdateService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            template: null,
            matchedProducts: [],
            unmatchedItems: [],
            isLoading: false,
            isParsing: false,
            isApplying: false,
            activeTab: 'matched'
        };
    },

    computed: {
        templateRepository() {
            return this.repositoryFactory.create('art_supplier_price_template');
        },

        productRepository() {
            return this.repositoryFactory.create('product');
        },

        matchedCount() {
            return this.matchedProducts.filter(m => m.product_id).length;
        },

        unmatchedCount() {
            return this.unmatchedItems.length;
        },

        canApply() {
            return this.matchedCount > 0 && !this.isApplying;
        },

        matchedColumns() {
            return [
                { property: 'code', label: this.$tc('supplier.priceUpdate.apply.columnCode') },
                { property: 'name', label: this.$tc('supplier.priceUpdate.apply.columnName') },
                { property: 'matched_product', label: this.$tc('supplier.priceUpdate.apply.columnProduct') },
                { property: 'price_1', label: this.$tc('supplier.priceUpdate.apply.columnPrice1') },
                { property: 'price_2', label: this.$tc('supplier.priceUpdate.apply.columnPrice2') },
                { property: 'confidence', label: this.$tc('supplier.priceUpdate.apply.columnConfidence') }
            ];
        },

        unmatchedColumns() {
            return [
                { property: 'code', label: this.$tc('supplier.priceUpdate.apply.columnCode') },
                { property: 'name', label: this.$tc('supplier.priceUpdate.apply.columnName') },
                { property: 'price_1', label: this.$tc('supplier.priceUpdate.apply.columnPrice1') },
                { property: 'price_2', label: this.$tc('supplier.priceUpdate.apply.columnPrice2') }
            ];
        }
    },

    created() {
        this.loadData();
    },

    methods: {
        async loadData() {
            this.isLoading = true;
            try {
                await this.loadTemplate();
                await this.parseAndMatch();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.apply.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadTemplate() {
            const criteria = new Criteria();
            criteria.addAssociation('supplier');
            criteria.addAssociation('lastImportMedia');

            this.template = await this.templateRepository.get(
                this.$route.params.id,
                Shopware.Context.api,
                criteria
            );
        },

        async parseAndMatch() {
            if (!this.template.lastImportMediaId) {
                this.createNotificationWarning({
                    message: this.$tc('supplier.priceUpdate.apply.errorNoFile')
                });
                return;
            }

            this.isParsing = true;
            try {
                await this.priceUpdateService.parseAndNormalize(
                    this.template.id,
                    this.template.lastImportMediaId,
                    false
                );

                const result = await this.priceUpdateService.matchPreview(this.template.id);

                this.matchedProducts = result.matched || [];
                this.unmatchedItems = result.unmatched || [];

                await this.loadProductDetails();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.apply.errorParsing')
                });
            } finally {
                this.isParsing = false;
            }
        },

        async loadProductDetails() {
            const productIds = this.matchedProducts
                .filter(m => m.product_id)
                .map(m => m.product_id);

            if (productIds.length === 0) return;

            const criteria = new Criteria();
            criteria.setIds(productIds);

            const products = await this.productRepository.search(
                criteria,
                Shopware.Context.api
            );

            this.matchedProducts = this.matchedProducts.map(match => {
                if (match.product_id) {
                    const product = products.get(match.product_id);
                    return {
                        ...match,
                        product: product,
                        product_name: product ? product.name : this.$tc('supplier.priceUpdate.apply.productNotFound')
                    };
                }
                return match;
            });
        },

        async onProductChange(item, productId) {
            try {
                await this.priceUpdateService.updateMatch(
                    this.template.id,
                    item.code,
                    productId
                );

                item.product_id = productId;
                await this.loadProductDetails();

                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.apply.successUpdateMatch')
                });
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.apply.errorUpdateMatch')
                });
            }
        },

        getConfidenceBadgeVariant(confidence) {
            switch (confidence) {
                case 'high':
                    return 'success';
                case 'medium':
                    return 'warning';
                case 'low':
                    return 'danger';
                default:
                    return 'neutral';
            }
        },

        getConfidenceLabel(confidence) {
            return this.$tc(`supplier.priceUpdate.apply.confidence${confidence.charAt(0).toUpperCase() + confidence.slice(1)}`);
        },

        async onApply() {
            if (!this.canApply) return;

            this.isApplying = true;
            try {
                await this.priceUpdateService.applyPrices(this.template.id);

                this.createNotificationSuccess({
                    message: this.$tc('supplier.priceUpdate.apply.successApply', this.matchedCount)
                });

                this.$router.push({ name: 'supplier.price.update.index' });
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.priceUpdate.apply.errorApply')
                });
            } finally {
                this.isApplying = false;
            }
        }
    }
});
