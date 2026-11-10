<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 다른 페이지의 본문 미디어를 쓴 페이지 저장 — 새 페이지 소유로 복제 (#887)
 *
 * 종전에는 페이지 A 의 본문 미디어 주소를 페이지 B 가 쓰면 B 에 행이 없어, A 가 미디어를 빼면(행·파일 삭제) B 가 깨졌다.
 * 이제 저장하는 사람이 원본을 볼 수 있으면(발행 또는 열람권) 파일을 복사해 B 소유의 새 해시로 바꾼다. 버전 스냅샷도 바뀐 본문이다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-clone.yaml
 */
class PageContentMediaCloneTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꾸고 열람권 없는 작성자로 로그인합니다.
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
     * 발행된 페이지의 미디어를 두 로케일에서 쓴 페이지는 사본 하나를 갖고, 원본 페이지가 지워져(원본 행·파일 삭제) 사본은 남는다.
     *
     * 원본 제거는 페이지 삭제로 일으킨다 — 본문에서 빼기만 하면 원본 행은 버전 참조로 남는다(#887 G5, 아래 별도 시험).
     *
     * @return void 반환값 없음
     *
     * @scenario case=readable_source
     *
     * @effects copied_to_new_owner, content_and_version_rewritten, survives_source_removal
     */
    public function test_published_source_is_copied_and_survives_removal(): void
    {
        [$source, $hash] = $this->pageWithMedia(published: true);
        $ref = '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$hash.'"></p>';

        $page = app(PageService::class)->createPage([
            'slug' => 'clone-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '복제', 'en' => 'Clone'],
            'content' => ['ko' => $ref, 'en' => $ref],
            'content_mode' => 'html',
            'published' => false,
        ]);

        $copies = PageAttachment::query()->where('page_id', $page->id)->where('collection', PageAttachment::COLLECTION_CONTENT_MEDIA)->get();
        $this->assertCount(1, $copies, '두 로케일에서 써도 사본은 하나');
        $copy = $copies->first();
        $this->assertNotSame($hash, $copy->hash);

        $stored = Page::query()->findOrFail($page->id)->content;
        foreach (['ko', 'en'] as $locale) {
            $this->assertStringContainsString('/content-media/'.$copy->hash.'"', (string) $stored[$locale]);
            $this->assertStringNotContainsString($hash, (string) $stored[$locale]);
        }
        $version = $page->versions()->orderByDesc('version')->firstOrFail();
        $this->assertStringContainsString($copy->hash, (string) ($version->content['ko'] ?? ''), '버전 스냅샷도 바뀐 본문');
        $media = app(PageContentMediaService::class);
        $this->get($media->signedUrl($copy->hash))->assertStatus(200);

        // #887 G5: 본문에서 빼기만 하면 원본은 버전 참조로 남으므로, 원본 행이 실제로 사라지는 경로(페이지 삭제)로 확인한다
        app(PageService::class)->deletePage($source);
        $this->assertNull(PageAttachment::query()->where('hash', $hash)->first(), '원본은 지워진다');
        $this->assertNotNull(PageAttachment::query()->find($copy->id), '사본은 남는다');
        $this->get($media->signedUrl($copy->hash))->assertStatus(200);
    }

    /**
     * 원본 페이지가 본문에서 미디어를 빼면 원본 행은 버전 참조로 남고(G5), 복제된 사본도 그대로 남아 서빙된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=source_dropped_media
     *
     * @effects source_kept_by_version_and_copy_survives
     */
    public function test_source_dropping_media_keeps_source_row_and_copy(): void
    {
        [$source, $hash] = $this->pageWithMedia(published: true);
        $sourceRow = PageAttachment::query()->where('hash', $hash)->firstOrFail();

        $page = app(PageService::class)->createPage([
            'slug' => 'clone-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '복제'],
            'content' => ['ko' => '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$hash.'"></p>'],
            'content_mode' => 'html',
            'published' => false,
        ]);
        $copy = PageAttachment::query()->where('page_id', $page->id)->where('collection', PageAttachment::COLLECTION_CONTENT_MEDIA)->firstOrFail();

        app(PageService::class)->updatePage($source, ['content' => ['ko' => '<p>미디어 없음</p>'], 'content_mode' => 'html']);

        $this->assertSame($source->id, (int) PageAttachment::query()->findOrFail($sourceRow->id)->page_id, '원본 행은 이전 버전이 참조해 원본 페이지에 남는다');
        $this->assertSame($page->id, (int) PageAttachment::query()->findOrFail($copy->id)->page_id, '사본도 남는다');
        $this->get(PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$hash)->assertStatus(200);
        $this->get(app(PageContentMediaService::class)->signedUrl($copy->hash))->assertStatus(200);
    }

    /**
     * 열람권 없는 사람이 미발행 페이지의 미디어를 쓰면 복제하지 않고 주소를 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unreadable_source
     *
     * @effects unreadable_not_copied
     */
    public function test_unpublished_source_without_read_permission_is_not_copied(): void
    {
        [, $hash] = $this->pageWithMedia(published: false);
        $ref = '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$hash.'"></p>';

        $page = app(PageService::class)->createPage([
            'slug' => 'clone-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '복제 안 됨'],
            'content' => ['ko' => $ref],
            'content_mode' => 'html',
            'published' => false,
        ]);

        $this->assertSame(0, PageAttachment::query()->where('page_id', $page->id)->count());
        $this->assertStringContainsString('/content-media/'.$hash.'"', (string) Page::query()->findOrFail($page->id)->content['ko']);
    }

    /**
     * 미발행 페이지 응답은 그 페이지가 소유한 해시만 서명하고, 복제되지 않은 다른 페이지 해시는 정식 주소로 둔다 (#887 G8).
     *
     * 서명은 「이 페이지 본문을 받을 자격」의 위임이다. 남의 미발행 페이지 해시를 자기 페이지에 넣어 서명 주소를 받아 가지 못하게 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=foreign_unsigned
     *
     * @effects only_owned_hashes_signed
     */
    public function test_present_signs_only_hashes_owned_by_the_page(): void
    {
        [, $foreign] = $this->pageWithMedia(published: false);
        $tempKey = (string) Str::uuid();
        $own = app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('own.png'), $tempKey);
        $html = '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$own->hash.'"></p>'
            .'<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$foreign.'"></p>';

        $page = app(PageService::class)->createPage([
            'slug' => 'clone-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '서명 범위'],
            'content' => ['ko' => $html],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);
        $this->assertSame(1, PageAttachment::query()->where('page_id', $page->id)->count(), '전제: 볼 수 없는 원본은 복제하지 않았다');

        $presented = (string) app(PageContentMediaService::class)->presentContent($page->content, false, $page->id)['ko'];

        $this->assertMatchesRegularExpression('#/content-media/'.$own->hash.'\?[^"]*signature=#', $presented, '이 페이지 소유 해시는 서명');
        $this->assertStringContainsString('/content-media/'.$foreign.'"', $presented, '다른 페이지 해시는 정식 주소 그대로');
        $this->assertDoesNotMatchRegularExpression('#/content-media/'.$foreign.'\?#', $presented);
    }

    /**
     * 본문 미디어 하나를 올려 이은 페이지를 만듭니다.
     *
     * @param  bool  $published  발행 여부
     * @return array{0: Page, 1: string} [페이지, 해시]
     */
    private function pageWithMedia(bool $published): array
    {
        $tempKey = (string) Str::uuid();
        $media = app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('src.png'), $tempKey);

        $page = app(PageService::class)->createPage([
            'slug' => 'src-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '원본'],
            'content' => ['ko' => '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$media->hash.'"></p>'],
            'content_mode' => 'html',
            'published' => $published,
            'temp_key' => $tempKey,
        ]);
        $this->assertSame($page->id, (int) $media->fresh()->page_id);

        return [$page, $media->hash];
    }
}
