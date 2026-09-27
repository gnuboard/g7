<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\UpgradeContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_3_0;
use Psr\Log\LoggerInterface;

/**
 * 상품 코드 허용 문자 규칙과 기존 비정상 코드 처리 검증 (KVE-2026-2349 후속)
 *
 * 상품 코드는 이미지 저장 폴더(`images/products/{코드}`)에 그대로 쓰여, 경로 구분자·인코딩된 구분자·`.`/`..` 가
 * 섞이면 저장소 경로 검사에 걸려 이미지 업로드·복사·삭제가 500 으로 실패했다. 새로 넣거나 바꾸는 코드는 입력에서
 * 막고, 이미 저장된 코드의 상품은 코드를 그대로 두면 계속 수정·삭제되며, 업그레이드가 경로에 쓸 수 없는 코드의
 * 상품을 알린다(설계 결정 2026-09-26).
 */
class ProductCodePathSafetyTest extends ModuleTestCase
{
    /** 허용 문자(영문·숫자·-·_) 밖의 코드 — 경로 위험 코드와 단순 규칙 밖 코드(`A.B`·`A B`) 모두 */
    private const UNSAFE_CODES = ['ABC/', 'A/B', 'A\\B', '..', 'A.B', 'A%2eB', 'A B'];

    /** 규칙 이전에 저장될 수 있던, 경로에는 문제가 없는 코드 */
    private const LEGACY_PATH_SAFE_CODE = '티셔츠 001';

    private User $admin;

    /**
     * 관리자와 격리 디스크를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.products.update',
            'sirsoft-ecommerce.products.delete',
        ]);
    }

    /**
     * 등록·수정 모두 허용 문자 밖의 코드를 422 로 거부하고, 영문·숫자·-·_ 코드는 규칙을 통과한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unsafe_input
     *
     * @effects unsafe_product_code_rejected_on_store_and_update, safe_product_code_passes_rule
     */
    public function test_store_and_update_reject_codes_outside_the_allowed_set(): void
    {
        $product = Product::factory()->create(['product_code' => 'SAFE-CODE_01']);

        foreach (self::UNSAFE_CODES as $code) {
            $this->actingAs($this->admin)
                ->postJson('/api/modules/sirsoft-ecommerce/admin/products', ['product_code' => $code])
                ->assertStatus(422)
                ->assertJsonValidationErrors('product_code');

            $this->actingAs($this->admin)
                ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", ['product_code' => $code])
                ->assertStatus(422)
                ->assertJsonValidationErrors('product_code');
        }

        $response = $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', ['product_code' => 'Abc-09_z'])
            ->assertStatus(422);
        $this->assertArrayNotHasKey('product_code', $response->json('errors') ?? [], '허용 문자 코드가 거부됐다');
    }

    /**
     * 규칙 이전 코드의 상품은 코드를 그대로 보내면 수정이 통과하고, 규칙 밖 값으로 바꾸려 할 때만 거부된다.
     *
     * 수정 화면은 코드 입력칸이 비활성인 채 폼 전체(현재 코드 포함)를 보내므로, 현재 코드에 규칙을 걸면
     * 그 상품은 어떤 항목도 저장할 수 없게 된다. {product} 경로와 by-code 경로 모두 확인한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=legacy_update
     *
     * @effects legacy_code_kept_on_update_passes_rule, changing_to_code_outside_rule_rejected
     */
    public function test_update_keeps_legacy_code_and_rejects_only_changes_outside_the_rule(): void
    {
        $product = Product::factory()->create(['product_code' => 'LEGACY-SEED']);
        DB::table('ecommerce_products')->where('id', $product->id)->update(['product_code' => self::LEGACY_PATH_SAFE_CODE]);
        // 응답 로케일은 요청자 설정을 따르므로 문자 규칙 문구를 로케일별로 모두 비교한다
        $regexMessages = array_map(
            fn (string $locale) => __('sirsoft-ecommerce::validation.product.product_code.regex', [], $locale),
            ['ko', 'en']
        );

        $this->actingAs($this->admin)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", ['product_code' => self::LEGACY_PATH_SAFE_CODE])
            ->assertJsonMissingValidationErrors('product_code');

        // by-code 경로는 {code} 인자가 현재 코드다 (이 경로의 중복 검사 제외 대상 부재는 이 테스트 범위 밖이라 문자 규칙 문구만 본다)
        $byCode = $this->actingAs($this->admin)
            ->putJson('/api/modules/sirsoft-ecommerce/admin/products/by-code/'.rawurlencode(self::LEGACY_PATH_SAFE_CODE), ['product_code' => self::LEGACY_PATH_SAFE_CODE]);
        $this->assertSame([], array_values(array_intersect($regexMessages, $byCode->json('errors.product_code') ?? [])), 'by-code 수정이 현재 코드를 문자 규칙으로 거부했다');

        $changed = $this->actingAs($this->admin)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", ['product_code' => '티셔츠 002'])
            ->assertStatus(422);
        $this->assertNotSame([], array_intersect($regexMessages, $changed->json('errors.product_code') ?? []), '규칙 밖 값으로 바꾸는 수정이 문자 규칙으로 거부되지 않았다');
    }

    /**
     * 규칙 이전에 저장된 비정상 코드의 상품도 삭제된다 — 이미지 폴더는 건너뛰고 경고를 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=legacy_delete
     *
     * @effects legacy_unsafe_code_product_still_deletable, skipped_image_folder_logged
     */
    public function test_legacy_unsafe_code_product_can_still_be_deleted(): void
    {
        $product = Product::factory()->create(['product_code' => 'LEGACY-OK']);
        DB::table('ecommerce_products')->where('id', $product->id)->update(['product_code' => 'A/../B']);
        ProductImage::create([
            'product_id' => $product->id,
            'hash' => 'legacyhash01',
            'original_filename' => 'a.png',
            'stored_filename' => 'a.png',
            'disk' => 'modules',
            'path' => 'products/A/../B/a.png',
            'mime_type' => 'image/png',
            'file_size' => 1,
            'is_thumbnail' => true,
            'sort_order' => 0,
        ]);
        Log::spy();

        $this->actingAs($this->admin)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}")
            ->assertSuccessful();

        $this->assertSoftDeletedOrMissing($product->id);
        Log::shouldHaveReceived('warning')->with(
            '상품 코드가 저장소 경로 규칙에 맞지 않아 이미지 폴더를 지우지 못했습니다 — 수동 정리가 필요합니다',
            Mockery::on(fn (array $context) => $context['product_id'] === $product->id)
        )->once();
    }

    /**
     * 1.3.0 업그레이드가 이미지 폴더로 쓸 수 없는 코드의 상품만 경고하고, 경로에 문제없는 규칙 밖 코드는
     * 건수로만 남기며, 코드 값은 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=legacy_upgrade_report
     *
     * @effects upgrade_reports_unsafe_codes_without_rewriting, path_safe_legacy_code_counted_not_warned
     */
    public function test_upgrade_reports_unsafe_codes_without_rewriting(): void
    {
        $unsafe = Product::factory()->create(['product_code' => 'REPORT-ME']);
        DB::table('ecommerce_products')->where('id', $unsafe->id)->update(['product_code' => 'A/B']);
        $legacy = Product::factory()->create(['product_code' => 'LEGACY-SEED']);
        DB::table('ecommerce_products')->where('id', $legacy->id)->update(['product_code' => self::LEGACY_PATH_SAFE_CODE]);
        $safe = Product::factory()->create(['product_code' => 'KEEP-QUIET']);
        $spy = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($spy);

        (new Upgrade_1_3_0)->run(new UpgradeContext('1.2.1', '1.3.0', '1.3.0', 'extension-upgrade'));

        $this->assertSame('A/B', DB::table('ecommerce_products')->where('id', $unsafe->id)->value('product_code'));
        $this->assertSame(self::LEGACY_PATH_SAFE_CODE, DB::table('ecommerce_products')->where('id', $legacy->id)->value('product_code'));
        $spy->shouldHaveReceived('warning')->with(Mockery::on(fn ($m) => str_contains($m, "상품 #{$unsafe->id} ")));
        $spy->shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_contains($m, "상품 #{$legacy->id} "))]);
        $spy->shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_contains($m, "상품 #{$safe->id} "))]);
        $spy->shouldHaveReceived('info')->with(Mockery::on(
            fn ($m) => str_contains($m, '이미지 폴더로 쓸 수 없는 코드 1건') && str_contains($m, '그대로 동작하는 코드 1건')
        ));
    }

    /**
     * 상품이 삭제(또는 soft delete)되었는지 확인합니다.
     *
     * @param  int  $id  상품 ID
     * @return void 반환값 없음
     */
    private function assertSoftDeletedOrMissing(int $id): void
    {
        $row = DB::table('ecommerce_products')->where('id', $id)->first();
        $this->assertTrue($row === null || ($row->deleted_at ?? null) !== null, '상품이 삭제되지 않았다');
    }
}
