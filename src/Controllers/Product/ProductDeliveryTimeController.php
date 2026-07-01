<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Controllers\Product;

use Jtl\Connector\Core\Model\Product as ProductModel;
use JtlWooCommerceConnector\Controllers\AbstractBaseController;
use JtlWooCommerceConnector\Logger\ErrorFormatter;
use JtlWooCommerceConnector\Utilities\Config;
use JtlWooCommerceConnector\Utilities\SupportedPlugins;
use JtlWooCommerceConnector\Utilities\Util;
use WP_Error;

class ProductDeliveryTimeController extends AbstractBaseController
{
    // FORK ADDITION: JTL sentinel value for the supplier delivery time meaning "on request"
    // ("auf Anfrage") instead of a real number of days. Hard-wired on purpose (JTL convention).
    private const ON_REQUEST_SUPPLIER_DELIVERY_TIME = 999;

    /**
     * @param ProductModel $product
     * @param \WC_Product  $wcProduct
     * @return void
     * @throws \Exception
     */
    public function pushData(ProductModel $product, \WC_Product $wcProduct): void
    {
        $productId                          = $product->getId()->getEndpoint();

        // Persist handling times so stock-level-only pushes can recalculate delivery time
        // without the full product model being available.
        \update_post_meta((int)$productId, '_jtl_additional_handling_time', $product->getAdditionalHandlingTime());
        \update_post_meta((int)$productId, '_jtl_supplier_delivery_time', $product->getSupplierDeliveryTime());

        $time                               = $product->calculateHandlingTime();
        $germanizedDeliveryTimeTaxonomyName = 'product_delivery_time';

        $this->removeDeliveryTimeTerm((int)$productId);
        $this->removeDeliveryTimeTerm((int)$productId, $germanizedDeliveryTimeTaxonomyName);

        if (Config::get(Config::OPTIONS_USE_DELIVERYTIME_CALC) !== 'deactivated') {
            //Check if product is in stock and custom in-stock delivery time is configured
            /** @var string $inStockDeliveryTime */
            $inStockDeliveryTime = Config::get(Config::OPTIONS_IN_STOCK_DELIVERY_TIME, '');
            $useInStockDeliveryTime = $product->getStockLevel() > 0 && !empty(\trim($inStockDeliveryTime));

            //FUNCTION ATTRIBUTE BY JTL
            $offset           = 0;
            $pushedAttributes = $product->getAttributes();
            foreach ($pushedAttributes as $key => $pushedAttribute) {
                foreach ($pushedAttribute->getI18ns() as $i18n) {
                    if (!$this->util->isWooCommerceLanguage($i18n->getLanguageISO())) {
                        continue;
                    }

                    if (\preg_match('/^(wc_)[a-zA-Z\_]+$/', \trim($i18n->getName()))) {
                        if (\strcmp(\trim($i18n->getName()), 'wc_dt_offset') === 0) {
                            /** @var string $i18nValue */
                            $i18nValue = $i18n->getValue();
                            $offset    = (int)\trim($i18nValue);
                        }
                    }
                    unset($pushedAttributes[$key]);
                }
            }

            // FORK ADDITION: track whether a concrete inflow date replaced the calculated time,
            // so the "auf Anfrage" sentinel below does not override a real inflow-based delivery time.
            $inflowDateApplied = false;
            if (Config::get(Config::OPTIONS_CONSIDER_SUPPLIER_INFLOW_DATE, false)) {
                if (
                    $product->getStockLevel() <= 0
                    && !\is_null($product->getNextAvailableInflowDate())
                ) {
                    $inflow = new \DateTime($product->getNextAvailableInflowDate()->format('Y-m-d'));
                    $today  = new \DateTime((new \DateTime())->format('Y-m-d'));
                    if ($inflow->getTimestamp() - $today->getTimestamp() > 0) {
                        $time              = $product->getAdditionalHandlingTime() + (int)$inflow->diff($today)->days;
                        $inflowDateApplied = true;
                    }
                }
            }

            // FORK ADDITION (PR #12): decide whether to show the "auf Anfrage" text. Computed before
            // the offset reformatting below so the handling-time check sees the raw calculated integer.
            $showOnRequest = $this->shouldShowOnRequest($product, $time, $inflowDateApplied);
            // END FORK ADDITION

            if ($offset !== 0 && !$useInStockDeliveryTime) {
                $min  = $time - $offset <= 0 ? 1 : $time - $offset;
                $max  = $time + $offset;
                $time = \sprintf('%s-%s', $min, $max);
            }

            if (
                $time === 0
                && Config::get(Config::OPTIONS_DISABLED_ZERO_DELIVERY_TIME)
                && Config::get(Config::OPTIONS_USE_DELIVERYTIME_CALC) === 'delivery_time_calc'
                && !$useInStockDeliveryTime
                // FORK ADDITION (PR #12): do not hide the delivery time when we intend to show "auf Anfrage"
                && !$showOnRequest
            ) {
                return;
            }

            /** @var string $prefixDeliveryTime */
            $prefixDeliveryTime = Config::get(Config::OPTIONS_PRAEFIX_DELIVERYTIME);
            /** @var string $suffixDeliveryTime */
            $suffixDeliveryTime = Config::get(Config::OPTIONS_SUFFIX_DELIVERYTIME);

            //Build Term string - use custom in-stock delivery time if configured and product is in stock
            if ($useInStockDeliveryTime) {
                $deliveryTimeString = \trim($inStockDeliveryTime);
            } elseif ($showOnRequest) {
                // FORK ADDITION (PR #12): show the configured "auf Anfrage" text instead of a day count.
                // Triggered either by the JTL supplier delivery time 999 sentinel or by no delivery
                // time being maintained in JTL at all (see $showOnRequest computation above).
                /** @var string $onRequestText */
                $onRequestText      = Config::get(Config::OPTIONS_ON_REQUEST_DELIVERY_TIME_TEXT, 'auf Anfrage');
                $onRequestText      = \trim($onRequestText);
                // Fall back to a sensible default if the configured text was saved empty,
                // so we never create an empty delivery time term.
                $deliveryTimeString = $onRequestText !== '' ? $onRequestText : 'auf Anfrage';
                // END FORK ADDITION
            } else {
                $deliveryTimeString = \trim(
                    \sprintf(
                        '%s %s %s',
                        $prefixDeliveryTime,
                        $time,
                        $suffixDeliveryTime
                    )
                );
            }

            if (
                !$useInStockDeliveryTime
                && (Config::get(Config::OPTIONS_USE_DELIVERYTIME_CALC) === 'delivery_status')
                && (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED)
                    || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED2)
                    || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZEDPRO)
                    || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET))
            ) {
                foreach ($product->getI18ns() as $i18n) {
                    if ($this->util->isWooCommerceLanguage($i18n->getLanguageISO())) {
                        $deliveryTimeString = $i18n->getDeliveryStatus();
                        break;
                    }
                }
            }

            // FORK ADDITION: "Erscheint am" from JTL stock options as delivery time string
            // Overrides the calculated $deliveryTimeString when stock is 0 and the "Erscheint am" date
            // (availableFrom) lies in the future. Priority: lower than in-stock time, higher than
            // the day-count string. Re-apply this block after upstream merges in pushData().
            if (
                !$useInStockDeliveryTime
                && Config::get(Config::OPTIONS_CONSIDER_ERSCHEINT_AM_DATE, false)
                && $product->getStockLevel() <= 0
                && !\is_null($product->getAvailableFrom())
            ) {
                $erscheintAm = new \DateTime($product->getAvailableFrom()->format('Y-m-d'));
                $today       = new \DateTime((new \DateTime())->format('Y-m-d'));
                if ($erscheintAm->getTimestamp() > $today->getTimestamp()) {
                    /** @var string $erscheintAmPrefix */
                    $erscheintAmPrefix  = Config::get(Config::OPTIONS_ERSCHEINT_AM_PREFIX, 'Lieferbar ab');
                    $deliveryTimeString = \trim($erscheintAmPrefix . ' ' . $erscheintAm->format('d.m.Y'));
                }
            }
            // END FORK ADDITION

            $term = \get_term_by(
                'slug',
                \wc_sanitize_taxonomy_name(
                    Util::removeSpecialchars($deliveryTimeString)
                ),
                'product_delivery_times'
            );

            if ($term === false) {
                //Add term
                $newTerm = \wp_insert_term(
                    $deliveryTimeString,
                    'product_delivery_times'
                );

                if ($newTerm instanceof WP_Error) {
                    // var_dump($newTerm);
                    // die();
                    $error = new WP_Error('invalid_taxonomy', 'Could not create delivery time.');
                    $this->logger->error(ErrorFormatter::formatError($error));
                    $this->logger->error(ErrorFormatter::formatError($newTerm));
                } else {
                    $termId = $newTerm['term_id'];

                    \wp_set_object_terms((int)$productId, $termId, 'product_delivery_times', true);

                    if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET)) {
                        \update_post_meta((int)$productId, '_lieferzeit', $termId);
                    }
                }
            } elseif ($term instanceof \WP_Term) {
                \wp_set_object_terms((int)$productId, $term->term_id, $term->taxonomy, true);

                if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET)) {
                    \update_post_meta((int)$productId, '_lieferzeit', $term->term_id);
                }
            }

            if (
                SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED)
                || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED2)
                || SupportedPlugins::isActive(SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZEDPRO)
            ) {
                $germanizedTerm = \get_term_by(
                    'slug',
                    \wc_sanitize_taxonomy_name(
                        Util::removeSpecialchars($deliveryTimeString)
                    ),
                    $germanizedDeliveryTimeTaxonomyName
                );

                $germanizedTermId = false;
                if ($germanizedTerm === false) {
                    $germanizedTermArray = \wp_insert_term(
                        $deliveryTimeString,
                        $germanizedDeliveryTimeTaxonomyName
                    );

                    if ($germanizedTermArray instanceof WP_Error) {
                        $error = new WP_Error(
                            'invalid_taxonomy',
                            'Could not create delivery time for germanized.'
                        );
                        $this->logger->error(ErrorFormatter::formatError($error));
                        $this->logger->error(ErrorFormatter::formatError($germanizedTermArray));
                    }

                    if (\is_array($germanizedTermArray) && isset($germanizedTermArray['term_id'])) {
                        $germanizedTermId = $germanizedTermArray['term_id'];
                    }
                } elseif ($germanizedTerm instanceof \WP_Term) {
                    $germanizedTermId = $germanizedTerm->term_id;
                }

                if ($germanizedTermId !== false) {
                    \wp_set_object_terms(
                        (int)$productId,
                        $germanizedTermId,
                        $germanizedDeliveryTimeTaxonomyName,
                        true
                    );

                    if (
                        SupportedPlugins::comparePluginVersion(
                            SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED2,
                            '>=',
                            '3.7.0'
                        )
                    ) {
                        /** @var string $oldDeliveryTime */
                        $oldDeliveryTime = $this->util->getPostMeta(
                            $productId,
                            '_default_delivery_time',
                            true
                        );
                        $this->util->updatePostMeta(
                            $productId,
                            '_default_delivery_time',
                            ($germanizedTerm instanceof \WP_Term) ? $germanizedTerm->slug : '',
                            $oldDeliveryTime
                        );
                    }
                }
            }
        }
    }

    /**
     * Decide whether the delivery time should be rendered as the configured "auf Anfrage" text
     * instead of a concrete day count.
     *
     * FORK ADDITION (PR #12). Applies when the product is out of stock and either the JTL supplier delivery
     * time is the 999 "on request" sentinel, or no delivery time is maintained in JTL at all
     * (calculated handling time is 0). A concrete inflow date takes precedence and disables this.
     *
     * @param ProductModel $product
     * @param int          $handlingTime      Calculated handling time before any offset formatting.
     * @param bool         $inflowDateApplied Whether a concrete inflow date already set the time.
     * @return bool
     */
    protected function shouldShowOnRequest(
        ProductModel $product,
        int $handlingTime,
        bool $inflowDateApplied
    ): bool {
        return !$inflowDateApplied
            && (bool)Config::get(Config::OPTIONS_ON_REQUEST_DELIVERY_TIME, true)
            && $product->getStockLevel() <= 0
            && (
                $product->getSupplierDeliveryTime() === self::ON_REQUEST_SUPPLIER_DELIVERY_TIME
                || $handlingTime === 0
            );
    }

    /**
     * @param int    $productId
     * @param string $taxonomyName
     * @return void
     */
    private function removeDeliveryTimeTerm(int $productId, string $taxonomyName = 'product_delivery_times'): void
    {
        $terms = \wp_get_object_terms($productId, $taxonomyName);
        if (!$terms instanceof WP_Error && \is_array($terms)) {
            if (\count($terms) > 0) {
                /** @var \WP_Term $term */
                foreach ($terms as $key => $term) {
                    if ($term instanceof \WP_Term) {
                        if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET)) {
                            \delete_post_meta($productId, '_lieferzeit', $term->term_id);
                        }
                        \wp_remove_object_terms($productId, $term->term_id, $taxonomyName);
                    }
                }
                if (
                    SupportedPlugins::comparePluginVersion(
                        SupportedPlugins::PLUGIN_WOOCOMMERCE_GERMANIZED2,
                        '>=',
                        '3.7.0'
                    )
                ) {
                    $this->util->deletePostMeta((string)$productId, '_default_delivery_time');
                }
            }
        }
    }
}
