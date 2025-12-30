import template from './supplier-detail.html.twig';
import './supplier-detail.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('supplier-detail', {
    template,

    inject: [
        'repositoryFactory'
    ],

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
            supplier: {
                customFields: {}
            },
            isLoading: false,
            processSuccess: false,
            repository: null,
            customFieldSets: [],
            manufacturers: [],
            equipmentTypes: [],
            equipmentTypePropertyGroupId: '20836795-aab8-97d8-c709-a2535f197268',
            uploadTag: 'supplier-price-list-upload',
            mediaFolderId: null
        };
    },

    computed: {
        identifier() {
            return this.supplier?.name || this.$tc('supplier.detail.titleNew');
        },

        supplierRepository() {
            return this.repositoryFactory.create('art_supplier');
        },

        manufacturerRepository() {
            return this.repositoryFactory.create('product_manufacturer');
        },

        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        mediaColumns() {
            return [
                {
                    property: 'fileName',
                    label: this.$tc('supplier.detail.columnFileName'),
                    allowResize: true,
                    primary: true
                },
                {
                    property: 'fileSize',
                    label: this.$tc('supplier.detail.columnFileSize'),
                    allowResize: true
                }
            ];
        },

        mediaItems() {
            if (!this.supplier.media) {
                return [];
            }
            return Array.from(this.supplier.media);
        },

        manufacturerOptions() {
            return this.manufacturers.map(manufacturer => ({
                value: manufacturer.id,
                label: manufacturer.name
            }));
        },

        equipmentTypeOptions() {
            return this.equipmentTypes.map(option => ({
                value: option.id,
                label: option.translated?.name || option.name || option.id
            }));
        },

        safeManufacturerIds: {
            get() {
                if (!this.supplier || !this.supplier.manufacturerIds) {
                    return [];
                }
                if (!Array.isArray(this.supplier.manufacturerIds)) {
                    return [];
                }
                return this.supplier.manufacturerIds;
            },
            set(value) {
                if (this.supplier) {
                    this.supplier.manufacturerIds = Array.isArray(value) ? value : [];
                }
            }
        },

        safeAlternativeManufacturerIds: {
            get() {
                if (!this.supplier || !this.supplier.alternativeManufacturerIds) {
                    return [];
                }
                if (!Array.isArray(this.supplier.alternativeManufacturerIds)) {
                    return [];
                }
                return this.supplier.alternativeManufacturerIds;
            },
            set(value) {
                if (this.supplier) {
                    this.supplier.alternativeManufacturerIds = Array.isArray(value) ? value : [];
                }
            }
        },

        safeEquipmentTypeIds: {
            get() {
                if (!this.supplier || !this.supplier.equipmentTypeIds) {
                    return [];
                }
                if (!Array.isArray(this.supplier.equipmentTypeIds)) {
                    return [];
                }
                return this.supplier.equipmentTypeIds;
            },
            set(value) {
                if (this.supplier) {
                    this.supplier.equipmentTypeIds = Array.isArray(value) ? value : [];
                }
            }
        },

        selectedManufacturerOptions() {
            return this.manufacturerOptions.filter(option =>
                this.safeManufacturerIds.includes(option.value)
            );
        },

        selectedAlternativeManufacturerOptions() {
            return this.manufacturerOptions.filter(option =>
                this.safeAlternativeManufacturerIds.includes(option.value)
            );
        },

        selectedEquipmentTypeOptions() {
            return this.equipmentTypeOptions.filter(option =>
                this.safeEquipmentTypeIds.includes(option.value)
            );
        }
    },

    created() {
        this.repository = this.supplierRepository;
        this.loadManufacturers();
        this.loadEquipmentTypes();
        this.getSupplier();
        this.loadCustomFieldSets();
        this.loadMediaFolder();
    },

    methods: {
        async loadManufacturers() {
            try {
                const allManufacturers = [];
                let page = 1;
                const limit = 500;
                let hasMore = true;

                while (hasMore) {
                    const criteria = new Criteria(page, limit);
                    criteria.addSorting(Criteria.sort('name', 'ASC'));

                    const result = await this.manufacturerRepository.search(criteria);
                    allManufacturers.push(...result);

                    hasMore = result.total > page * limit;
                    page++;

                    // Safety limit to prevent infinite loops
                    if (page > 20) break;
                }

                this.manufacturers = allManufacturers;
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoadManufacturers')
                });
            }
        },

        async loadEquipmentTypes() {
            try {
                const propertyGroupRepository = this.repositoryFactory.create('property_group');
                const allCriteria = new Criteria();
                allCriteria.setLimit(100);

                const allGroups = await propertyGroupRepository.search(allCriteria, Shopware.Context.api);

                const equipmentGroup = Array.from(allGroups).find(group =>
                    group.name === 'Тип обладнання' ||
                    group.name === 'Equipment Type' ||
                    group.id === this.equipmentTypePropertyGroupId
                );

                if (equipmentGroup) {
                    const criteria = new Criteria();
                    criteria.addAssociation('options');
                    criteria.setIds([equipmentGroup.id]);

                    const result = await propertyGroupRepository.search(criteria, Shopware.Context.api);

                    if (result && result.length > 0) {
                        const propertyGroup = result.first();
                        if (propertyGroup && propertyGroup.options) {
                            const options = Array.from(propertyGroup.options);
                            options.sort((a, b) => {
                                const posA = a.position || 0;
                                const posB = b.position || 0;
                                if (posA !== posB) {
                                    return posA - posB;
                                }
                                const nameA = a.translated?.name || a.name || '';
                                const nameB = b.translated?.name || b.name || '';
                                return nameA.localeCompare(nameB);
                            });
                            this.equipmentTypes = options;
                        } else {
                            this.equipmentTypes = [];
                        }
                    } else {
                        this.equipmentTypes = [];
                    }
                } else {
                    this.equipmentTypes = [];
                }
            } catch (error) {
                this.equipmentTypes = [];
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoadEquipmentTypes')
                });
            }
        },

        async loadCustomFieldSets() {
            try {
                const customFieldSetRepository = this.repositoryFactory.create('custom_field_set');
                const criteria = new Criteria();
                criteria.addAssociation('customFields');
                criteria.addFilter(Criteria.equals('name', 'supplier_fields'));

                const result = await customFieldSetRepository.search(criteria, Shopware.Context.api);
                this.customFieldSets = Array.from(result);
            } catch (error) {
                console.error('Error loading custom field sets:', error);
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoadFields')
                });
                this.customFieldSets = [];
            }
        },

        async getSupplier() {
            this.isLoading = true;
            try {
                if (this.$route.params.id) {
                    const criteria = new Criteria();
                    criteria.addAssociation('media');
                    const entity = await this.repository.get(this.$route.params.id, Shopware.Context.api, criteria);
                    this.supplier = entity;
                } else {
                    this.supplier = this.repository.create();
                }

                if (!this.supplier.customFields) {
                    this.supplier.customFields = {};
                }
                if (!this.supplier.manufacturerIds || !Array.isArray(this.supplier.manufacturerIds)) {
                    this.supplier.manufacturerIds = [];
                }
                if (!this.supplier.alternativeManufacturerIds || !Array.isArray(this.supplier.alternativeManufacturerIds)) {
                    this.supplier.alternativeManufacturerIds = [];
                }
                if (!this.supplier.equipmentTypeIds || !Array.isArray(this.supplier.equipmentTypeIds)) {
                    this.supplier.equipmentTypeIds = [];
                }
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorLoad')
                });
            } finally {
                this.isLoading = false;
            }
        },

        onClickSave() {
            this.isLoading = true;
            this.processSuccess = false;
            const isNew = !this.$route.params.id;
            const supplierId = this.supplier.id;

            return this.repository.save(this.supplier, Shopware.Context.api)
                .then(() => {
                    const criteria = new Criteria();
                    criteria.addAssociation('media');
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
                    if (!this.supplier.alternativeManufacturerIds || !Array.isArray(this.supplier.alternativeManufacturerIds)) {
                        this.supplier.alternativeManufacturerIds = [];
                    }
                    if (!this.supplier.equipmentTypeIds || !Array.isArray(this.supplier.equipmentTypeIds)) {
                        this.supplier.equipmentTypeIds = [];
                    }

                    // Force update media items in UI
                    this.$forceUpdate();

                    this.createNotificationSuccess({
                        message: this.$tc('supplier.detail.successSave')
                    });

                    this.isLoading = false;
                    this.processSuccess = true;

                    if (isNew) {
                        return this.$router.push({
                            name: 'artiss.supplier.detail',
                            params: { id: supplierId }
                        });
                    }
                })
                .catch((error) => {
                    console.error('Save error:', error);
                    this.createNotificationError({
                        message: this.$tc('supplier.detail.errorSave')
                    });
                    this.isLoading = false;
                });
        },

        saveFinish() {
            this.processSuccess = false;
        },

        onManufacturersChange(selectedValues) {
            if (this.supplier) {
                this.supplier.manufacturerIds = Array.isArray(selectedValues) ? selectedValues : [];
            }
        },

        onAlternativeManufacturersChange(selectedValues) {
            if (this.supplier) {
                this.supplier.alternativeManufacturerIds = Array.isArray(selectedValues) ? selectedValues : [];
            }
        },

        onEquipmentTypesChange(selectedValues) {
            if (this.supplier) {
                this.supplier.equipmentTypeIds = Array.isArray(selectedValues) ? selectedValues : [];
            }
        },

        async loadMediaFolder() {
            try {
                const mediaFolderRepository = this.repositoryFactory.create('media_folder');
                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('name', 'Suppliers Prices'));

                const result = await mediaFolderRepository.search(criteria, Shopware.Context.api);
                if (result.length > 0) {
                    this.mediaFolderId = result.first().id;
                }
            } catch (error) {
                console.error('Error loading media folder:', error);
            }
        },

        async onMediaUploadFinish({ targetId }) {
            const media = await this.mediaRepository.get(targetId, Shopware.Context.api);

            if (media && this.supplier.media) {
                this.supplier.media.add(media);
            }
        },

        onRemoveMedia(item) {
            if (!this.supplier.media) {
                return;
            }

            this.supplier.media.remove(item.id);
        },

        onDownloadMedia(item) {
            if (!item || !item.url) {
                this.createNotificationError({
                    message: this.$tc('supplier.detail.errorDownload')
                });
                return;
            }

            // Create temporary link and trigger download
            const link = document.createElement('a');
            link.href = item.url;
            link.download = item.fileName || 'download';
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
});
