<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 본문 미디어 복제로 본문을 다시 저장해도 한 번 저장으로 기록된다 — 활동 로그 1건·버전 1개·after 훅 1회 (#887 G7)
 *
 * 다른 페이지 소유 본문 미디어를 복제하면 바뀐 본문을 같은 트랜잭션에서 페이지에 다시 저장한다(리포지토리 갱신). 그 재저장이
 * 활동 로그·버전 스냅샷·`after_*` 훅을 한 번 더 남기면 이력에 같은 저장이 두 번 보인다. 생성·수정·버전 복원 각각에서 한 번인지 잠근다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-page/tests/scenarios/page-content-media-save-once.yaml
 */
class PageContentMediaSaveOnceTest extends ModuleTestCase
{
    /** @var array<string, int> 훅 발화 횟수 */
    private array $fired = [];

    /**
     * 저장소를 가짜로 바꾸고 작성자로 로그인한 뒤, 페이지 저장 훅 발화 횟수를 셉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        config(['activity_log.enabled' => true]);
        $this->actingAs(User::factory()->create());
        $this->registerModuleRoutes();

        foreach (['after_create', 'after_update', 'after_restore'] as $hook) {
            $this->fired[$hook] = 0;
            HookManager::addAction('sirsoft-page.page.'.$hook, function () use ($hook) {
                $this->fired[$hook]++;
            });
        }
    }

    /**
     * 생성이 복제를 일으켜 본문을 다시 저장해도 활동 로그 1건·버전 1개·after_create 1회다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=create
     *
     * @effects clone_resave_logged_once, clone_resave_one_version, clone_resave_hooks_once
     */
    public function test_create_with_clone_is_recorded_once(): void
    {
        $hash = $this->publishedSourceHash();
        $this->resetFired();

        $page = $this->createPage($this->img($hash));

        $this->assertSame(1, $this->mediaCount($page), '전제: 생성에서 복제했다');
        $this->assertRecorded($page, 'page.create', versions: 1, hook: 'after_create');
    }

    /**
     * 수정이 복제를 일으켜 본문을 다시 저장해도 활동 로그 1건·버전 1개·after_update 1회다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=update
     *
     * @effects clone_resave_logged_once, clone_resave_one_version, clone_resave_hooks_once
     */
    public function test_update_with_clone_is_recorded_once(): void
    {
        $hash = $this->publishedSourceHash();
        $page = $this->createPage('<p>미디어 없음</p>');
        $this->resetFired();

        $page = app(PageService::class)->updatePage($page, ['content' => ['ko' => $this->img($hash)], 'content_mode' => 'html']);

        $this->assertSame(1, $this->mediaCount($page), '전제: 수정에서 복제했다');
        $this->assertRecorded($page, 'page.update', versions: 2, hook: 'after_update');
    }

    /**
     * 버전 복원이 복제를 일으켜 본문을 다시 저장해도 활동 로그 1건·버전 1개·after_restore 1회이고 after_update 는 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=restore
     *
     * @effects clone_resave_logged_once, clone_resave_one_version, clone_resave_hooks_once
     */
    public function test_restore_with_clone_is_recorded_once(): void
    {
        $hash = $this->publishedSourceHash();
        $service = app(PageService::class);
        $page = $this->createPage('<p>버전 1</p>');
        $page = $service->updatePage($page, ['content' => ['ko' => '<p>버전 2</p>'], 'content_mode' => 'html']);
        // 다른 페이지 미디어가 든 버전을 만든다 — 버전 1 본문을 원본 주소로 바꿔 둔다
        $first = $page->versions()->where('version', 1)->firstOrFail();
        PageVersion::query()->whereKey($first->id)->update(['content' => json_encode(['ko' => $this->img($hash)])]);
        $this->resetFired();

        $page = $service->restoreVersion($page->fresh(), $first->id);

        $this->assertSame(1, $this->mediaCount($page), '전제: 복원에서 복제했다');
        $this->assertStringNotContainsString($hash, (string) $page->content['ko']);
        $this->assertRecorded($page, 'page.restore', versions: 3, hook: 'after_restore');
        $this->assertSame(0, $this->fired['after_update'], '복원은 after_update 를 발화하지 않는다');
    }

    /**
     * 본문 하나로 미발행 페이지를 만듭니다.
     *
     * @param  string  $html  한국어 본문 HTML
     * @return Page 페이지
     */
    private function createPage(string $html): Page
    {
        return app(PageService::class)->createPage([
            'slug' => 'cm-once-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '한 번'],
            'content' => ['ko' => $html],
            'content_mode' => 'html',
            'published' => false,
        ]);
    }

    /**
     * 한 저장의 기록 횟수를 단언합니다.
     *
     * @param  Page  $page  페이지
     * @param  string  $action  활동 로그 action
     * @param  int  $versions  지금까지 쌓인 버전 수
     * @param  string  $hook  발화를 셀 훅 (`after_*`)
     * @return void 반환값 없음
     */
    private function assertRecorded(Page $page, string $action, int $versions, string $hook): void
    {
        $logs = DB::table('activity_logs')
            ->where('action', $action)
            ->where('loggable_type', $page->getMorphClass())
            ->where('loggable_id', $page->id)
            ->count();

        $this->assertSame(1, $logs, $action.' 활동 로그는 1건');
        $this->assertSame($versions, PageVersion::query()->where('page_id', $page->id)->count(), $action.' 버전 행은 저장마다 1개');
        $this->assertSame(1, $this->fired[$hook], $hook.' 는 1회');
    }

    /**
     * 발행된 원본 페이지에 이은 본문 미디어 해시를 만듭니다.
     *
     * @return string 해시
     */
    private function publishedSourceHash(): string
    {
        $tempKey = (string) Str::uuid();
        $row = app(PageContentMediaService::class)->upload(UploadedFile::fake()->image('src.png'), $tempKey);

        app(PageService::class)->createPage([
            'slug' => 'cm-once-src-'.Str::lower(Str::random(6)),
            'title' => ['ko' => '원본'],
            'content' => ['ko' => $this->img($row->hash)],
            'content_mode' => 'html',
            'published' => true,
            'temp_key' => $tempKey,
        ]);

        return $row->hash;
    }

    /**
     * 훅 발화 횟수를 0 으로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    private function resetFired(): void
    {
        $this->fired = array_fill_keys(array_keys($this->fired), 0);
    }

    /**
     * 페이지에 이어진 본문 미디어 수.
     *
     * @param  Page  $page  페이지
     * @return int 개수
     */
    private function mediaCount(Page $page): int
    {
        return PageAttachment::query()
            ->where('page_id', $page->id)
            ->where('collection', PageAttachment::COLLECTION_CONTENT_MEDIA)
            ->count();
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
}
