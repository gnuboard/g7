<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Enums\ContentMediaKind;
use App\Exceptions\ContentMediaLimitExceededException;
use App\Extension\HookManager;
use App\Extension\Storage\ModuleStorageDriver;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\EcommerceAttachment;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 본문 미디어 저장소 — 범용 첨부 표 `ecommerce_attachments` 의 보존 계약 (#887)
 *
 * 저장소를 개발 중 전용 표 `ecommerce_content_media` 에서 게시판·페이지와 같은 모양의 이 모듈 범용 첨부 표 `ecommerce_attachments`
 * (collection = content_media)로 옮겼고, 흐름은 코어 골격(`AbstractContentMediaService`)이 돌린다. 바깥 계약(업로드·업로드 계약·서빙
 * 주소와 응답, 정책 해석, 개수 상한)은 그대로이고, 코어 공용 첨부 표·코어 공개 다운로드와는 관계없다. 본문에서 빠진 미디어의 파일은
 * 빠진 지 보존기간이 지나면 임시 상품 이미지 정리 명령이 지운다. 미공개 상품 미디어 응답은 private 캐시다. 업로드·영구 삭제에서
 * 코어 공용 훅 `core.content_media.*` 가 발화한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media-storage.yaml
 */
class ContentMediaStorageTest extends ModuleTestCase
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
        Storage::fake('attachments');
    }

    /**
     * 시험이 건 훅 리스너를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([ContentMediaHooks::BEFORE_UPLOAD, ContentMediaHooks::AFTER_UPLOAD, ContentMediaHooks::BEFORE_DELETE] as $hook) {
            HookManager::clearAction($hook);
        }

        parent::tearDown();
    }

    /**
     * 업로드는 범용 첨부 표에 본문 미디어 행(collection content_media)을 만든다 — 응답 모양(id·hash·kind·서명 url)은 그대로, 파일은 모듈
     * 저장소 `content-media` 카테고리, 행 path 는 카테고리 기준 경로, 전용 표·코어 첨부 표에는 없다. 범위 모델은 같은 표의 다른
     * 컬렉션 행을 보지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=storage
     *
     * @effects stored_in_ecommerce_attachments, upload_response_shape_kept, scope_excludes_other_collections
     */
    public function test_upload_is_stored_in_ecommerce_attachments_with_same_contract(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $tempKey = (string) Str::uuid();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product',
            'file' => UploadedFile::fake()->image('d.png'),
            'temp_key' => $tempKey,
        ]);
        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame(['hash', 'id', 'kind', 'url'], collect($data)->keys()->sort()->values()->all());
        $this->assertSame('image', $data['kind']);
        $this->assertMatchesRegularExpression(
            '#^/api/modules/sirsoft-ecommerce/content-media/'.$data['hash'].'\?expires=\d+&signature=[0-9a-f]+$#',
            $data['url']
        );

        $this->assertFalse(Schema::hasTable('ecommerce_content_media'), '전용 표는 없다');
        $this->assertDatabaseHas('ecommerce_attachments', [
            'id' => $data['id'],
            'hash' => $data['hash'],
            'collection' => 'content_media',
            'attachmentable_type' => Product::class,
            'attachmentable_id' => null,
            'temp_key' => $tempKey,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseMissing('attachments', ['hash' => $data['hash']]);

        $media = ContentMedia::query()->findOrFail($data['id']);
        $this->assertMatchesRegularExpression('#^product/\d{4}/\d{2}/\d{2}/[0-9a-f-]{36}\.png$#', $media->path, '행 path 는 카테고리 기준 경로');
        $this->assertSame($media->path, $media->storagePath());
        $this->assertTrue($this->exists($media));
        $this->assertArrayNotHasKey('temp_key', $media->toArray(), '임시 키는 직렬화하지 않는다');

        $other = EcommerceAttachment::query()->create([
            'attachmentable_type' => Product::class, 'attachmentable_id' => 1, 'collection' => 'other',
            'original_filename' => 'o.png', 'stored_filename' => 'o.png', 'disk' => 'modules', 'path' => 'other/o.png',
            'mime_type' => 'image/png', 'size' => 1,
        ]);
        $this->assertSame(12, strlen($other->hash), '범용 모델도 해시를 붙인다');
        $this->assertNull(ContentMedia::query()->find($other->id), '범위 모델은 다른 컬렉션 행을 보지 않는다');
        $this->get(ContentMediaService::SERVE_URL_PREFIX.$other->hash)->assertStatus(404);
    }

    /**
     * 코어 공개 다운로드(`/api/attachment/{hash}`)는 본문 미디어를 모른다 — 본문 미디어가 코어 공용 첨부 표에 없으므로 404. 서빙은
     * 모듈 주소의 게이트로만 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=storage
     *
     * @effects core_download_does_not_serve_content_media
     */
    public function test_core_attachment_download_does_not_serve_content_media(): void
    {
        $service = app(ContentMediaService::class);
        $hidden = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $tempKey = (string) Str::uuid();
        $linked = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('h.png'), $tempKey);
        $temp = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('t.png'), (string) Str::uuid());
        $service->syncWithContent(ContentMediaOwnerType::Product, $hidden->id, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$linked->hash.'">'], true, $tempKey);

        $this->get('/api/attachment/'.$linked->hash)->assertStatus(404);
        $this->get('/api/attachment/'.$temp->hash)->assertStatus(404);
        $this->get(ContentMediaService::SERVE_URL_PREFIX.$linked->hash)->assertStatus(403);

        $hidden->update(['display_status' => ProductDisplayStatus::VISIBLE->value]);
        $this->get(ContentMediaService::SERVE_URL_PREFIX.$linked->hash)->assertStatus(200);
        $this->get('/api/attachment/'.$linked->hash)->assertStatus(404);
    }

    /**
     * 서명 주소는 만료되면 숨긴 상품의 미디어를 내보내지 않는다(403). 유효한 서명 주소의 이미지 응답은 인라인 + nosniff 다.
     *
     * 래스터 이미지의 인라인은 `Content-Disposition` 이 없거나 `inline` 인 것으로 본다 — 로컬 디스크 응답은 그 헤더를 싣지 않고
     * (`InlineMediaPolicy::harden` 은 이미지면 그대로 둠), 게시판·페이지 본문 미디어 서빙도 같은 코어 응답기라 같은 헤더다. 다운로드로
     * 바뀌면(`attachment`) 실패한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve
     *
     * @effects expired_signature_refused, image_served_inline
     */
    public function test_expired_signature_is_refused_and_image_is_inline(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('e.png'), $tempKey);
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$media->hash.'">'], true, $tempKey);

        $this->get($service->signedUrl($media->hash, now()->subMinute()))->assertStatus(403);

        $ok = $this->get($service->signedUrl($media->hash));
        $ok->assertStatus(200);
        $disposition = (string) $ok->headers->get('Content-Disposition');
        $this->assertTrue($disposition === '' || str_starts_with($disposition, 'inline'), '이미지는 인라인(헤더 없음 또는 inline): '.$disposition);
        $this->assertSame('image/png', $ok->headers->get('Content-Type'));
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
    }

    /**
     * 응답 본문은 공개되지 않은 상품일 때만 그 상품이 소유한 해시를 서명 주소로 바꾸고(공개면 정식 주소 그대로), 숨긴 상품의 미디어는
     * 서명이 없어도 상품 읽기 권한 관리자에게는 나간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve
     *
     * @effects response_signed_only_when_not_public, hidden_product_served_to_product_reader
     */
    public function test_response_signing_and_admin_read_gate(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('s.png'), $tempKey);
        $html = '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$media->hash.'"></p>';
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => $html], true, $tempKey);

        $signed = $service->presentContent(['ko' => $html], false, ContentMediaOwnerType::Product, $product->id);
        $this->assertStringContainsString('/content-media/'.$media->hash.'?expires=', $signed['ko']);
        $this->assertStringContainsString('signature=', $signed['ko']);
        $this->assertSame(['ko' => $html], $service->presentContent(['ko' => $html], true, ContentMediaOwnerType::Product, $product->id), '공개 소유자는 정식 주소');

        $url = ContentMediaService::SERVE_URL_PREFIX.$media->hash;
        $this->get($url)->assertStatus(403);
        $reader = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $this->actingAs($reader, 'sanctum')->get($url)->assertStatus(200);
    }

    /**
     * 응답 캐시는 서명 없이 아무나 볼 수 있을 때만 public 이다 — 미공개 상품 미디어는 서명 주소·관리자 열람 모두 private(종전에는
     * 이어진 행이면 public 이었다), 전시 중 상품·공통 정보 미디어는 public (#887 Q9).
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve
     *
     * @effects unpublished_product_media_cache_private, public_media_cache_public
     */
    public function test_unpublished_product_media_cache_is_private(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('c.png'), $tempKey);
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$media->hash.'">'], true, $tempKey);

        $signed = $this->get($service->signedUrl($media->hash));
        $signed->assertStatus(200);
        $this->assertStringContainsString('private', (string) $signed->headers->get('Cache-Control'), '미공개 상품(서명 주소)은 private');
        $this->assertStringNotContainsString('public', (string) $signed->headers->get('Cache-Control'));

        $reader = $this->createAdminUser(['sirsoft-ecommerce.products.read']);
        $admin = $this->actingAs($reader, 'sanctum')->get(ContentMediaService::SERVE_URL_PREFIX.$media->hash);
        $admin->assertStatus(200);
        $this->assertStringContainsString('private', (string) $admin->headers->get('Cache-Control'), '미공개 상품(관리자 열람)도 private');

        $product->update(['display_status' => ProductDisplayStatus::VISIBLE->value]);
        $public = $this->get(ContentMediaService::SERVE_URL_PREFIX.$media->hash);
        $public->assertStatus(200);
        $this->assertStringContainsString('public', (string) $public->headers->get('Cache-Control'), '전시 중 상품은 public');

        $commonKey = (string) Str::uuid();
        $common = $service->upload(ContentMediaOwnerType::CommonInfo, UploadedFile::fake()->image('ci.png'), $commonKey);
        $service->syncWithContent(ContentMediaOwnerType::CommonInfo, 1, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$common->hash.'">'], true, $commonKey);
        $commonResponse = $this->get(ContentMediaService::SERVE_URL_PREFIX.$common->hash);
        $commonResponse->assertStatus(200);
        $this->assertStringContainsString('public', (string) $commonResponse->headers->get('Cache-Control'), '공통 정보는 public');
    }

    /**
     * 업로드 1건은 코어 공용 훅 `before_upload`·`after_upload` 를 한 번씩 발화한다 — 문맥은 이 모듈(module·sirsoft-ecommerce)·소유 종류·
     * 요청자·종류이고 문서는 아직 없다. 본문에서 빠져 소프트 삭제될 때는 `before_delete` 가 발화하지 않고, 보존기간 뒤 정리(영구
     * 삭제)에서 한 번 발화한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=hooks
     *
     * @effects upload_fires_core_hooks_once, before_delete_only_on_permanent_delete
     */
    public function test_upload_fires_core_content_media_hooks_once(): void
    {
        $calls = [];
        foreach ([ContentMediaHooks::BEFORE_UPLOAD, ContentMediaHooks::AFTER_UPLOAD, ContentMediaHooks::BEFORE_DELETE] as $hook) {
            HookManager::addAction($hook, function ($subject, ContentMediaContext $context) use (&$calls, $hook): void {
                $calls[] = [$hook, $subject, $context];
            });
        }

        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $tempKey = (string) Str::uuid();
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product',
            'file' => UploadedFile::fake()->image('hook.png'),
            'temp_key' => $tempKey,
        ]);
        $response->assertStatus(201);

        $this->assertSame([ContentMediaHooks::BEFORE_UPLOAD, ContentMediaHooks::AFTER_UPLOAD], array_column($calls, 0), '업로드 1건 = before_upload 1 + after_upload 1');
        $this->assertInstanceOf(UploadedFile::class, $calls[0][1]);
        $this->assertInstanceOf(Model::class, $calls[1][1]);
        $this->assertSame($response->json('data.hash'), $calls[1][1]->getAttribute('hash'));
        foreach ($calls as [, , $context]) {
            $this->assertSame(ContentMediaContext::EXTENSION_MODULE, $context->extensionType);
            $this->assertSame('sirsoft-ecommerce', $context->extension);
            $this->assertSame('product', $context->documentType);
            $this->assertNull($context->documentId);
            $this->assertSame($admin->id, $context->actorId);
            $this->assertSame(ContentMediaKind::Image, $context->kind);
        }

        $calls = [];
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create();
        $hash = (string) $response->json('data.hash');
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$hash.'">'], true, $tempKey);
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<p>뺌</p>'], true, null);
        $this->assertSame([], $calls, '본문에서 빠진 소프트 삭제는 before_delete 를 발화하지 않는다');

        ContentMedia::withTrashed()->where('hash', $hash)->update(['deleted_at' => now()->subDays(3)]);
        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();
        $this->assertSame([ContentMediaHooks::BEFORE_DELETE], array_column($calls, 0), '영구 삭제 직전에 한 번');
        $this->assertSame('product', $calls[0][2]->documentType);
        $this->assertSame($product->id, $calls[0][2]->documentId);
    }

    /**
     * 표준 계약 엔드포인트 `GET admin/content-media/target` 은 코어 도우미 모양 그대로다 — 200 `{data: 계약}`, 소유 종류별 권한이 없으면
     * 403 `auth.scope_denied`, 받는 종류가 없으면 `data: null`.
     *
     * @return void 반환값 없음
     *
     * @scenario case=policy
     *
     * @effects target_endpoint_standard_shape
     */
    public function test_target_endpoint_uses_standard_shape(): void
    {
        $productAdmin = $this->createAdminUser(['sirsoft-ecommerce.products.update']);
        $target = $this->actingAs($productAdmin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/content-media/target?owner_type=product')
            ->assertStatus(200)->json('data');
        $this->assertSame('/api/modules/sirsoft-ecommerce/admin/content-media', $target['endpoint']);
        $this->assertSame(['owner_type' => 'product'], $target['fields']);
        $this->assertSame('/api/modules/sirsoft-ecommerce/content-media/(?<hash>[A-Za-z0-9]{12})', $target['mediaUrlPattern']);

        $this->actingAs($productAdmin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/content-media/target?owner_type=common_info')
            ->assertStatus(403);

        config(['g7_settings.modules.sirsoft-ecommerce.product_display.content_media' => [
            'image' => ['enabled' => false], 'video' => ['enabled' => false], 'audio' => ['enabled' => false],
        ]]);
        $this->actingAs($productAdmin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/content-media/target?owner_type=product')
            ->assertStatus(200)->assertJsonPath('data', null);
    }

    /**
     * 동영상은 코어 「본문 콘텐츠」의 업로드 동영상 스위치가 켜져야 받고, 받으면 종류 video·인라인 재생 헤더로 서빙한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=policy
     *
     * @effects video_needs_core_switch, video_served_playable_inline
     */
    public function test_video_upload_follows_core_switch_and_is_served_playable(): void
    {
        config(['g7_settings.modules.sirsoft-ecommerce.product_display.content_media' => [
            'video' => ['enabled' => true, 'max_size_mb' => 10, 'extensions' => ['mp4']],
        ]]);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $tempKey = (string) Str::uuid();

        config(['g7_settings.core.content.allow_uploaded_video' => false]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product', 'file' => $this->videoFile(), 'temp_key' => $tempKey,
        ])->assertStatus(422);

        config(['g7_settings.core.content.allow_uploaded_video' => true]);
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product', 'file' => $this->videoFile(), 'temp_key' => $tempKey,
        ]);
        $response->assertStatus(201);
        $this->assertSame('video', $response->json('data.kind'));
        $this->assertSame('video', ContentMedia::query()->findOrFail($response->json('data.id'))->kind);

        $served = $this->get((string) $response->json('data.url'));
        $served->assertStatus(200);
        $this->assertStringStartsWith('inline', (string) $served->headers->get('Content-Disposition'));
        $this->assertStringContainsString('media-src', (string) $served->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', (string) $served->headers->get('Cache-Control'), '임시 행은 private 캐시');
    }

    /**
     * 규칙이 비어 있으면 상품 이미지 규칙(이미지만, 상품 이미지 확장자)을 따르고 동영상·오디오는 받지 않는다. 정한 규칙의 문서당 최대
     * 개수를 쓰고, 받는 종류가 하나도 없으면 업로드 계약이 null 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=policy
     *
     * @effects policy_falls_back_to_product_image_rules, upload_target_null_when_nothing_accepted
     */
    public function test_policy_fallback_and_upload_target(): void
    {
        $service = app(ContentMediaService::class);
        config(['g7_settings.modules.sirsoft-ecommerce.product_display.content_media' => null]);

        $policy = $service->resolvePolicy();
        $this->assertTrue($policy->acceptsUpload(ContentMediaKind::Image));
        $this->assertSame([], array_diff($policy->extensions(ContentMediaKind::Image), ['jpeg', 'png', 'jpg', 'gif', 'webp']));
        config(['g7_settings.core.content.allow_uploaded_video' => false, 'g7_settings.core.content.allow_uploaded_audio' => false]);
        $this->assertFalse($service->resolvePolicy()->acceptsUpload(ContentMediaKind::Video));
        $this->assertFalse($service->resolvePolicy()->acceptsUpload(ContentMediaKind::Audio));

        $target = $service->uploadTarget(ContentMediaOwnerType::CommonInfo);
        $this->assertSame('/api/modules/sirsoft-ecommerce/admin/content-media', $target['endpoint']);
        $this->assertSame('common_info', ((array) $target['fields'])['owner_type'], '필드는 JSON 객체로 나가도록 객체다');

        config(['g7_settings.modules.sirsoft-ecommerce.product_display.content_media' => [
            'image' => ['enabled' => false], 'video' => ['enabled' => false], 'audio' => ['enabled' => false], 'max_per_document' => 7,
        ]]);
        $this->assertSame(7, $service->resolvePolicy()->maxPerDocument());
        $this->assertNull($service->uploadTarget(ContentMediaOwnerType::Product));
    }

    /**
     * 같은 임시 키 업로드는 문서당 최대의 2배까지(422 `content_media_limit_exceeded`), 저장은 문서당 최대를 넘으면 예외로 잇지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=limit
     *
     * @effects upload_headroom_enforced, sync_limit_enforced
     */
    public function test_upload_headroom_and_per_document_limit(): void
    {
        config(['g7_settings.modules.sirsoft-ecommerce.product_display.content_media' => ['max_per_document' => 1]]);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $tempKey = (string) Str::uuid();
        $upload = fn () => $this->actingAs($admin, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/content-media', [
            'owner_type' => 'product', 'file' => UploadedFile::fake()->image('l.png'), 'temp_key' => $tempKey,
        ]);

        $first = $upload()->assertStatus(201)->json('data.hash');
        $second = $upload()->assertStatus(201)->json('data.hash');
        $upload()->assertStatus(422)->assertJsonPath('errors.code', 'content_media_limit_exceeded');

        $product = Product::factory()->create();
        $html = '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$first.'"><img src="'.ContentMediaService::SERVE_URL_PREFIX.$second.'">';
        try {
            $this->actingAs($admin);
            app(ContentMediaService::class)->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => $html], true, $tempKey);
            $this->fail('문서당 최대를 넘으면 예외');
        } catch (ContentMediaLimitExceededException) {
            // 기대한 예외
        }
        $this->assertSame(0, ContentMedia::query()->where('attachmentable_id', $product->id)->count(), '넘으면 잇지 않는다');
    }

    /**
     * 본문에서 빠진 미디어는 소프트 삭제된 채 보존기간(기본 2일) 안에는 파일이 남고, 지나면 정리 명령이 행·파일을 지운다.
     * 본문에 남은 미디어와 다른 소유 종류(공통 정보)도 같은 규칙이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=removed
     *
     * @effects removed_media_file_pruned_after_retention, removed_media_kept_within_retention
     */
    public function test_removed_media_file_is_pruned_after_retention(): void
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create();
        $common = ProductCommonInfo::create([
            'name' => ['ko' => '안내', 'en' => 'Info'], 'content' => ['ko' => '<p>a</p>'], 'content_mode' => 'html',
            'is_default' => false, 'is_active' => true, 'sort_order' => 1,
        ]);
        $removed = [];
        $kept = [];
        foreach ([[ContentMediaOwnerType::Product, $product->id], [ContentMediaOwnerType::CommonInfo, $common->id]] as [$type, $id]) {
            $tempKey = (string) Str::uuid();
            $keep = $service->upload($type, UploadedFile::fake()->image('k.png'), $tempKey);
            $drop = $service->upload($type, UploadedFile::fake()->image('r.png'), $tempKey);
            $img = fn (ContentMedia $m) => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$m->hash.'">';
            $service->syncWithContent($type, $id, ['ko' => $img($keep).$img($drop)], true, $tempKey);
            $service->syncWithContent($type, $id, ['ko' => $img($keep)], true, null);
            $removed[] = ContentMedia::withTrashed()->findOrFail($drop->id);
            $kept[] = $keep->fresh();
        }

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();
        foreach ($removed as $media) {
            $this->assertNotNull(ContentMedia::withTrashed()->find($media->id), '보존기간 안에는 남긴다');
            $this->assertTrue($this->exists($media));
        }

        ContentMedia::withTrashed()->whereKey(collect($removed)->pluck('id'))->update(['deleted_at' => now()->subDays(3)]);
        $this->artisan('sirsoft-ecommerce:prune-temp-product-images --dry-run')->assertSuccessful();
        $this->assertTrue($this->exists($removed[0]), 'dry-run 은 지우지 않는다');

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();
        foreach ($removed as $media) {
            $this->assertNull(ContentMedia::withTrashed()->find($media->id), '빠진 지 보존기간이 지나면 행을 지운다');
            $this->assertFalse($this->exists($media), '파일도 지운다');
        }
        foreach ($kept as $media) {
            $this->assertNotNull(ContentMedia::query()->find($media->id), '본문에 남은 미디어는 그대로');
            $this->assertTrue($this->exists($media));
        }
    }

    /**
     * 종류는 저장된 MIME 으로 정한다(전용 표의 kind 열을 대신함).
     *
     * @return void 반환값 없음
     *
     * @scenario case=storage
     *
     * @effects kind_from_mime
     */
    public function test_kind_is_derived_from_mime(): void
    {
        $this->assertSame('image', (new ContentMedia(['mime_type' => 'image/webp']))->kind);
        $this->assertSame('video', (new ContentMedia(['mime_type' => 'video/mp4']))->kind);
        $this->assertSame('audio', (new ContentMedia(['mime_type' => 'audio/mpeg']))->kind);
    }

    /**
     * 범용 첨부 표 마이그레이션은 표가 이미 있으면 아무것도 하지 않는다(재실행 안전) — 있는 행이 그대로 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=storage
     *
     * @effects create_migration_rerun_safe
     */
    public function test_create_migration_is_rerun_safe(): void
    {
        $media = app(ContentMediaService::class)->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('m.png'), (string) Str::uuid());

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_10_000001_create_ecommerce_attachments_table.php';
        $migration->up();

        $this->assertTrue(Schema::hasTable('ecommerce_attachments'));
        $this->assertNotNull(ContentMedia::query()->find($media->id), '다시 돌려도 행이 남는다');
    }

    /**
     * 업로드용 동영상 파일 (1초 mp4 표본 복사본).
     *
     * @return UploadedFile 파일
     */
    private function videoFile(): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'ecm').'.mp4';
        copy(base_path('tests/Fixtures/media/sample-1s.mp4'), $copy);

        return new UploadedFile($copy, 'v.mp4', 'video/mp4', null, true);
    }

    /**
     * 본문 미디어 파일이 저장소에 있는지.
     *
     * @param  ContentMedia  $media  본문 미디어
     * @return bool 있으면 true
     */
    private function exists(ContentMedia $media): bool
    {
        return (new ModuleStorageDriver('sirsoft-ecommerce', $media->disk))->exists(ContentMediaService::CATEGORY, $media->storagePath());
    }
}
