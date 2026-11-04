<?php

namespace Tests\Feature\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 코어 업데이트의 운영자 public/favicon.ico 보존 판정 테스트 (#434 D7)
 *
 * 이름 기준 업데이트 제외(`G7_UPDATE_EXCLUDES`)에 `favicon.ico` 를 넣으면 번들 아이콘(`public/assets/core/favicon/favicon.ico`)
 * 갱신까지 막히므로, 경로 하나(`public/favicon.ico`)만 건너뛴다. 비어 있는 파일(7.1 이하 기본값)과 배포본과 같은 파일은
 * 운영자 파일이 아니라 평소대로 갱신한다.
 */
class CoreUpdateFaviconPreserveTest extends TestCase
{
    private string $source;

    private string $original;

    /**
     * 가짜 배포본과 현재 favicon.ico 사본을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->original = File::get(public_path('favicon.ico'));
        $this->source = storage_path('framework/testing/qa434-update-'.uniqid());
        File::ensureDirectoryExists($this->source.'/public');
        File::put($this->source.'/public/favicon.ico', 'packaged-icon-bytes');
    }

    /**
     * 사이트 favicon.ico 와 가짜 배포본을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::put(public_path('favicon.ico'), $this->original);
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    /**
     * 판정 함수를 부릅니다.
     *
     * @return bool 보존 대상이면 true
     */
    private function isOperatorFavicon(): bool
    {
        $method = new \ReflectionMethod(CoreUpdateService::class, 'isOperatorFavicon');

        return (bool) $method->invoke(app(CoreUpdateService::class), $this->source);
    }

    /**
     * 운영자 파일은 보존, 빈 파일·배포본과 같은 파일은 갱신 대상, 번들 아이콘 경로는 판정과 무관하다.
     *
     * @scenario surface=update
     *
     * @effects update_preserves_public_favicon_ico
     *
     * @return void 반환값 없음
     */
    public function test_only_operator_favicon_is_preserved(): void
    {
        File::put(public_path('favicon.ico'), "\x00\x00\x01\x00operator");
        $this->assertTrue($this->isOperatorFavicon(), '운영자가 넣은 파일은 건너뛴다');

        File::put(public_path('favicon.ico'), '');
        $this->assertFalse($this->isOperatorFavicon(), '빈 파일은 새 기본 아이콘으로 바뀐다');

        File::put(public_path('favicon.ico'), 'packaged-icon-bytes');
        $this->assertFalse($this->isOperatorFavicon(), '배포본과 같으면 운영자 파일이 아니다');

        $this->assertNotContains('favicon.ico', (array) config('app.update.excludes'), '이름 기준 제외는 번들 아이콘 갱신까지 막으므로 쓰지 않는다');
    }

    /**
     * 임시 사이트 루트(운영자 `public/favicon.ico` + 다른 폴더의 `favicon.ico`)에 실제 적용 경로(`applyUpdate`)를 돌립니다.
     *
     * 사이트 루트를 임시 폴더로 바꿔 실제 public 폴더에는 아무것도 쓰지 않는다 — 바꾼 루트가 실제로 쓰이는지 먼저 확인한다.
     *
     * @return string 임시 사이트 루트
     */
    private function applyUpdateOnTemporarySite(): string
    {
        $site = storage_path('framework/testing/qa434-site-'.uniqid());
        File::ensureDirectoryExists($site.'/public/qa434-other');
        File::put($site.'/public/favicon.ico', "\x00\x00\x01\x00operator");
        File::put($site.'/public/qa434-other/favicon.ico', 'old-other-icon');
        File::ensureDirectoryExists($this->source.'/public/qa434-other');
        File::put($this->source.'/public/qa434-other/favicon.ico', 'new-other-icon');

        config(['app.update.targets' => ['public'], 'app.update.excludes' => []]);
        $originalBase = base_path();
        $service = app(CoreUpdateService::class);

        try {
            app()->setBasePath($site);
            $this->assertSame(realpath($site), realpath(base_path()), '전제 — 임시 사이트 루트가 쓰인다');
            $service->applyUpdate($this->source);
        } finally {
            app()->setBasePath($originalBase);
        }

        $this->assertSame(realpath($originalBase), realpath(base_path()));

        return $site;
    }

    /**
     * 실제 적용 경로가 운영자의 `public/favicon.ico` 를 덮지 않는다.
     *
     * @scenario surface=update
     *
     * @effects update_preserves_public_favicon_ico
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_keeps_operator_root_favicon(): void
    {
        $site = $this->applyUpdateOnTemporarySite();

        try {
            $this->assertSame("\x00\x00\x01\x00operator", File::get($site.'/public/favicon.ico'), '운영자 파일은 그대로');
        } finally {
            File::deleteDirectory($site);
        }
    }

    /**
     * 건너뛰는 것은 `public/favicon.ico` 한 경로뿐 — 다른 폴더의 `favicon.ico` 는 새 판으로 바뀐다.
     *
     * @scenario surface=update
     *
     * @effects update_preserves_public_favicon_ico
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_refreshes_favicon_ico_in_other_directory(): void
    {
        $site = $this->applyUpdateOnTemporarySite();

        try {
            $this->assertSame('new-other-icon', File::get($site.'/public/qa434-other/favicon.ico'), '다른 폴더의 favicon.ico 는 갱신된다');
        } finally {
            File::deleteDirectory($site);
        }
    }
}
