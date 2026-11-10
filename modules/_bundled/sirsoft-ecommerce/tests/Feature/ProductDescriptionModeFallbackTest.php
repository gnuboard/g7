<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 설명 저장 정화 — 모드를 보내지 않은 수정은 저장된 모드를 따른다 (#887)
 *
 * 종전 `sanitizeDescription` 은 요청의 `description_mode` 만 봐서, 모드를 빼고 설명만 보낸 수정(관리자 API 부분 수정)은
 * HTML 모드 상품의 설명을 저장 정화 없이 저장했다(스크립트가 남음). 이제 요청에 모드가 없으면 저장된 모드로 판정한다 — 공통 정보
 * `sanitizeContent` 와 같은 판정. 요청에 모드가 있으면 요청이 우선하고, 텍스트 모드 상품의 설명은 손대지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-description-mode-fallback.yaml
 */
class ProductDescriptionModeFallbackTest extends ModuleTestCase
{
    /** 정화 대상 설명 — 스크립트·이벤트 속성 포함 */
    private const DIRTY = '<p onclick="x()">고침</p><script>alert(1)</script>';

    private User $admin;

    /**
     * 저장소를 가짜로 바꾸고 상품 수정 권한 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.update',
        ]);
    }

    /**
     * HTML 모드 상품을 관리자 API 로 수정하며 `description_mode` 없이 설명만 보내면 저장된 html 모드로 정화해 저장한다.
     *
     * 수정 전 결함: 요청 검증값(`validated()`)에 `description_mode` 키가 없어 `sanitizeDescription` 이 text 로 보고 그대로 돌려줬다.
     *
     * @return void 반환값 없음
     *
     * @scenario stored_mode=html, request_mode=absent
     *
     * @effects stored_html_mode_purified_without_request_mode, script_removed_on_update
     */
    public function test_admin_update_without_mode_purifies_by_stored_html_mode(): void
    {
        $product = Product::factory()->create([
            'product_code' => 'MODE-FALLBACK-01',
            'description_mode' => 'html',
            'description' => ['ko' => '<p>처음</p>', 'en' => '<p>First</p>'],
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", [
                'product_code' => 'MODE-FALLBACK-01',
                'description' => ['ko' => self::DIRTY, 'en' => self::DIRTY],
            ]);
        $this->assertSame(200, $response->status(), (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE));

        $product->refresh();
        $this->assertSame('html', $product->description_mode, '모드는 그대로');
        foreach (['ko', 'en'] as $locale) {
            $stored = (string) ($product->description[$locale] ?? '');
            $this->assertStringNotContainsString('<script', $stored, "{$locale}: 모드를 보내지 않은 수정도 스크립트를 지운다");
            $this->assertStringNotContainsString('onclick', $stored, "{$locale}: 이벤트 속성을 지운다");
            $this->assertStringContainsString('<p>고침</p>', $stored, "{$locale}: 본문은 남긴다");
        }
    }

    /**
     * 텍스트 모드 상품은 모드 없이 설명을 보내도 손대지 않는다 — 판정 기준이 저장된 모드(text)라서.
     *
     * @return void 반환값 없음
     *
     * @scenario stored_mode=text, request_mode=absent
     *
     * @effects stored_text_mode_untouched
     */
    public function test_update_without_mode_leaves_stored_text_mode_untouched(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create(['description_mode' => 'text']);

        app(ProductService::class)->update($product, ['description' => ['ko' => self::DIRTY]]);

        $this->assertSame(self::DIRTY, (string) ($product->fresh()->description['ko'] ?? ''), '텍스트 모드는 정화하지 않는다');
    }

    /**
     * 요청에 모드가 있으면 요청이 우선한다 — 텍스트 상품을 html 로 바꾸며 보내면 정화한다.
     *
     * @return void 반환값 없음
     *
     * @scenario stored_mode=text, request_mode=html
     *
     * @effects request_mode_takes_precedence
     */
    public function test_request_html_mode_purifies_stored_text_product(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create(['description_mode' => 'text']);

        app(ProductService::class)->update($product, ['description_mode' => 'html', 'description' => ['ko' => self::DIRTY]]);

        $this->assertSame('<p>고침</p>', (string) ($product->fresh()->description['ko'] ?? ''), 'html 로 바꾸는 수정은 정화');
    }

    /**
     * 요청에 모드가 있으면 요청이 우선한다 — html 상품을 text 로 바꾸며 보내면 정화하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario stored_mode=html, request_mode=text
     *
     * @effects request_mode_takes_precedence
     */
    public function test_request_text_mode_leaves_stored_html_product_untouched(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create(['description_mode' => 'html']);

        app(ProductService::class)->update($product, ['description_mode' => 'text', 'description' => ['ko' => self::DIRTY]]);

        $this->assertSame(self::DIRTY, (string) ($product->fresh()->description['ko'] ?? ''), 'text 로 바꾸는 수정은 정화하지 않는다');
    }
}
