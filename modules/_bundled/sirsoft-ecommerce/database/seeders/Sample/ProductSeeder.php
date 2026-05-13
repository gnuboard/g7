<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Contracts\Extension\StorageInterface;
use App\Extension\ModuleManager;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductImageCollection;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductLabelAssignment;
use Modules\Sirsoft\Ecommerce\Models\ProductNotice;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;

/**
 * 상품 더미 데이터 시더
 */
class ProductSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 생성할 기본 상품 수
     */
    private const PRODUCT_COUNT = 100;

    /**
     * 이미지 풀 최대 크기 (이 수 만큼만 외부에서 다운로드, 이후 색상 필터로 재사용)
     */
    private const IMAGE_POOL_SIZE = 50;

    /**
     * 병렬 다운로드 배치 크기
     */
    private const DOWNLOAD_BATCH_SIZE = 10;

    /**
     * 스토리지 드라이버 인스턴스
     */
    private StorageInterface $storage;

    /**
     * 벌크 모드에서 사용된 해시 저장 (메모리 기반 고유성 보장)
     *
     * @var array<string, true>
     */
    private array $usedHashes = [];

    /**
     * 상품 템플릿 데이터 정의 (이 데이터를 기반으로 100개 생성)
     *
     * 가격 분포: 50000원 이하 80%, 최대 200000원, 1000원 단위
     * - 1000~10000원: 저가 (20%)
     * - 10000~30000원: 중저가 (30%)
     * - 30000~50000원: 중가 (30%)
     * - 50000~100000원: 중고가 (15%)
     * - 100000~200000원: 고가 (5%)
     */
    private array $productTemplates = [
        // === 저가 상품 (1,000~10,000원) - 3개 ===
        [
            'name' => ['ko' => '면 손수건 3매입', 'en' => 'Cotton Handkerchief 3pcs'],
            'product_code' => 'HK-001',
            'sku' => 'HK-001-WHT',
            'list_price' => 5000,
            'selling_price' => 3000,
            'stock_quantity' => 200,
            'safe_stock_quantity' => 20,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>부드러운 면 100% 손수건 3매 세트입니다.</p>', 'en' => '<p>A set of 3 soft 100% cotton handkerchiefs.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '화이트', 'en' => 'White'], ['ko' => '블루', 'en' => 'Blue'], ['ko' => '핑크', 'en' => 'Pink']]],
            ],
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'uniqlo',
        ],
        [
            'name' => ['ko' => '기본 양말 5족', 'en' => 'Basic Socks 5 Pairs'],
            'product_code' => 'SK-001',
            'sku' => 'SK-001-BLK',
            'list_price' => 8000,
            'selling_price' => 5000,
            'stock_quantity' => 300,
            'safe_stock_quantity' => 30,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>매일 신기 좋은 기본 양말 5족 세트입니다.</p>', 'en' => '<p>A set of 5 basic socks for everyday wear.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '블랙', 'en' => 'Black'], ['ko' => '화이트', 'en' => 'White'], ['ko' => '그레이', 'en' => 'Gray']]],
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'values' => [['ko' => 'M', 'en' => 'M'], ['ko' => 'L', 'en' => 'L']]],
            ],
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'spao',
        ],
        [
            'name' => ['ko' => '볼펜 세트', 'en' => 'Ballpoint Pen Set'],
            'product_code' => 'PN-001',
            'sku' => 'PN-001-BLU',
            'list_price' => 3000,
            'selling_price' => 2000,
            'stock_quantity' => 500,
            'safe_stock_quantity' => 50,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>부드럽게 써지는 볼펜 3개 세트입니다.</p>', 'en' => '<p>A set of 3 smooth writing ballpoint pens.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '블루', 'en' => 'Blue'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '레드', 'en' => 'Red']]],
            ],
            'category_slug' => 'electronics',
            'brand_slug' => null,
        ],
        // === 중저가 상품 (10,000~30,000원) - 5개 ===
        [
            'name' => ['ko' => '베이직 라운드 티셔츠', 'en' => 'Basic Round T-Shirt'],
            'product_code' => 'TS-001',
            'sku' => 'TS-001-WHT-M',
            'list_price' => 25000,
            'selling_price' => 19000,
            'stock_quantity' => 100,
            'safe_stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>부드러운 면 100% 소재로 제작된 베이직 라운드 티셔츠입니다.</p>', 'en' => '<p>A basic round T-shirt made from 100% soft cotton.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '화이트', 'en' => 'White'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '네이비', 'en' => 'Navy']]],
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'values' => [['ko' => 'S', 'en' => 'S'], ['ko' => 'M', 'en' => 'M'], ['ko' => 'L', 'en' => 'L'], ['ko' => 'XL', 'en' => 'XL']]],
            ],
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'uniqlo',
        ],
        [
            'name' => ['ko' => '프리미엄 브이넥 티셔츠', 'en' => 'Premium V-Neck T-Shirt'],
            'product_code' => 'TS-002',
            'sku' => 'TS-002-BLK-L',
            'list_price' => 29000,
            'selling_price' => 23000,
            'stock_quantity' => 80,
            'safe_stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>세련된 브이넥 디자인의 프리미엄 티셔츠입니다.</p>', 'en' => '<p>A premium T-shirt with an elegant V-neck design.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '블랙', 'en' => 'Black'], ['ko' => '그레이', 'en' => 'Gray'], ['ko' => '화이트', 'en' => 'White']]],
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'values' => [['ko' => 'M', 'en' => 'M'], ['ko' => 'L', 'en' => 'L'], ['ko' => 'XL', 'en' => 'XL']]],
            ],
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'zara',
        ],
        [
            'name' => ['ko' => '캔버스 에코백', 'en' => 'Canvas Eco Bag'],
            'product_code' => 'BG-001',
            'sku' => 'BG-001-NAT',
            'list_price' => 18000,
            'selling_price' => 12000,
            'stock_quantity' => 150,
            'safe_stock_quantity' => 15,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>튼튼한 캔버스 소재의 친환경 에코백입니다.</p>', 'en' => '<p>A durable eco-friendly canvas bag.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '내추럴', 'en' => 'Natural'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '네이비', 'en' => 'Navy']]],
            ],
            'category_slug' => 'women-dresses',
            'brand_slug' => 'zara',
        ],
        [
            'name' => ['ko' => 'USB 충전 케이블', 'en' => 'USB Charging Cable'],
            'product_code' => 'CB-001',
            'sku' => 'CB-001-TYPEC',
            'list_price' => 15000,
            'selling_price' => 9000,
            'stock_quantity' => 200,
            'safe_stock_quantity' => 20,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>고속 충전 지원 USB-C 케이블입니다.</p>', 'en' => '<p>A USB-C cable supporting fast charging.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '길이', 'en' => 'Length'], 'values' => [['ko' => '1m', 'en' => '1m'], ['ko' => '2m', 'en' => '2m']]],
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '화이트', 'en' => 'White'], ['ko' => '블랙', 'en' => 'Black']]],
            ],
            'category_slug' => 'electronics',
            'brand_slug' => 'samsung',
        ],
        [
            'name' => ['ko' => '사과 1박스 (3kg)', 'en' => 'Apple Box (3kg)'],
            'product_code' => 'FR-002',
            'sku' => 'FR-002-3KG',
            'list_price' => 25000,
            'selling_price' => 18000,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'tax_free',
            'description' => ['ko' => '<p>당도 높은 국산 사과 3kg 박스입니다.</p>', 'en' => '<p>A 3kg box of sweet Korean apples.</p>'],
            'has_options' => false,
            'option_groups' => null,
            'category_slug' => 'fruits',
            'brand_slug' => null,
        ],
        // === 중가 상품 (30,000~50,000원) - 5개 ===
        [
            'name' => ['ko' => '플로럴 롱 원피스', 'en' => 'Floral Long Dress'],
            'product_code' => 'DR-001',
            'sku' => 'DR-001-FLR-M',
            'list_price' => 49000,
            'selling_price' => 39000,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>봄/여름 시즌에 어울리는 화사한 플로럴 패턴의 롱 원피스입니다.</p>', 'en' => '<p>A long dress with a bright floral pattern, perfect for spring/summer.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'values' => [['ko' => 'S', 'en' => 'S'], ['ko' => 'M', 'en' => 'M'], ['ko' => 'L', 'en' => 'L']]],
            ],
            'category_slug' => 'women-dresses',
            'brand_slug' => 'zara',
        ],
        [
            'name' => ['ko' => '프리미엄 샤인머스캣 2kg', 'en' => 'Premium Shine Muscat 2kg'],
            'product_code' => 'FR-001',
            'sku' => 'FR-001-2KG',
            'list_price' => 45000,
            'selling_price' => 35000,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'tax_free',
            'description' => ['ko' => '<p>당도 18브릭스 이상의 프리미엄 국산 샤인머스캣입니다.</p>', 'en' => '<p>Premium Korean Shine Muscat with 18+ Brix sweetness.</p>'],
            'has_options' => false,
            'option_groups' => null,
            'category_slug' => 'fruits',
            'brand_slug' => null,
        ],
        [
            'name' => ['ko' => '블루투스 이어폰', 'en' => 'Bluetooth Earphones'],
            'product_code' => 'EP-001',
            'sku' => 'EP-001-BLK',
            'list_price' => 45000,
            'selling_price' => 32000,
            'stock_quantity' => 80,
            'safe_stock_quantity' => 8,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>고음질 블루투스 5.0 무선 이어폰입니다.</p>', 'en' => '<p>High-quality Bluetooth 5.0 wireless earphones.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '블랙', 'en' => 'Black'], ['ko' => '화이트', 'en' => 'White']]],
            ],
            'category_slug' => 'electronics',
            'brand_slug' => 'samsung',
        ],
        [
            'name' => ['ko' => '코튼 후드티', 'en' => 'Cotton Hoodie'],
            'product_code' => 'HD-002',
            'sku' => 'HD-002-GRY',
            'list_price' => 49000,
            'selling_price' => 39000,
            'stock_quantity' => 60,
            'safe_stock_quantity' => 6,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>부드러운 면 소재의 편안한 후드티입니다.</p>', 'en' => '<p>A comfortable cotton hoodie.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '그레이', 'en' => 'Gray'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '네이비', 'en' => 'Navy']]],
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'values' => [['ko' => 'M', 'en' => 'M'], ['ko' => 'L', 'en' => 'L'], ['ko' => 'XL', 'en' => 'XL']]],
            ],
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'spao',
        ],
        [
            'name' => ['ko' => '스테인리스 텀블러', 'en' => 'Stainless Tumbler'],
            'product_code' => 'TB-001',
            'sku' => 'TB-001-SLV',
            'list_price' => 38000,
            'selling_price' => 28000,
            'stock_quantity' => 100,
            'safe_stock_quantity' => 10,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>보온보냉 기능의 스테인리스 텀블러입니다.</p>', 'en' => '<p>A stainless tumbler with hot/cold retention.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '용량', 'en' => 'Capacity'], 'values' => [['ko' => '350ml', 'en' => '350ml'], ['ko' => '500ml', 'en' => '500ml']]],
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '실버', 'en' => 'Silver'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '로즈골드', 'en' => 'Rose Gold']]],
            ],
            'category_slug' => 'electronics',
            'brand_slug' => null,
        ],
        // === 중고가 상품 (50,000~100,000원) - 2개 ===
        [
            'name' => ['ko' => '가죽 크로스백', 'en' => 'Leather Crossbody Bag'],
            'product_code' => 'BG-002',
            'sku' => 'BG-002-BRN',
            'list_price' => 89000,
            'selling_price' => 69000,
            'stock_quantity' => 30,
            'safe_stock_quantity' => 3,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>고급 인조가죽으로 제작된 크로스백입니다.</p>', 'en' => '<p>A crossbody bag made of premium faux leather.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '브라운', 'en' => 'Brown'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '카멜', 'en' => 'Camel']]],
            ],
            'category_slug' => 'women-dresses',
            'brand_slug' => 'zara',
        ],
        [
            'name' => ['ko' => '무선 마우스 세트', 'en' => 'Wireless Mouse Set'],
            'product_code' => 'MS-001',
            'sku' => 'MS-001-BLK',
            'list_price' => 79000,
            'selling_price' => 59000,
            'stock_quantity' => 40,
            'safe_stock_quantity' => 4,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>인체공학 디자인의 무선 마우스와 패드 세트입니다.</p>', 'en' => '<p>An ergonomic wireless mouse and pad set.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '블랙', 'en' => 'Black'], ['ko' => '화이트', 'en' => 'White'], ['ko' => '그레이', 'en' => 'Gray']]],
            ],
            'category_slug' => 'electronics',
            'brand_slug' => 'lg-electronics',
        ],
        // === 고가 상품 (100,000~200,000원) - 2개 ===
        [
            'name' => ['ko' => '겨울 패딩 점퍼', 'en' => 'Winter Padded Jacket'],
            'product_code' => 'JK-001',
            'sku' => 'JK-001-NVY',
            'list_price' => 189000,
            'selling_price' => 149000,
            'stock_quantity' => 20,
            'safe_stock_quantity' => 2,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>경량 충전재로 따뜻하고 가벼운 겨울 패딩입니다.</p>', 'en' => '<p>A warm and lightweight winter padded jacket.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '네이비', 'en' => 'Navy'], ['ko' => '블랙', 'en' => 'Black'], ['ko' => '카키', 'en' => 'Khaki']]],
                ['name' => ['ko' => '사이즈', 'en' => 'Size'], 'values' => [['ko' => 'M', 'en' => 'M'], ['ko' => 'L', 'en' => 'L'], ['ko' => 'XL', 'en' => 'XL']]],
            ],
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'uniqlo',
        ],
        [
            'name' => ['ko' => '프리미엄 백팩', 'en' => 'Premium Backpack'],
            'product_code' => 'BG-003',
            'sku' => 'BG-003-BLK',
            'list_price' => 159000,
            'selling_price' => 129000,
            'stock_quantity' => 25,
            'safe_stock_quantity' => 3,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>노트북 수납이 가능한 고급 비즈니스 백팩입니다.</p>', 'en' => '<p>A premium business backpack with laptop compartment.</p>'],
            'has_options' => true,
            'option_groups' => [
                ['name' => ['ko' => '색상', 'en' => 'Color'], 'values' => [['ko' => '블랙', 'en' => 'Black'], ['ko' => '그레이', 'en' => 'Gray']]],
            ],
            'category_slug' => 'electronics',
            'brand_slug' => 'samsung',
        ],
        // === 특수 상태 상품 ===
        // 판매중지 상품
        [
            'name' => ['ko' => '단종 예정 상품', 'en' => 'Discontinued Product'],
            'product_code' => 'DC-001',
            'sku' => 'DC-001-END',
            'list_price' => 35000,
            'selling_price' => 19000,
            'stock_quantity' => 5,
            'safe_stock_quantity' => 0,
            'sales_status' => 'suspended',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>판매가 중지된 상품입니다.</p>', 'en' => '<p>This product has been suspended.</p>'],
            'has_options' => false,
            'option_groups' => null,
            'category_slug' => 'men-tshirts',
            'brand_slug' => 'spao',
        ],
        // 품절 상품
        [
            'name' => ['ko' => '인기 품절 상품', 'en' => 'Popular Sold Out Product'],
            'product_code' => 'SO-001',
            'sku' => 'SO-001-OUT',
            'list_price' => 45000,
            'selling_price' => 35000,
            'stock_quantity' => 0,
            'safe_stock_quantity' => 5,
            'sales_status' => 'sold_out',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>재고 소진으로 품절된 인기 상품입니다.</p>', 'en' => '<p>A popular product that is sold out due to high demand.</p>'],
            'has_options' => false,
            'option_groups' => null,
            'category_slug' => 'women-dresses',
            'brand_slug' => '8seconds',
        ],
        // 숨김 상품
        [
            'name' => ['ko' => '비공개 상품', 'en' => 'Hidden Product'],
            'product_code' => 'HD-001',
            'sku' => 'HD-001-HID',
            'list_price' => 55000,
            'selling_price' => 42000,
            'stock_quantity' => 20,
            'safe_stock_quantity' => 5,
            'sales_status' => 'on_sale',
            'display_status' => 'hidden',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>전시 상태가 숨김으로 설정된 상품입니다.</p>', 'en' => '<p>A product with hidden display status.</p>'],
            'has_options' => false,
            'option_groups' => null,
            'category_slug' => 'electronics',
            'brand_slug' => 'lg-electronics',
        ],
        // 출시예정 상품
        [
            'name' => ['ko' => '신제품 출시 예정', 'en' => 'Coming Soon Product'],
            'product_code' => 'CS-001',
            'sku' => 'CS-001-NEW',
            'list_price' => 69000,
            'selling_price' => 55000,
            'stock_quantity' => 0,
            'safe_stock_quantity' => 10,
            'sales_status' => 'coming_soon',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'description' => ['ko' => '<p>곧 출시될 예정인 신제품입니다.</p>', 'en' => '<p>A new product that will be launched soon.</p>'],
            'has_options' => false,
            'option_groups' => null,
            'category_slug' => 'tablets',
            'brand_slug' => 'samsung',
        ],
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('상품 더미 데이터 생성을 시작합니다.');

        // 스토리지 드라이버 초기화
        $this->storage = app(ModuleManager::class)
            ->getModule('sirsoft-ecommerce')
            ->getStorage();

        $this->deleteExistingProducts();
        $this->createProducts();

        $count = Product::count();
        $this->command->info("상품 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 상품 삭제
     */
    private function deleteExistingProducts(): void
    {
        $deletedCount = Product::withTrashed()->count();

        if ($deletedCount > 0) {
            // 스토리지에서 상품 이미지 파일 삭제
            $this->deleteProductImagesFromStorage();

            // 주문 관련 데이터 먼저 삭제 (외래 키 제약 조건)
            // order_options의 product_id, product_option_id가 restrictOnDelete이므로
            // Order 삭제 → order_options/order_addresses/order_payments/order_shippings/order_tax_invoices cascade 삭제
            // Order는 SoftDeletes 사용하므로 forceDelete 필요
            $orderCount = Order::withTrashed()->count();
            if ($orderCount > 0) {
                Order::withTrashed()->forceDelete();
                $this->command->warn("  - 기존 주문 데이터 {$orderCount}건을 삭제했습니다.");
            }

            // 관련 데이터 삭제
            ProductNotice::query()->delete();
            ProductLabelAssignment::query()->delete();
            ProductOption::query()->delete();
            ProductImage::withTrashed()->forceDelete();
            DB::table('ecommerce_product_categories')->delete();
            Product::withTrashed()->forceDelete();

            $this->command->warn("기존 상품 관련 데이터 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 스토리지에서 상품 이미지 파일 삭제
     */
    private function deleteProductImagesFromStorage(): void
    {
        // images/products 폴더 전체 삭제 (새 스토리지 시스템)
        // 경로: modules/sirsoft-ecommerce/images/products/
        if ($this->storage->exists('images', 'products')) {
            $this->storage->deleteDirectory('images', 'products');
            $this->command->line('  - 스토리지에서 상품 이미지 폴더를 삭제했습니다.');
        }
    }

    /**
     * 상품 생성
     */
    private function createProducts(): void
    {
        $count = $this->getSeederCount('products', self::PRODUCT_COUNT);
        $categories = Category::all();
        $categoryCount = $categories->count();

        if ($categoryCount === 0) {
            $this->command->error('카테고리가 없습니다. CategorySeeder를 먼저 실행해주세요.');

            return;
        }

        // 모든 브랜드 조회 (slug를 키로 사용)
        $brands = Brand::all()->keyBy('slug');

        if ($brands->isEmpty()) {
            $this->command->warn('브랜드가 없습니다. BrandSeeder를 먼저 실행하는 것을 권장합니다.');
        }

        // 배송정책 조회 (활성 상태인 것만)
        $shippingPolicies = ShippingPolicy::where('is_active', true)->get();
        $defaultShippingPolicy = $shippingPolicies->firstWhere('is_default', true) ?? $shippingPolicies->first();

        if ($shippingPolicies->isEmpty()) {
            $this->command->warn('배송정책이 없습니다. ShippingPolicySeeder를 먼저 실행하는 것을 권장합니다.');
        }

        // 공통정보 조회 (활성 상태인 것만)
        $commonInfos = ProductCommonInfo::where('is_active', true)->get();
        $defaultCommonInfo = $commonInfos->firstWhere('is_default', true) ?? $commonInfos->first();

        if ($commonInfos->isEmpty()) {
            $this->command->warn('공통정보가 없습니다. ProductCommonInfoSeeder를 먼저 실행하는 것을 권장합니다.');
        }

        // 상품정보제공고시 템플릿 조회
        $noticeTemplates = ProductNoticeTemplate::where('is_active', true)->get();

        if ($noticeTemplates->isEmpty()) {
            $this->command->warn('상품정보제공고시 템플릿이 없습니다. ProductNoticeTemplateSeeder를 먼저 실행하는 것을 권장합니다.');
        }

        // 라벨 조회 (활성 상태인 것만)
        $labels = ProductLabel::where('is_active', true)->get();

        if ($labels->isEmpty()) {
            $this->command->warn('라벨이 없습니다. ProductLabelSeeder를 먼저 실행하는 것을 권장합니다.');
        }

        // leaf 카테고리 미리 필터링 (in-memory, DB 쿼리 없음)
        $parentIds = $categories->pluck('parent_id')->filter()->unique();
        $leafCategories = $categories->reject(fn ($cat) => $parentIds->contains($cat->id));

        // 벌크 모드 판단 (100개 이상: GD 필터 제거 + hash 메모리 생성)
        $useBulkMode = $count >= 100;

        if ($useBulkMode) {
            $this->command->info('벌크 모드 활성화: GD 색상 필터 제거, hash 메모리 생성 적용');
        }

        // Phase 1: 이미지 풀 병렬 다운로드
        $poolSize = min(self::IMAGE_POOL_SIZE, $count);
        $this->command->info("이미지 풀 다운로드 시작 ({$poolSize}개 상품분)...");
        $imagePool = $this->downloadImagePool($poolSize);
        $this->command->info('이미지 풀 다운로드 완료: '.count($imagePool).'개 상품분');

        // Phase 2-3: 상품 생성 + 이미지 할당
        $this->command->info("상품 {$count}개 생성 시작...");
        $progressBar = $this->command->getOutput()->createProgressBar($count);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        for ($i = 1; $i <= $count; $i++) {
            // 템플릿 순환 사용
            $templateIndex = ($i - 1) % count($this->productTemplates);
            $template = $this->productTemplates[$templateIndex];

            // 고유 상품 코드 생성 (채번 서비스 사용)
            $sequenceService = app(SequenceService::class);
            $productCode = $sequenceService->generateCode(SequenceType::PRODUCT);

            // SKU 생성: 템플릿 카테고리 접두사 + 순번 (예: TS-0001, BG-0012)
            $skuPrefix = explode('-', $template['product_code'])[0];
            $sku = sprintf('%s-%04d', $skuPrefix, $i);

            // 가격 변동 (템플릿 기준 ±20%, 1000원 단위, 최대 200000원)
            $priceVariation = rand(80, 120) / 100;
            $listPrice = (int) (floor($template['list_price'] * $priceVariation / 1000) * 1000);
            $sellingPrice = (int) (floor($template['selling_price'] * $priceVariation / 1000) * 1000);

            // 최대 가격 제한 (200,000원)
            $listPrice = min($listPrice, 200000);
            $sellingPrice = min($sellingPrice, $listPrice); // 판매가는 정가보다 클 수 없음

            // 최소 가격 보장 (1,000원)
            $listPrice = max($listPrice, 1000);
            $sellingPrice = max($sellingPrice, 1000);

            // 랜덤 카테고리 선택 (leaf 카테고리 우선)
            $category = $leafCategories->isNotEmpty()
                ? $leafCategories->random()
                : $categories->random();

            // 브랜드 ID 가져오기
            $brandId = null;
            if (isset($template['brand_slug']) && $template['brand_slug'] !== null) {
                // 템플릿에 brand_slug가 지정되어 있으면 해당 브랜드 사용
                $brand = $brands->get($template['brand_slug']);
                if ($brand) {
                    $brandId = $brand->id;
                }
            }

            // 브랜드가 없는 경우 랜덤 브랜드 할당
            if ($brandId === null && $brands->isNotEmpty()) {
                $brandId = $brands->random()->id;
            }

            // 옵션 그룹 생성 (모든 상품에 최소 1개 옵션 필수)
            $optionGroups = $this->generateRandomOptionGroups($template);

            // 배송정책 선택 (80% 기본 배송정책, 20% 랜덤)
            $shippingPolicyId = null;
            if ($shippingPolicies->isNotEmpty()) {
                $shippingPolicyId = rand(1, 100) <= 80 && $defaultShippingPolicy
                    ? $defaultShippingPolicy->id
                    : $shippingPolicies->random()->id;
            }

            // 공통정보 선택 (80% 기본 공통정보, 20% 랜덤)
            $commonInfoId = null;
            if ($commonInfos->isNotEmpty()) {
                $commonInfoId = rand(1, 100) <= 80 && $defaultCommonInfo
                    ? $defaultCommonInfo->id
                    : $commonInfos->random()->id;
            }

            // 상품 생성
            $product = Product::create([
                'name' => [
                    'ko' => $template['name']['ko'].' #'.$i,
                    'en' => $template['name']['en'].' #'.$i,
                ],
                'product_code' => $productCode,
                'sku' => $sku,
                'brand_id' => $brandId,
                'shipping_policy_id' => $shippingPolicyId,
                'common_info_id' => $commonInfoId,
                'list_price' => $listPrice,
                'selling_price' => $sellingPrice,
                'currency_code' => 'KRW',
                'stock_quantity' => rand(10, 200),
                'safe_stock_quantity' => rand(5, 20),
                'sales_status' => $this->getRandomSalesStatus($i),
                'display_status' => $this->getRandomDisplayStatus($i),
                'tax_status' => ProductTaxStatus::from($template['tax_status']),
                'tax_rate' => $template['tax_status'] === 'taxable' ? 10.00 : 0.00,
                'description' => $template['description'],
                'has_options' => true, // 모든 상품에 옵션 필수
                'option_groups' => $optionGroups,
            ]);

            // 카테고리 연결
            $this->attachCategory($product, $category->slug);

            // 옵션 생성 (모든 상품에 필수)
            $this->createOptions($product, $optionGroups, $sku);

            // 상품정보제공고시 생성
            if ($noticeTemplates->isNotEmpty()) {
                $this->createProductNotice($product, $noticeTemplates->random());
            }

            // 라벨 할당 (70% 확률로 1~3개 라벨 할당)
            if ($labels->isNotEmpty() && rand(1, 100) <= 70) {
                $this->assignLabels($product, $labels);
            }

            // 이미지 풀 기반 이미지 생성
            $this->createProductImages($product, $i - 1, $imagePool, $poolSize, $useBulkMode);

            $progressBar->advance();

            // 100건마다 메모리 정리
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }

        $progressBar->finish();
        $this->command->newLine();
    }

    /**
     * 랜덤 옵션 그룹 생성 (최소 1개 보장, 다국어 형식)
     *
     * @param  array  $template  상품 템플릿
     * @return array 옵션 그룹 배열
     */
    private function generateRandomOptionGroups(array $template): array
    {
        // 템플릿에 옵션 그룹이 있으면 사용
        if (! empty($template['option_groups'])) {
            return $template['option_groups'];
        }

        // 기본 옵션 그룹 정의 (다국어 형식)
        $defaultOptionGroups = [
            [
                'name' => ['ko' => '기본', 'en' => 'Default'],
                'values' => [['ko' => '기본', 'en' => 'Default']],
            ],
            [
                'name' => ['ko' => '색상', 'en' => 'Color'],
                'values' => [
                    ['ko' => '화이트', 'en' => 'White'],
                    ['ko' => '블랙', 'en' => 'Black'],
                    ['ko' => '그레이', 'en' => 'Gray'],
                ],
            ],
            [
                'name' => ['ko' => '사이즈', 'en' => 'Size'],
                'values' => [
                    ['ko' => 'S', 'en' => 'S'],
                    ['ko' => 'M', 'en' => 'M'],
                    ['ko' => 'L', 'en' => 'L'],
                    ['ko' => 'XL', 'en' => 'XL'],
                ],
            ],
            [
                'name' => ['ko' => '용량', 'en' => 'Capacity'],
                'values' => [
                    ['ko' => '256GB', 'en' => '256GB'],
                    ['ko' => '512GB', 'en' => '512GB'],
                    ['ko' => '1TB', 'en' => '1TB'],
                ],
            ],
        ];

        // 랜덤하게 1~2개의 옵션 그룹 선택
        $numGroups = rand(1, 2);
        $shuffled = collect($defaultOptionGroups)->shuffle()->take($numGroups)->toArray();

        return $shuffled;
    }

    /**
     * 랜덤 판매 상태 반환 (대부분 판매중)
     *
     * @param  int  $index  상품 인덱스
     */
    private function getRandomSalesStatus(int $index): ProductSalesStatus
    {
        // 90%는 판매중, 나머지는 다양한 상태
        $rand = rand(1, 100);
        if ($rand <= 90) {
            return ProductSalesStatus::ON_SALE;
        } elseif ($rand <= 93) {
            return ProductSalesStatus::SOLD_OUT;
        } elseif ($rand <= 96) {
            return ProductSalesStatus::SUSPENDED;
        } else {
            return ProductSalesStatus::COMING_SOON;
        }
    }

    /**
     * 랜덤 전시 상태 반환 (대부분 전시중)
     *
     * @param  int  $index  상품 인덱스
     */
    private function getRandomDisplayStatus(int $index): ProductDisplayStatus
    {
        // 95%는 전시중, 5%는 숨김
        return rand(1, 100) <= 95
            ? ProductDisplayStatus::VISIBLE
            : ProductDisplayStatus::HIDDEN;
    }

    /**
     * 카테고리 연결
     *
     * @param  Product  $product  상품 모델
     * @param  string  $categorySlug  카테고리 슬러그
     */
    private function attachCategory(Product $product, string $categorySlug): void
    {
        $category = Category::where('slug', $categorySlug)->first();

        if ($category) {
            $product->categories()->attach($category->id, ['is_primary' => true]);

            // 상위 카테고리도 연결 (검색용)
            $ancestorIds = $category->getAncestorIds();
            foreach ($ancestorIds as $ancestorId) {
                if ($ancestorId !== $category->id) {
                    $product->categories()->attach($ancestorId, ['is_primary' => false]);
                }
            }
        }
    }

    /**
     * 상품 옵션 생성 (다국어 지원)
     *
     * @param  Product  $product  상품 모델
     * @param  array  $optionGroups  옵션 그룹 배열 (다국어 형식)
     * @param  string  $productSku  상품 SKU (옵션 SKU 생성 기준)
     */
    private function createOptions(Product $product, array $optionGroups, string $productSku): void
    {
        // 옵션 조합 생성
        $combinations = $this->generateOptionCombinations($optionGroups);
        $sortOrder = 0;
        $totalStock = 0;

        foreach ($combinations as $combination) {
            // 다국어 option_name 생성
            $optionName = $this->generateMultilingualOptionName($combination);

            // 다국어 option_values 생성 (배열 형식)
            $optionValues = $this->generateMultilingualOptionValues($combination, $optionGroups);

            // 옵션 SKU 생성: 상품SKU + 옵션값 약어 (예: TS-0001-WHT-M)
            $optionSku = $this->generateOptionSku($productSku, $combination);

            $stockQuantity = rand(5, 30);
            $totalStock += $stockQuantity;

            ProductOption::create([
                'product_id' => $product->id,
                'option_code' => $product->product_code.'-'.str_pad($sortOrder + 1, 3, '0', STR_PAD_LEFT),
                'option_values' => $optionValues,
                'option_name' => $optionName,
                'sku' => $optionSku,
                'price_adjustment' => $sortOrder === 0 ? 0 : rand(0, 5) * 1000,
                'currency_code' => 'KRW',
                'stock_quantity' => $stockQuantity,
                'safe_stock_quantity' => 3,
                'is_default' => $sortOrder === 0,
                'is_active' => true,
                'sort_order' => $sortOrder++,
            ]);
        }

        // 상품 재고 업데이트 (옵션 합계)
        $product->update(['stock_quantity' => $totalStock]);
    }

    /**
     * 옵션 SKU 생성
     *
     * 상품 SKU에 옵션값 영문 약어를 붙여 옵션 SKU를 생성합니다.
     * 예: TS-0001-WHT-M, BG-0012-BLK, CB-0008-1M-WHT
     *
     * @param  string  $productSku  상품 SKU (예: TS-0001)
     * @param  array  $combination  옵션 조합 (groupIndex => 다국어 값 배열)
     * @return string 옵션 SKU
     */
    private function generateOptionSku(string $productSku, array $combination): string
    {
        $parts = [$productSku];

        foreach ($combination as $value) {
            $englishValue = $value['en'] ?? $value['ko'] ?? '';
            $parts[] = $this->abbreviateOptionValue($englishValue);
        }

        return strtoupper(implode('-', $parts));
    }

    /**
     * 옵션값 영문 약어 생성
     *
     * 일반적인 옵션값을 3자 이내의 약어로 변환합니다.
     * 매핑에 없는 값은 처음 3자를 대문자로 사용합니다.
     *
     * @param  string  $value  옵션값 (영문)
     * @return string 약어
     */
    private function abbreviateOptionValue(string $value): string
    {
        // 자주 사용되는 옵션값 약어 매핑
        $abbreviations = [
            // 색상
            'White' => 'WHT',
            'Black' => 'BLK',
            'Navy' => 'NVY',
            'Gray' => 'GRY',
            'Blue' => 'BLU',
            'Red' => 'RED',
            'Pink' => 'PNK',
            'Brown' => 'BRN',
            'Camel' => 'CML',
            'Khaki' => 'KHK',
            'Silver' => 'SLV',
            'Rose Gold' => 'RSG',
            'Natural' => 'NAT',
            // 사이즈
            'S' => 'S',
            'M' => 'M',
            'L' => 'L',
            'XL' => 'XL',
            // 기본
            'Default' => 'STD',
        ];

        // 매핑에 있으면 사용
        if (isset($abbreviations[$value])) {
            return $abbreviations[$value];
        }

        // 숫자+단위 형식 (예: 350ml, 1m, 2m, 256GB) → 그대로 사용
        if (preg_match('/^\d+\w*$/', $value)) {
            return strtoupper($value);
        }

        // 그 외: 처음 3자 대문자
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $value), 0, 3));
    }

    /**
     * 다국어 option_name 생성
     *
     * @param  array  $combination  옵션 조합 (groupIndex => valueArray)
     * @return array 다국어 option_name {ko: "값1/값2", en: "Value1/Value2"}
     */
    private function generateMultilingualOptionName(array $combination): array
    {
        $locales = config('app.supported_locales', ['ko', 'en']);
        $result = [];

        foreach ($locales as $locale) {
            $parts = [];
            foreach ($combination as $value) {
                // value는 다국어 배열 ['ko' => '...', 'en' => '...']
                $parts[] = $value[$locale] ?? $value['ko'] ?? '';
            }
            $result[$locale] = implode('/', $parts);
        }

        return $result;
    }

    /**
     * 다국어 option_values 생성 (배열 형식)
     *
     * @param  array  $combination  옵션 조합 (groupIndex => valueArray)
     * @param  array  $optionGroups  옵션 그룹 배열
     * @return array option_values 배열 [{key: {...}, value: {...}}, ...]
     */
    private function generateMultilingualOptionValues(array $combination, array $optionGroups): array
    {
        $result = [];
        $index = 0;

        foreach ($optionGroups as $group) {
            if (isset($combination[$index])) {
                $result[] = [
                    'key' => $group['name'], // 다국어 객체 {ko: "색상", en: "Color"}
                    'value' => $combination[$index], // 다국어 객체 {ko: "빨강", en: "Red"}
                ];
            }
            $index++;
        }

        return $result;
    }

    /**
     * 옵션 조합 생성 (다국어 값 반환)
     *
     * @param  array  $optionGroups  옵션 그룹 배열 (다국어 형식)
     * @return array 조합 배열 (각 조합은 groupIndex => multilingualValue)
     */
    private function generateOptionCombinations(array $optionGroups): array
    {
        if (empty($optionGroups)) {
            return [];
        }

        $result = [[]];

        foreach ($optionGroups as $groupIndex => $group) {
            $newResult = [];
            foreach ($result as $combination) {
                foreach ($group['values'] as $value) {
                    $newCombination = $combination;
                    // value는 다국어 배열 ['ko' => '화이트', 'en' => 'White']
                    $newCombination[$groupIndex] = $value;
                    $newResult[] = $newCombination;
                }
            }
            $result = $newResult;
        }

        return $result;
    }

    /**
     * 이미지 풀을 병렬 다운로드합니다.
     *
     * poolSize개 상품분의 이미지를 Http::pool()로 병렬 다운로드하여
     * 메모리에 저장합니다. poolSize 이후 상품은 이 풀에서 순환 선택 + 색상 필터로 재사용합니다.
     *
     * @param  int  $poolSize  다운로드할 상품 수 (최대 IMAGE_POOL_SIZE)
     * @return array<int, array<int, array{content: string, width: int, height: int}>> 이미지 풀 [productIndex][imageIndex]
     */
    private function downloadImagePool(int $poolSize): array
    {
        $imagePool = [];
        $imagesPerProduct = 4; // 메인 1 + 추가 3

        // 모든 시드 값 생성
        $allSeeds = [];
        for ($p = 0; $p < $poolSize; $p++) {
            $baseSeed = crc32("product-pool-{$p}");
            for ($img = 0; $img < $imagesPerProduct; $img++) {
                $allSeeds[] = [
                    'product_index' => $p,
                    'image_index' => $img,
                    'seed' => $baseSeed + $img * 100,
                ];
            }
        }

        // 배치 단위 Http::pool() 병렬 다운로드
        $progressBar = $this->command->getOutput()->createProgressBar(count($allSeeds));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');

        foreach (array_chunk($allSeeds, self::DOWNLOAD_BATCH_SIZE) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch) {
                foreach ($batch as $item) {
                    $pool->as("{$item['product_index']}_{$item['image_index']}")
                        ->withoutVerifying()
                        ->timeout(30)
                        ->get("https://picsum.photos/seed/{$item['seed']}/800/800");
                }
            });

            foreach ($batch as $item) {
                $key = "{$item['product_index']}_{$item['image_index']}";
                $response = $responses[$key] ?? null;

                if ($response && $response->successful()) {
                    $imagePool[$item['product_index']][$item['image_index']] = [
                        'content' => $response->body(),
                        'width' => 800,
                        'height' => 800,
                    ];
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine();

        return $imagePool;
    }

    /**
     * 이미지 풀에서 상품 이미지를 생성합니다.
     *
     * poolSize 이내: 풀에서 직접 사용
     * poolSize 초과: 풀에서 순환 선택 + GD 색상 필터 적용 (벌크 모드에서는 필터 생략)
     *
     * @param  Product  $product  상품 모델
     * @param  int  $index  상품 인덱스 (0-based)
     * @param  array  $imagePool  이미지 풀
     * @param  int  $poolSize  이미지 풀 크기
     * @param  bool  $useBulkMode  벌크 모드 여부 (true: GD 필터 제거 + hash 메모리 생성)
     */
    private function createProductImages(Product $product, int $index, array $imagePool, int $poolSize, bool $useBulkMode = false): void
    {
        $poolIndex = $index % $poolSize;
        $isReused = $index >= $poolSize;
        $images = $imagePool[$poolIndex] ?? [];

        if (empty($images)) {
            return;
        }

        $productPath = 'products/'.$product->product_code;
        $sortOrder = 0;

        foreach ($images as $imgIndex => $imgData) {
            $content = $imgData['content'];

            // poolSize 이후 상품은 색상 필터 적용으로 이미지 변형 (벌크 모드에서는 생략)
            if ($isReused && ! $useBulkMode) {
                $content = $this->applyColorFilter($content);
            }

            $filename = Str::uuid().'.jpg';
            $fullPath = $productPath.'/'.$filename;

            // 스토리지에 저장
            $this->storage->put('images', $fullPath, $content);

            $isMain = $imgIndex === 0;
            $collection = $isMain ? ProductImageCollection::MAIN : ProductImageCollection::ADDITIONAL;

            // 벌크 모드: 메모리 기반 hash 생성 (DB 조회 없음)
            // 일반 모드: md5 기반 hash 생성
            if ($useBulkMode) {
                $hash = $this->generateUniqueHashInMemory();
            } else {
                $hashSuffix = $isMain ? 'main' : 'additional-'.$imgIndex;
                $hash = substr(md5($product->product_code.'-'.$hashSuffix), 0, 12);
            }

            ProductImage::create([
                'product_id' => $product->id,
                'hash' => $hash,
                'original_filename' => $isMain ? 'product-main.jpg' : 'product-additional-'.$imgIndex.'.jpg',
                'stored_filename' => $filename,
                'disk' => $this->storage->getDisk(),
                'path' => $fullPath,
                'mime_type' => 'image/jpeg',
                'file_size' => strlen($content),
                'width' => $imgData['width'],
                'height' => $imgData['height'],
                'alt_text' => $product->name,
                'collection' => $collection,
                'is_thumbnail' => $isMain,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /**
     * GD 색상 필터를 적용하여 이미지를 변형합니다.
     *
     * 5가지 필터 중 랜덤 적용: 색상 시프트, 따뜻한 톤, 차가운 톤, 밝기/대비, 세피아
     *
     * @param  string  $imageContent  원본 이미지 바이너리
     * @return string 필터 적용된 이미지 바이너리
     */
    public function applyColorFilter(string $imageContent): string
    {
        $image = @imagecreatefromstring($imageContent);
        if ($image === false) {
            return $imageContent;
        }

        $filterType = rand(0, 4);
        switch ($filterType) {
            case 0: // 랜덤 색상 시프트
                imagefilter($image, IMG_FILTER_COLORIZE, rand(-80, 80), rand(-80, 80), rand(-80, 80));
                break;
            case 1: // 따뜻한 톤 (오렌지/옐로)
                imagefilter($image, IMG_FILTER_COLORIZE, rand(20, 60), rand(10, 30), rand(-20, 0));
                break;
            case 2: // 차가운 톤 (블루)
                imagefilter($image, IMG_FILTER_COLORIZE, rand(-30, 0), rand(-10, 10), rand(20, 60));
                break;
            case 3: // 밝기/대비 조정
                imagefilter($image, IMG_FILTER_BRIGHTNESS, rand(-30, 30));
                imagefilter($image, IMG_FILTER_CONTRAST, rand(-20, 20));
                break;
            case 4: // 세피아 효과
                imagefilter($image, IMG_FILTER_GRAYSCALE);
                imagefilter($image, IMG_FILTER_COLORIZE, rand(80, 110), rand(50, 70), rand(20, 40));
                break;
        }

        ob_start();
        imagejpeg($image, null, 85);
        $filtered = ob_get_clean();
        imagedestroy($image);

        return $filtered ?: $imageContent;
    }

    /**
     * 메모리 기반 고유 해시 생성 (벌크 모드용)
     *
     * DB 조회 없이 메모리 Set으로 고유성을 보장합니다.
     * ProductImage boot hook에서 hash가 이미 설정된 경우 DB 조회를 건너뜁니다.
     *
     * @return string 12자리 고유 해시
     */
    public function generateUniqueHashInMemory(): string
    {
        do {
            $hash = substr(bin2hex(random_bytes(6)), 0, 12);
        } while (isset($this->usedHashes[$hash]));

        $this->usedHashes[$hash] = true;

        return $hash;
    }

    /**
     * 상품정보제공고시 생성
     *
     * @param  Product  $product  상품 모델
     * @param  ProductNoticeTemplate  $template  상품정보제공고시 템플릿
     */
    private function createProductNotice(Product $product, ProductNoticeTemplate $template): void
    {
        // 템플릿의 fields를 values로 변환
        $values = [];
        foreach ($template->fields as $field) {
            $values[] = [
                'name' => $field['name'],
                'content' => $field['content'],
            ];
        }

        ProductNotice::create([
            'product_id' => $product->id,
            'values' => $values,
        ]);
    }

    /**
     * 상품에 라벨 할당
     *
     * @param  Product  $product  상품 모델
     * @param  \Illuminate\Support\Collection  $labels  사용 가능한 라벨 컬렉션
     */
    private function assignLabels(Product $product, $labels): void
    {
        // 1~3개의 랜덤 라벨 선택
        $labelCount = rand(1, min(3, $labels->count()));
        $selectedLabels = $labels->random($labelCount);

        // Collection이 아닌 단일 모델이 반환될 수 있음
        if (! ($selectedLabels instanceof \Illuminate\Support\Collection)) {
            $selectedLabels = collect([$selectedLabels]);
        }

        foreach ($selectedLabels as $label) {
            // 시작일/종료일 설정 (50%는 무기한, 50%는 기간 제한)
            $startDate = null;
            $endDate = null;

            if (rand(1, 100) <= 50) {
                // 기간 제한 라벨
                $startDate = now()->subDays(rand(0, 30));
                $endDate = now()->addDays(rand(7, 90));
            }

            ProductLabelAssignment::create([
                'product_id' => $product->id,
                'label_id' => $label->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }
    }
}
