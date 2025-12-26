import template from './artiss-custom-fields-manager.html.twig';
import './artiss-custom-fields-manager.scss';

// Import snippets
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import ruRU from './snippet/ru-RU.json';
import ukUA from './snippet/uk-UA.json';

const { Component } = Shopware;

// Register snippets
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('ru-RU', ruRU);
Shopware.Locale.extend('uk-UA', ukUA);

/**
 * Component for managing custom fields with smart display:
 * - Shows only filled custom fields by default
 * - Provides modal to add hidden (empty) fields
 * - Allows removing/clearing custom field values
 */
Component.register('artiss-custom-fields-manager', {
    template,

    props: {
        entity: {
            type: Object,
            required: true
        },
        sets: {
            type: Array,
            required: true,
            default: () => []
        }
    },

    data() {
        return {
            showAddFieldModal: false,
            searchTerm: '',
            visibleFields: [],
            hiddenFields: [],
            fieldOrder: [] // Track order of fields as they are added
        };
    },

    computed: {
        /**
         * Get all custom fields from all sets
         */
        allCustomFields() {
            console.log('🔍 allCustomFields - sets:', this.sets?.length);
            const fields = [];

            this.sets.forEach((set) => {
                console.log(`🔍 Set: ${set.name}, has customFields:`, !!set.customFields);
                if (set.customFields) {
                    // Check if it's an array or object
                    const customFieldsArray = Array.isArray(set.customFields)
                        ? set.customFields
                        : (set.customFields.elements || Object.values(set.customFields));

                    console.log(`🔍 Set ${set.name} - fields length:`, customFieldsArray?.length);

                    if (customFieldsArray && customFieldsArray.length > 0) {
                        customFieldsArray.forEach(field => {
                            fields.push({
                                ...field,
                                setId: set.id,
                                setName: set.name,
                                setLabel: this.getSetLabel(set)
                            });
                        });
                    }
                }
            });

            console.log('🔍 Total fields:', fields.length);
            return fields;
        },

        /**
         * Filtered hidden fields based on search term
         */
        filteredHiddenFields() {
            if (!this.searchTerm) {
                return this.hiddenFields;
            }

            const search = this.searchTerm.toLowerCase();
            return this.hiddenFields.filter(field => {
                const name = field.name?.toLowerCase() || '';
                const label = String(this.getFieldLabel(field) || '').toLowerCase();
                return name.includes(search) || label.includes(search);
            });
        }
    },

    watch: {
        'entity.customFields': {
            handler() {
                this.updateFieldsVisibility();
            },
            deep: true
        },
        
        sets: {
            handler(newSets) {
                // Check if customFields are loaded
                if (newSets && newSets.length > 0) {
                    const firstSet = newSets[0];
                    if (firstSet.customFields && firstSet.customFields.length > 0) {
                        // Data is loaded, update visibility
                        this.updateFieldsVisibility();
                    }
                }
            },
            deep: true,
            immediate: true
        },

        allCustomFields: {
            handler(newFields) {
                if (newFields.length > 0) {
                    this.updateFieldsVisibility();
                }
            }
        }
    },

    created() {
        this.initializeCustomFields();
        // Initialize field order from existing fields
        if (this.entity.customFields) {
            this.fieldOrder = Object.keys(this.entity.customFields).filter(key => this.hasValue(this.entity.customFields[key]));
        }
        this.updateFieldsVisibility();
    },

    mounted() {
        // Force update after mount to catch async loaded data
        this.$nextTick(() => {
            setTimeout(() => {
                this.updateFieldsVisibility();
            }, 500);
        });
    },

    methods: {
        /**
         * Initialize custom fields object if not exists
         */
        initializeCustomFields() {
            if (!this.entity.customFields) {
                this.entity.customFields = {};
            }
        },

        /**
         * Update visible and hidden fields based on current values
         */
        updateFieldsVisibility() {
            const customFieldsValues = this.entity.customFields || {};
            
            const visible = this.allCustomFields.filter(field => {
                const value = customFieldsValues[field.name];
                return this.hasValue(value);
            });

            // Sort visible fields: existing fields first (in fieldOrder), then new fields at the end
            this.visibleFields = visible.sort((a, b) => {
                const indexA = this.fieldOrder.indexOf(a.name);
                const indexB = this.fieldOrder.indexOf(b.name);

                // Both in fieldOrder - sort by order
                if (indexA !== -1 && indexB !== -1) {
                    return indexA - indexB;
                }

                // A in order, B not - A comes first
                if (indexA !== -1) return -1;

                // B in order, A not - B comes first
                if (indexB !== -1) return 1;

                // Both not in order - new fields, keep original order
                return 0;
            });

            this.hiddenFields = this.allCustomFields.filter(field => {
                const value = customFieldsValues[field.name];
                return !this.hasValue(value);
            });
        },

        /**
         * Check if a value is considered "filled"
         * Empty string ('') and 0 are considered filled because user explicitly set them
         */
        hasValue(value) {
            // null and undefined are not filled
            if (value === null || value === undefined || value === 0) {
                return false;
            }
            
            // Empty arrays are not filled
            if (Array.isArray(value) && value.length === 0) {
                return false;
            }
            
            // Everything else is considered filled (including '', 0, false)
            return true;
        },

        /**
         * Open modal to add hidden fields
         */
        openAddFieldModal() {
            this.searchTerm = '';
            this.showAddFieldModal = true;
        },

        /**
         * Close add field modal
         */
        closeAddFieldModal() {
            this.showAddFieldModal = false;
            this.searchTerm = '';
        },

        /**
         * Add a field to visible list (when selected from modal)
         */
        addField(field) {
            // Initialize with empty value based on field type
            const defaultValue = this.getDefaultValue(field);
            
            // Vue 3 - direct assignment
            this.entity.customFields[field.name] = defaultValue;

            // Add to end of field order (for newly added fields)
            if (!this.fieldOrder.includes(field.name)) {
                this.fieldOrder.push(field.name);
            }

            // Force update visibility immediately so field disappears from modal and appears in list
            this.$nextTick(() => {
                this.updateFieldsVisibility();
            });

            // Don't close modal - user might want to add more fields
        },

        /**
         * Get default value for a field based on its type
         */
        getDefaultValue(field) {
            const type = field.type;
            
            switch (type) {
                case 'bool':
                case 'checkbox':
                    return false;
                case 'int':
                    return 0; // 0 is valid value, will be visible
                case 'float':
                    return 0.0;
                case 'select':
                case 'entity':
                case 'date':
                case 'datetime':
                    return null;
                case 'text':
                case 'textarea':
                default:
                    return ''; // Empty string for text fields
            }
        },

        /**
         * Remove/clear a custom field value
         */
        removeField(field) {
            // Vue 3 - direct assignment
            this.entity.customFields[field.name] = null;
        },

        /**
         * Get label for a field
         */
        getFieldLabel(field) {
            if (field.config && field.config.label) {
                // Handle translated labels (object with locale keys)
                if (typeof field.config.label === 'object') {
                    return field.config.label['uk-UA'] || field.config.label['ru-RU'] || field.config.label['en-GB'] || field.name;
                }
                return field.config.label;
            }
            return field.name;
        },

        /**
         * Get label for a set
         */
        getSetLabel(set) {
            if (set.config && set.config.label) {
                return set.config.label;
            }
            return set.name;
        }
    }
});

