/**
 * Shared error handler mixin for property processing tabs
 * Provides unified error handling across all tabs
 */
export default {
    methods: {
        /**
         * Handle API errors consistently across all tabs
         * @param {Error} error - The error object from axios/httpClient
         * @param {string} fallbackMessage - Fallback translation key if error message not available
         * @returns {string} Error message to display
         */
        handleApiError(error, fallbackMessage = 'artissTools.propertyProcessing.errors.loadFailed') {
            // Try to extract error message from response data
            if (error?.response?.data?.error) {
                return error.response.data.error;
            }
            
            // Try error message
            if (error?.message) {
                return error.message;
            }
            
            // Fallback to translation
            return this.$tc(fallbackMessage);
        },

        /**
         * Standardized API request wrapper
         * Handles loading state, error handling, and notifications
         * @param {Function} apiCall - Async function that returns axios response
         * @param {Object} options - Configuration options
         * @param {string} options.successMessage - Translation key for success message
         * @param {string} options.fallbackErrorMessage - Fallback error message translation key
         * @param {boolean} options.showSuccessNotification - Whether to show success notification (default: true)
         * @returns {Promise<Object|null>} Response data or null on error
         */
        async handleApiRequest(apiCall, options = {}) {
            const {
                successMessage,
                fallbackErrorMessage = 'artissTools.propertyProcessing.errors.loadFailed',
                showSuccessNotification = true
            } = options;

            this.isLoading = true;

            try {
                const response = await apiCall();

                if (response.data.success) {
                    if (showSuccessNotification && successMessage) {
                        this.createNotificationSuccess({
                            message: this.$tc(successMessage)
                        });
                    }
                    return response.data.data;
                } else {
                    throw new Error(response.data.error || 'Unknown error');
                }
            } catch (error) {
                const errorMessage = this.handleApiError(error, fallbackErrorMessage);
                this.createNotificationError({
                    message: errorMessage
                });
                return null;
            } finally {
                this.isLoading = false;
            }
        }
    }
};

