import template from './sw-media-upload-v2.html.twig';

const { Component } = Shopware;

Component.override('sw-media-upload-v2', {
    template,

    inject: ['artissCropperConfigService'],

    data() {
        return {
            artissShowCropper: false,
            artissCurrentFile: null,
            artissFilesToProcess: [],
            artissAspectRatio: null,
            artissCropperEnabled: false,
            artissProcessedFiles: new WeakSet()
        };
    },

    computed: {
        artissIsProductMedia() {
            // Check if we're in product media context
            return this.$route?.name?.includes('product') ||
                   this.uploadTag?.includes('product') ||
                   this.defaultFolder === 'product';
        }
    },

    async created() {
        await this.artissLoadConfig();
    },

    methods: {
        async artissLoadConfig() {
            try {
                this.artissCropperEnabled = await this.artissCropperConfigService.isCropperEnabled();
                this.artissAspectRatio = await this.artissCropperConfigService.getAspectRatio();
            } catch (error) {
                this.artissCropperEnabled = false;
            }
        },

        artissShouldShowCropper(file) {
            // Don't show cropper for already processed files
            if (this.artissProcessedFiles.has(file)) {
                return false;
            }

            if (!this.artissCropperEnabled) {
                return false;
            }

            if (!this.artissIsProductMedia) {
                return false;
            }

            // Only show cropper for images
            const imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
            return imageTypes.includes(file.type);
        },

        handleFileCheck(files) {
            if (!files || files.length === 0) {
                return this.$super('handleFileCheck', files);
            }

            // Filter image files that should show cropper
            const filesToCrop = [];
            const filesToUploadDirectly = [];

            for (const file of files) {
                // First check if file passes validation (size and type)
                if (!this.checkFileSize(file) || !this.checkFileType(file)) {
                    continue;
                }

                if (this.artissShouldShowCropper(file)) {
                    filesToCrop.push(file);
                } else {
                    filesToUploadDirectly.push(file);
                }
            }

            // Upload non-image files directly using parent method
            if (filesToUploadDirectly.length > 0) {
                this.$super('handleFileCheck', filesToUploadDirectly);
            }

            // Queue image files for cropping
            if (filesToCrop.length > 0) {
                this.artissFilesToProcess = filesToCrop;
                this.artissProcessNextFile();
            }
        },

        artissProcessNextFile() {
            if (this.artissFilesToProcess.length === 0) {
                return;
            }

            this.artissCurrentFile = this.artissFilesToProcess.shift();
            this.artissShowCropper = true;
        },

        artissOnCropConfirm(croppedFile) {
            this.artissShowCropper = false;
            // Mark file as processed to avoid showing cropper again
            this.artissProcessedFiles.add(croppedFile);
            // Use handleFileCheck to properly handle file replacement
            this.handleFileCheck([croppedFile]);
            this.artissProcessNextFile();
        },

        artissOnCropCancel() {
            this.artissShowCropper = false;
            this.artissCurrentFile = null;
            this.artissFilesToProcess = [];
        },

        artissOnCropSkip(originalFile) {
            this.artissShowCropper = false;
            // Mark file as processed to avoid showing cropper again
            this.artissProcessedFiles.add(originalFile);
            // Use handleFileCheck to properly handle file replacement
            this.handleFileCheck([originalFile]);
            this.artissProcessNextFile();
        },

        handleUpload(files) {
            return this.$super('handleUpload', files);
        }
    }
});
