import template from './supplier-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('supplier-detail', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    metaInfo() {
        return {
            title: this.$createTitle(this.identifier)
        };
    },

    data() {
        return {
            supplier: null,
            isLoading: false,
            processSuccess: false,
            repository: null
        };
    },

    computed: {
        identifier() {
            return this.supplier?.name || this.$tc('supplier.detail.titleNew');
        },

        supplierRepository() {
            return this.repositoryFactory.create('supplier');
        }
    },

    created() {
        this.repository = this.supplierRepository;
        this.getSupplier();
    },

    methods: {
        async getSupplier() {
            this.isLoading = true;
            try {
                const entity = await this.repository.get(this.$route.params.id);
                this.supplier = entity;
            } catch (error) {
                console.error('Error loading supplier:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onClickSave() {
            this.isLoading = true;

            try {
                await this.repository.save(this.supplier);
                this.getSupplier();
                this.createNotificationSuccess({
                    message: this.$tc('supplier.detail.successSave')
                });
                this.processSuccess = true;
            } catch (error) {
                console.error('Error saving supplier:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorSave')
                });
            } finally {
                this.isLoading = false;
            }
        },

        saveFinish() {
            this.processSuccess = false;
        }
    }
});
