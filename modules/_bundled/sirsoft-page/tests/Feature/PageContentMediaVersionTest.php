<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\HookManager;
use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Http\Resources\PageVersionResource;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 버전과 본문 미디어 — 버전이 참조하면 남기고, 복원 때 동기화한다 (#887 G5)
 *
 * 종전에는 본문에서 뺀 본문 미디어를 저장 즉시 행·파일째 지워, 그 미디어가 든 이전 버전으로 복원하면 이미지가 깨졌다. 이제 어느 버전
 * 본문도 해시를 참조하지 않을 때만 지우고(참조하면 페이지에 붙인 채 둔다), 복원 본문도 저장처럼 정규화·동기화를 거친다 — 다른 페이지
 * 소유 미디어가 섞인 버전을 복원하면 볼 수 있을 때 이 페이지 소유로 복제한다. 페이지를 지우면 남겨 둔 것까지 모두 영구 삭제하고 행마다
 * 공용 훅 `core.content_media.before_delete` 를 발화한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-version.yaml
 */
class PageContentMediaVersionTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꾸고 작성자로 로그인한 뒤 모듈 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        $this->actingAs(User::factory()->create());
        $this->registerModuleRoutes();
    }

    /**
     * 본문에서 뺀 미디어는 이전 버전이 참조하므로 행·파일이 남고, 그 버전으로 복원하면 다시 이어져 서빙된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=dropped_then_restored
     *
     * @effects dropped_media_kept_while_version_references, restore_relinks_and_serves, restore_snapshot_after_sync
     */
    public function test_dropped_media_is_kept_and_served_after_restoring_previous_version(): void
    {
        $media = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $a = $media->upload(UploadedFile::fake()->image('a.png'), $tempKey);
        $b = $media->upload(UploadedFile::fake()->image('b.png'), $tempKey);
        $service = app(PageService::class);

        $page = $service->createPage([
            'slug' => 'cm-ver-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '버전 미디어'],
            'content' => ['ko' => $this->img($a->hash).$this->img($b->hash)],
            'content_mode' => 'html',
            'published' => true,
            'temp_key' => $tempKey,
        ]);
        $firstVersion = $page->versions()->where('version', 1)->firstOrFail();

        $page = $service->updatePage($page, ['content' => ['ko' => $this->img($a->hash)], 'content_mode' => 'html']);

        $kept = PageAttachment::query()->find($b->id);
        $this->assertNotNull($kept, '이전 버전이 참조하는 미디어는 본문에서 빠져도 남는다');
        $this->assertSame($page->id, (int) $kept->page_id, '페이지에 붙인 채 둔다');
        $this->assertTrue($this->disk()->exists('attachments', $b->path), '파일도 남는다');

        $restored = $service->restoreVersion($page->fresh(), $firstVersion->id);

        $this->assertStringContainsString('/content-media/'.$b->hash.'"', (string) $restored->content['ko']);
        $this->assertSame($restored->id, (int) PageAttachment::query()->findOrFail($b->id)->page_id);
        $this->get(PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$b->hash)->assertStatus(200);

        $latest = $restored->versions()->orderByDesc('version')->firstOrFail();
        $this->assertSame(3, (int) $latest->version, '복원도 새 버전이다');
        $this->assertStringContainsString($b->hash, (string) ($latest->content['ko'] ?? ''), '복원 스냅샷은 동기화 뒤 본문');
    }

    /**
     * 페이지를 지우면 버전이 참조해 남겨 둔 미디어까지 모두 영구 삭제하고, 행마다 `before_delete` 를 발화한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=page_deleted
     *
     * @effects page_delete_purges_all_media, before_delete_fired_per_row
     */
    public function test_deleting_page_purges_every_media_and_fires_before_delete(): void
    {
        $media = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $a = $media->upload(UploadedFile::fake()->image('a.png'), $tempKey);
        $b = $media->upload(UploadedFile::fake()->image('b.png'), $tempKey);
        $service = app(PageService::class);

        $page = $service->createPage([
            'slug' => 'cm-del-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '삭제'],
            'content' => ['ko' => $this->img($a->hash).$this->img($b->hash)],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);
        $page = $service->updatePage($page, ['content' => ['ko' => $this->img($a->hash)], 'content_mode' => 'html']);
        $this->assertNotNull(PageAttachment::query()->find($b->id), '전제: 버전이 참조해 남겨 둔 미디어');

        $deleted = [];
        HookManager::addAction(ContentMediaHooks::BEFORE_DELETE, function (Model $row, ContentMediaContext $context) use (&$deleted) {
            $deleted[] = [$row->getAttribute('hash'), $context->extension, $context->documentType, $context->documentId];
        });

        $service->deletePage($page);

        $this->assertDatabaseMissing('page_attachments', ['id' => $a->id]);
        $this->assertDatabaseMissing('page_attachments', ['id' => $b->id]);
        $this->assertFalse($this->disk()->exists('attachments', $a->path));
        $this->assertFalse($this->disk()->exists('attachments', $b->path));
        $this->assertSame(0, PageVersion::query()->where('page_id', $page->id)->count());

        $this->assertCount(2, $deleted, '영구 삭제 행마다 한 번');
        $this->assertEqualsCanonicalizing([$a->hash, $b->hash], array_column($deleted, 0));
        foreach ($deleted as [, $extension, $documentType, $documentId]) {
            $this->assertSame('sirsoft-page', $extension);
            $this->assertSame('page', $documentType);
            $this->assertSame($page->id, $documentId);
        }
    }

    /**
     * 다른 페이지 소유 미디어가 든 버전을 복원하면, 지금 원본을 볼 수 있을 때 이 페이지 소유로 복제하고 본문·스냅샷을 바꾼다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=foreign_media_restored
     *
     * @effects restore_clones_foreign_media
     */
    public function test_restoring_version_with_foreign_media_clones_it(): void
    {
        $media = app(PageContentMediaService::class);
        $service = app(PageService::class);
        $tempKey = (string) Str::uuid();
        $foreign = $media->upload(UploadedFile::fake()->image('src.png'), $tempKey);
        $source = $service->createPage([
            'slug' => 'cm-src-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '원본'],
            'content' => ['ko' => $this->img($foreign->hash)],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);

        // 원본이 미발행이고 열람권이 없어 복제되지 않은 채 버전 1 에 남는다
        $page = $service->createPage([
            'slug' => 'cm-dst-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '대상'],
            'content' => ['ko' => $this->img($foreign->hash)],
            'content_mode' => 'html',
            'published' => false,
        ]);
        $this->assertSame(0, $this->mediaOf($page)->count(), '전제: 볼 수 없어 복제하지 않았다');
        $firstVersion = $page->versions()->where('version', 1)->firstOrFail();
        $page = $service->updatePage($page, ['content' => ['ko' => '<p>미디어 없음</p>'], 'content_mode' => 'html']);

        $source->update(['published' => true]);
        $restored = $service->restoreVersion($page->fresh(), $firstVersion->id);

        $copies = $this->mediaOf($restored);
        $this->assertCount(1, $copies, '복원 때 원본을 볼 수 있으면 복제한다');
        $copy = $copies->first();
        $this->assertNotSame($foreign->hash, $copy->hash);
        $this->assertStringContainsString('/content-media/'.$copy->hash.'"', (string) Page::query()->findOrFail($restored->id)->content['ko']);
        $latest = $restored->versions()->orderByDesc('version')->firstOrFail();
        $this->assertStringContainsString($copy->hash, (string) ($latest->content['ko'] ?? ''), '복원 스냅샷도 바뀐 본문');
        $this->assertStringNotContainsString($foreign->hash, (string) ($latest->content['ko'] ?? ''));
        $this->assertSame($source->id, (int) PageAttachment::query()->findOrFail($foreign->id)->page_id, '원본은 그대로');
    }

    /**
     * 미발행 페이지의 버전 응답(버전 이력 미리보기)은 그 페이지가 소유한 미디어를 서명 주소로 내보내고, 발행 페이지 버전은 정식 주소로 둔다.
     *
     * 관리자 화면의 `<img>`·`<video>` 요청에는 인증 헤더가 없어, 서명 없는 미발행 페이지 미디어는 미리보기에서 403 으로 깨졌다
     * (#883 부터 — 페이지 응답만 서명했다). 서명은 페이지 응답과 같은 규칙으로 이 페이지 소유 해시만 한다 (#887 G5·G8).
     *
     * @return void 반환값 없음
     *
     * @scenario case=version_preview_signed
     *
     * @effects unpublished_version_preview_signs_owned_media, published_version_preview_unsigned
     */
    public function test_version_response_signs_owned_media_of_unpublished_page(): void
    {
        $media = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $a = $media->upload(UploadedFile::fake()->image('a.png'), $tempKey);
        $b = $media->upload(UploadedFile::fake()->image('b.png'), $tempKey);
        $foreign = $this->foreignUnpublishedHash();
        $service = app(PageService::class);

        $page = $service->createPage([
            'slug' => 'cm-prev-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '버전 미리보기'],
            'content' => ['ko' => $this->img($a->hash).$this->img($b->hash)],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);
        $page = $service->updatePage($page, ['content' => ['ko' => $this->img($a->hash)], 'content_mode' => 'html']);
        $firstVersion = $page->versions()->where('version', 1)->firstOrFail();
        // 다른 미발행 페이지 해시가 든 버전 본문(직접 넣은 경우) — 소유 해시만 서명하는지 함께 본다
        $firstVersion->update(['content' => ['ko' => $firstVersion->content['ko'].$this->img($foreign)]]);

        $content = (string) (new PageVersionResource($firstVersion->fresh()))->toArray(request())['content']['ko'];

        $this->assertMatchesRegularExpression('#/content-media/'.$a->hash.'\?[^"]*signature=#', $content, '미발행 페이지 버전의 소유 미디어는 서명');
        $this->assertMatchesRegularExpression('#/content-media/'.$b->hash.'\?[^"]*signature=#', $content, '본문에서 빠졌지만 버전이 참조해 남은 미디어도 서명');
        $this->assertStringContainsString('/content-media/'.$foreign.'"', $content, '다른 페이지 해시는 정식 주소 그대로');

        $service->updatePage($page->fresh(), ['published' => true]);
        $published = (string) (new PageVersionResource($firstVersion->fresh()))->toArray(request())['content']['ko'];

        $this->assertStringContainsString('/content-media/'.$a->hash.'"', $published, '발행 페이지 버전은 정식 주소');
        $this->assertDoesNotMatchRegularExpression('#signature=#', $published);
    }

    /**
     * 다른 미발행 페이지가 소유한 본문 미디어 해시를 만듭니다.
     *
     * @return string 해시
     */
    private function foreignUnpublishedHash(): string
    {
        $tempKey = (string) Str::uuid();
        $media = app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('foreign.png'), $tempKey);

        app(PageService::class)->createPage([
            'slug' => 'cm-foreign-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '남의 페이지'],
            'content' => ['ko' => $this->img($media->hash)],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);

        return $media->hash;
    }

    /**
     * 페이지에 이어진 본문 미디어.
     *
     * @param  Page  $page  페이지
     * @return Collection<int, PageAttachment> 본문 미디어
     */
    private function mediaOf(Page $page): Collection
    {
        return PageAttachment::query()
            ->where('page_id', $page->id)
            ->where('collection', PageAttachment::COLLECTION_CONTENT_MEDIA)
            ->get();
    }

    /**
     * 본문 미디어 정식 주소 이미지 태그.
     *
     * @param  string  $hash  본문 미디어 해시 (12자)
     * @return string 이미지 태그를 담은 문단 마크업
     */
    private function img(string $hash): string
    {
        return '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$hash.'"></p>';
    }

    /**
     * 페이지 모듈 저장소를 돌려줍니다.
     *
     * @return ModuleStorageDriver 저장소
     */
    private function disk(): ModuleStorageDriver
    {
        return new ModuleStorageDriver('sirsoft-page', 'modules');
    }
}
