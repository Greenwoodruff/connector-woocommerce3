<?php

declare(strict_types=1);

namespace JtlWooCommerceConnector\Tests\Controllers\Product;

use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\Product as ProductModel;
use JtlWooCommerceConnector\Controllers\Product\ProductDeliveryTimeController;
use JtlWooCommerceConnector\Tests\AbstractTestCase;
use JtlWooCommerceConnector\Utilities\Config;

class ProductDeliveryTimeTest extends AbstractTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        \WP_Mock::setUp();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @dataProvider shouldShowOnRequestDataProvider
     * @covers       \JtlWooCommerceConnector\Controllers\Product\ProductDeliveryTimeController::shouldShowOnRequest
     * @param float $stockLevel
     * @param int   $supplierDeliveryTime
     * @param int   $handlingTime
     * @param bool  $inflowDateApplied
     * @param bool  $onRequestConfigEnabled
     * @param bool  $expected
     * @return void
     * @throws \ReflectionException
     */
    public function testShouldShowOnRequest(
        float $stockLevel,
        int $supplierDeliveryTime,
        int $handlingTime,
        bool $inflowDateApplied,
        bool $onRequestConfigEnabled,
        bool $expected
    ): void {
        // Config::get(OPTIONS_ON_REQUEST_DELIVERY_TIME) delegates to get_option().
        \WP_Mock::userFunction('get_option')
            ->andReturnUsing(function ($name, $default = null) use ($onRequestConfigEnabled) {
                if ($name === Config::OPTIONS_ON_REQUEST_DELIVERY_TIME) {
                    return $onRequestConfigEnabled;
                }
                return $default;
            });

        $db   = $this->createDbMock();
        $util = $this->createUtilMock();

        $controller = new ProductDeliveryTimeController($db, $util);

        $product = (new ProductModel())
            ->setId(new Identity('1', 1))
            ->setStockLevel($stockLevel)
            ->setSupplierDeliveryTime($supplierDeliveryTime);

        $result = $this->invokeMethodFromObject(
            $controller,
            'shouldShowOnRequest',
            $product,
            $handlingTime,
            $inflowDateApplied
        );

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array<int, float|int|bool>>
     */
    public function shouldShowOnRequestDataProvider(): array
    {
        return [
            // Out of stock, JTL 999 sentinel -> "auf Anfrage".
            '999 sentinel out of stock' => [0.0, 999, 999, false, true, true],
            // Out of stock, no delivery time maintained in JTL (handling time 0) -> "auf Anfrage".
            'no delivery time out of stock' => [0.0, 0, 0, false, true, true],
            // Out of stock but a real delivery time exists -> show the day count, not "auf Anfrage".
            'real delivery time out of stock' => [0.0, 0, 5, false, true, false],
            // In stock with zero handling time -> not "auf Anfrage" (feature is out-of-stock only).
            'no delivery time in stock' => [3.0, 0, 0, false, true, false],
            // A concrete inflow date already set the time -> it takes precedence.
            'inflow date applied' => [0.0, 0, 0, true, true, false],
            // "auf Anfrage" feature disabled in config -> never shown.
            'feature disabled' => [0.0, 999, 999, false, false, false],
        ];
    }
}
