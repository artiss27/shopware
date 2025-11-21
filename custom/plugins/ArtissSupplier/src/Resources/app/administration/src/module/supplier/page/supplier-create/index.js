import template from './supplier-create.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.extend('supplier-create', 'supplier-detail', {
    template,

    methods: {
        getSupplier() {
            this.supplier = this.repository.create();
        },

        onClickSave() {
            this.isLoading = true;
            this.processSuccess = false;
            const supplierId = this.supplier.id;

            return this.repository.save(this.supplier, Shopware.Context.api)
                .then(() => {
                    const criteria = new Criteria();
                    return this.repository.get(supplierId, Shopware.Context.api, criteria);
                })
                .then((loadedSupplier) => {
                    this.supplier = loadedSupplier;

                    if (!this.supplier.customFields) {
                        this.supplier.customFields = {};
                    }
                    if (!this.supplier.manufacturerIds || !Array.isArray(this.supplier.manufacturerIds)) {
                        this.supplier.manufacturerIds = [];
                    }
                    if (!this.supplier.equipmentTypeIds || !Array.isArray(this.supplier.equipmentTypeIds)) {
                        this.supplier.equipmentTypeIds = [];
                    }

                    this.createNotificationSuccess({
                        message: this.$tc('supplier.detail.successSave')
                    });

                    this.isLoading = false;
                    this.processSuccess = true;

                    return this.$router.push({
                        name: 'artiss.supplier.detail',
                        params: { id: supplierId }
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        message: this.$tc('supplier.detail.errorCreate')
                    });
                    this.isLoading = false;
                });
        }
    }
});
