import template from './cleanup-tab.html.twig';
import './cleanup-tab.scss';

const { Component, Mixin, Data: { Criteria } } = Shopware;

Component.register('artiss-images-cleanup-tab', {
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
            isLoadingSize: false,
            
            // Media library size
            mediaLibrarySize: null,
            mediaLibrarySizeFormatted: null,
            
            // Parameters (from media:delete-unused command)
            folderEntity: 'product', // Default to product, null = all folders
            gracePeriodDays: 20,
            limit: 100,
            offset: 0,
            dryRun: true,
            report: true,
            showAdvanced: false,
            
            // Folder entity options (loaded from repository)
            folderEntityOptions: [],
            
            // Command results
            commandResult: null,
            showResults: false,
            
            // Confirmation modal
            showConfirmModal: false
        };
    },

    computed: {
        defaultFolderRepository() {
            return this.repositoryFactory.create('media_default_folder');
        },

        canPreview() {
            return !this.isLoading;
        },

        canDelete() {
            return !this.dryRun && !this.isLoading;
        }
    },

    methods: {
        async calculateMediaLibrarySize() {
            this.isLoadingSize = true;
            try {
                const response = await this.httpClient.get(
                    '/_action/artiss-tools/images/calculate-media-size',
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.mediaLibrarySize = response.data.data.size;
                    this.mediaLibrarySizeFormatted = response.data.data.sizeFormatted;
                } else {
                    this.createNotificationError({
                        message: response.data.message || this.$tc('artissTools.images.cleanup.errors.calculateSizeFailed')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.cleanup.errors.calculateSizeFailed')
                });
            } finally {
                this.isLoadingSize = false;
            }
        },

        async preview() {
            if (!this.canPreview) {
                return;
            }

            this.isLoading = true;
            this.showResults = false;
            this.commandResult = null;

            try {
                const payload = {
                    folderEntity: this.folderEntity || null,
                    gracePeriodDays: this.gracePeriodDays,
                    limit: this.limit,
                    offset: this.offset,
                    dryRun: true,
                    report: this.report
                };

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/images/run-cleanup',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success && response.data.data) {
                    this.commandResult = response.data.data;
                    this.showResults = true;
                } else {
                    this.createNotificationError({
                        message: response.data.data?.output || response.data.error || this.$tc('artissTools.images.cleanup.errors.commandFailed')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.cleanup.errors.commandFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        deleteUnusedMedia() {
            if (!this.canDelete) {
                return;
            }

            this.showConfirmModal = true;
        },

        async confirmDelete() {
            this.showConfirmModal = false;
            this.isLoading = true;
            this.showResults = false;
            this.commandResult = null;

            try {
                const payload = {
                    folderEntity: this.folderEntity || null,
                    gracePeriodDays: this.gracePeriodDays,
                    limit: this.limit,
                    offset: this.offset,
                    dryRun: false,
                    report: this.report
                };

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/images/run-cleanup',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success && response.data.data) {
                    this.commandResult = response.data.data;
                    this.showResults = true;
                    
                    if (this.commandResult.success) {
                        this.createNotificationSuccess({
                            message: this.$tc('artissTools.images.cleanup.messages.deleteSuccess')
                        });
                        
                        // Recalculate size if needed
                        if (this.mediaLibrarySize) {
                            await this.calculateMediaLibrarySize();
                        }
                    } else {
                        this.createNotificationError({
                            message: this.commandResult.output || this.$tc('artissTools.images.cleanup.errors.deleteFailed')
                        });
                    }
                } else {
                    this.createNotificationError({
                        message: response.data.data?.output || response.data.error || this.$tc('artissTools.images.cleanup.errors.deleteFailed')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.cleanup.errors.deleteFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        cancelDelete() {
            this.showConfirmModal = false;
        },

        async loadFolderEntities() {
            try {
                const criteria = new Criteria(1, 100);
                criteria.addSorting(Criteria.sort('entity', 'ASC'));
                
                const result = await this.defaultFolderRepository.search(criteria, Shopware.Context.api);
                
                const folders = [];
                result.forEach(folder => {
                    folders.push({
                        value: folder.entity,
                        label: folder.entity || folder.id
                    });
                });
                
                // Add empty option for "all folders"
                this.folderEntityOptions = [
                    { value: null, label: this.$tc('artissTools.images.cleanup.parameters.folderEntity.all') },
                    ...folders
                ];
            } catch (error) {
                console.error('Error loading folder entities:', error);
                // Fallback options
                this.folderEntityOptions = [
                    { value: null, label: this.$tc('artissTools.images.cleanup.parameters.folderEntity.all') },
                    { value: 'product', label: 'Product' },
                    { value: 'category', label: 'Category' },
                    { value: 'cms_page', label: 'CMS Page' },
                    { value: 'manufacturer', label: 'Manufacturer' }
                ];
            }
        }
    },

    created() {
        this.loadFolderEntities();
    }
});
