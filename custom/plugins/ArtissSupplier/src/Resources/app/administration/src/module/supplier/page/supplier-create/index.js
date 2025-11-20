import template from './supplier-create.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

console.log('[SUPPLIER-CREATE] Registering component, timestamp:', Date.now());

Component.extend('supplier-create', 'supplier-detail', {
    template,

    methods: {
        getSupplier() {
            this.supplier = this.repository.create();
        },

        onClickSave() {
            console.log('[SUPPLIER-CREATE] onClickSave called, version: 2024-11-20-22:40');
            this.isLoading = true;
            this.processSuccess = false;
            const supplierId = this.supplier.id;

            console.log('[SUPPLIER-CREATE] Creating supplier:', supplierId);

            return this.repository.save(this.supplier, Shopware.Context.api)
                .then(() => {
                    console.log('[SUPPLIER-CREATE] Save successful, loading full data...');
                    const criteria = new Criteria();
                    return this.repository.get(supplierId, Shopware.Context.api, criteria);
                })
                .then((loadedSupplier) => {
                    console.log('[SUPPLIER-CREATE] Loaded full supplier:', loadedSupplier);
                    this.supplier = loadedSupplier;

                    if (!this.supplier.customFields) {
                        this.supplier.customFields = {};
                    }
                    if (!this.supplier.manufacturerIds || !Array.isArray(this.supplier.manufacturerIds)) {
                        this.supplier.manufacturerIds = [];
                    }

                    this.createNotificationSuccess({
                        message: this.$tc('supplier.detail.successSave')
                    });

                    this.isLoading = false;
                    this.processSuccess = true;

                    // Перенаправляем на страницу редактирования
                    return this.$router.push({
                        name: 'artiss.supplier.detail',
                        params: { id: supplierId }
                    });
                })
                .catch((error) => {
                    console.error('[SUPPLIER-CREATE] Error:', error);
                    this.createNotificationError({
                        message: this.$tc('supplier.detail.errorCreate')
                    });
                    this.isLoading = false;
                });
        }
    }
});
