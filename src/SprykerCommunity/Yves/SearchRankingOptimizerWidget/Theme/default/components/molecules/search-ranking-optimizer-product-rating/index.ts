import './search-ranking-optimizer-product-rating.scss';
import register from 'ShopUi/app/registry';

export default register(
    'search-ranking-optimizer-product-rating',
    () =>
        import(
            /* webpackMode: "lazy" */
            /* webpackChunkName: "search-ranking-optimizer-product-rating" */
            './search-ranking-optimizer-product-rating'
        ),
);
