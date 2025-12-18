import './module/artiss-property-processing';
import './extension/sw-plugin-config';

// Import snippets
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import ruRU from './snippet/ru-RU.json';
import ukUA from './snippet/uk-UA.json';
import ruUA from './snippet/ru-UA.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('ru-RU', ruRU);
Shopware.Locale.extend('uk-UA', ukUA);
Shopware.Locale.extend('ru-UA', ruUA);
