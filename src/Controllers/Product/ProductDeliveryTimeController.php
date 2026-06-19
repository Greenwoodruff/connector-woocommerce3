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

    // FORK ADDITION: sortable "days" value for "auf Anfrage" when choosing the shortest variant
    // delivery time for a variable parent product. Treated as the longest possible delivery time.
    private const ON_REQUEST_SORT_VALUE = 99999;

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

        // FORK ADDITION: persist inflow / "Erscheint am" dates too, so a pure stock-level sync
        // reproduces the same delivery time (otherwise a 999 article with an inflow date would fall
        // back to "auf Anfrage" because the date is not part of the reconstructed stock model).
        $nextInflowDate = $product->getNextAvailableInflowDate();
        \update_post_meta(
            (int)$productId,
            '_jtl_next_available_inflow_date',
            $nextInflowDate instanceof \DateTimeInterface ? $nextInflowDate->format('Y-m-d') : ''
        );
        $availableFromDate = $product->getAvailableFrom();
        \update_post_meta(
            (int)$productId,
            '_jtl_available_from',
            $availableFromDate instanceof \DateTimeInterface ? $availableFromDate->format('Y-m-d') : ''
        );
        // END FORK ADDITION

        $time                               = $product->calculateHandlingTime();
        $germanizedDeliveryTimeTaxonomyName = 'product_delivery_time';

        $this->removeDeliveryTimeTerm((int)$productId);
        $this->removeDeliveryTimeTerm((int)$productId, $germanizedDeliveryTimeTaxonomyName);

        // FORK ADDITION: reset the aggregation meta; it is (re)set below once a delivery time is
        // resolved. Lets aggregateMasterDeliveryTime() pick the shortest variant for the parent.
        \delete_post_meta((int)$productId, '_jtl_delivery_time_days');
        \delete_post_meta((int)$productId, '_jtl_delivery_time_string');
        // END FORK ADDITION

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

            // FORK ADDITION: numeric base for choosing the shortest variant delivery time on the
            // parent (aggregateMasterDeliveryTime). Captured before the offset turns $time into a range.
            $baseDays = (int)$time;
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
            ) {
                // FORK ADDITION: in stock with zero delivery time = fastest, no term. Record it so a
                // variable parent can still treat this as the shortest variant (empty string => no term).
                \update_post_meta((int)$productId, '_jtl_delivery_time_days', 0);
                \update_post_meta((int)$productId, '_jtl_delivery_time_string', '');
                // END FORK ADDITION
                return;
            }

            /** @var string $prefixDeliveryTime */
            $prefixDeliveryTime = Config::get(Config::OPTIONS_PRAEFIX_DELIVERYTIME);
            /** @var string $suffixDeliveryTime */
            $suffixDeliveryTime = Config::get(Config::OPTIONS_SUFFIX_DELIVERYTIME);

            //Build Term string - use custom in-stock delivery time if configured and product is in stock
            if ($useInStockDeliveryTime) {
                $deliveryTimeString = \trim($inStockDeliveryTime);
                $sortDays           = 0; // FORK ADDITION: in stock = shortest delivery time
            } elseif (
                // FORK ADDITION: JTL supplier delivery time of 999 days is a sentinel meaning
                // "on request" ("auf Anfrage"). Show the configured text instead of a day count.
                // Only relevant when out of stock (supplier delivery time only feeds the
                // calculation then) and when no concrete inflow date already applied.
                !$inflowDateApplied
                && Config::get(Config::OPTIONS_ON_REQUEST_DELIVERY_TIME, true)
                && $product->getStockLevel() <= 0
                && $product->getSupplierDeliveryTime() === self::ON_REQUEST_SUPPLIER_DELIVERY_TIME
            ) {
                /** @var string $onRequestText */
                $onRequestText      = Config::get(Config::OPTIONS_ON_REQUEST_DELIVERY_TIME_TEXT, 'auf Anfrage');
                $onRequestText      = \trim($onRequestText);
                // Fall back to a sensible default if the configured text was saved empty,
                // so we never create an empty delivery time term.
                $deliveryTimeString = $onRequestText !== '' ? $onRequestText : 'auf Anfrage';
                $sortDays           = self::ON_REQUEST_SORT_VALUE; // longest = "auf Anfrage"
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
                $sortDays = $baseDays; // FORK ADDITION: calculated day count
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
                    $sortDays           = (int)$today->diff($erscheintAm)->days; // FORK ADDITION: days until date
                }
            }
            // END FORK ADDITION

            // FORK ADDITION: store the resolved delivery time + a sortable day value so a variable
            // parent can adopt the shortest variant's delivery time (aggregateMasterDeliveryTime()).
            \update_post_meta((int)$productId, '_jtl_delivery_time_days', $sortDays);
            \update_post_meta((int)$productId, '_jtl_delivery_time_string', $deliveryTimeString);
            // END FORK ADDITION

            $this->assignDeliveryTimeTerm((int)$productId, $deliveryTimeString);
        }
    }

    /**
     * Create (if needed) and assign the delivery time term(s) for a single product on both the
     * WooCommerce and (if active) Germanized delivery time taxonomies.
     *
     * Extracted from pushData() so the variable-parent aggregation can reuse the exact same logic.
     *
     * @param int    $productId
     * @param string $deliveryTimeString
     * @return void
     */
    private function assignDeliveryTimeTerm(int $productId, string $deliveryTimeString): void
    {
        $germanizedDeliveryTimeTaxonomyName = 'product_delivery_time';

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
                $error = new WP_Error('invalid_taxonomy', 'Could not create delivery time.');
                $this->logger->error(ErrorFormatter::formatError($error));
                $this->logger->error(ErrorFormatter::formatError($newTerm));
            } else {
                $termId = $newTerm['term_id'];

                \wp_set_object_terms($productId, $termId, 'product_delivery_times', true);

                if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET)) {
                    \update_post_meta($productId, '_lieferzeit', $termId);
                }
            }
        } elseif ($term instanceof \WP_Term) {
            \wp_set_object_terms($productId, $term->term_id, $term->taxonomy, true);

            if (SupportedPlugins::isActive(SupportedPlugins::PLUGIN_GERMAN_MARKET)) {
                \update_post_meta($productId, '_lieferzeit', $term->term_id);
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
                    $productId,
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
                        (string)$productId,
                        '_default_delivery_time',
                        true
                    );
                    $this->util->updatePostMeta(
                        (string)$productId,
                        '_default_delivery_time',
                        ($germanizedTerm instanceof \WP_Term) ? $germanizedTerm->slug : '',
                        $oldDeliveryTime
                    );
                }
            }
        }
    }

    /**
     * FORK ADDITION: Set a variable parent product's delivery time to the SHORTEST delivery time
     * among its variations, so the pre-selection display reflects the fastest available variant.
     * Reads the per-variation meta written by pushData() and is called from the finish hook
     * (Util::syncMasterProducts) after all products of a sync run have been pushed.
     *
     * @param int $masterProductId
     * @return void
     */
    public function aggregateMasterDeliveryTime(int $masterProductId): void
    {
        if (Config::get(Config::OPTIONS_USE_DELIVERYTIME_CALC) === 'deactivated') {
            return;
        }

        $wcProduct = \wc_get_product($masterProductId);
        if (!$wcProduct instanceof \WC_Product || !$wcProduct->is_type('variable')) {
            return;
        }

        $found      = false;
        $bestDays   = 0;
        $bestString = '';

        foreach ($wcProduct->get_children() as $childId) {
            $daysMeta = \get_post_meta((int)$childId, '_jtl_delivery_time_days', true);
            if ($daysMeta === '' || $daysMeta === false) {
                continue; // variation has no resolved delivery time
            }

            $days = (int)$daysMeta;
            if (!$found || $days < $bestDays) {
                $found      = true;
                $bestDays   = $days;
                $bestString = (string)\get_post_meta((int)$childId, '_jtl_delivery_time_string', true);
            }
        }

        if (!$found) {
            return;
        }

        $this->removeDeliveryTimeTerm($masterProductId);
        $this->removeDeliveryTimeTerm($masterProductId, 'product_delivery_time');

        // Empty string => the shortest variant is in stock with no delivery time term -> leave none.
        if ($bestString !== '') {
            $this->assignDeliveryTimeTerm($masterProductId, $bestString);
        }

        \wc_delete_product_transients($masterProductId);
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
