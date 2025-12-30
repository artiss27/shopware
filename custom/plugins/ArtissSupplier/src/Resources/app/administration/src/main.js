import './module/supplier';
import './module/supplier-price-update';
import './extension/sw-media-upload-v2';
import PriceUpdateService from './service/price-update.service';

const { Application } = Shopware;

Application.addServiceProvider('priceUpdateService', (container) => {
    const initContainer = Application.getContainer('init');
    return new PriceUpdateService(initContainer.httpClient, container.loginService);
});
