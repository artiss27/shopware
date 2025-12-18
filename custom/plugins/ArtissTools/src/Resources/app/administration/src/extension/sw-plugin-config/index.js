import template from './sw-plugin-config.html.twig';
import './sw-plugin-config.scss';

const { Component } = Shopware;

Component.override('sw-plugin-config', {
    template,

    data() {
        return {
            artissBackupFullPath: null
        };
    },

    computed: {
        isArtissToolsConfig() {
            return this.$route.params?.namespace === 'ArtissTools';
        }
    },

    watch: {
        '$route.params.namespace': {
            immediate: true,
            handler(namespace) {
                if (namespace === 'ArtissTools') {
                    this.loadArtissBackupPath();
                }
            }
        }
    },

    methods: {
        async loadArtissBackupPath() {
            const loginService = Shopware.Service('loginService');
            const httpClient = Shopware.Application.getContainer('init').httpClient;
            
            try {
                const response = await httpClient.get(
                    '/_action/artiss-tools/backup/config',
                    {
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'Authorization': `Bearer ${loginService.getToken()}`
                        }
                    }
                );

                if (response.data.success && response.data.data) {
                    const config = response.data.data;
                    this.artissBackupFullPath = `${config.projectDir}/${config.backupPath}`;
                }
            } catch (error) {
                console.error('Failed to load Artiss backup config:', error);
            }
        }
    }
});

