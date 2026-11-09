<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 본문 미디어 — 업로드·연결·서빙 게이트·정리 (#883 4단계)
 *
 * 관리자가 상품 설명·공통 정보 편집기로 올린 본문 미디어는 임시 행으로 남았다가 저장 때 본문이 참조한 것만 소유자에 이어진다.
 * 서빙은 상품이면 「전시 중 또는 관리자 또는 서명」, 공통 정보는 공개다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media.yaml
 */
class ContentMediaUploadAndSyncTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
    }

    /**
     * 상품 등록 권한이 있는 관리자는 상품 설명 본문 미디어를 올린다 — 201·서명 주소·임시 행. 공통 정보 권한만 있으면 상품용은 403.
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_upload
     *
     * @effects upload_creates_temp_row, owner_type_permission_enforced
     */
    public function test_admin_upload_and_owner_type_permission(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $tempKey = (string) Str::uuid();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product',
            'file' => UploadedFile::fake()->image('d.png'),
            'temp_key' => $tempKey,
        ]);
        $this->assertSame(201, $response->status(), (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('signature=', (string) $response->json('data.url'));
        $media = ContentMedia::query()->where('hash', $response->json('data.hash'))->firstOrFail();
        $this->assertNull($media->owner_id);
        $this->assertSame(ContentMediaOwnerType::Product, $media->owner_type);
        $this->assertSame('image', $media->kind);

        $target = $this->actingAs($admin, 'sanctum')->getJson('/api/modules/sirsoft-ecommerce/admin/content-media/target?owner_type=product')
            ->assertStatus(200)->json('data');
        $this->assertSame('/api/modules/sirsoft-ecommerce/admin/content-media', $target['endpoint']);
        $this->assertSame('product', $target['fields']['owner_type']);

        $commonOnly = $this->createAdminUser(['sirsoft-ecommerce.product-common-infos.create']);
        $this->actingAs($commonOnly, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product',
            'file' => UploadedFile::fake()->image('d.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(403);
    }

    /**
     * 본문이 참조한 것만 잇고, 빠지면 소프트 삭제한다. 서명 쿼리는 떼어 정규화한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync
     *
     * @effects referenced_linked, removed_soft_deleted, signed_query_stripped
     */
    public function test_sync_links_and_removes(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create();
        $tempKey = (string) Str::uuid();
        $a = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('a.png'), $tempKey);
        $b = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('b.png'), $tempKey);

        $normalized = $service->normalizeContent(['ko' => '<p><img src="'.$service->signedUrl($a->hash).'"></p>']);
        $this->assertSame('<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$a->hash.'"></p>', $normalized['ko']);

        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, [
            'ko' => $normalized['ko'],
            'en' => '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$b->hash.'"></p>',
        ], true, $tempKey);

        $this->assertSame($product->id, $a->fresh()->owner_id);
        $this->assertSame($product->id, $b->fresh()->owner_id);

        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => $normalized['ko']], true, null);
        $this->assertSoftDeleted('ecommerce_content_media', ['id' => $b->id]);
        $this->assertNotSoftDeleted('ecommerce_content_media', ['id' => $a->id]);
    }

    /**
     * 상품 「수정」 저장도 편집 폼이 받은 서명 주소를 정식 주소로 되돌려 저장한다 — 숨긴 상품의 편집 폼은 서명 주소를 받는다.
     * 정리는 정화 단계(`sanitizeDescription`)에 있어 생성·수정이 같은 길을 탄다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync
     *
     * @effects signed_query_stripped_on_update
     */
    public function test_update_strips_signed_query_before_store(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.update']);
        $this->actingAs($admin, 'sanctum');

        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('a.png'), (string) Str::uuid());

        app(\Modules\Sirsoft\Ecommerce\Services\ProductService::class)->update($product, [
            'description_mode' => 'html',
            'description' => ['ko' => '<p><img src="'.$service->signedUrl($media->hash).'"></p>'],
        ]);

        $stored = (string) ($product->fresh()->description['ko'] ?? '');
        $this->assertStringContainsString('src="'.ContentMediaService::SERVE_URL_PREFIX.$media->hash.'"', $stored, '수정 저장은 정식 주소');
        $this->assertStringNotContainsString('signature=', $stored);
        $this->assertStringNotContainsString('expires=', $stored);
    }

    /**
     * 서빙 게이트 — 전시 중 상품은 서명 없이, 숨긴 상품은 서명이 있어야. 임시 행은 서명 필수, 공통 정보는 공개.
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve_gate
     *
     * @effects visible_product_public, hidden_product_requires_signature, temp_requires_signature, common_info_public
     */
    public function test_serve_gate(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('a.png'), $tempKey);
        $url = ContentMediaService::SERVE_URL_PREFIX.$media->hash;

        $this->get($url)->assertStatus(403);
        $this->get($service->signedUrl($media->hash))->assertStatus(200);

        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<img src="'.$url.'">'], true, $tempKey);
        $this->get($url)->assertStatus(403);

        $product->update(['display_status' => ProductDisplayStatus::VISIBLE->value]);
        $this->get($url)->assertStatus(200);

        $common = $service->upload(ContentMediaOwnerType::CommonInfo, UploadedFile::fake()->image('c.png'), $tempKey);
        $service->syncWithContent(ContentMediaOwnerType::CommonInfo, 1, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$common->hash.'">'], true, $tempKey);
        $this->get(ContentMediaService::SERVE_URL_PREFIX.$common->hash)->assertStatus(200);

        $this->get(ContentMediaService::SERVE_URL_PREFIX.'ZZZZZZZZZZZZ')->assertStatus(404);
    }

    /**
     * 서빙 라우트가 구간 요청을 받는다 — 1바이트 206 · 끝 기준 206 · 범위 밖 416 (#883 5단계 Responder 경유).
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve_gate
     *
     * @effects serve_honours_range
     */
    public function test_serve_route_honours_range_requests(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => ProductDisplayStatus::VISIBLE->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('r.png'), $tempKey);
        $url = ContentMediaService::SERVE_URL_PREFIX.$media->hash;
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<img src="'.$url.'">'], true, $tempKey);

        $first = $this->get($url, ['Range' => 'bytes=0-0']);
        $first->assertStatus(206);
        $this->assertMatchesRegularExpression('#^bytes 0-0/\d+$#', (string) $first->headers->get('Content-Range'));
        $this->assertSame('bytes', $first->headers->get('Accept-Ranges'));

        $this->get($url, ['Range' => 'bytes=-4'])->assertStatus(206);
        $this->get($url, ['Range' => 'bytes=99999999-'])->assertStatus(416);
    }

    /**
     * 끝내 잇지 않은 오래된 임시 행은 정리가 파일과 함께 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=prune
     *
     * @effects stale_temp_pruned
     */
    public function test_prune_removes_stale_temp(): void
    {
        $service = app(ContentMediaService::class);
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('old.png'), (string) Str::uuid());
        ContentMedia::query()->whereKey($media->id)->update(['created_at' => now()->subDays(10)]);

        $result = $service->pruneTempUploads(7, 100);

        $this->assertSame(1, $result['deleted']);
        $this->assertNull(ContentMedia::withTrashed()->find($media->id));
    }
}
