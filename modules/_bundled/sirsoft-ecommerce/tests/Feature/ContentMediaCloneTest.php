<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Services\ProductCommonInfoService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 다른 상품·공통 정보의 본문 미디어를 쓴 본문 저장 — 새 소유자로 복제 (#887)
 *
 * 종전에는 상품 A 설명의 본문 미디어 주소를 상품 B 설명(상품 복사·붙여넣기)이 쓰면 B 에 행이 없어, A 가 미디어를 빼면 B 가
 * 깨졌다. 이제 저장하는 사람이 원본을 볼 수 있으면(공통 정보는 공개, 상품은 전시 중이거나 상품 읽기 권한) 파일을 복사해 B 소유의
 * 새 해시로 바꿔 저장한다. 바뀐 본문을 다시 저장해도 한 저장으로 기록된다(after_* 1회·활동 로그 1건).
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media-clone.yaml
 */
class ContentMediaCloneTest extends ModuleTestCase
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
     * 전시 중인 상품 A 의 미디어를 쓴 상품 B 동기화는 사본을 만들고 본문을 새 해시로 바꾼다. A 가 미디어를 빼도 사본은 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=readable_source
     *
     * @effects copied_to_new_owner, content_rewritten, survives_source_removal
     */
    public function test_visible_product_media_is_copied_and_survives_removal(): void
    {
        $service = app(ContentMediaService::class);
        [$productA, $hash] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        $productB = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $ref = '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$hash.'"></p>';

        $result = $service->syncWithContent(ContentMediaOwnerType::Product, $productB->id, ['ko' => $ref.$ref, 'en' => $ref], true, null);

        $this->assertSame(1, $result['cloned'], '두 로케일·두 자리에서 써도 사본은 하나');
        $copy = ContentMedia::query()->where('attachmentable_type', ContentMediaOwnerType::Product->morphType())->where('attachmentable_id', $productB->id)->firstOrFail();
        $this->assertNotSame($hash, $copy->hash);
        $this->assertSame(2, substr_count($result['content']['ko'], '/content-media/'.$copy->hash.'"'));
        $this->assertStringNotContainsString($hash, $result['content']['en']);
        $this->assertSame($productA->id, ContentMedia::query()->where('hash', $hash)->firstOrFail()->owner_id, '원본 행은 그대로');

        $service->syncWithContent(ContentMediaOwnerType::Product, $productA->id, ['ko' => '<p>미디어 없음</p>'], true, null);
        $this->assertSoftDeleted('ecommerce_attachments', ['hash' => $hash]);
        $this->assertNotSoftDeleted('ecommerce_attachments', ['id' => $copy->id]);
        $this->get($service->signedUrl($copy->hash))->assertStatus(200);
    }

    /**
     * 상품 수정 저장은 숨긴 상품의 미디어라도 상품 읽기 권한이 있으면 복제하고, 바뀐 설명을 저장한다. 공통 정보 미디어도 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=readable_source
     *
     * @effects stored_description_rewritten
     */
    public function test_product_update_stores_rewritten_description(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.update', 'sirsoft-ecommerce.products.read']);
        $this->actingAs($admin, 'sanctum');

        [, $hiddenHash] = $this->productWithMedia(ProductDisplayStatus::HIDDEN);
        $service = app(ContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $common = $service->upload(ContentMediaOwnerType::CommonInfo, UploadedFile::fake()->image('c.png'), $tempKey);
        $service->syncWithContent(ContentMediaOwnerType::CommonInfo, 1, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$common->hash.'">'], true, $tempKey);

        $target = Product::factory()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        app(ProductService::class)->update($target, [
            'description_mode' => 'html',
            'description' => ['ko' => '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$hiddenHash.'"><img src="'.ContentMediaService::SERVE_URL_PREFIX.$common->hash.'"></p>'],
        ]);

        $copies = ContentMedia::query()->where('attachmentable_type', ContentMediaOwnerType::Product->morphType())->where('attachmentable_id', $target->id)->pluck('hash')->all();
        $this->assertCount(2, $copies);
        $stored = (string) ($target->fresh()->description['ko'] ?? '');
        foreach ($copies as $copyHash) {
            $this->assertStringContainsString('/content-media/'.$copyHash.'"', $stored);
        }
        $this->assertStringNotContainsString($hiddenHash, $stored);
        $this->assertStringNotContainsString($common->hash, $stored);
    }

    /**
     * 상품 읽기 권한 없이 숨긴 상품의 미디어를 쓰면 복제하지 않고 본문을 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unreadable_source
     *
     * @effects unreadable_not_copied
     */
    public function test_hidden_product_media_without_read_permission_is_not_copied(): void
    {
        [, $hash] = $this->productWithMedia(ProductDisplayStatus::HIDDEN);
        $productB = Product::factory()->create();
        $content = ['ko' => '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$hash.'"></p>'];

        $result = app(ContentMediaService::class)->syncWithContent(ContentMediaOwnerType::Product, $productB->id, $content, true, null);

        $this->assertSame(0, $result['cloned']);
        $this->assertSame($content, $result['content']);
        $this->assertSame(0, ContentMedia::query()->where('attachmentable_id', $productB->id)->where('attachmentable_type', ContentMediaOwnerType::Product->morphType())->count());
    }

    /**
     * 복제할 미디어도 문서당 최대 개수에 든다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=limit
     *
     * @effects clones_counted_in_limit
     */
    public function test_clones_count_toward_per_document_limit(): void
    {
        config(['g7_settings.modules.sirsoft-ecommerce.product_display.content_media' => ['max_per_document' => 1]]);
        $service = app(ContentMediaService::class);
        $this->assertSame(1, $service->resolvePolicy()->maxPerDocument(), '전제: 모듈 설정 config 미러로 문서당 최대 1');

        [, $hashA] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        [, $hashB] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        $productC = Product::factory()->create();

        $this->expectException(ContentMediaLimitExceededException::class);
        $service->syncWithContent(ContentMediaOwnerType::Product, $productC->id, [
            'ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$hashA.'"><img src="'.ContentMediaService::SERVE_URL_PREFIX.$hashB.'">',
        ], true, null);
    }

    /**
     * 공개되지 않은 상품의 응답 본문은 그 상품이 소유한 해시만 서명한다 — 다른 상품 해시는 정식 주소로 두어 원본 상품의 게이트(전시
     * 중·관리자·서명)를 따르게 한다. 남의 숨긴 상품 해시를 자기 설명에 넣어 서명 주소를 받아 가지 못한다 (#887 G8).
     *
     * @return void 반환값 없음
     *
     * @scenario case=sign_scope
     *
     * @effects only_owned_hashes_signed
     */
    public function test_response_signs_only_owned_hashes(): void
    {
        $service = app(ContentMediaService::class);
        [, $foreignHash] = $this->productWithMedia(ProductDisplayStatus::HIDDEN);
        [$product, $ownHash] = $this->productWithMedia(ProductDisplayStatus::HIDDEN);
        $own = ContentMediaService::SERVE_URL_PREFIX.$ownHash;
        $foreign = ContentMediaService::SERVE_URL_PREFIX.$foreignHash;
        $content = ['ko' => '<p><img src="'.$own.'"><img src="'.$foreign.'"></p>'];

        $presented = $service->presentContent($content, false, ContentMediaOwnerType::Product, $product->id);

        $this->assertMatchesRegularExpression('#'.preg_quote($own, '#').'\?expires=\d+&(amp;)?signature=#', $presented['ko'], '소유 해시는 서명');
        $this->assertStringContainsString('src="'.$foreign.'"', $presented['ko'], '다른 상품 해시는 정식 주소 그대로');
        $this->assertStringNotContainsString($foreignHash.'?expires=', $presented['ko']);
        $this->get($foreign)->assertStatus(403);

        $this->assertSame($content, $service->presentContent($content, true, ContentMediaOwnerType::Product, $product->id), '공개 상품은 서명하지 않는다');
        $this->assertSame($content, $service->presentContent($content, false, ContentMediaOwnerType::Product, null), '저장 전(소유자 없음)은 서명할 해시가 없다');
    }

    /**
     * 복제 재저장이 있어도 상품 생성·수정은 한 번으로 기록된다 — after_create·after_update 1회, 활동 로그 1건 (#887 G7).
     *
     * 복제하면 바뀐 설명을 같은 트랜잭션에서 리포지토리로 다시 저장한다. 그 재저장이 서비스 훅·활동 로그를 한 번 더 남기면 이력에
     * 같은 저장이 두 번 보인다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=record_once
     *
     * @effects product_clone_resave_recorded_once
     */
    public function test_product_clone_resave_is_recorded_as_a_single_save(): void
    {
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.products.update', 'sirsoft-ecommerce.products.read']), 'sanctum');
        [, $hashA] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        [, $hashB] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        $service = app(ProductService::class);

        $fired = $this->countHooks('sirsoft-ecommerce.product', function () use ($service, $hashA, $hashB) {
            $product = $service->create(Product::factory()->raw([
                'display_status' => ProductDisplayStatus::HIDDEN->value,
                'description_mode' => 'html',
                'description' => ['ko' => $this->img($hashA)],
            ]));
            $this->assertSame(1, $this->ownedMediaCount(ContentMediaOwnerType::Product, $product->id), '전제: 생성에서 복제했다');
            $this->assertSame(1, $this->activityLogCount('product.create', $product->id), '상품 생성 활동 로그는 한 건');

            // 생성 때 복제한 사본은 그대로 두고(빼면 본문에서 빠진 미디어로 소프트 삭제) 다른 상품 미디어를 하나 더한다
            $fresh = $product->fresh();
            $service->update($fresh, [
                'description_mode' => 'html',
                'description' => ['ko' => ($fresh->description['ko'] ?? '').$this->img($hashB)],
            ]);
            $this->assertSame(2, $this->ownedMediaCount(ContentMediaOwnerType::Product, $product->id), '전제: 수정에서 복제했다');
            $this->assertSame(1, $this->activityLogCount('product.update', $product->id), '상품 수정 활동 로그는 한 건');
        });

        $this->assertSame(['after_create' => 1, 'after_update' => 1], $fired, '상품 after_create·after_update 는 각각 한 번');
    }

    /**
     * 복제 재저장이 있어도 공통 정보 생성·수정은 한 번으로 기록된다 — after_create·after_update 1회, 활동 로그 1건 (#887 G7).
     *
     * @return void 반환값 없음
     *
     * @scenario case=record_once
     *
     * @effects common_info_clone_resave_recorded_once
     */
    public function test_common_info_clone_resave_is_recorded_as_a_single_save(): void
    {
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.products.read']), 'sanctum');
        [, $hashA] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        [, $hashB] = $this->productWithMedia(ProductDisplayStatus::VISIBLE);
        $service = app(ProductCommonInfoService::class);

        $fired = $this->countHooks('sirsoft-ecommerce.product-common-info', function () use ($service, $hashA, $hashB) {
            $commonInfo = $service->createCommonInfo([
                'name' => ['ko' => '안내', 'en' => 'Info'],
                'content' => ['ko' => $this->img($hashA)],
                'content_mode' => 'html',
                'is_default' => false,
                'is_active' => true,
            ]);
            $this->assertSame(1, $this->ownedMediaCount(ContentMediaOwnerType::CommonInfo, $commonInfo->id), '전제: 생성에서 복제했다');
            $this->assertSame(1, $this->activityLogCount('product_common_info.create', $commonInfo->id), '공통 정보 생성 활동 로그는 한 건');

            // 생성 때 복제한 사본은 그대로 두고(빼면 본문에서 빠진 미디어로 소프트 삭제) 다른 상품 미디어를 하나 더한다
            $kept = $commonInfo->fresh()->content['ko'] ?? '';
            $service->updateCommonInfo($commonInfo->id, ['content' => ['ko' => $kept.$this->img($hashB)]]);
            $this->assertSame(2, $this->ownedMediaCount(ContentMediaOwnerType::CommonInfo, $commonInfo->id), '전제: 수정에서 복제했다');
            $this->assertSame(1, $this->activityLogCount('product_common_info.update', $commonInfo->id), '공통 정보 수정 활동 로그는 한 건');
        });

        $this->assertSame(['after_create' => 1, 'after_update' => 1], $fired, '공통 정보 after_create·after_update 는 각각 한 번');
    }

    /**
     * 작업 동안 `{접두}.after_create`·`{접두}.after_update` 발화 횟수를 셉니다.
     *
     * @param  string  $prefix  훅 이름 접두 (예: `sirsoft-ecommerce.product`)
     * @param  callable  $work  셀 동안 실행할 작업
     * @return array{after_create: int, after_update: int} 훅별 발화 횟수
     */
    private function countHooks(string $prefix, callable $work): array
    {
        $fired = ['after_create' => 0, 'after_update' => 0];
        $counters = [];
        foreach (array_keys($fired) as $hook) {
            $counters[$hook] = function () use (&$fired, $hook) {
                $fired[$hook]++;
            };
            HookManager::addAction($prefix.'.'.$hook, $counters[$hook]);
        }

        try {
            $work();
        } finally {
            foreach ($counters as $hook => $counter) {
                HookManager::removeAction($prefix.'.'.$hook, $counter);
            }
        }

        return $fired;
    }

    /**
     * 이 대상의 활동 로그 건수를 셉니다.
     *
     * @param  string  $action  활동 로그 동작
     * @param  int  $loggableId  대상 ID
     * @return int 건수
     */
    private function activityLogCount(string $action, int $loggableId): int
    {
        return DB::table('activity_logs')
            ->where('action', $action)
            ->where('loggable_id', $loggableId)
            ->count();
    }

    /**
     * 소유자에 이어진 본문 미디어 수.
     *
     * @param  ContentMediaOwnerType  $type  소유 유형
     * @param  int  $ownerId  소유자 ID
     * @return int 개수
     */
    private function ownedMediaCount(ContentMediaOwnerType $type, int $ownerId): int
    {
        return ContentMedia::query()
            ->where('attachmentable_type', $type->morphType())
            ->where('attachmentable_id', $ownerId)
            ->count();
    }

    /**
     * 본문 미디어 정식 주소 이미지 태그.
     *
     * @param  string  $hash  본문 미디어 해시
     * @return string 이미지 태그를 담은 문단 마크업
     */
    private function img(string $hash): string
    {
        return '<p><img src="'.ContentMediaService::SERVE_URL_PREFIX.$hash.'"></p>';
    }

    /**
     * 본문 미디어 하나를 올려 이은 상품을 만듭니다.
     *
     * @param  ProductDisplayStatus  $status  전시 상태
     * @return array{0: Product, 1: string} [상품, 해시]
     */
    private function productWithMedia(ProductDisplayStatus $status): array
    {
        $service = app(ContentMediaService::class);
        $product = Product::factory()->create(['display_status' => $status->value]);
        $tempKey = (string) Str::uuid();
        $media = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('p-'.Str::random(4).'.png'), $tempKey);
        $service->syncWithContent(ContentMediaOwnerType::Product, $product->id, ['ko' => '<img src="'.ContentMediaService::SERVE_URL_PREFIX.$media->hash.'">'], true, $tempKey);
        $this->assertSame($product->id, $media->fresh()->owner_id);

        return [$product, $media->hash];
    }
}
