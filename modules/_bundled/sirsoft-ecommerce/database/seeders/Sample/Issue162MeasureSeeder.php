<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * #162 브라우저 실측용 이커머스 데이터 시더 (CLI + G7_PLAYWRIGHT_BYPASS=1 전용)
 *
 * 비회원이 실제 주문 흐름(담기 → 주문서 → 결제 수단 → 결제하기)을 밟을 표지 상품 하나를 만든다. 비회원 조회 토큰은
 * 주문 생성 응답에서만 발급되므로(`GuestOrderAuthService::issueForOrder`) 주문은 시더가 아니라 실측 중 화면에서 만든다.
 * 상품 이름에 표지 `[실측-162]` 를 붙여 정리 시더(`Issue162MeasureCleanupSeeder`)가 그 상품과 그 상품이 든 주문만 지운다.
 * 이미 있으면 새로 만들지 않는다.
 */
class Issue162MeasureSeeder extends Seeder
{
    /** 정리 표지 */
    public const MARKER = '[실측-162]';

    /** 상품 코드 (중복 방지용 고정값) */
    public const PRODUCT_CODE = 'M162-MEASURE';

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        if (php_sapi_name() !== 'cli' || env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->command?->error(__('sirsoft-ecommerce::messages.seeders.issue162_measure.guard'));

            return;
        }

        $product = Product::where('product_code', self::PRODUCT_CODE)->first() ?? $this->createProduct();
        $option = ProductOption::where('product_id', $product->id)->orderBy('sort_order')->first();
        $this->seedDescriptionMedia($product);

        $this->command?->line(__('sirsoft-ecommerce::messages.seeders.issue162_measure.product', [
            'id' => $product->id,
            'option_id' => $option?->id,
        ]));
        $this->command?->info(__('sirsoft-ecommerce::messages.seeders.issue162_measure.done'));
    }

    /**
     * 판매 중·노출 상태의 표지 상품을 옵션 하나와 함께 만듭니다.
     *
     * @return Product 만든 상품
     */
    private function createProduct(): Product
    {
        $currency = app(CurrencyConversionService::class)->getDefaultCurrency();
        $optionGroups = [[
            'name' => ['ko' => '구성', 'en' => 'Set'],
            'values' => [['ko' => '기본', 'en' => 'Basic']],
        ]];

        $product = Product::create([
            'name' => ['ko' => self::MARKER.' 실측 상품', 'en' => self::MARKER.' Measurement product'],
            'product_code' => self::PRODUCT_CODE,
            'sku' => self::PRODUCT_CODE,
            'list_price' => 1000,
            'selling_price' => 1000,
            'currency_code' => $currency,
            'stock_quantity' => 100,
            'safe_stock_quantity' => 1,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'tax_status' => ProductTaxStatus::from('taxable'),
            'tax_rate' => 10.00,
            'description' => ['ko' => '<p>'.self::MARKER.' 실측 상품</p>', 'en' => '<p>'.self::MARKER.' Measurement product</p>'],
            'description_mode' => 'html',
            'has_options' => true,
            'option_groups' => $optionGroups,
        ]);

        ProductOption::create([
            'product_id' => $product->id,
            'option_code' => self::PRODUCT_CODE.'-001',
            'option_values' => [['key' => ['ko' => '구성', 'en' => 'Set'], 'value' => ['ko' => '기본', 'en' => 'Basic']]],
            'option_name' => ['ko' => '기본', 'en' => 'Basic'],
            'sku' => self::PRODUCT_CODE.'-001',
            'price_adjustment' => 0,
            'currency_code' => $currency,
            'stock_quantity' => 100,
            'safe_stock_quantity' => 1,
            'weight' => 100,
            'volume' => 100,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $category = Category::query()->orderBy('id')->first();
        if ($category) {
            $product->categories()->attach($category->id, ['is_primary' => true]);
        }

        return $product;
    }

    /**
     * 상품 설명에 본문 미디어 표본을 넣습니다(#883 4·5단계) — 캡션 이미지 1·동영상 1·유튜브 외부 영상 1.
     * 파일은 실제 업로드 경로(`ContentMediaService::upload` → `syncWithContent`)로 붙인다. 이미 넣었으면 건너뛴다.
     *
     * @param  Product  $product  표지 상품
     * @return void 반환값 없음
     */
    private function seedDescriptionMedia(Product $product): void
    {
        $fixture = base_path('tests/Fixtures/media/sample-1s.mp4');
        $current = (array) ($product->description ?? []);
        if (! is_file($fixture) || ! function_exists('imagecreatetruecolor') || str_contains((string) ($current['ko'] ?? ''), '<video')) {
            return;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->first();
        if ($admin) {
            Auth::login($admin);
        }

        $service = app(ContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $png = tempnam(sys_get_temp_dir(), 'm162').'.png';
        $img = imagecreatetruecolor(120, 80);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
        imagepng($img, $png);
        imagedestroy($img);
        $image = $service->upload(ContentMediaOwnerType::Product, new UploadedFile($png, 'm162.png', 'image/png', null, true), $tempKey);
        $copy = tempnam(sys_get_temp_dir(), 'm162').'.mp4';
        copy($fixture, $copy);
        $video = $service->upload(ContentMediaOwnerType::Product, new UploadedFile($copy, 'm162.mp4', 'video/mp4', null, true), $tempKey);

        $base = '/api/modules/sirsoft-ecommerce/content-media/';
        $html = '<p>'.self::MARKER.' 실측 상품</p>'
            .'<figure class="image"><img src="'.$base.$image->hash.'" alt="m162"><figcaption>'.self::MARKER.' 캡션</figcaption></figure>'
            .'<video controls src="'.$base.$video->hash.'"></video>'
            .'<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>';
        $description = ['ko' => $html, 'en' => $html];
        $product->forceFill(['description' => $description, 'description_mode' => 'html'])->save();
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, $description, true, $tempKey);

        if ($admin) {
            Auth::logout();
        }
    }
}
