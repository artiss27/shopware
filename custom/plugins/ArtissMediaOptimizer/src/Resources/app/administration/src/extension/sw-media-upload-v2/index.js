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
            artissCropperEnabled: false
        };
    },

    computed: {
        artissIsProductMedia() {
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
            if (!this.artissCropperEnabled || !this.artissIsProductMedia) {
                return false;
            }
            const imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
            return imageTypes.includes(file.type);
        },

        handleFileCheck(files) {
            if (!files || files.length === 0) {
                return this.$super('handleFileCheck', files);
            }

            const filesToCrop = [];
            const filesToUploadDirectly = [];

            for (const file of files) {
                if (!this.checkFileSize(file) || !this.checkFileType(file)) {
                    continue;
                }

                if (this.artissShouldShowCropper(file)) {
                    filesToCrop.push(file);
                } else {
                    filesToUploadDirectly.push(file);
                }
            }

            if (filesToUploadDirectly.length > 0) {
                this.$super('handleFileCheck', filesToUploadDirectly);
            }

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
            
            // Add timestamp suffix to avoid duplicate name conflict
            // Since existing files are converted to webp, but new file has original extension
            const originalName = this.artissCurrentFile?.name || croppedFile.name;
            const nameParts = originalName.split('.');
            const ext = nameParts.pop();
            const baseName = nameParts.join('.');
            const timestamp = Date.now();
            const newName = `${baseName}_${timestamp}.${ext}`;
            
            // Create new file with unique name
            const renamedFile = new File([croppedFile], newName, {
                type: croppedFile.type,
                lastModified: croppedFile.lastModified
            });
            
            this.$super('handleFileCheck', [renamedFile]);
            this.artissProcessNextFile();
        },

        artissOnCropCancel() {
            this.artissShowCropper = false;
            this.artissCurrentFile = null;
            this.artissFilesToProcess = [];
        },

        artissOnCropSkip(originalFile) {
            this.artissShowCropper = false;
            this.$super('handleFileCheck', [originalFile]);
            this.artissProcessNextFile();
        }
    }
});
