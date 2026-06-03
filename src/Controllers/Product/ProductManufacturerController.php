<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Controllers\Product;

use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product as ProductModel;
use JtlWooCommerceConnector\Controllers\AbstractBaseController;
use JtlWooCommerceConnector\Utilities\SupportedPlugins;

class ProductManufacturerController extends AbstractBaseController
{
    /**
     * @param ProductModel $product
     * @return void
     */
    public function pushData(ProductModel $product): void
    {
        $productId = $product->getId()->getEndpoint();

        if (SupportedPlugins::isPerfectWooCommerceBrandsActive()) {
            $manufacturerId = $product->getManufacturerId()->getEndpoint();

            // FORK ADDITION (PR #4): look up endpoint ID from link table when it's missing
            if ($manufacturerId === '') {
                $hostId = $product->getManufacturerId()->getHost();
                if ($hostId > 0) {
                    $manufacturerId = $this->getManufacturerEndpointId($hostId);
                }
            }
            // END FORK ADDITION

            $this->removeManufacturerTerm($productId);

            if ($manufacturerId === '') {
                return;
            }

            $term = \get_term_by('id', $manufacturerId, 'pwb-brand');
            if ($term instanceof \WP_Term) {
                \wp_set_object_terms((int)$productId, $term->term_id, $term->taxonomy, true);
            }
        } else {
            $this->removeManufacturerTerm($productId);
        }
    }

    // FORK ADDITION (PR #4): helper to look up manufacturer endpoint ID from link table
    /**
     * @param int $hostId
     * @return string
     */
    private function getManufacturerEndpointId(int $hostId): string
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'jtl_connector_link_manufacturer';

        $endpointId = $this->db->queryOne(
            "SELECT endpoint_id FROM {$tableName} WHERE host_id = {$hostId}"
        );

        return $endpointId !== null ? (string)$endpointId : '';
    }
    // END FORK ADDITION

    /**
     * @param string $productId
     * @return void
     */
    private function removeManufacturerTerm(string $productId): void
    {
        $terms = \wp_get_object_terms((int)$productId, 'pwb-brand');

        if (\is_array($terms) && \count($terms) > 0) {
            /** @var \WP_Term $term */
            foreach ($terms as $key => $term) {
                if ($term instanceof \WP_Term) {
                    \wp_remove_object_terms((int)$productId, $term->term_id, 'pwb-brand');
                }
            }
        }
    }

    /**
     * @param ProductModel $model
     * @return Identity|null
     * @throws \InvalidArgumentException
     */
    public function pullData(ProductModel $model): ?Identity
    {
        $productId      = $model->getId()->getEndpoint();
        $manufacturerId = null;
        if (SupportedPlugins::isPerfectWooCommerceBrandsActive()) {
            $terms = \wp_get_object_terms((int)$productId, 'pwb-brand');
        } elseif (SupportedPlugins::isGermanizedActive()) {
            $terms = \wp_get_object_terms((int)$productId, 'product_manufacturer');
        } else {
            $terms = \wp_get_object_terms((int)$productId, 'product_brand');
        }

        if (!\is_array($terms)) {
            throw new \InvalidArgumentException(
                'Array type expected. Got ' . \gettype($terms) . ' instead.'
            );
        }

        if (\count($terms) > 0) {
            /** @var \WP_Term $term */
            $term           = $terms[0];
            $manufacturerId = (new Identity())->setEndpoint((string)$term->term_id);
        }

        return $manufacturerId;
    }
}
