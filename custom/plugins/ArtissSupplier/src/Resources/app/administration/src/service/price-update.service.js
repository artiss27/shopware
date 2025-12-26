const ApiService = Shopware.Classes.ApiService;

class PriceUpdateService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'supplier') {
        super(httpClient, loginService, apiEndpoint);
    }

    previewFile(mediaId) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/preview-file`,
                { mediaId },
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

    updateMatch(templateId, code, productId) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `/_action/${this.getApiBasePath()}/price-update/update-match`,
                { templateId, code, productId },
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
}

export default PriceUpdateService;
