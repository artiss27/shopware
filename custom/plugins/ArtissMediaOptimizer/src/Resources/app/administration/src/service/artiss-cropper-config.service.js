const { Application } = Shopware;

class ArtissCropperConfigService {
    constructor() {
        this.configCache = null;
    }

    getSystemConfigApiService() {
        // Try multiple ways to get the service
        try {
            return Shopware.Service('systemConfigApiService') ||
                   Application.getContainer('init')?.systemConfigApiService ||
                   Application.getContainer('service')?.systemConfigApiService;
        } catch (e) {
            return null;
        }
    }

    async getConfig() {
        if (this.configCache) {
            return this.configCache;
        }

        const systemConfigApiService = this.getSystemConfigApiService();
        
        if (!systemConfigApiService || typeof systemConfigApiService.getValues !== 'function') {
            this.configCache = {};
            return {};
        }

        try {
            const config = await systemConfigApiService.getValues('ArtissMediaOptimizer.config');
            this.configCache = config;
            return config;
        } catch (error) {
            this.configCache = {};
            return {};
        }
    }

    async isCropperEnabled() {
        const config = await this.getConfig();
        return config['ArtissMediaOptimizer.config.enableCropper'] === true;
    }

    async getAspectRatio() {
        const config = await this.getConfig();
        const ratio = config['ArtissMediaOptimizer.config.productImageAspectRatio'] || 'free';

        const ratioMap = {
            'free': null,
            '1:1': 1,
            '4:3': 4 / 3,
            '3:4': 3 / 4,
            '16:9': 16 / 9,
            'custom': null
        };

        if (ratio === 'custom') {
            const width = parseInt(config['ArtissMediaOptimizer.config.customAspectRatioWidth'], 10) || 1;
            const height = parseInt(config['ArtissMediaOptimizer.config.customAspectRatioHeight'], 10) || 1;
            return width / height;
        }

        return ratioMap[ratio] ?? null;
    }

    async getAspectRatioLabel() {
        const config = await this.getConfig();
        const ratio = config['ArtissMediaOptimizer.config.productImageAspectRatio'] || 'free';

        if (ratio === 'custom') {
            const width = config['ArtissMediaOptimizer.config.customAspectRatioWidth'] || 1;
            const height = config['ArtissMediaOptimizer.config.customAspectRatioHeight'] || 1;
            return `${width}:${height}`;
        }

        return ratio;
    }

    clearCache() {
        this.configCache = null;
    }
}

Application.addServiceProvider('artissCropperConfigService', () => {
    return new ArtissCropperConfigService();
});

export default ArtissCropperConfigService;
