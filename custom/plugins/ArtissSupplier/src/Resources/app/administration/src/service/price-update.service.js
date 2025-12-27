const ApiService = Shopware.Classes.ApiService;

class PriceUpdateService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'supplier') {
        super(httpClient, loginService, apiEndpoint);
    }

    previewFile(mediaId, previewRows = 20) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/preview-file`,
                { mediaId, previewRows },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    parseAndNormalize(templateId, mediaId, forceRefresh = false) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/parse`,
                { templateId, mediaId, forceRefresh },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    matchPreview(templateId) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/match-preview`,
                { templateId },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    updateMatch(templateId, productId, supplierCode) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/update-match`,
                { templateId, productId, supplierCode },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    applyPrices(templateId) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/apply`,
                { templateId },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    recalculatePrices(priceType = 'retail', limit = null) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/recalculate`,
                { priceType, limit },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    autoMatch(templateId, batchSize = 50, offset = 0, minMatchPercentage = 50) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/auto-match`,
                { templateId, batchSize, offset, minMatchPercentage },
                { headers }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}

export default PriceUpdateService;
