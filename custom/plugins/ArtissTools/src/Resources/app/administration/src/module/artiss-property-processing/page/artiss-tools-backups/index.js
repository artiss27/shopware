import template from './artiss-tools-backups.html.twig';
import './artiss-tools-backups.scss';

const { Component, Mixin } = Shopware;

Component.register('artiss-tools-backups', {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            activeTab: 'create',
            isConfigLoading: true,
            // Plugin configuration
            pluginConfig: {
                backupPath: 'artiss-backups',
                backupRetention: 5,
                dbGzipDefault: true,
                dbTypeDefault: 'smart',
                mediaScopeDefault: 'all',
                mediaExcludeThumbnailsDefault: true,
                dbOutputDir: 'artiss-backups/db',
                mediaOutputDir: 'artiss-backups/media'
            },
            // Create tab
            isDbBackupLoading: false,
            isMediaBackupLoading: false,
            lastDbBackup: null,
            lastMediaBackup: null,
            dbBackup: {
                type: 'smart',
                outputDir: 'artiss-backups/db',
                keep: 5,
                gzip: true,
                comment: ''
            },
            mediaBackup: {
                scope: 'all',
                outputDir: 'artiss-backups/media',
                keep: 5,
                compress: false,
                excludeThumbnails: true,
                comment: ''
            },
            // Restore tab
            isLoadingDbList: false,
            isLoadingMediaList: false,
            dbBackupsList: [],
            mediaBackupsList: [],
            // DB Restore modal
            showDbRestoreModal: false,
            selectedDbBackup: null,
            dbRestoreConfirmation: '',
            dbRestoreOptions: {
                dropTables: false
            },
            isDbRestoring: false,
            // Media Restore modal
            showMediaRestoreModal: false,
            selectedMediaBackup: null,
            mediaRestoreConfirmed: false,
            mediaRestoreOptions: {
                mode: 'add'
            },
            isMediaRestoring: false
        };
    },

    computed: {
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        dbTypeOptions() {
            return [
                { value: 'smart', label: this.$tc('artissTools.backups.create.dbBackup.types.smart') },
                { value: 'full', label: this.$tc('artissTools.backups.create.dbBackup.types.full') }
            ];
        },

        mediaScopeOptions() {
            return [
                { value: 'all', label: this.$tc('artissTools.backups.create.mediaBackup.scopes.all') },
                { value: 'product', label: this.$tc('artissTools.backups.create.mediaBackup.scopes.product') }
            ];
        },

        mediaRestoreModeOptions() {
            return [
                { value: 'add', label: this.$tc('artissTools.backups.restore.mediaRestore.modes.add') },
                { value: 'overwrite', label: this.$tc('artissTools.backups.restore.mediaRestore.modes.overwrite') },
                { value: 'clean', label: this.$tc('artissTools.backups.restore.mediaRestore.modes.clean') }
            ];
        },

        canRestoreDb() {
            return this.dbRestoreConfirmation.toUpperCase() === 'RESTORE';
        },

        canRestoreMedia() {
            return this.mediaRestoreConfirmed;
        }
    },

    watch: {
        activeTab(newVal) {
            if (newVal === 'restore') {
                this.loadBackupsList();
            }
        }
    },

    created() {
        this.loadConfig();
    },

    methods: {
        async loadConfig() {
            this.isConfigLoading = true;

            try {
                const response = await this.httpClient.get(
                    '/_action/artiss-tools/backup/config',
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success && response.data.data) {
                    this.pluginConfig = response.data.data;
                    
                    // Apply config defaults to forms
                    this.dbBackup.type = this.pluginConfig.dbTypeDefault;
                    this.dbBackup.outputDir = this.pluginConfig.dbOutputDir;
                    this.dbBackup.keep = this.pluginConfig.backupRetention;
                    this.dbBackup.gzip = this.pluginConfig.dbGzipDefault;

                    this.mediaBackup.scope = this.pluginConfig.mediaScopeDefault;
                    this.mediaBackup.outputDir = this.pluginConfig.mediaOutputDir;
                    this.mediaBackup.keep = this.pluginConfig.backupRetention;
                    this.mediaBackup.excludeThumbnails = this.pluginConfig.mediaExcludeThumbnailsDefault;
                }
            } catch (error) {
                console.error('Failed to load backup config:', error);
            } finally {
                this.isConfigLoading = false;
                this.loadLastBackups();
            }
        },

        onTabChange(tabItem) {
            let newTab = 'create';

            if (typeof tabItem === 'string') {
                newTab = tabItem;
            } else if (tabItem && typeof tabItem === 'object') {
                newTab = tabItem.name || tabItem.id || tabItem.key || 'create';
            }

            this.activeTab = newTab;
        },

        getAuthHeaders() {
            const loginService = Shopware.Service('loginService');
            const token = loginService.getToken();

            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };

            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }

            return headers;
        },

        async loadLastBackups() {
            try {
                const [dbResponse, mediaResponse] = await Promise.all([
                    this.httpClient.get(
                        '/_action/artiss-tools/backup/last/db',
                        { headers: this.getAuthHeaders() }
                    ),
                    this.httpClient.get(
                        '/_action/artiss-tools/backup/last/media',
                        { headers: this.getAuthHeaders() }
                    )
                ]);

                if (dbResponse.data.success && dbResponse.data.data) {
                    this.lastDbBackup = dbResponse.data.data;
                }

                if (mediaResponse.data.success && mediaResponse.data.data) {
                    this.lastMediaBackup = mediaResponse.data.data;
                }
            } catch (error) {
                console.error('Failed to load last backups:', error);
            }
        },

        async loadBackupsList() {
            this.isLoadingDbList = true;
            this.isLoadingMediaList = true;

            try {
                const [dbResponse, mediaResponse] = await Promise.all([
                    this.httpClient.get(
                        '/_action/artiss-tools/backup/list/db',
                        { headers: this.getAuthHeaders() }
                    ),
                    this.httpClient.get(
                        '/_action/artiss-tools/backup/list/media',
                        { headers: this.getAuthHeaders() }
                    )
                ]);

                if (dbResponse.data.success) {
                    this.dbBackupsList = dbResponse.data.data || [];
                }

                if (mediaResponse.data.success) {
                    this.mediaBackupsList = mediaResponse.data.data || [];
                }
            } catch (error) {
                console.error('Failed to load backups list:', error);
                this.createNotificationError({
                    message: this.$tc('artissTools.backups.restore.messages.loadError')
                });
            } finally {
                this.isLoadingDbList = false;
                this.isLoadingMediaList = false;
            }
        },

        async createDbBackup() {
            this.isDbBackupLoading = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/backup/db',
                    {
                        type: this.dbBackup.type,
                        outputDir: this.dbBackup.outputDir,
                        keep: this.dbBackup.keep,
                        gzip: this.dbBackup.gzip,
                        comment: this.dbBackup.comment || null
                    },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.backups.create.messages.dbSuccess')
                    });

                    if (response.data.data?.lastBackup) {
                        this.lastDbBackup = response.data.data.lastBackup;
                    }
                    
                    this.dbBackup.comment = '';
                } else {
                    throw new Error(response.data.error || 'Unknown error');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.backups.create.messages.dbError')
                });
            } finally {
                this.isDbBackupLoading = false;
            }
        },

        async createMediaBackup() {
            this.isMediaBackupLoading = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/backup/media',
                    {
                        scope: this.mediaBackup.scope,
                        outputDir: this.mediaBackup.outputDir,
                        keep: this.mediaBackup.keep,
                        compress: this.mediaBackup.compress,
                        excludeThumbnails: this.mediaBackup.excludeThumbnails,
                        comment: this.mediaBackup.comment || null
                    },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.backups.create.messages.mediaSuccess')
                    });

                    if (response.data.data?.lastBackup) {
                        this.lastMediaBackup = response.data.data.lastBackup;
                    }
                    
                    this.mediaBackup.comment = '';
                } else {
                    throw new Error(response.data.error || 'Unknown error');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.backups.create.messages.mediaError')
                });
            } finally {
                this.isMediaBackupLoading = false;
            }
        },

        // DB Restore
        openDbRestoreModal(backup) {
            this.selectedDbBackup = backup;
            this.dbRestoreConfirmation = '';
            this.dbRestoreOptions.dropTables = false;
            this.showDbRestoreModal = true;
        },

        closeDbRestoreModal() {
            this.showDbRestoreModal = false;
            this.selectedDbBackup = null;
            this.dbRestoreConfirmation = '';
        },

        async restoreDb() {
            if (!this.canRestoreDb || !this.selectedDbBackup) {
                return;
            }

            this.isDbRestoring = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/restore/db',
                    {
                        backupFile: this.selectedDbBackup.relativePath,
                        dropTables: this.dbRestoreOptions.dropTables,
                        noForeignChecks: true
                    },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.backups.restore.messages.dbRestoreSuccess')
                    });
                    this.closeDbRestoreModal();
                } else {
                    throw new Error(response.data.error || 'Unknown error');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.backups.restore.messages.dbRestoreError')
                });
            } finally {
                this.isDbRestoring = false;
            }
        },

        // Media Restore
        openMediaRestoreModal(backup) {
            this.selectedMediaBackup = backup;
            this.mediaRestoreConfirmed = false;
            this.mediaRestoreOptions.mode = 'add';
            this.showMediaRestoreModal = true;
        },

        closeMediaRestoreModal() {
            this.showMediaRestoreModal = false;
            this.selectedMediaBackup = null;
            this.mediaRestoreConfirmed = false;
        },

        async restoreMedia() {
            if (!this.canRestoreMedia || !this.selectedMediaBackup) {
                return;
            }

            this.isMediaRestoring = true;

            try {
                const response = await this.httpClient.post(
                    '/_action/artiss-tools/restore/media',
                    {
                        backupFile: this.selectedMediaBackup.relativePath,
                        mode: this.mediaRestoreOptions.mode
                    },
                    { headers: this.getAuthHeaders() }
                );

                if (response.data.success) {
                    this.createNotificationSuccess({
                        message: this.$tc('artissTools.backups.restore.messages.mediaRestoreSuccess')
                    });
                    this.closeMediaRestoreModal();
                } else {
                    throw new Error(response.data.error || 'Unknown error');
                }
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error || error.message || this.$tc('artissTools.backups.restore.messages.mediaRestoreError')
                });
            } finally {
                this.isMediaRestoring = false;
            }
        }
    }
});
