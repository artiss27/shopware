import template from './artiss-image-cropper.html.twig';
import './artiss-image-cropper.scss';

const { Component, Mixin } = Shopware;

Component.register('artiss-image-cropper', {
    template,

    inject: ['artissCropperConfigService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        imageFile: {
            type: File,
            required: true
        },
        aspectRatio: {
            type: Number,
            required: false,
            default: null
        }
    },

    data() {
        return {
            cropper: null,
            imageUrl: null,
            selectedRatio: this.aspectRatio,
            isLoading: false,
            previewUrl: null,
            cropperReady: false
        };
    },

    computed: {
        aspectRatioOptions() {
            return [
                { value: null, label: this.$tc('artiss-image-cropper.ratioFree') },
                { value: 1, label: '1:1' },
                { value: 4 / 3, label: '4:3' },
                { value: 3 / 4, label: '3:4' },
                { value: 16 / 9, label: '16:9' }
            ];
        }
    },

    created() {
        this.loadImage();
    },

    beforeDestroy() {
        this.destroyCropper();
        if (this.imageUrl) {
            URL.revokeObjectURL(this.imageUrl);
        }
    },

    methods: {
        loadImage() {
            this.imageUrl = URL.createObjectURL(this.imageFile);
        },

        onImageLoad() {
            this.initCropper();
        },

        async initCropper() {
            const Cropper = await this.loadCropperJs();
            if (!Cropper) {
                this.createNotificationError({
                    message: this.$tc('artiss-image-cropper.cropperLoadError')
                });
                return;
            }

            const imageElement = this.$refs.cropperImage;
            if (!imageElement) {
                return;
            }

            this.cropper = new Cropper(imageElement, {
                aspectRatio: this.selectedRatio,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                ready: () => {
                    this.cropperReady = true;
                    this.updatePreview();
                },
                crop: () => {
                    this.updatePreview();
                }
            });
        },

        async loadCropperJs() {
            if (window.Cropper) {
                return window.Cropper;
            }

            return new Promise((resolve) => {
                // Load Cropper.js CSS
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css';
                document.head.appendChild(link);

                // Load Cropper.js
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js';
                script.onload = () => {
                    resolve(window.Cropper);
                };
                script.onerror = () => {
                    resolve(null);
                };
                document.head.appendChild(script);
            });
        },

        destroyCropper() {
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
        },

        onRatioChange(ratio) {
            this.selectedRatio = ratio;
            if (this.cropper) {
                this.cropper.setAspectRatio(ratio);
            }
        },

        updatePreview() {
            if (!this.cropper || !this.cropperReady) {
                return;
            }

            const canvas = this.cropper.getCroppedCanvas({
                maxWidth: 300,
                maxHeight: 300
            });

            if (canvas) {
                this.previewUrl = canvas.toDataURL('image/jpeg', 0.8);
            }
        },

        rotateLeft() {
            if (this.cropper) {
                this.cropper.rotate(-90);
            }
        },

        rotateRight() {
            if (this.cropper) {
                this.cropper.rotate(90);
            }
        },

        flipHorizontal() {
            if (this.cropper) {
                const data = this.cropper.getData();
                this.cropper.scaleX(data.scaleX === -1 ? 1 : -1);
            }
        },

        flipVertical() {
            if (this.cropper) {
                const data = this.cropper.getData();
                this.cropper.scaleY(data.scaleY === -1 ? 1 : -1);
            }
        },

        reset() {
            if (this.cropper) {
                this.cropper.reset();
            }
        },

        async getCroppedFile() {
            if (!this.cropper) {
                return this.imageFile;
            }

            return new Promise((resolve) => {
                const canvas = this.cropper.getCroppedCanvas({
                    maxWidth: 4096,
                    maxHeight: 4096,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });

                if (!canvas) {
                    resolve(this.imageFile);
                    return;
                }

                canvas.toBlob((blob) => {
                    if (!blob) {
                        resolve(this.imageFile);
                        return;
                    }

                    const croppedFile = new File(
                        [blob],
                        this.imageFile.name,
                        { type: 'image/jpeg', lastModified: Date.now() }
                    );

                    resolve(croppedFile);
                }, 'image/jpeg', 0.92);
            });
        },

        onConfirm() {
            this.isLoading = true;
            this.getCroppedFile().then((file) => {
                this.isLoading = false;
                this.$emit('crop-confirm', file);
            });
        },

        onCancel() {
            this.$emit('crop-cancel');
        },

        onSkip() {
            this.$emit('crop-skip', this.imageFile);
        }
    }
});
