<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use App\Extension\ModuleManager;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * EcommerceModule SEO declaration 회귀 테스트.
 *
 * 회귀: 상품 다국어 컬럼이 MariaDB 환경에서 array 로 전달될 때
 * "Array to string conversion" → SeoMiddleware catch → SPA fallback (모든 봇 미리보기 미출력).
 */
class EcommerceModuleSeoTest extends ModuleTestCase
{
    private Module $module;

    /**
     * 활성 모듈 관리자에서 이커머스 모듈 인스턴스를 꺼내고, 없으면 모듈 경로로 직접 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->module = app(ModuleManager::class)->getModule('sirsoft-ecommerce')
            ?? new Module(base_path('modules/sirsoft-ecommerce'));
    }

    /**
     * 회귀: 상품 name/description 이 다국어 JSON array 일 때 throw 없이 정상 추출.
     *
     * @effects seo_og_defaults_handle_multilingual_array
     *
     * @return void 반환값 없음
     */
    public function test_product_seo_og_defaults_handles_multilingual_array(): void
    {
        app()->setLocale('ko');

        $context = [
            'product' => [
                'data' => [
                    'name' => ['ko' => '에어맥스', 'en' => 'AirMax'],
                    'thumbnail_url' => 'https://example.com/p.jpg',
                    'selling_price' => 129000,
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('product', $context);

        $this->assertSame('에어맥스', $og['image_alt']);
        $this->assertSame('https://example.com/p.jpg', $og['image']);
    }

    /**
     * 회귀: thumbnail_url 이 상대 경로(/api/...) 일 때 og:image 가 절대 URL 로 변환.
     *
     * 슬랙·페이스북·쓰레드 모두 og:image 는 절대 URL 필수. 상대 경로 출력 시 이미지 미표시.
     *
     * @effects seo_og_image_is_absolute_url
     *
     * @return void 반환값 없음
     */
    public function test_product_seo_og_defaults_image_is_absolute_url(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '가죽 크로스백',
                    'thumbnail_url' => '/api/modules/sirsoft-ecommerce/product-image/abc123',
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('product', $context);

        $this->assertArrayHasKey('image', $og);
        $this->assertStringStartsWith('http', $og['image'], 'og:image 는 절대 URL 이어야 페이스북·슬랙·쓰레드가 인식합니다');
        $this->assertStringContainsString('/api/modules/sirsoft-ecommerce/product-image/abc123', $og['image']);
    }

    /**
     * 회귀: 하위 폴더 마운트에서 og:image · schema.image 에 접두사가 두 번 붙지 않는다.
     *
     * `ProductImage::download_url` 이 `AssetUrl::basePrefix()` 를 거치도록 바뀌면서
     * 그 값은 이미 마운트 접두사를 품는다(`/g7/api/…`). 그 값에 `url()` 을 쓰면 요청
     * 루트(접두사 포함)가 앞에 다시 붙어 `https://host/g7/g7/api/…` 가 된다 —
     * 크롤러만 받는 값이라 화면에는 아무 표시가 없고, 미리보기 이미지만 404 다.
     * 오리진만 붙이는 `AssetUrl::absolute()` 가 그 SSoT 이고, 그 독블록이 바로 이
     * 실패를 이름으로 적고 있다.
     *
     * @effects seo_image_mount_prefix_applied_once
     *
     * @return void 반환값 없음
     */
    public function test_seo_image_does_not_double_apply_the_mount_prefix(): void
    {
        // 마운트를 **실제로** 재현한다. `AssetUrl::forceBasePrefix()` 만으로는 부족하다 —
        // 이중 부착을 만드는 것은 `url()` 이고 그 헬퍼는 요청 루트를 보기 때문에,
        // 루트를 함께 옮기지 않으면 이 결함은 재현되지 않고 검사는 조용히 초록이 된다.
        // 절대 주소의 접두사 출처는 사이트 주소(SiteUrl)다 — 하위 폴더 설치의 사이트 주소는 접두사를 품는다.
        config(['app.url' => 'http://localhost/g7']);
        AssetUrl::forceBasePrefix('/g7');
        URL::forceRootUrl('http://localhost/g7');

        try {
            $context = [
                'product' => [
                    'data' => [
                        'name' => '가죽 크로스백',
                        'thumbnail_url' => '/g7/api/modules/sirsoft-ecommerce/product-image/abc123',
                    ],
                ],
                'category' => [
                    'data' => [
                        'name' => '가방',
                        'thumbnail_url' => '/g7/api/modules/sirsoft-ecommerce/category-image/cat1',
                    ],
                ],
            ];

            $og = $this->module->seoOgDefaults('product', $context);
            $this->assertStringNotContainsString('/g7/g7/', $og['image'], 'og:image 에 마운트 접두사가 두 번 붙었다');
            $this->assertStringEndsWith('/g7/api/modules/sirsoft-ecommerce/product-image/abc123', $og['image']);

            $catOg = $this->module->seoOgDefaults('category', $context);
            $this->assertStringNotContainsString('/g7/g7/', $catOg['image'], '카테고리 og:image 에 접두사가 두 번 붙었다');
            $this->assertStringStartsWith('http', $catOg['image'], '카테고리 og:image 는 절대 URL 이어야 크롤러가 인식한다');
            $this->assertStringEndsWith(
                '/g7/api/modules/sirsoft-ecommerce/category-image/cat1',
                $catOg['image']
            );

            // `schema.image` 축은 og 축과 **같은 강도**로 잰다.
            //
            // 종전에는 이 자리에 이중접두 단언 하나뿐이었고, 파일 전체에서 `$schema['image']`
            // 를 재는 단언이 그것 하나였다. 그런데 이 결함의 반대편 실패(절대화 자체를
            // 지움)는 `/g7/g7/` 를 만들지 않으므로 그 상태에서도 초록이다 — 형제 모듈
            // (`BoardModuleSeoTest`)은 이미 세 축으로 잠갔는데 이쪽만 남아 있었다
            // (16차 G3 라운드 A-5).
            $schema = $this->module->seoStructuredData('product', $context);
            $this->assertStringNotContainsString('/g7/g7/', $schema['image'], 'schema.image 에 접두사가 두 번 붙었다');
            $this->assertStringStartsWith('http', $schema['image'], 'schema.image 는 절대 URL 이어야 크롤러가 인식한다');
            $this->assertStringEndsWith(
                '/g7/api/modules/sirsoft-ecommerce/product-image/abc123',
                $schema['image']
            );
        } finally {
            AssetUrl::forceBasePrefix(null);
            URL::forceRootUrl(null);
        }
    }

    /**
     * 접두사가 없는 표준 배치에서는 종전과 동일하다 — 과잉 전환 대조.
     *
     * 형제 모듈(`BoardModuleSeoTest`)이 이미 갖고 있던 축인데 이쪽에는 없었다.
     * 그래서 `schema.image` 의 표준 배치 동작은 어느 검사에도 걸리지 않았다
     * (16차 G3 라운드 A-5).
     *
     * @effects seo_image_unchanged_without_mount_prefix
     *
     * @return void 반환값 없음
     */
    public function test_seo_image_is_unchanged_without_a_mount_prefix(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '표준 배치 상품',
                    'thumbnail_url' => '/api/modules/sirsoft-ecommerce/product-image/abc123',
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('product', $context);
        $this->assertStringStartsWith('http', $og['image'], 'og:image 는 절대 URL 이어야 크롤러가 인식한다');
        $this->assertStringEndsWith('/api/modules/sirsoft-ecommerce/product-image/abc123', $og['image']);

        // 무접두사 배치에서도 두 축을 나란히 잰다.
        $schema = $this->module->seoStructuredData('product', $context);
        $this->assertStringStartsWith('http', $schema['image'], 'schema.image 는 절대 URL 이어야 크롤러가 인식한다');
        $this->assertStringEndsWith('/api/modules/sirsoft-ecommerce/product-image/abc123', $schema['image']);
    }

    /**
     * 사이트 경로가 아닌 자산 값은 손대지 않고, 리터럴 사이트 경로에는 접두사를 채운다.
     *
     * 종전 형태(`str_starts_with('http') ? … : AssetUrl::absolute()`)는 세 입력을
     * 조용히 망가뜨렸다 — 프로토콜 상대(`//cdn/…` → `https://host//cdn/…`) · 상대 경로
     * (`img.png` → `https://hostimg.png`) · `data:` URI(`https://hostdata:image/…`).
     * 그리고 접두사를 채우는 주체가 없어 리터럴 사이트 경로는 하위 폴더 배치에서
     * 접두사를 잃었다. 넷 다 오류가 나지 않고 그 이미지만 안 나온다
     * (16차 G3 라운드 A-4).
     *
     * @effects seo_image_leaves_non_site_paths_alone
     *
     * @return void 반환값 없음
     */
    public function test_seo_image_leaves_non_site_paths_alone_and_fills_the_prefix(): void
    {
        // 절대 주소의 접두사 출처는 사이트 주소(SiteUrl)다 — 하위 폴더 설치의 사이트 주소는 접두사를 품는다.
        config(['app.url' => 'http://localhost/g7']);
        AssetUrl::forceBasePrefix('/g7');
        URL::forceRootUrl('http://localhost/g7');

        try {
            $imageFor = function (string $thumbnail): string {
                return $this->module->seoOgDefaults('product', [
                    'product' => ['data' => ['name' => '상품', 'thumbnail_url' => $thumbnail]],
                ])['image'] ?? '';
            };

            // 프로토콜 상대 — 오리진을 앞에 붙이면 `https://host//cdn/…` 가 된다.
            $this->assertSame('//cdn.example.com/y.png', $imageFor('//cdn.example.com/y.png'));

            // 상대 경로 — 오리진을 붙이면 구분자 없이 이어 붙는다.
            $this->assertSame('img.png', $imageFor('img.png'));

            // `data:` URI — 사이트 경로가 아니다.
            $this->assertSame('data:image/png;base64,AAA', $imageFor('data:image/png;base64,AAA'));

            // 절대 URL 은 종전과 같이 그대로.
            $this->assertSame('https://cdn.example.com/x.png', $imageFor('https://cdn.example.com/x.png'));

            // 리터럴 사이트 경로에는 접두사를 채울 주체가 여기밖에 없다.
            $this->assertStringEndsWith('/g7/images/logo.png', $imageFor('/images/logo.png'));
            $this->assertStringNotContainsString('/g7/g7/', $imageFor('/images/logo.png'));
        } finally {
            AssetUrl::forceBasePrefix(null);
            URL::forceRootUrl(null);
        }
    }

    /**
     * 카테고리 og:image 출력 레벨 — thumbnail_url 이 있으면 image 가 절대 URL 로 방출된다.
     *
     * @scenario product-content-thumbnail-fallback
     *
     * @effects category_og_image_emitted_from_thumbnail_url
     *
     * @return void 반환값 없음
     */
    public function test_category_seo_og_defaults_emits_image_from_thumbnail_url(): void
    {
        $context = [
            'category' => [
                'data' => [
                    'name' => ['ko' => '아우터', 'en' => 'Outer'],
                    'thumbnail_url' => '/api/modules/sirsoft-ecommerce/category-image/cat123',
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('category', $context);

        $this->assertArrayHasKey('image', $og);
        $this->assertStringStartsWith('http', $og['image'], 'og:image 는 절대 URL 이어야 공유 카드가 인식합니다');
        $this->assertStringContainsString('/api/modules/sirsoft-ecommerce/category-image/cat123', $og['image']);
        $this->assertSame('아우터', $og['image_alt']);
    }

    /**
     * 카테고리 og:image 출력 레벨 — 이미지 없는 카테고리는 image 키 자체가 미출력된다
     * (빈 값은 array_filter 로 제거 → 코어 사이트 기본 OG 이미지 폴백에 위임).
     *
     * @scenario product-content-thumbnail-fallback
     *
     * @effects category_og_image_omitted_without_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_category_seo_og_defaults_omits_image_without_thumbnail(): void
    {
        $context = [
            'category' => [
                'data' => [
                    'name' => '아우터',
                    'thumbnail_url' => null,
                ],
            ],
        ];

        $og = $this->module->seoOgDefaults('category', $context);

        $this->assertArrayNotHasKey('image', $og);
        $this->assertSame('website', $og['type']);
    }

    /**
     * 회귀: 상품 structured_data 도 다국어 array 안전 처리.
     *
     * @effects structured_data_handles_multilingual_array
     *
     * @return void 반환값 없음
     */
    public function test_product_structured_data_handles_multilingual_array(): void
    {
        app()->setLocale('ko');

        $context = [
            'product' => [
                'data' => [
                    'name' => ['ko' => '에어맥스', 'en' => 'AirMax'],
                    'description' => ['ko' => '한국어 설명', 'en' => 'English description'],
                    'thumbnail_url' => 'https://example.com/p.jpg',
                    'selling_price' => 129000,
                    'sales_status' => 'on_sale',
                ],
            ],
        ];

        $schema = $this->module->seoStructuredData('product', $context);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('에어맥스', $schema['name']);
        $this->assertSame('한국어 설명', $schema['description']);
        $this->assertSame('129000', $schema['offers']['price']);
    }

    /**
     * seoOgDefaultMeta 가 데이터 경로(연결 칩) + 다국어 라벨을 선언한다.
     *
     * @effects og_default_meta_declares_data_path_and_label
     *
     * @return void 반환값 없음
     */
    public function test_product_seo_og_default_meta_declares_data_path_and_label(): void
    {
        $meta = $this->module->seoOgDefaultMeta('product');

        // label 은 번역 키(언어팩 대응) — __() 로 해석돼 한국어로 표시된다.
        $this->assertSame('{{product.data.thumbnail_url}}', $meta['image']['expr']);
        $this->assertSame('sirsoft-ecommerce::seo.auto_value.product_image', $meta['image']['label']);
        $this->assertSame('상품 대표 이미지', __($meta['image']['label'], [], 'ko'));
        $this->assertSame('Product image', __($meta['image']['label'], [], 'en'));
        $this->assertSame('{{product.data.name}}', $meta['image_alt']['expr']);

        // category 도 자기 데이터 경로 선언.
        $catMeta = $this->module->seoOgDefaultMeta('category');
        $this->assertSame('{{category.data.thumbnail_url}}', $catMeta['image']['expr']);

        // 도메인 외 page_type 은 빈 배열(평문 폴백).
        $this->assertSame([], $this->module->seoOgDefaultMeta('shop_index'));
    }

    /**
     * seoStructuredDataMeta 가 점 경로 키별 데이터 경로를 선언한다.
     *
     * @effects structured_data_meta_declares_dotted_paths
     *
     * @return void 반환값 없음
     */
    public function test_product_structured_data_meta_declares_dotted_paths(): void
    {
        $meta = $this->module->seoStructuredDataMeta('product');

        // 제목/설명은 사용자 입력 meta(다국어) 우선 + 상품 필드 폴백 구조다.
        // $localized() 로 현재 로케일 문자열을 뽑으므로 언어별 SEO 분기가 성립한다.
        $this->assertSame(
            '{{$localized(product.data.meta_title) ?? $localized(product.data.name)}}',
            $meta['name']['expr']
        );
        $this->assertSame(
            '{{$localized(product.data.meta_description) ?? $localized(product.data.short_description)}}',
            $meta['description']['expr']
        );

        // 다국어 대상이 아닌 값은 점 경로를 그대로 선언한다.
        $this->assertSame('{{product.data.selling_price}}', $meta['offers.price']['expr']);
        $this->assertSame('{{product.data.sku}}', $meta['sku']['expr']);
    }

    /**
     * 정합성: 메타가 선언한 키는 운영 declaration 이 산출 가능한 키여야 한다(키 드리프트 차단).
     * og.image/image_alt 는 declaration 키 집합에 존재해야 하고, structured 점 경로는 평탄화한
     * declaration 키에 존재해야 한다(파생값 제외는 허용 — 메타 ⊆ declaration).
     *
     * @effects seo_meta_keys_subset_of_declaration
     *
     * @return void 반환값 없음
     */
    public function test_meta_keys_are_subset_of_declaration_keys(): void
    {
        $context = [
            'product' => [
                'data' => [
                    'name' => '키정합 상품',
                    'short_description' => '설명',
                    'thumbnail_url' => 'https://example.com/p.jpg',
                    'sku' => 'SKU-1',
                    'selling_price' => 1000,
                ],
            ],
        ];

        $ogKeys = array_keys($this->module->seoOgDefaults('product', $context));
        foreach (array_keys($this->module->seoOgDefaultMeta('product')) as $metaKey) {
            $this->assertContains($metaKey, $ogKeys, "og 메타 키 '{$metaKey}' 는 declaration 키여야 합니다");
        }

        $flatStructured = $this->flattenKeys($this->module->seoStructuredData('product', $context));
        foreach (array_keys($this->module->seoStructuredDataMeta('product')) as $metaKey) {
            $this->assertContains($metaKey, $flatStructured, "structured 메타 키 '{$metaKey}' 는 declaration 평탄 키여야 합니다");
        }
    }

    /**
     * 결함(라이브 검수 2026-06-14): structured offers.availability 가 존재하지 않는 'in_stock' 키를
     * 참조해 **모든 상품이 OutOfStock** 으로 출력됨. ProductResource 실제 키는 sales_status(enum value)
     * 와 stock_quantity. sales_status='on_sale' → InStock 이어야 한다.
     *
     * @effects structured_availability_follows_sales_status
     *
     * @return void 반환값 없음
     */
    public function test_product_structured_availability_uses_sales_status_not_missing_in_stock_key(): void
    {
        $base = [
            'name' => '재고 상품',
            'selling_price' => 1000,
        ];

        // 판매중(on_sale) → InStock.
        $onSale = $this->module->seoStructuredData('product', ['product' => ['data' => $base + ['sales_status' => 'on_sale', 'stock_quantity' => 320]]]);
        $this->assertSame('https://schema.org/InStock', $onSale['offers']['availability'], '판매중 상품은 InStock');

        // 품절(sold_out) → OutOfStock.
        $soldOut = $this->module->seoStructuredData('product', ['product' => ['data' => $base + ['sales_status' => 'sold_out', 'stock_quantity' => 0]]]);
        $this->assertSame('https://schema.org/OutOfStock', $soldOut['offers']['availability'], '품절 상품은 OutOfStock');

        // 판매중지(suspended) → OutOfStock.
        $suspended = $this->module->seoStructuredData('product', ['product' => ['data' => $base + ['sales_status' => 'suspended']]]);
        $this->assertSame('https://schema.org/OutOfStock', $suspended['offers']['availability'], '판매중지 상품은 OutOfStock');
    }

    /**
     * 중첩 배열을 점 경로 키 목록으로 평탄화(@type/@context 제외).
     *
     * @param  array  $block  평탄화할 중첩 배열
     * @param  string  $prefix  상위 경로 접두사(최상위는 빈 문자열)
     * @return array 점 경로 키 목록
     */
    private function flattenKeys(array $block, string $prefix = ''): array
    {
        $out = [];
        foreach ($block as $k => $v) {
            if ($k === '@type' || $k === '@context') {
                continue;
            }
            $path = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            if (is_array($v) && ! array_is_list($v)) {
                $out = array_merge($out, $this->flattenKeys($v, $path));
            } else {
                $out[] = $path;
            }
        }

        return $out;
    }
}
