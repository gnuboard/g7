<?php

namespace Tests\Unit\Support\ContentMedia;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Extension\HookManager;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use App\Http\Controllers\Api\Base\BaseApiController;
use App\Http\Controllers\Concerns\RespondsWithContentMediaTarget;
use App\Seo\SeoRenderRequest;
use App\Support\Content\MediaSourceRoutes;
use App\Support\ContentMedia\AbstractContentMediaService;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaHooks;
use App\Support\ContentMedia\ContentMediaRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Tests\Unit\Support\ContentMedia\Fixtures\FakeContentMediaStore;

/**
 * 본문 미디어 코어 골격 — 가짜 어댑터(모듈 1·플러그인 1)로 업로드·훅·동기화·복제·제거·서명·서빙·정리 흐름을 잠근다 (#887, G1·G2)
 *
 * 세 확장(게시판·페이지·쇼핑몰)의 본문 미디어 흐름을 골격 한 곳으로 모으면서 정한 공통 규칙을 확인한다.
 * - 업로드 훅 순서 before_upload → filter_upload_file → after_upload, 필터가 돌려준 파일은 판정을 다시 거친다.
 * - 저장 동기화 순서는 연결 → 복제 → 제거, 복제분도 문서당 개수에 든다.
 * - before_delete 는 영구 삭제 직전에만, 파일은 행 디스크에서 커밋 뒤 지운다.
 * - 응답 서명은 이 문서 소유 해시만(관리자 증거 열람만 signAll), 캐시는 아무나 볼 수 있을 때만 public.
 *
 * @scenario-manifest tests/scenarios/content-media-core-skeleton.yaml
 */
class AbstractContentMediaServiceTest extends TestCase
{
    /** 모듈 시험 서빙 주소 앞부분 */
    private const MODULE_PREFIX = '/api/modules/test-notes/content-media/';

    /** 플러그인 시험 서빙 주소 앞부분 (범위 lobby) */
    private const PLUGIN_PREFIX = '/api/plugins/test-notes-plugin/notes/lobby/content-media/';

    /** @var array<int, array{0: string, 1: mixed, 2: mixed}> 훅 발화 기록 */
    private array $fired = [];

    /**
     * 시험 라우트·저장소·훅 기록을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // 개발 PC 설정 파일이 부팅 때 실리므로 코어 「본문 콘텐츠」 업로드 스위치를 기본값으로 고정한다
        config(['g7_settings.core.content.allow_uploaded_video' => false, 'g7_settings.core.content.allow_uploaded_audio' => false]);
        Route::get('/api/modules/test-notes/content-media/{hash}', fn () => 'ok')->name('api.modules.test-notes.content-media.show');
        Route::get('/api/plugins/test-notes-plugin/notes/{scope}/content-media/{hash}', fn () => 'ok')
            ->name('api.plugins.test-notes-plugin.notes.content-media.show');
        app('router')->getRoutes()->refreshNameLookups();

        $this->fired = [];
        foreach ([ContentMediaHooks::BEFORE_UPLOAD => 'before_upload', ContentMediaHooks::AFTER_UPLOAD => 'after_upload', ContentMediaHooks::BEFORE_DELETE => 'before_delete'] as $hook => $label) {
            HookManager::addAction($hook, function ($subject, $context) use ($label): void {
                $this->fired[] = [$label, $subject, $context];
            });
        }
    }

    /**
     * 시험 훅을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([ContentMediaHooks::BEFORE_UPLOAD, ContentMediaHooks::AFTER_UPLOAD, ContentMediaHooks::BEFORE_DELETE] as $hook) {
            HookManager::clearAction($hook);
        }
        HookManager::clearFilter(ContentMediaHooks::FILTER_UPLOAD_FILE);

        parent::tearDown();
    }

    /**
     * 업로드는 before_upload → filter_upload_file → after_upload 순서로 발화하고, 문맥 객체 하나를 함께 넘긴다.
     * 파일은 어댑터가 정한 최종 경로에, 행은 임시(문서 없음 + 임시 키)로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upload, extension=module
     *
     * @effects hooks_fire_in_order, context_object_passed, temp_row_created, file_at_final_path, meta_shape_from_adapter
     */
    public function test_module_upload_fires_hooks_in_order_and_creates_temp_row(): void
    {
        [$service, $store, $storage] = $this->module();
        HookManager::addFilter(ContentMediaHooks::FILTER_UPLOAD_FILE, function (UploadedFile $file, ContentMediaContext $context) {
            $this->fired[] = ['filter_upload_file', $file, $context];

            return $file;
        });

        $row = $service->upload($store->context(null, 'tk-1'), UploadedFile::fake()->image('photo.png', 20, 10));

        $this->assertSame(['before_upload', 'filter_upload_file', 'after_upload'], array_column($this->fired, 0));
        foreach ($this->fired as [, , $context]) {
            $this->assertInstanceOf(ContentMediaContext::class, $context);
            $this->assertSame('test-notes', $context->extension);
            $this->assertSame('image', $context->kind?->value);
        }
        $this->assertSame($row, $this->fired[2][1], 'after_upload 은 만든 행을 받는다');

        $this->assertTrue($store->isTemp($row));
        $this->assertSame('tk-1', $row->getAttribute('temp_key'));
        $this->assertSame(7, $row->getAttribute('created_by'));
        $this->assertNull($row->getAttribute('guest_owner_hash'));
        $this->assertSame('image/png', $row->getAttribute('mime_type'));
        $this->assertSame(['kind' => 'image', 'width' => 20, 'height' => 10], $row->getAttribute('meta'));
        $this->assertMatchesRegularExpression('#^content-media/2026/11/09/[0-9a-f-]{36}\.png$#', $row->getAttribute('path'));
        $this->assertTrue($storage->exists('attachments', $row->getAttribute('path')));
    }

    /**
     * 플러그인 비회원 업로드는 소유 증명 해시를 행에 남기고, meta 모양은 어댑터가 정한다(이미지 크기만).
     *
     * @return void 반환값 없음
     *
     * @scenario case=upload, extension=plugin
     *
     * @effects guest_owner_hash_kept, meta_shape_from_adapter, file_at_final_path
     */
    public function test_plugin_guest_upload_keeps_owner_hash(): void
    {
        [$service, $store, $storage] = $this->plugin();

        $row = $service->upload($store->context(null, 'tk-g', null, 'guest-hash'), UploadedFile::fake()->image('g.png', 8, 6));

        $this->assertNull($row->getAttribute('created_by'));
        $this->assertSame('guest-hash', $row->getAttribute('guest_owner_hash'));
        $this->assertSame(['width' => 8, 'height' => 6], $row->getAttribute('meta'));
        $this->assertStringStartsWith('lobby/content-media/', $row->getAttribute('path'));
        $this->assertTrue($storage->exists('attachments', $row->getAttribute('path')));
        $this->assertSame('plugin', $this->fired[0][2]->extensionType);
    }

    /**
     * 임시 키가 없으면 거부하고, 같은 임시 키 업로드는 문서당 최대 × 2 까지만 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upload, extension=module
     *
     * @effects temp_key_required, upload_headroom_enforced
     */
    public function test_upload_requires_temp_key_and_enforces_headroom(): void
    {
        [$service, $store] = $this->module();
        $store->policyValue = ['image' => ['enabled' => true, 'extensions' => ['png']], 'max_per_document' => 1];

        try {
            $service->upload($store->context(null, null), UploadedFile::fake()->image('a.png'));
            $this->fail('임시 키 없이 올라갔다');
        } catch (InvalidArgumentException) {
        }

        $service->upload($store->context(null, 'tk-h'), UploadedFile::fake()->image('a.png'));
        $service->upload($store->context(null, 'tk-h'), UploadedFile::fake()->image('b.png'));

        $this->expectException(ContentMediaLimitExceededException::class);
        $service->upload($store->context(null, 'tk-h'), UploadedFile::fake()->image('c.png'));
    }

    /**
     * 필터가 돌려준 파일은 내용 판정을 다시 거친다 — 이름만 png 인 SVG 로 바꾸면 422, 저장·행·after_upload 없음.
     *
     * @return void 반환값 없음
     *
     * @scenario case=filter_rejudge, extension=module
     *
     * @effects filtered_file_rejudged, rejected_filter_stores_nothing
     */
    public function test_filtered_file_is_rejudged_and_rejected(): void
    {
        [$service, $store] = $this->module();
        $svg = $this->tempFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        HookManager::addFilter(ContentMediaHooks::FILTER_UPLOAD_FILE, fn () => new UploadedFile($svg, 'evil.png', 'image/png', null, true));

        try {
            $service->upload($store->context(null, 'tk-f'), UploadedFile::fake()->image('ok.png'));
            $this->fail('필터가 바꾼 SVG 가 저장됐다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }

        $this->assertSame([], $store->rows);
        $this->assertSame(['before_upload'], array_column($this->fired, 0));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * 필터가 돌려준 정상 파일은 그 파일 기준(MIME·크기·이름)으로 저장하고, 파일이 아닌 값을 돌려주면 원본을 저장한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=filter_rejudge, extension=plugin
     *
     * @effects filtered_file_stored, non_file_filter_result_ignored
     */
    public function test_filtered_replacement_is_stored_and_non_file_is_ignored(): void
    {
        [$service, $store] = $this->plugin();
        $replacement = UploadedFile::fake()->image('converted.png', 40, 30);
        HookManager::addFilter(ContentMediaHooks::FILTER_UPLOAD_FILE, fn () => $replacement);

        $row = $service->upload($store->context(null, 'tk-r'), UploadedFile::fake()->image('origin.png', 5, 5));
        $this->assertSame('converted.png', $row->getAttribute('original_filename'));
        $this->assertSame(['width' => 40, 'height' => 30], $row->getAttribute('meta'));
        $this->assertSame((int) filesize($replacement->getRealPath()), $row->getAttribute('size'));

        HookManager::clearFilter(ContentMediaHooks::FILTER_UPLOAD_FILE);
        HookManager::addFilter(ContentMediaHooks::FILTER_UPLOAD_FILE, fn () => 'not-a-file');
        $kept = $service->upload($store->context(null, 'tk-r'), UploadedFile::fake()->image('origin.png', 5, 5));
        $this->assertSame('origin.png', $kept->getAttribute('original_filename'));
    }

    /**
     * 저장 동기화는 연결 → 복제 → 제거 순서다. 같은 임시 키 본인 임시 행은 잇고, 볼 수 있는 다른 문서 미디어는 이 문서 소유로
     * 복제해 본문 주소를 바꾸고, 본문에서 빠진 이 문서 미디어는 어댑터 정책으로 처리한다(소프트 삭제 — 훅 없음).
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync, extension=module
     *
     * @effects sync_order_link_clone_remove, clone_replaces_hash, soft_removal_without_hook
     */
    public function test_sync_links_then_clones_then_removes(): void
    {
        [$service, $store, $storage] = $this->module();
        $owned = $store->seed(['hash' => 'OWNEDAAAAAAA', 'document_id' => 10]);
        $temp = $store->seed(['hash' => 'TEMPAAAAAAAA', 'temp_key' => 'tk-s', 'created_by' => 7]);
        $store->seed(['hash' => 'OTHERAAAAAAA', 'document_id' => 20, 'path' => 'content-media/src/other.png']);
        $storage->put('attachments', 'content-media/src/other.png', 'PNG');
        $store->viewableDocuments = [20];

        $content = '<p><img src="'.self::MODULE_PREFIX.'TEMPAAAAAAAA"><img src="'.self::MODULE_PREFIX.'OTHERAAAAAAA"></p>';
        $result = $service->syncWithContent($store->context(10, 'tk-s'), $content, true);

        $this->assertSame(['link:TEMPAAAAAAAA', 'create', 'soft:OWNEDAAAAAAA'], array_map(
            fn (string $call) => str_starts_with($call, 'create:') ? 'create' : $call,
            $store->calls
        ));
        $this->assertSame(['linked' => 1, 'removed' => 1, 'cloned' => 1], array_intersect_key($result, array_flip(['linked', 'removed', 'cloned'])));

        $clone = collect($store->rows)->first(fn ($r) => $r->getAttribute('document_id') === 10
            && ! in_array($r->getAttribute('hash'), ['TEMPAAAAAAAA', 'OWNEDAAAAAAA'], true));
        $this->assertNotNull($clone);
        $this->assertStringContainsString(self::MODULE_PREFIX.$clone->getAttribute('hash'), $result['content']);
        $this->assertStringNotContainsString('OTHERAAAAAAA', $result['content']);
        $this->assertSame('PNG', $storage->get('attachments', $clone->getAttribute('path')));
        $this->assertSame(10, $temp->getAttribute('document_id'));
        $this->assertTrue((bool) $owned->getAttribute('deleted'));
        $this->assertSame([], $this->fired, '소프트 삭제는 before_delete 를 발화하지 않는다');
    }

    /**
     * 볼 수 없는 다른 문서 미디어·남의 임시 행은 잇지도 복제하지도 않고 본문 주소를 그대로 둔다. 비회원은 소유 증명이 맞는
     * 자기 임시 행만 복제한다(다른 임시 키).
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync, extension=plugin
     *
     * @effects unviewable_not_cloned, foreign_temp_not_linked, guest_own_temp_cloned
     */
    public function test_sync_clones_only_viewable_or_own_rows(): void
    {
        [$service, $store, $storage] = $this->plugin();
        $store->seed(['hash' => 'SECRETAAAAAA', 'document_id' => 30, 'scope' => 'lobby']);
        $store->seed(['hash' => 'STRANGERAAAA', 'temp_key' => 'tk-x', 'created_by' => 99]);
        $store->seed(['hash' => 'GUESTOWNAAAA', 'temp_key' => 'tk-old', 'guest_owner_hash' => 'g-1', 'path' => 'lobby/content-media/g.png']);
        $storage->put('attachments', 'lobby/content-media/g.png', 'PNG');

        $content = ['ko' => '<img src="'.self::PLUGIN_PREFIX.'SECRETAAAAAA"><img src="'.self::PLUGIN_PREFIX.'STRANGERAAAA">',
            'en' => '<img src="'.self::PLUGIN_PREFIX.'GUESTOWNAAAA">'];
        $result = $service->syncWithContent($store->context(40, 'tk-x', null, 'g-1'), $content, true);

        $this->assertSame(0, $result['linked'], '남의 임시 행은 같은 임시 키여도 잇지 않는다');
        $this->assertSame(1, $result['cloned']);
        $this->assertStringContainsString('SECRETAAAAAA', $result['content']['ko']);
        $this->assertStringContainsString('STRANGERAAAA', $result['content']['ko']);
        $this->assertStringNotContainsString('GUESTOWNAAAA', $result['content']['en']);
    }

    /**
     * 복제할 것도 문서당 최대 개수에 들고, 넘으면 아무것도 바꾸기 전에 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync, extension=module
     *
     * @effects clones_count_toward_limit, nothing_changed_on_limit
     */
    public function test_sync_counts_clones_toward_limit_before_any_change(): void
    {
        [$service, $store] = $this->module();
        $store->policyValue = ['image' => ['enabled' => true, 'extensions' => ['png']], 'max_per_document' => 1];
        $store->seed(['hash' => 'TEMPBBBBBBBB', 'temp_key' => 'tk-l', 'created_by' => 7]);
        $store->seed(['hash' => 'OTHERBBBBBBB', 'document_id' => 20]);
        $store->viewableDocuments = [20];

        try {
            $service->syncWithContent(
                $store->context(10, 'tk-l'),
                '<img src="'.self::MODULE_PREFIX.'TEMPBBBBBBBB"><img src="'.self::MODULE_PREFIX.'OTHERBBBBBBB">',
                true
            );
            $this->fail('개수 초과가 통과했다');
        } catch (ContentMediaLimitExceededException) {
        }

        $this->assertSame([], $store->calls);
    }

    /**
     * 어댑터가 영구 삭제를 고르면 before_delete 를 발화하고 파일은 커밋 뒤에 지운다. 남기기를 고르면 제거로 세지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=removal, extension=module
     *
     * @effects before_delete_on_purge, file_deleted_after_commit, kept_row_not_counted
     */
    public function test_purge_removal_fires_before_delete_and_deletes_after_commit(): void
    {
        [$service, $store, $storage] = $this->module();
        $store->removalMode = 'purge';
        $row = $store->seed(['hash' => 'PURGEAAAAAAA', 'document_id' => 10, 'path' => 'content-media/p.png']);
        $storage->put('attachments', 'content-media/p.png', 'PNG');

        DB::beginTransaction();
        $result = $service->syncWithContent($store->context(10), '<p>no media</p>', true);
        $this->assertTrue($storage->exists('attachments', 'content-media/p.png'), '커밋 전에는 파일이 남는다');
        DB::commit();

        $this->assertSame(1, $result['removed']);
        $this->assertFalse($storage->exists('attachments', 'content-media/p.png'));
        $this->assertSame(['force:PURGEAAAAAAA'], $store->calls);
        $this->assertSame(['before_delete'], array_column($this->fired, 0));
        $this->assertSame($row, $this->fired[0][1]);
        $this->assertSame('image', $this->fired[0][2]->kind?->value);

        $store->removalMode = 'keep';
        $store->seed(['hash' => 'KEEPAAAAAAAA', 'document_id' => 11]);
        $this->assertSame(0, $service->syncWithContent($store->context(11), '', true)['removed']);
    }

    /**
     * 응답 서명은 이 문서 소유 해시만 한다. 관리자 증거 열람(signAll)은 모두, 공개 문서와 봇 렌더는 서명하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=present, extension=module
     *
     * @effects owned_only_signed, sign_all_for_evidence, public_document_canonical, normalize_strips_signature
     */
    public function test_present_content_signs_only_owned_hashes(): void
    {
        [$service, $store] = $this->module();
        $store->seed(['hash' => 'MINEAAAAAAAA', 'document_id' => 10]);
        $store->seed(['hash' => 'THEIRSAAAAAA', 'document_id' => 20]);
        $html = '<img src="'.self::MODULE_PREFIX.'MINEAAAAAAAA"><img src="'.self::MODULE_PREFIX.'THEIRSAAAAAA">';
        $context = $store->context(10);

        $owned = $service->presentContent($context, $html);
        $this->assertMatchesRegularExpression('#MINEAAAAAAAA\?expires=\d+&(amp;)?signature=#', $owned);
        $this->assertStringContainsString('"'.self::MODULE_PREFIX.'THEIRSAAAAAA"', $owned, '다른 문서 해시는 정식 주소');

        $all = $service->presentContent($context, ['ko' => $html], true);
        $this->assertSame(2, substr_count($all['ko'], 'signature='));

        $this->assertSame($html, $service->presentContent($context, $html, false, true), '공개 문서는 정식 주소');
        $store->publicDocuments = [10];
        $this->assertSame($html, $service->presentContent($context, $html));

        $this->assertSame($html, $service->normalizeContent($context, $all['ko']));
    }

    /**
     * 봇(SEO) 렌더 요청에는 소유 해시라도 서명하지 않는다 — 캐시된 HTML 의 서명이 만료되면 깨진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=present, extension=plugin
     *
     * @effects bot_render_canonical
     */
    public function test_bot_render_keeps_canonical_urls(): void
    {
        [$service, $store] = $this->plugin();
        $store->seed(['hash' => 'MINEBBBBBBBB', 'document_id' => 10, 'scope' => 'lobby']);
        $html = '<img src="'.self::PLUGIN_PREFIX.'MINEBBBBBBBB">';
        request()->headers->set(SeoRenderRequest::HEADER, SeoRenderRequest::signature());

        $this->assertSame($html, $service->presentContent($store->context(10), $html, true));
    }

    /**
     * 서빙 게이트: 서명이면 임시 행도 통과, 서명 없는 임시 행·볼 수 없는 행은 403, 없으면 404.
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve, extension=module
     *
     * @effects signed_passes_temp, unsigned_temp_denied, unviewable_denied, missing_not_found
     */
    public function test_find_servable_gates(): void
    {
        [$service, $store] = $this->module();
        $store->seed(['hash' => 'TEMPCCCCCCCC', 'temp_key' => 'tk']);
        $store->seed(['hash' => 'LOCKEDCCCCCC', 'document_id' => 50]);
        $store->seed(['hash' => 'OPENCCCCCCCC', 'document_id' => 51]);
        $store->viewableDocuments = [51];
        $context = $store->context();

        $this->assertSame('TEMPCCCCCCCC', $service->findServable($context, 'TEMPCCCCCCCC', true)->getAttribute('hash'));
        $this->assertSame('OPENCCCCCCCC', $service->findServable($context, 'OPENCCCCCCCC', false)->getAttribute('hash'));

        foreach (['TEMPCCCCCCCC', 'LOCKEDCCCCCC'] as $hash) {
            try {
                $service->findServable($context, $hash, false);
                $this->fail("{$hash} 가 서명 없이 통과했다");
            } catch (AccessDeniedHttpException) {
            }
        }

        $this->expectException(NotFoundHttpException::class);
        $service->findServable($context, 'NONEXISTENTX', true);
    }

    /**
     * 응답 캐시는 서명 없이 아무나 볼 수 있는 문서 미디어만 public 이고, 비공개 문서·임시 행은 private 다. 파일은 행 디스크에서 읽는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=serve, extension=plugin
     *
     * @effects cache_public_only_for_anyone, cache_private_otherwise, serve_reads_row_disk
     */
    public function test_respond_cache_header_and_row_disk(): void
    {
        [$service, $store, $storage] = $this->plugin();
        $this->fakeArchiveDisk();
        (new PluginStorageDriver('test-notes-plugin', 'archive'))->put('attachments', 'lobby/old.png', $this->pngBytes());
        $public = $store->seed(['hash' => 'PUBLICDDDDDD', 'document_id' => 60, 'disk' => 'archive', 'path' => 'lobby/old.png']);
        $private = $store->seed(['hash' => 'PRIVATEDDDDD', 'document_id' => 61, 'path' => 'lobby/new.png']);
        $temp = $store->seed(['hash' => 'TEMPDDDDDDDD', 'temp_key' => 'tk', 'path' => 'lobby/new.png']);
        $storage->put('attachments', 'lobby/new.png', $this->pngBytes());
        $store->publicDocuments = [60];
        $context = $store->context();

        $publicResponse = $service->respond($context, $public, Request::create('/x'));
        $this->assertNotNull($publicResponse, '행 디스크(archive)에서 읽는다');
        $this->assertStringContainsString('public', (string) $publicResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', (string) $publicResponse->headers->get('Cache-Control'));

        foreach ([$private, $temp] as $row) {
            $response = $service->respond($context, $row, Request::create('/x'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        }
    }

    /**
     * 복제 원본은 그 행이 저장된 디스크에서 읽고, 사본은 기본 디스크에 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync, extension=module
     *
     * @effects clone_reads_row_disk
     */
    public function test_clone_reads_source_from_row_disk(): void
    {
        [$service, $store, $storage] = $this->module();
        $this->fakeArchiveDisk();
        (new ModuleStorageDriver('test-notes', 'archive'))->put('attachments', 'content-media/old/src.png', 'OLD');
        $store->seed(['hash' => 'ARCHIVEEEEEE', 'document_id' => 20, 'disk' => 'archive', 'path' => 'content-media/old/src.png']);
        $store->viewableDocuments = [20];

        $result = $service->syncWithContent($store->context(10), '<img src="'.self::MODULE_PREFIX.'ARCHIVEEEEEE">', true);

        $this->assertSame(1, $result['cloned']);
        $clone = collect($store->rows)->first(fn ($r) => $r->getAttribute('document_id') === 10);
        $this->assertSame('local', $clone->getAttribute('disk'));
        $this->assertSame('OLD', $storage->get('attachments', $clone->getAttribute('path')));
    }

    /**
     * 정리는 어댑터가 고른 오래된 행마다 before_delete 를 발화하고 파일·행을 지운다. dry-run 은 세기만 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=prune, extension=module
     *
     * @effects prune_dry_run_counts_only, prune_fires_before_delete, prune_deletes_file_and_row
     */
    public function test_prune_stale_fires_before_delete_and_removes_files(): void
    {
        [$service, $store, $storage] = $this->module();
        $store->seed(['hash' => 'STALEAAAAAAA', 'temp_key' => 'old', 'stale' => true, 'path' => 'content-media/s1.png']);
        $store->seed(['hash' => 'STALEBBBBBBB', 'document_id' => 3, 'deleted' => true, 'stale' => true, 'path' => 'content-media/s2.png']);
        $store->seed(['hash' => 'FRESHAAAAAAA', 'temp_key' => 'new', 'path' => 'content-media/s3.png']);
        foreach (['s1', 's2', 's3'] as $name) {
            $storage->put('attachments', "content-media/{$name}.png", 'X');
        }

        $this->assertSame(['scanned' => 2, 'deleted' => 0, 'failed' => 0], $service->pruneStale(2, 100, true));
        $this->assertSame([], $this->fired);

        $this->assertSame(['scanned' => 2, 'deleted' => 2, 'failed' => 0], $service->pruneStale(2, 100));
        $this->assertSame(['before_delete', 'before_delete'], array_column($this->fired, 0));
        $this->assertFalse($storage->exists('attachments', 'content-media/s1.png'));
        $this->assertFalse($storage->exists('attachments', 'content-media/s2.png'));
        $this->assertTrue($storage->exists('attachments', 'content-media/s3.png'));
        $this->assertSame(['FRESHAAAAAAA'], collect($store->rows)->pluck('hash')->values()->all());
    }

    /**
     * 편집기 계약은 어댑터의 주소·필드·서빙 정규식으로 만들고, 받는 종류가 없으면 null 이다. 표준 엔드포인트 도우미는
     * 권한이 없으면 403, 있으면 같은 모양의 200 을 낸다(모듈·플러그인 공통).
     *
     * @return void 반환값 없음
     *
     * @scenario case=target, extension=plugin
     *
     * @effects target_shape_from_adapter, target_null_when_nothing_accepted, target_endpoint_denies_without_permission
     */
    public function test_upload_target_and_standard_endpoint(): void
    {
        [$service, $store] = $this->plugin();
        $context = $store->context();

        $target = $service->uploadTarget($context, true);
        $this->assertSame('/api/plugins/test-notes-plugin/admin/content-media', $target['endpoint']);
        $this->assertSame(['scope' => 'lobby'], (array) $target['fields']);
        $this->assertSame('/api/plugins/test-notes-plugin/notes/lobby/content-media/(?<hash>[A-Za-z0-9]{12})', $target['mediaUrlPattern']);
        $this->assertTrue($target['policy']['image']['enabled']);
        $this->assertFalse($target['policy']['video']['enabled'], '동영상은 코어 업로드 스위치(기본 꺼짐)를 따른다');

        $controller = new class extends BaseApiController
        {
            use RespondsWithContentMediaTarget;

            /**
             * 시험용 표준 엔드포인트.
             *
             * @param  AbstractContentMediaService  $service  서비스
             * @param  ContentMediaContext  $context  문맥
             * @return JsonResponse 응답
             */
            public function target(AbstractContentMediaService $service, ContentMediaContext $context): JsonResponse
            {
                return $this->contentMediaTargetResponse($service, $context, 'common.success', true);
            }
        };

        $ok = $controller->target($service, $context);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('/api/plugins/test-notes-plugin/admin/content-media', $ok->getData(true)['data']['endpoint']);

        $store->uploadAllowed = false;
        $this->assertSame(403, $controller->target($service, $context)->getStatusCode());

        $store->uploadAllowed = true;
        $store->policyValue = ['image' => ['enabled' => false], 'video' => ['enabled' => false], 'audio' => ['enabled' => false]];
        $this->assertNull($service->uploadTarget($context));
        $this->assertNull($controller->target($service, $context)->getData(true)['data']);
    }

    /**
     * 등록 도우미는 어댑터(또는 라우트 이름)의 서빙 라우트 이름을 미디어 출처 레지스트리에 넘긴다 — 모듈·플러그인 모두.
     *
     * @return void 반환값 없음
     *
     * @scenario case=register, extension=plugin
     *
     * @effects serve_route_registered
     */
    public function test_registrar_registers_serve_route_names(): void
    {
        $recorder = new class
        {
            /** @var array<int, string> 등록된 이름 */
            public array $names = [];

            /**
             * 라우트 이름을 기록합니다.
             *
             * @param  string  $routeName  라우트 이름
             * @return void 반환값 없음
             */
            public function register(string $routeName): void
            {
                $this->names[] = $routeName;
            }
        };
        $this->app->instance(MediaSourceRoutes::class, $recorder);

        ContentMediaRegistrar::register(new FakeContentMediaStore);
        ContentMediaRegistrar::register(new FakeContentMediaStore(ContentMediaContext::EXTENSION_PLUGIN, 'test-notes-plugin', true));
        ContentMediaRegistrar::register('api.modules.other.content-media.show');
        ContentMediaRegistrar::register('');

        $this->assertSame([
            'api.modules.test-notes.content-media.show',
            'api.plugins.test-notes-plugin.notes.content-media.show',
            'api.modules.other.content-media.show',
        ], $recorder->names);
    }

    /**
     * 모듈 어댑터 시험 묶음.
     *
     * @return array{0: AbstractContentMediaService, 1: FakeContentMediaStore, 2: ModuleStorageDriver} 서비스·어댑터·저장소
     */
    private function module(): array
    {
        $store = new FakeContentMediaStore;
        $storage = new ModuleStorageDriver('test-notes', 'local');

        return [$this->service($store, $storage), $store, $storage];
    }

    /**
     * 플러그인 어댑터 시험 묶음(범위 lobby).
     *
     * @return array{0: AbstractContentMediaService, 1: FakeContentMediaStore, 2: PluginStorageDriver} 서비스·어댑터·저장소
     */
    private function plugin(): array
    {
        $store = new FakeContentMediaStore(ContentMediaContext::EXTENSION_PLUGIN, 'test-notes-plugin', true);
        $storage = new PluginStorageDriver('test-notes-plugin', 'local');

        return [$this->service($store, $storage), $store, $storage];
    }

    /**
     * 골격을 상속한 얇은 서비스.
     *
     * @param  FakeContentMediaStore  $store  어댑터
     * @param  ModuleStorageDriver|PluginStorageDriver  $storage  저장소
     * @return AbstractContentMediaService 서비스
     */
    private function service(FakeContentMediaStore $store, ModuleStorageDriver|PluginStorageDriver $storage): AbstractContentMediaService
    {
        return new class($store, $storage) extends AbstractContentMediaService {};
    }

    /**
     * 설정에 있는 두 번째 디스크(archive)를 페이크로 만든다 — 행 디스크 시험.
     *
     * @return void 반환값 없음
     */
    private function fakeArchiveDisk(): void
    {
        config(['filesystems.disks.archive' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/archive')]]);
        Storage::fake('archive');
    }

    /**
     * 내용이 있는 임시 파일 경로.
     *
     * @param  string  $content  내용
     * @return string 경로
     */
    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * 1×1 PNG 바이트.
     *
     * @return string 바이트
     */
    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }
}
