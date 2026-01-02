import template from './product-merge-tab.html.twig';
import './product-merge-tab.scss';

const { Component, Mixin, Data: { Criteria } } = Shopware;

Component.register('artiss-product-merge-tab', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        httpClient: {
            type: Object,
            required: true
        },
        getAuthHeaders: {
            type: Function,
            required: true
        }
    },

    data() {
        return {
            isLoading: false,
            mode: 'new', // 'new' or 'existing'
            mergeAllMedia: true, // Merge all media to parent by default

            // Target parent (for existing mode)
            targetParent: null,
            targetParentSearchTerm: '',
            targetParentResults: [],
            showTargetParentResults: false,
            targetParentCategory: null,
            targetParentManufacturer: null,

            // Products to merge
            mergeProductSearchTerm: '',
            mergeProductResults: [],
            showMergeProductModal: false,
            mergeProductCategory: null,
            mergeProductManufacturer: null,
            selectedProducts: [],
            mergeProductsPage: 1,
            mergeProductsLimit: 25,
            mergeProductsTotal: 0,
            selectedProductIdsInModal: [],
            modalFilterName: '',

            // Parent name (for new mode)
            newParentName: '',

            // Variant-forming properties selection
            selectedVariantFormingProperties: [],
            availableVariantFormingProperties: [],

            // Preview data
            previewData: null,
            showPreview: false,
            showConfirmModal: false
        };
    },

    computed: {
        productRepository() {
            return this.repositoryFactory.create('product');
        },

        categoryRepository() {
            return this.repositoryFactory.create('category');
        },

        manufacturerRepository() {
            return this.repositoryFactory.create('product_manufacturer');
        },

        canSearchTargetParent() {
            return this.mode === 'existing';
        },

        canAddProducts() {
            return this.selectedProducts.length > 0;
        },

        canPreview() {
            const hasSelectedProducts = this.selectedProducts.length >= (this.mode === 'existing' ? 1 : 2);
            const hasParentName = this.mode === 'existing' || (this.newParentName && this.newParentName.trim().length > 0);
            const hasVariantProperties = this.selectedVariantFormingProperties.length > 0;
            
            if (this.mode === 'existing') {
                return this.targetParent && hasSelectedProducts && hasVariantProperties;
            } else {
                return hasSelectedProducts && hasParentName && hasVariantProperties;
            }
        },

        targetParentCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('parentId', null));
            criteria.addFilter(Criteria.not('AND', [Criteria.equals('childCount', 0)]));
            criteria.addAssociation('categories');
            criteria.addAssociation('manufacturer');
            
            if (this.targetParentSearchTerm) {
                criteria.setTerm(this.targetParentSearchTerm);
            }
            
            if (this.targetParentCategory) {
                criteria.addFilter(Criteria.equals('categories.id', this.targetParentCategory));
            }
            
            if (this.targetParentManufacturer) {
                criteria.addFilter(Criteria.equals('manufacturerId', this.targetParentManufacturer));
            }
            
            return criteria;
        },

        mergeProductCriteria() {
            const criteria = new Criteria(this.mergeProductsPage, this.mergeProductsLimit);
            criteria.addFilter(Criteria.equals('parentId', null));
            criteria.addFilter(Criteria.equals('childCount', 0));
            criteria.addAssociation('categories');
            criteria.addAssociation('manufacturer');
            
            if (this.mergeProductCategory) {
                criteria.addFilter(Criteria.equals('categories.id', this.mergeProductCategory));
            }
            
            if (this.mergeProductManufacturer) {
                criteria.addFilter(Criteria.equals('manufacturerId', this.mergeProductManufacturer));
            }
            
            // Exclude target parent if selected
            if (this.targetParent) {
                criteria.addFilter(Criteria.not('AND', [Criteria.equals('id', this.targetParent.id)]));
            }
            
            // Exclude already selected products
            if (this.selectedProducts.length > 0) {
                const selectedIds = this.selectedProducts.map(p => p.id);
                criteria.addFilter(Criteria.not('AND', [Criteria.equalsAny('id', selectedIds)]));
            }
            
            return criteria;
        },

        filteredMergeProductResults() {
            if (!this.modalFilterName) {
                return this.mergeProductResults;
            }
            const filter = this.modalFilterName.toLowerCase();
            return this.mergeProductResults.filter(product => {
                const name = (product.name || product.translated?.name || '').toLowerCase();
                const productNumber = (product.productNumber || '').toLowerCase();
                return name.includes(filter) || productNumber.includes(filter);
            });
        },

        hasMoreProducts() {
            return this.mergeProductResults.length < this.mergeProductsTotal;
        }
    },

    methods: {
        async searchTargetParent() {
            try {
                const results = await this.productRepository.search(this.targetParentCriteria, Shopware.Context.api);
                this.targetParentResults = Array.from(results);
                this.showTargetParentResults = results.total > 0;
            } catch (error) {
                console.error('Search target parent error:', error);
                this.createNotificationError({
                    message: this.$tc('artissTools.products.merge.errors.searchFailed')
                });
            }
        },

        selectTargetParent(product) {
            this.targetParent = {
                id: product.id,
                name: product.name,
                productNumber: product.productNumber
            };
            this.showTargetParentResults = false;
            this.targetParentSearchTerm = product.name;
        },

        clearTargetParent() {
            this.targetParent = null;
            this.targetParentSearchTerm = '';
        },

        async searchMergeProducts() {
            this.isLoading = true;
            try {
                // Reset to first page
                this.mergeProductsPage = 1;
                const results = await this.productRepository.search(this.mergeProductCriteria, Shopware.Context.api);
                this.mergeProductResults = Array.from(results);
                this.mergeProductsTotal = results.total;
                this.selectedProductIdsInModal = [];
                this.modalFilterName = '';
                this.showMergeProductModal = true;
            } catch (error) {
                console.error('Search error:', error);
                this.createNotificationError({
                    message: this.$tc('artissTools.products.merge.errors.searchFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadMoreProducts() {
            if (this.mergeProductResults.length >= this.mergeProductsTotal || this.isLoading) {
                return;
            }

            this.isLoading = true;
            try {
                this.mergeProductsPage += 1;
                const criteria = this.mergeProductCriteria;
                criteria.setPage(this.mergeProductsPage);
                const results = await this.productRepository.search(criteria, Shopware.Context.api);
                const newProducts = Array.from(results);
                this.mergeProductResults = [...this.mergeProductResults, ...newProducts];
            } catch (error) {
                console.error('Load more error:', error);
                this.mergeProductsPage -= 1; // Revert on error
            } finally {
                this.isLoading = false;
            }
        },

        addSelectedProducts() {
            if (this.selectedProductIdsInModal.length === 0) {
                return;
            }

            // Add selected products from modal - use all results, not filtered
            const productsToAdd = this.mergeProductResults.filter(product => 
                this.selectedProductIdsInModal.includes(product.id)
            );

            const newProducts = [];
            productsToAdd.forEach(product => {
                // Check if product is already in the list
                const existingIndex = this.selectedProducts.findIndex(p => p.id === product.id);
                if (existingIndex === -1) {
                    // Get product name from translations or default
                    let productName = '';
                    if (product.translated && product.translated.name) {
                        productName = product.translated.name;
                    } else if (product.name) {
                        productName = product.name;
                    }
                    
                    newProducts.push({
                        id: product.id,
                        name: productName || 'Без названия',
                        productNumber: product.productNumber || ''
                    });
                }
            });

            // Add all new products at once - create new array for Vue reactivity
            if (newProducts.length > 0) {
                // Use spread operator to ensure Vue reactivity
                this.selectedProducts = [...this.selectedProducts, ...newProducts];
            }

            // Close modal and clear selection
            this.showMergeProductModal = false;
            this.selectedProductIdsInModal = [];
            this.modalFilterName = '';
            // Don't clear mergeProductResults in case user wants to search again
        },

        selectAllOnPage() {
            this.selectedProductIdsInModal = this.filteredMergeProductResults.map(p => p.id);
        },

        toggleProductSelection(productId) {
            const index = this.selectedProductIdsInModal.indexOf(productId);
            if (index > -1) {
                this.selectedProductIdsInModal.splice(index, 1);
            } else {
                this.selectedProductIdsInModal.push(productId);
            }
        },

        isProductSelected(productId) {
            return this.selectedProductIdsInModal.includes(productId);
        },

        closeMergeProductModal() {
            this.showMergeProductModal = false;
            this.selectedProductIdsInModal = [];
            this.modalFilterName = '';
        },

        removeProduct(productId) {
            this.selectedProducts = this.selectedProducts.filter(p => p.id !== productId);
        },

        async generatePreview() {
            if (!this.canPreview) {
                return;
            }

            this.isLoading = true;

            try {
                const payload = {
                    mode: this.mode,
                    selectedProductIds: this.selectedProducts.map(p => p.id),
                    newParentName: this.mode === 'new' ? this.newParentName : null,
                    variantFormingPropertyGroupIds: this.selectedVariantFormingProperties
                };

                if (this.mode === 'existing' && this.targetParent) {
                    payload.targetParentId = this.targetParent.id;
                }

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/products/merge-preview',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.previewData = response.data.data;
                    this.showPreview = true;
                } else {
                    throw new Error(response.data.error || 'Preview generation failed');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.products.merge.errors.previewFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadVariantFormingProperties() {
            if (this.selectedProducts.length < (this.mode === 'existing' ? 1 : 2)) {
                this.availableVariantFormingProperties = [];
                this.selectedVariantFormingProperties = [];
                this.showPreview = false;
                return;
            }

            this.isLoading = true;

            try {
                const payload = {
                    mode: this.mode,
                    selectedProductIds: this.selectedProducts.map(p => p.id)
                };

                if (this.mode === 'existing' && this.targetParent) {
                    payload.targetParentId = this.targetParent.id;
                }

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/products/get-variant-forming-properties',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success && response.data.data) {
                    this.availableVariantFormingProperties = response.data.data;
                    // Auto-select all by default
                    this.selectedVariantFormingProperties = response.data.data.map(prop => prop.groupId);
                } else {
                    this.availableVariantFormingProperties = [];
                    this.selectedVariantFormingProperties = [];
                }
            } catch (error) {
                console.error('Error loading variant-forming properties:', error);
                this.availableVariantFormingProperties = [];
                this.selectedVariantFormingProperties = [];
            } finally {
                this.isLoading = false;
            }
        },

        toggleVariantProperty(groupId) {
            const index = this.selectedVariantFormingProperties.indexOf(groupId);
            if (index > -1) {
                this.selectedVariantFormingProperties.splice(index, 1);
            } else {
                this.selectedVariantFormingProperties.push(groupId);
            }
        },

        isVariantPropertySelected(groupId) {
            return this.selectedVariantFormingProperties.includes(groupId);
        },

        selectAllVariantProperties() {
            this.selectedVariantFormingProperties = this.availableVariantFormingProperties.map(prop => prop.groupId);
        },

        deselectAllVariantProperties() {
            this.selectedVariantFormingProperties = [];
        },

        async executeMerge() {
            if (!this.previewData) {
                return;
            }

            this.showConfirmModal = true;
        },

        async confirmMerge() {
            this.showConfirmModal = false;
            this.isLoading = true;

            try {
                const payload = {
                    mode: this.mode,
                    selectedProductIds: this.selectedProducts.map(p => p.id),
                    newParentName: this.mode === 'new' ? this.newParentName : null,
                    variantFormingPropertyGroupIds: this.selectedVariantFormingProperties,
                    mergeAllMedia: this.mergeAllMedia
                };

                if (this.mode === 'existing' && this.targetParent) {
                    payload.targetParentId = this.targetParent.id;
                }

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/products/merge',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.products.merge.messages.mergeSuccess')
                    });
                    
                    // Reset form
                    this.resetForm();
                } else {
                    throw new Error(response.data.error || 'Merge failed');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.products.merge.errors.mergeFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        resetForm() {
            this.mode = 'new';
            this.targetParent = null;
            this.targetParentSearchTerm = '';
            this.selectedProducts = [];
            this.newParentName = '';
            this.previewData = null;
            this.showPreview = false;
            this.mergeProductSearchTerm = '';
            this.mergeProductCategory = null;
            this.mergeProductManufacturer = null;
            this.targetParentCategory = null;
            this.targetParentManufacturer = null;
            this.selectedVariantFormingProperties = [];
            this.availableVariantFormingProperties = [];
        },

        suggestParentName() {
            if (this.selectedProducts.length === 0) {
                this.newParentName = '';
                return;
            }

            const names = this.selectedProducts.map(p => p.name).filter(Boolean);
            if (names.length === 0) {
                this.newParentName = '';
                return;
            }

            // If only one product, use its name
            if (names.length === 1) {
                this.newParentName = names[0];
                return;
            }

            // Find common prefix (word by word)
            const words = names.map(name => name.split(/\s+/).filter(w => w.length > 0));
            if (words.length === 0 || words[0].length === 0) {
                this.newParentName = names[0] || '';
                return;
            }

            let commonWords = [];
            const firstWords = words[0];
            
            for (let i = 0; i < firstWords.length; i++) {
                const word = firstWords[i];
                const allMatch = words.every(w => w[i] === word);
                if (allMatch) {
                    commonWords.push(word);
                } else {
                    break;
                }
            }

            if (commonWords.length > 0 && commonWords.length <= 5) {
                this.newParentName = commonWords.join(' ');
            } else {
                // Use first name as fallback
                this.newParentName = names[0];
            }
        }
    },

    watch: {
        selectedProducts: {
            handler(newVal, oldVal) {
                // Suggest parent name when products are added (only for new mode)
                if (this.mode === 'new' && this.selectedProducts.length >= 2) {
                    // Always suggest name when products are added (unless user has manually changed it)
                    const productAdded = !oldVal || newVal.length > oldVal.length;
                    if (productAdded) {
                        this.suggestParentName();
                    }
                } else if (this.mode === 'new' && this.selectedProducts.length < 2) {
                    // Clear name if not enough products
                    this.newParentName = '';
                }
                // Load variant-forming properties when products are selected
                if (this.selectedProducts.length >= (this.mode === 'existing' ? 1 : 2)) {
                    this.loadVariantFormingProperties();
                } else {
                    this.availableVariantFormingProperties = [];
                    this.selectedVariantFormingProperties = [];
                    this.showPreview = false;
                }
            },
            deep: true,
            immediate: false
        },
        mode: {
            handler() {
                // Reset variant properties when mode changes
                this.availableVariantFormingProperties = [];
                this.selectedVariantFormingProperties = [];
                this.showPreview = false;
                // Reload if products are selected
                if (this.selectedProducts.length >= (this.mode === 'existing' ? 1 : 2)) {
                    this.loadVariantFormingProperties();
                }
            }
        },
        targetParentCategory: {
            handler(newValue, oldValue) {
                if (this.mode === 'existing' && newValue !== oldValue) {
                    this.$nextTick(() => {
                        this.searchTargetParent();
                    });
                }
            }
        },
        targetParentManufacturer: {
            handler(newValue, oldValue) {
                if (this.mode === 'existing' && newValue !== oldValue) {
                    this.$nextTick(() => {
                        this.searchTargetParent();
                    });
                }
            }
        }
    }
});

