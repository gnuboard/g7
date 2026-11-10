<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Models\User;
use App\Rules\ContentMediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 본문 미디어 — 업로드·연결·서빙 게이트 (#883 4단계)
 *
 * 임시로 올린 본문 미디어는 페이지 저장 때 본문(로케일마다)이 참조한 것만 이어지고, 빠져도 이전 버전이 참조하면 남는다(#887). 첨부 연결(파일 이동)은
 * 같은 임시 키여도 본문 미디어를 집어 가지 않는다. 서빙은 「발행 또는 열람권 또는 서명」, 응답 본문은 미발행일 때만 서명한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media.yaml
 */
class PageContentMediaTest extends ModuleTestCase
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
        // 페이지 저장은 작성자(버전 스냅샷)가 필요하다 — 본문 미디어도 같은 사람이 올린 것만 잇는다
        $this->actingAs(User::factory()->create());
        // 서명 주소 생성·서빙 요청에 모듈 라우트가 필요하다
        $this->registerModuleRoutes();
    }

    /**
     * 저장 때 본문이 참조한 것만 잇고, 첨부 연결은 본문 미디어를 옮기지 않으며, 고쳐서 빼도 이전 버전이 참조하면 남긴다. 서명 쿼리는 떼어 저장한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync
     *
     * @effects referenced_linked, attachment_link_skips_content_media, removed_kept_while_version_references, signed_query_stripped
     */
    public function test_sync_links_referenced_and_removes_dropped(): void
    {
        $media = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $a = $media->upload(UploadedFile::fake()->image('a.png'), $tempKey);
        $b = $media->upload(UploadedFile::fake()->image('b.png'), $tempKey);
        $signedA = $media->signedUrl($a->hash);

        $page = app(PageService::class)->createPage([
            'slug' => 'cm-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '본문 미디어', 'en' => 'Content media'],
            'content' => ['ko' => '<p><img src="'.$signedA.'"></p>', 'en' => '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$b->hash.'"></p>'],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);

        $page->refresh();
        $this->assertStringNotContainsString('signature=', (string) $page->content['ko']);
        $this->assertSame($page->id, (int) $a->fresh()->page_id);
        $this->assertSame($page->id, (int) $b->fresh()->page_id);
        $this->assertCount(0, $page->attachments, '본문 미디어는 첨부 목록에 들지 않는다');
        $this->assertCount(2, $page->contentMedia);
        $this->assertStringStartsWith('content-media/', $a->fresh()->path, '첨부 연결이 파일을 옮기지 않았다');

        app(PageService::class)->updatePage($page, [
            'content' => ['ko' => '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$a->hash.'"></p>', 'en' => '<p>no media</p>'],
            'content_mode' => 'html',
        ]);

        $this->assertNotNull(PageAttachment::query()->find($a->id));
        // #887 G5: 본문에서 빠져도 이전 버전 본문이 참조하면 지우지 않고 페이지에 붙인 채 둔다(그 버전으로 복원할 수 있게)
        $this->assertSame($page->id, (int) PageAttachment::query()->findOrFail($b->id)->page_id, '본문에서 빠져도 이전 버전이 참조하면 남긴다');
    }

    /**
     * 서빙 게이트 — 미발행은 서명이 있어야, 발행은 서명 없이. 관리자 응답 본문은 미발행일 때만 서명한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve_gate
     *
     * @effects unpublished_requires_signature, published_public, present_signs_only_unpublished
     */
    public function test_serve_gate_and_present(): void
    {
        $media = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $a = $media->upload(UploadedFile::fake()->image('a.png'), $tempKey);
        $html = '<p><img src="'.PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$a->hash.'"></p>';

        $page = app(PageService::class)->createPage([
            'slug' => 'cm-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '게이트'],
            'content' => ['ko' => $html],
            'content_mode' => 'html',
            'published' => false,
            'temp_key' => $tempKey,
        ]);

        $url = PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$a->hash;
        $this->get($url)->assertStatus(403);
        $this->get($media->signedUrl($a->hash))->assertStatus(200);
        $this->assertStringContainsString('signature=', (string) $media->presentContent(['ko' => $html], false)['ko']);

        $page->update(['published' => true]);
        $this->get($url)->assertStatus(200);
        $this->assertSame(['ko' => $html], $media->presentContent(['ko' => $html], true));

        $this->get(PageContentMediaService::SERVE_PATTERN_URL_PREFIX.'ZZZZZZZZZZZZ')->assertStatus(404);
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
        $media = app(PageContentMediaService::class);
        $tempKey = (string) Str::uuid();
        $a = $media->upload(UploadedFile::fake()->image('r.png'), $tempKey);
        $url = PageContentMediaService::SERVE_PATTERN_URL_PREFIX.$a->hash;

        app(PageService::class)->createPage([
            'slug' => 'cm-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '구간'],
            'content' => ['ko' => '<p><img src="'.$url.'"></p>'],
            'content_mode' => 'html',
            'published' => true,
            'temp_key' => $tempKey,
        ]);

        $first = $this->get($url, ['Range' => 'bytes=0-0']);
        $first->assertStatus(206);
        $this->assertMatchesRegularExpression('#^bytes 0-0/\d+$#', (string) $first->headers->get('Content-Range'));
        $this->assertSame('bytes', $first->headers->get('Accept-Ranges'));

        $this->get($url, ['Range' => 'bytes=-4'])->assertStatus(206);
        $this->get($url, ['Range' => 'bytes=99999999-'])->assertStatus(416);
    }

    /**
     * 일반 첨부 업로드는 예약 컬렉션 이름을 쓸 수 없고, 첨부 개수에 본문 미디어를 세지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=reserved_collection
     *
     * @effects content_media_not_counted_as_attachment
     */
    public function test_content_media_not_counted_as_attachment(): void
    {
        $tempKey = (string) Str::uuid();
        app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('a.png'), $tempKey);

        $this->assertCount(0, app(PageAttachmentService::class)->getByPageId(0));
        $this->assertSame(0, app(PageAttachmentRepositoryInterface::class)->getByTempKey($tempKey)->count());
    }

    /**
     * 페이지 규칙이 비어 있으면(기본 `{}` — 페이지에는 설정 화면이 없다) 동영상·오디오는 코어 「본문 콘텐츠」 스위치를 따른다.
     *
     * 종전에는 대체 정책이 이미지만 켜서 코어가 업로드 동영상을 켜도 페이지 편집기에 동영상 단추가 없고 업로드도 거부됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=fallback_follows_core
     *
     * @effects video_follows_core_switch_without_page_rule
     */
    public function test_video_follows_core_switch_when_page_rule_is_empty(): void
    {
        $media = app(PageContentMediaService::class);
        $mp4 = UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64));
        $passes = fn () => Validator::make(['file' => $mp4], ['file' => [new ContentMediaFile($media->resolvePolicy())]])->passes();

        config(['g7_settings.core.content.allow_uploaded_video' => false]);
        $this->assertFalse($media->uploadTarget()['policy']['video']['enabled'], '코어가 끄면 동영상 단추가 없다');
        $this->assertFalse($passes(), '코어가 끄면 동영상 업로드를 거부한다');

        config(['g7_settings.core.content.allow_uploaded_video' => true]);
        $policy = $media->uploadTarget()['policy'];
        $this->assertTrue($policy['video']['enabled'], '규칙이 없어도 코어가 켜면 동영상 단추가 나온다');
        $this->assertContains('video/mp4', $policy['video']['mimeTypes']);
        $this->assertTrue($passes(), '규칙이 없어도 코어가 켜면 동영상을 받는다');
        $this->assertFalse($policy['audio']['enabled'], '오디오는 따로 켠다');
    }
}
