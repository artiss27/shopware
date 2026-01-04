import './module/sw-cms/elements/category-grid';
import './module/sw-cms/elements/category-info';
import './module/sw-cms/blocks/category-grid';
import './module/sw-cms/blocks/category-info';

import deDE from './module/sw-cms/snippet/de-DE.json';
import enGB from './module/sw-cms/snippet/en-GB.json';
import ukUA from './module/sw-cms/snippet/uk-UA.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('uk-UA', ukUA);
