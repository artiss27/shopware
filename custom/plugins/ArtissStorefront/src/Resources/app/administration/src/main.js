import './module/sw-cms/elements/category-grid';
import './module/sw-cms/elements/category-info';
import './module/sw-cms/elements/category-h1';
import './module/sw-cms/blocks/category-grid';
import './module/sw-cms/blocks/category-info';

import './extension/sw-category/view/sw-category-detail-seo';

import deDE from './module/sw-cms/snippet/de-DE.json';
import enGB from './module/sw-cms/snippet/en-GB.json';
import ukUA from './module/sw-cms/snippet/uk-UA.json';

import categoryDeDE from './extension/sw-category/snippet/de-DE.json';
import categoryEnGB from './extension/sw-category/snippet/en-GB.json';
import categoryRuRU from './extension/sw-category/snippet/ru-RU.json';
import categoryUkUA from './extension/sw-category/snippet/uk-UA.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('uk-UA', ukUA);

Shopware.Locale.extend('de-DE', categoryDeDE);
Shopware.Locale.extend('en-GB', categoryEnGB);
Shopware.Locale.extend('ru-RU', categoryRuRU);
Shopware.Locale.extend('uk-UA', categoryUkUA);
