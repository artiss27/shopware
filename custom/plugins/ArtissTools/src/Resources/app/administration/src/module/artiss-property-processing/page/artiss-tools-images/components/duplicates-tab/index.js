import template from './duplicates-tab.html.twig';
import './duplicates-tab.scss';

const { Component, Mixin } = Shopware;

Component.register('artiss-images-duplicates-tab', {
    template,

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
            isUpdatingHashes: false,
            isAutoMerging: false,

            // Hash status
            hashStatus: null,

            // Search parameters
            folderEntity: null,
            folderEntityOptions: [],
            recalculateAll: false,

            // Current duplicate set
            currentDuplicateSet: null,
            showDuplicateSet: false
        };
    },

    computed: {
        canSearch() {
            return !this.isLoading && !this.isUpdatingHashes;
        },

        canMerge() {
            return this.currentDuplicateSet && !this.isLoading;
        },

        hashStatusText() {
            if (!this.hashStatus) {
                return this.$tc('artissTools.images.duplicates.hashStatus.notCalculated');
            }

            return `${this.$tc('artissTools.images.duplicates.hashStatus.lastUpdate')}: ${this.formatDate(this.hashStatus.lastUpdate)} (${this.hashStatus.totalHashed} ${this.$tc('artissTools.images.duplicates.hashStatus.files')})`;
        }
    },

    methods: {
        async loadHashStatus() {
            try {
                const response = await this.httpClient.get(
                    '/_action/artiss-tools/images/hash-status',
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.hashStatus = response.data.data;
                }
            } catch (error) {
                console.error('Error loading hash status:', error);
            }
        },

        async updateHashes() {
            this.isUpdatingHashes = true;
            let totalProcessed = 0;
            let hasMore = true;
            let offset = 0;

            try {
                while (hasMore) {
                    const payload = {
                        batchSize: 1000,
                        folderEntity: this.folderEntity || null,
                        offset: offset,
                        recalculateAll: this.recalculateAll
                    };

                    const response = await this.httpClient.post(
                        '/_action/artiss-tools/images/update-hashes',
                        payload,
                        {
                            headers: this.getAuthHeaders()
                        }
                    );

                    if (response.data.success && response.data.data) {
                        totalProcessed += response.data.data.processed;
                        hasMore = response.data.data.hasMore || false;
                        offset = response.data.data.nextOffset || 0;

                        // Update notification with progress
                        this.createNotificationInfo({
                            message: `${this.$tc('artissTools.images.duplicates.messages.processing')}: ${totalProcessed} / ${response.data.data.totalHashed}`
                        });

                        // If no more files processed in this batch, stop
                        if (response.data.data.processed === 0) {
                            hasMore = false;
                        }
                    } else {
                        this.createNotificationError({
                            message: response.data.error || this.$tc('artissTools.images.duplicates.errors.hashUpdateFailed')
                        });
                        break;
                    }
                }

                if (totalProcessed > 0) {
                    this.createNotificationSuccess({
                        message: `${this.$tc('artissTools.images.duplicates.messages.hashUpdateComplete')}: ${totalProcessed} ${this.$tc('artissTools.images.duplicates.hashStatus.files')}`
                    });
                } else {
                    this.createNotificationInfo({
                        message: this.$tc('artissTools.images.duplicates.messages.noNewFiles')
                    });
                }

                // Reload status
                await this.loadHashStatus();

            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.duplicates.errors.hashUpdateFailed')
                });
            } finally {
                this.isUpdatingHashes = false;
            }
        },

        async findNextDuplicate() {
            this.isLoading = true;
            this.showDuplicateSet = false;
            this.currentDuplicateSet = null;

            try {
                const payload = {
                    folderEntity: this.folderEntity || null
                };

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/images/find-next-duplicate',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    if (response.data.data === null) {
                        this.createNotificationInfo({
                            message: this.$tc('artissTools.images.duplicates.messages.noDuplicates')
                        });
                    } else {
                        this.currentDuplicateSet = response.data.data;
                        this.showDuplicateSet = true;
                    }
                } else {
                    this.createNotificationError({
                        message: response.data.error || this.$tc('artissTools.images.duplicates.errors.searchFailed')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.duplicates.errors.searchFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async mergeDuplicateSet() {
            if (!this.currentDuplicateSet) {
                return;
            }

            this.isLoading = true;

            try {
                const keeperMediaId = this.currentDuplicateSet.keeperMediaId;
                const duplicateMediaIds = this.currentDuplicateSet.mediaList
                    .filter(m => m.id !== keeperMediaId)
                    .map(m => m.id);

                const payload = {
                    keeperMediaId: keeperMediaId,
                    duplicateMediaIds: duplicateMediaIds
                };

                const response = await this.httpClient.post(
                    '/_action/artiss-tools/images/merge-duplicates',
                    payload,
                    {
                        headers: this.getAuthHeaders()
                    }
                );

                if (response.data.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.images.duplicates.messages.mergeSuccess', null, {
                            count: response.data.data.updatedReferencesCount
                        })
                    });

                    // Clear current set and find next
                    this.currentDuplicateSet = null;
                    this.showDuplicateSet = false;

                    // Auto-find next duplicate
                    await this.findNextDuplicate();
                } else {
                    this.createNotificationError({
                        message: response.data.error || this.$tc('artissTools.images.duplicates.errors.mergeFailed')
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.duplicates.errors.mergeFailed')
                });
            } finally {
                this.isLoading = false;
            }
        },

        async mergeAllDuplicates() {
            this.isAutoMerging = true;
            let totalMerged = 0;
            let totalUpdatedReferences = 0;

            try {
                // eslint-disable-next-line no-constant-condition
                while (true) {
                    // Find next duplicate set
                    const payload = {
                        folderEntity: this.folderEntity || null
                    };

                    const findResponse = await this.httpClient.post(
                        '/_action/artiss-tools/images/find-next-duplicate',
                        payload,
                        {
                            headers: this.getAuthHeaders()
                        }
                    );

                    if (!findResponse.data.success || findResponse.data.data === null) {
                        // No more duplicates
                        break;
                    }

                    const duplicateSet = findResponse.data.data;

                    // Merge this set
                    const keeperMediaId = duplicateSet.keeperMediaId;
                    const duplicateMediaIds = duplicateSet.mediaList
                        .filter(m => m.id !== keeperMediaId)
                        .map(m => m.id);

                    const mergePayload = {
                        keeperMediaId: keeperMediaId,
                        duplicateMediaIds: duplicateMediaIds
                    };

                    const mergeResponse = await this.httpClient.post(
                        '/_action/artiss-tools/images/merge-duplicates',
                        mergePayload,
                        {
                            headers: this.getAuthHeaders()
                        }
                    );

                    if (mergeResponse.data.success) {
                        totalMerged++;
                        totalUpdatedReferences += mergeResponse.data.data.updatedReferencesCount;

                        // Update progress notification
                        this.createNotificationInfo({
                            message: `${this.$tc('artissTools.images.duplicates.messages.autoMergeProgress')}: ${totalMerged} ${this.$tc('artissTools.images.duplicates.messages.sets')}`
                        });
                    } else {
                        this.createNotificationError({
                            message: mergeResponse.data.error || this.$tc('artissTools.images.duplicates.errors.mergeFailed')
                        });
                        break;
                    }
                }

                // Final notification
                if (totalMerged > 0) {
                    this.createNotificationSuccess({
                        message: `${this.$tc('artissTools.images.duplicates.messages.autoMergeComplete')}: ${totalMerged} ${this.$tc('artissTools.images.duplicates.messages.sets')}, ${totalUpdatedReferences} ${this.$tc('artissTools.images.duplicates.messages.references')}`
                    });
                } else {
                    this.createNotificationInfo({
                        message: this.$tc('artissTools.images.duplicates.messages.noDuplicates')
                    });
                }

                // Clear current set and try to find next
                this.currentDuplicateSet = null;
                this.showDuplicateSet = false;

            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.images.duplicates.errors.mergeFailed')
                });
            } finally {
                this.isAutoMerging = false;
            }
        },

        formatDate(dateString) {
            if (!dateString) {
                return '-';
            }

            const date = new Date(dateString);
            return date.toLocaleString();
        },

        formatBytes(bytes) {
            if (!bytes) {
                return '0 B';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            let size = bytes;
            let unitIndex = 0;

            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }

            return `${size.toFixed(2)} ${units[unitIndex]}`;
        },

        isKeeperMedia(mediaId) {
            return this.currentDuplicateSet && mediaId === this.currentDuplicateSet.keeperMediaId;
        },

        async loadFolderEntities() {
            // Reuse the same logic as cleanup-tab
            this.folderEntityOptions = [
                { value: null, label: this.$tc('artissTools.images.cleanup.parameters.folderEntity.all') },
                { value: 'product', label: 'Product' },
                { value: 'category', label: 'Category' },
                { value: 'cms_page', label: 'CMS Page' },
                { value: 'manufacturer', label: 'Manufacturer' }
            ];
        }
    },

    created() {
        this.loadFolderEntities();
        this.loadHashStatus();
    }
});
