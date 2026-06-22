<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Controllers;

use Automattic\WooCommerce\Internal\DependencyManagement\ContainerException;
use Exception;
use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Product;
use JtlWooCommerceConnector\Controllers\Product\ProductDeliveryTimeController;
use JtlWooCommerceConnector\Integrations\Plugins\Wpml\WpmlProduct;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Log\InvalidArgumentException;

class ProductStockLevelController extends AbstractBaseController implements PushInterface
{
    /**
     * @param AbstractModel ...$models
     * @return AbstractModel[]
     * @throws \InvalidArgumentException
     * @throws \WP_Exception
     * @throws ExpectationFailedException
     * @throws Exception
     */
    public function push(AbstractModel ...$models): array
    {
        $returnModels = [];

        foreach ($models as $model) {
            /** @var Product $model */
            $productId = $model->getId()->getEndpoint();
            $wcProduct = \wc_get_product($productId);

            if ($wcProduct === false || $wcProduct === null) {
                $returnModels[] = $model;
                continue;
            }

            if ('yes' === \get_option('woocommerce_manage_stock')) {
                $wcProducts[] = $wcProduct;

                if ($this->wpml->canBeUsed()) {
                    /** @var WpmlProduct $wpmlProduct */
                    $wpmlProduct = $this->wpml->getComponent(WpmlProduct::class);

                    $wcProductTranslations = $wpmlProduct
                        ->getWooCommerceProductTranslations($wcProduct);
                    $wcProducts            = \array_merge($wcProducts, $wcProductTranslations);
                }

                foreach ($wcProducts as $wcProduct) {
                    \update_post_meta($wcProduct->get_id(), '_manage_stock', 'yes');

                    $stockLevel  = $model->getStockLevel();
                    $stockStatus = $this->util->getStockStatus($stockLevel, $wcProduct->backorders_allowed());

                    // Stock status is always determined by children so sync later.
                    if (!$wcProduct->is_type('variable')) {
                        $wcProduct->set_stock_status($stockStatus);
                    }

                    \wc_update_product_stock((int)$productId, (int)\wc_stock_amount($stockLevel));

                    if ($wcProduct->is_type('variation')) {
                        \WC_Product_Variable::sync_stock_status($wcProduct->get_id());
                    }

                    \wc_delete_product_transients($wcProduct->get_id());
                }
            }

            // Recalculate delivery time based on the new stock level.
            // The full product push stores additionalHandlingTime and supplierDeliveryTime as
            // post meta so we can reconstruct the right delivery time string here without
            // needing the complete product model.
            $mainWcProduct = \wc_get_product($productId);
            if ($mainWcProduct !== false && $mainWcProduct !== null) {
                $additionalHandlingTime = (int)\get_post_meta((int)$productId, '_jtl_additional_handling_time', true);
                $supplierDeliveryTime   = (int)\get_post_meta((int)$productId, '_jtl_supplier_delivery_time', true);

                $stockProduct = (new Product())
                    ->setId($model->getId())
                    ->setStockLevel($model->getStockLevel())
                    ->setAdditionalHandlingTime($additionalHandlingTime)
                    ->setSupplierDeliveryTime($supplierDeliveryTime);

                (new ProductDeliveryTimeController($this->db, $this->util))
                    ->pushData($stockProduct, $mainWcProduct);
            }

            $returnModels[] = $model;
        }
        return $returnModels;
    }
}
