import './service/artiss-cropper-config.service';
import './component/artiss-image-cropper';
import './extension/sw-media-upload-v2';

import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';
import ruRU from './snippet/ru-RU.json';
import ukUA from './snippet/uk-UA.json';

const { Locale } = Shopware;

Locale.extend('en-GB', enGB);
Locale.extend('de-DE', deDE);
Locale.extend('ru-RU', ruRU);
Locale.extend('uk-UA', ukUA);
