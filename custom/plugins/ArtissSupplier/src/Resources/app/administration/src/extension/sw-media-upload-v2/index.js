const { Component } = Shopware;

Component.override('sw-media-upload-v2', {
    methods: {
        /**
         * Transliterate Cyrillic characters to Latin
         */
        transliterateFileName(fileName) {
            if (!fileName) return fileName;

            const cyrillicToLatin = {
                'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo',
                'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
                'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
                'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch',
                'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya',
                'А': 'A', 'Б': 'B', 'В': 'V', 'Г': 'G', 'Д': 'D', 'Е': 'E', 'Ё': 'Yo',
                'Ж': 'Zh', 'З': 'Z', 'И': 'I', 'Й': 'Y', 'К': 'K', 'Л': 'L', 'М': 'M',
                'Н': 'N', 'О': 'O', 'П': 'P', 'Р': 'R', 'С': 'S', 'Т': 'T', 'У': 'U',
                'Ф': 'F', 'Х': 'H', 'Ц': 'Ts', 'Ч': 'Ch', 'Ш': 'Sh', 'Щ': 'Sch',
                'Ъ': '', 'Ы': 'Y', 'Ь': '', 'Э': 'E', 'Ю': 'Yu', 'Я': 'Ya',
                'і': 'i', 'І': 'I', 'ї': 'yi', 'Ї': 'Yi', 'є': 'ye', 'Є': 'Ye',
                'ґ': 'g', 'Ґ': 'G'
            };

            let transliterated = '';
            for (let i = 0; i < fileName.length; i++) {
                const char = fileName[i];
                transliterated += cyrillicToLatin[char] || char;
            }

            // Replace spaces and special characters that might cause issues
            transliterated = transliterated.replace(/[^\w\-_.]/g, '_');
            
            return transliterated;
        },

        handleFileCheck(files) {
            if (!files || files.length === 0) {
                return this.$super('handleFileCheck', files);
            }

            // Process files to transliterate Cyrillic file names
            const processedFiles = [];
            
            for (const file of files) {
                if (!file || !file.name) {
                    continue;
                }

                // Check if file name contains Cyrillic characters
                const hasCyrillic = /[а-яёіїєґА-ЯЁІЇЄҐ]/.test(file.name);
                
                if (hasCyrillic) {
                    const transliteratedName = this.transliterateFileName(file.name);
                    
                    // Create new file with transliterated name
                    const newFile = new File([file], transliteratedName, {
                        type: file.type,
                        lastModified: file.lastModified
                    });

                    console.log('Transliterated file name:', file.name, '->', transliteratedName);
                    processedFiles.push(newFile);
                } else {
                    processedFiles.push(file);
                }
            }

            // Call parent method with processed files
            return this.$super('handleFileCheck', processedFiles);
        }
    }
});
