import template from './sw-plugin-config.html.twig';
import './sw-plugin-config.scss';

const { Component } = Shopware;

Component.override('sw-plugin-config', {
    template,

    data() {
        return {
            artissBackupFullPath: null,
            customFieldSets: []
        };
    },

    inject: ['repositoryFactory'],

    computed: {
        isArtissToolsConfig() {
            return this.$route.params?.namespace === 'ArtissTools';
        },

        customFieldSetRepository() {
            return this.repositoryFactory.create('custom_field_set');
        }
    },

    watch: {
        '$route.params.namespace': {
            immediate: true,
            handler(namespace) {
                if (namespace === 'ArtissTools') {
                    this.loadArtissBackupPath();
                    this.loadCustomFieldSets();
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
        },

        async loadCustomFieldSets() {
            try {
                const criteria = new Shopware.Data.Criteria();
                criteria.addFilter(
                    Shopware.Data.Criteria.equals('relations.entityName', 'product')
                );
                criteria.addSorting(Shopware.Data.Criteria.sort('name', 'ASC'));

                const result = await this.customFieldSetRepository.search(criteria, Shopware.Context.api);

                this.customFieldSets = [];
                result.forEach((entity) => {
                    this.customFieldSets.push({
                        id: entity.name,
                        name: entity.config?.label ? `${entity.config.label} (${entity.name})` : entity.name
                    });
                });
            } catch (error) {
                console.error('Failed to load custom field sets:', error);
            }
        },

        getElementBind(element) {
            const bind = this.$super('getElementBind', element);

            // Override options for productMergeCustomFieldSet
            if (this.isArtissToolsConfig && element.name === 'ArtissTools.config.productMergeCustomFieldSet') {
                bind.options = this.customFieldSets;
            }

            return bind;
        }
    }
});

