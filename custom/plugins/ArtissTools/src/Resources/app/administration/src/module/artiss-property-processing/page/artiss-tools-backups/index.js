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
            isDbBackupLoading: false,
            isMediaBackupLoading: false,
            lastDbBackup: null,
            lastMediaBackup: null,
            dbBackup: {
                type: 'smart',
                outputDir: 'var/artiss-backups/db',
                keep: 3,
                gzip: true,
                comment: ''
            },
            mediaBackup: {
                scope: 'all',
                outputDir: 'var/artiss-backups/media',
                keep: 3,
                excludeThumbnails: true,
                comment: ''
            }
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
        }
    },

    created() {
        this.loadLastBackups();
    },

    methods: {
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
        }
    }
});
