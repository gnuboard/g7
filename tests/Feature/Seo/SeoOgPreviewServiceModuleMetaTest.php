<?php

namespace Tests\Feature\Seo;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\AbstractModule;
use App\Seo\Editor\SeoOgPreviewService;
use App\Seo\SeoMetaResolver;
use Tests\TestCase;

/**
 * SeoOgPreviewService 모듈 자동값 → 연결 칩 메타 동반 테스트.
 *
 * 모듈 seoOgDefaults()/seoStructuredData() 는 resolve 된 평문을 반환하므로 데이터 경로가 소실된다.
 * 편집기가 자동값을 "상품 이름" 연결 칩으로 보여주려면 키별 데이터 경로(expr)+라벨이 필요하다.
 * 본 테스트는 미리보기 응답이:
 *  - 출처가 module 인 og 행에 seoOgDefaultMeta() 의 sourceExpr/label 을 (현재 로케일로 해석해) 동반하는지
 *  - structured autoMeta 가 점 경로 키별 expr/label 을 동반하는지
 *  - 메타 미선언(평문 폴백) 시 sourceExpr/label 미동반(하위호환)인지
 *  - 레이아웃이 직접 override 한 키엔 메타 미동반인지
 * 를 격리된 fake 모듈로 검증한다.
 */
class SeoOgPreviewServiceModuleMetaTest extends TestCase
{
    /** seoOgDefaults(평문) + seoOgDefaultMeta(expr/label) 를 동시에 제공하는 fake 모듈을 만든다. */
    private function fakeModule(): AbstractModule
    {
        return new class extends AbstractModule
        {
            public function seoOgDefaults(string $pageType, array $context, array $routeParams = []): array
            {
                return ['image' => 'https://cdn/p.jpg', 'image_alt' => '베이직 오버핏 티셔츠'];
            }

            public function seoOgDefaultMeta(string $pageType): array
            {
                return [
                    'image' => ['expr' => '{{product.data.thumbnail_url}}', 'label' => ['ko' => '상품 대표 이미지', 'en' => 'Product image']],
                    'image_alt' => ['expr' => '{{product.data.name}}', 'label' => ['ko' => '상품 이름', 'en' => 'Product name']],
                ];
            }

            public function seoTwitterDefaults(string $pageType, array $context, array $routeParams = []): array
            {
                return [];
            }

            public function seoTwitterDefaultMeta(string $pageType): array
            {
                return [];
            }

            public function seoStructuredData(string $pageType, array $context, array $routeParams = []): array
            {
                return ['@type' => 'Product', 'name' => '베이직 오버핏 티셔츠', 'offers' => ['price' => '23200']];
            }

            public function seoStructuredDataMeta(string $pageType): array
            {
                return [
                    'name' => ['expr' => '{{product.data.name}}', 'label' => ['ko' => '상품 이름', 'en' => 'Product name']],
                    'offers.price' => ['expr' => '{{product.data.selling_price}}', 'label' => ['ko' => '판매가', 'en' => 'Selling price']],
                ];
            }
        };
    }

    private function service(?AbstractModule $module): SeoOgPreviewService
    {
        $moduleManager = $this->createMock(ModuleManagerInterface::class);
        $moduleManager->method('getModule')->willReturn($module);
        $pluginManager = $this->createMock(PluginManagerInterface::class);
        $pluginManager->method('getPlugin')->willReturn(null);

        return new SeoOgPreviewService(
            app(SeoMetaResolver::class),
            $moduleManager,
            $pluginManager,
        );
    }

    /** og 배열에서 특정 key 행을 찾는다. */
    private function ogRow(array $preview, string $key): ?array
    {
        foreach ($preview['og'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /** 자동값 cascade 가 일어나도록 extensions ∧ page_type 충족 + 레이아웃 og 비움. */
    private function seoConfig(array $overrides = []): array
    {
        return array_merge([
            'page_type' => 'product',
            'extensions' => [['type' => 'module', 'id' => 'sirsoft-ecommerce']],
        ], $overrides);
    }

    public function test_모듈_출처_og_행에_sourceExpr_와_라벨이_동반된다(): void
    {
        app()->setLocale('ko');
        $preview = $this->service($this->fakeModule())->preview($this->seoConfig(), [], []);

        $imageRow = $this->ogRow($preview, 'image');
        $this->assertNotNull($imageRow);
        $this->assertStringStartsWith('module:', $imageRow['source']);
        $this->assertSame('{{product.data.thumbnail_url}}', $imageRow['sourceExpr']);
        $this->assertSame('상품 대표 이미지', $imageRow['label'], '라벨은 현재 로케일(ko)로 해석');

        $altRow = $this->ogRow($preview, 'image_alt');
        $this->assertSame('{{product.data.name}}', $altRow['sourceExpr']);
        $this->assertSame('상품 이름', $altRow['label']);
    }

    public function test_라벨은_요청_로케일로_해석된다(): void
    {
        app()->setLocale('en');
        $preview = $this->service($this->fakeModule())->preview($this->seoConfig(), [], [], null, 'en');

        $imageRow = $this->ogRow($preview, 'image');
        $this->assertSame('Product image', $imageRow['label']);
    }

    /** label 이 번역 키 문자열이면 __() 로 해석된다 — 언어팩 대응(추가 언어 자동). */
    private function translationKeyLabelModule(): AbstractModule
    {
        return new class extends AbstractModule
        {
            public function seoOgDefaults(string $pageType, array $context, array $routeParams = []): array
            {
                return ['image' => 'https://cdn/p.jpg'];
            }

            public function seoOgDefaultMeta(string $pageType): array
            {
                // 인라인 ko/en 맵이 아니라 **번역 키 문자열** — 언어팩(ja 등)이 그 키를 번역하면 자동 대응.
                return ['image' => ['expr' => '{{product.data.thumbnail_url}}', 'label' => 'demo-pkg::seo.product_image']];
            }
        };
    }

    public function test_label_번역키_문자열은_언어팩으로_해석된다(): void
    {
        // 언어팩이 키를 번역한 상태를 모사 — 번역기에 키 등록(ja 로케일).
        app('translator')->addLines(['seo.product_image' => '商品画像'], 'ja', 'demo-pkg');
        app()->setLocale('ja');

        $preview = $this->service($this->translationKeyLabelModule())->preview($this->seoConfig(), [], [], null, 'ja');

        $imageRow = $this->ogRow($preview, 'image');
        // 번역 키가 그대로 노출되면(=__() 미해석) 실패. 언어팩 번역값으로 해석돼야 통과.
        $this->assertSame('商品画像', $imageRow['label'], 'label 번역 키는 __() 로 해석돼 언어팩에 대응해야 한다');
    }

    public function test_label_번역키_미등록_로케일은_다른_언어로_폴백되지_원문키_노출_안함(): void
    {
        // ko/en 만 등록된 키를 미등록 로케일(ja)로 요청 — 번역기 폴백(기본 로케일)로 사람이 읽을
        // 문자열이 나오되, raw 키(demo-pkg::seo.x)가 그대로 노출되지는 않아야 한다.
        app('translator')->addLines(['seo.product_image' => '상품 이미지'], 'ko', 'demo-pkg');
        app('translator')->addLines(['seo.product_image' => 'Product image'], 'en', 'demo-pkg');

        $preview = $this->service($this->translationKeyLabelModule())->preview($this->seoConfig(), [], [], null, 'ja');

        $imageRow = $this->ogRow($preview, 'image');
        $this->assertStringNotContainsString('demo-pkg::', $imageRow['label'], 'raw 번역 키가 노출되면 안 된다');
    }

    public function test_레이아웃이_직접_채운_키엔_메타_미동반(): void
    {
        // 레이아웃이 og.image 를 직접 입력 → 출처 layout → 연결 칩 메타 미동반(자기 값이므로).
        $config = $this->seoConfig(['og' => ['image' => '{{custom.path}}']]);
        $preview = $this->service($this->fakeModule())->preview($config, [], []);

        $imageRow = $this->ogRow($preview, 'image');
        $this->assertSame('layout', $imageRow['source']);
        $this->assertArrayNotHasKey('sourceExpr', $imageRow);
        $this->assertArrayNotHasKey('label', $imageRow);
    }

    public function test_메타_미선언_모듈은_평문_폴백_메타_미동반(): void
    {
        // seoOgDefaultMeta 가 빈 배열(부모 기본)인 모듈 → effectiveValue(평문)만, 연결 칩 메타 없음(하위호환).
        $plain = new class extends AbstractModule
        {
            public function seoOgDefaults(string $pageType, array $context, array $routeParams = []): array
            {
                return ['image' => 'https://cdn/p.jpg'];
            }
        };
        $preview = $this->service($plain)->preview($this->seoConfig(), [], []);

        $imageRow = $this->ogRow($preview, 'image');
        $this->assertNotNull($imageRow);
        $this->assertStringStartsWith('module:', $imageRow['source']);
        $this->assertSame('https://cdn/p.jpg', $imageRow['effectiveValue'], '평문 폴백 유지');
        $this->assertArrayNotHasKey('sourceExpr', $imageRow);
    }

    public function test_structured_autoMeta_가_점경로_키별_데이터경로를_동반한다(): void
    {
        app()->setLocale('ko');
        $preview = $this->service($this->fakeModule())->preview($this->seoConfig(), [], []);

        $autoMeta = $preview['structured']['autoMeta'];
        $this->assertArrayHasKey('name', $autoMeta);
        $this->assertSame('{{product.data.name}}', $autoMeta['name']['expr']);
        $this->assertSame('상품 이름', $autoMeta['name']['label']);
        $this->assertArrayHasKey('offers.price', $autoMeta);
        $this->assertSame('{{product.data.selling_price}}', $autoMeta['offers.price']['expr']);
        // 자동 블록 자체는 종전대로 평문(하위호환).
        $this->assertSame('베이직 오버핏 티셔츠', $preview['structured']['autoBlock']['name']);
    }

    public function test_레이아웃_구조화_선언이_있으면_autoMeta_는_여전히_모듈_기준(): void
    {
        // 레이아웃이 structured_data 를 통 override 해도 autoBlock/autoMeta(모듈 자동)는 OFF 미리보기용으로 유지.
        $config = $this->seoConfig(['structured_data' => ['@type' => 'WebPage']]);
        $preview = $this->service($this->fakeModule())->preview($config, [], []);

        $this->assertTrue($preview['structured']['hasLayoutBlock']);
        $this->assertArrayHasKey('name', $preview['structured']['autoMeta']);
    }
}
