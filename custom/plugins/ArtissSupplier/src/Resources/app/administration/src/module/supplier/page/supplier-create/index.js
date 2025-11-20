import template from './supplier-create.html.twig';

const { Component } = Shopware;

Component.extend('supplier-create', 'supplier-detail', {
    template,

    methods: {
        getSupplier() {
            this.supplier = this.repository.create();
        },

        onClickSave() {
            this.isLoading = true;

            this.repository
                .save(this.supplier)
                .then(() => {
                    this.isLoading = false;
                    this.$router.push({ name: 'supplier.detail', params: { id: this.supplier.id } });
                }).catch((exception) => {
                    this.isLoading = false;
                    this.createNotificationError({
                        message: this.$tc('supplier.detail.errorCreate')
                    });
                });
        }
    }
});
