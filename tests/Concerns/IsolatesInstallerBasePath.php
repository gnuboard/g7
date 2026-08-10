<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * 인스톨러/업그레이드 테스트용 앱 루트 격리.
 *
 * ## 왜 필요한가
 *
 * 인스톨러·업그레이드 코드는 `base_path('.env')`, `base_path('storage/installer/runtime.php')`
 * 같은 실제 파일을 읽고 쓴다. 이를 테스트하려면 그 파일들이 있어야 하는데, 앱 루트를 그대로
 * 두면 **개발자의 실제 `.env` 를 삭제·재생성하게 된다.**
 *
 * 각 테스트가 setUp 에서 원본을 메모리에 담고 tearDown 에서 되돌리는 방식은 안전해 보이지만
 * 그렇지 않다 — 테스트가 fatal 로 죽으면 tearDown 이 실행되지 않아 `.env` 가 사라진 채 남는다.
 * `.env` 는 Git 추적 대상이 아니라 복구 수단이 없고, APP_KEY 를 잃으면 기존 암호화 데이터와
 * 세션이 전부 무효가 된다. 2026-07-21 에 실제로 그렇게 유실됐다.
 *
 * 그래서 "복원" 이 아니라 **애초에 건드리지 않는" 방식으로 바꾼다.** 앱 루트를 임시 디렉토리로
 * 돌리면 `base_path()` 를 쓰는 프로덕션 코드는 한 줄도 바뀌지 않은 채 격리된 파일만 다룬다.
 *
 * ## 사용
 *
 * ```php
 * protected function setUp(): void
 * {
 *     parent::setUp();
 *
 *     // 실제 파일이 필요하면 복사 대상으로 넘긴다 (.env.example 등)
 *     $this->isolateInstallerBasePath(['.env.example']);
 *
 *     // 프로젝트 실제 파일을 읽어야 할 때만 realBasePath() 사용
 *     require_once $this->realBasePath('upgrades/data/7.0.4/migrations/01_Foo.php');
 * }
 *
 * protected function tearDown(): void
 * {
 *     $this->releaseInstallerBasePath();
 *     parent::tearDown();
 * }
 * ```
 *
 * @see CoreUpdateServiceTest::withIsolatedBasePath 콜백 단위 격리 변형
 */
trait IsolatesInstallerBasePath
{
    /** 격리 이전의 실제 앱 루트 */
    private ?string $realProjectBasePath = null;

    /** 이번 테스트가 사용하는 임시 앱 루트 */
    private ?string $isolatedBasePath = null;

    /**
     * 앱 루트를 임시 디렉토리로 전환합니다.
     *
     * 인스톨러 코드가 흔히 기대하는 `storage/`, `storage/app/` 을 미리 만들어 둔다.
     *
     * @param  array<int, string>  $copyFromProject  실제 프로젝트에서 복사해올 파일 (루트 기준 상대경로)
     * @return string 생성된 임시 앱 루트 절대경로
     */
    protected function isolateInstallerBasePath(array $copyFromProject = []): string
    {
        $this->realProjectBasePath = $this->app->basePath();
        $this->isolatedBasePath = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'g7-installer-isolated-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->isolatedBasePath.'/storage/app');

        foreach ($copyFromProject as $relative) {
            $source = $this->realProjectBasePath.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
            if (is_file($source)) {
                $destination = $this->isolatedBasePath.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
                File::ensureDirectoryExists(dirname($destination));
                File::copy($source, $destination);
            }
        }

        $this->app->setBasePath($this->isolatedBasePath);

        return $this->isolatedBasePath;
    }

    /**
     * 앱 루트를 원래대로 되돌리고 임시 디렉토리를 제거합니다.
     *
     * tearDown 에서 호출한다. 호출되지 않아도 실제 프로젝트 파일은 애초에 건드리지 않았으므로
     * 남는 것은 임시 디렉토리뿐이다 — 이것이 복원 의존 방식과의 결정적 차이다.
     */
    protected function releaseInstallerBasePath(): void
    {
        if ($this->realProjectBasePath !== null) {
            $this->app->setBasePath($this->realProjectBasePath);
        }

        if ($this->isolatedBasePath !== null && is_dir($this->isolatedBasePath)) {
            File::deleteDirectory($this->isolatedBasePath);
        }

        $this->realProjectBasePath = null;
        $this->isolatedBasePath = null;
    }

    /**
     * 실제 프로젝트 루트 기준 경로를 반환합니다 (격리와 무관).
     *
     * 업그레이드 마이그레이션 파일처럼 **실제 소스**를 읽어야 할 때만 사용한다.
     *
     * @param  string  $relative  프로젝트 루트 기준 상대경로
     * @return string 절대경로
     */
    protected function realBasePath(string $relative = ''): string
    {
        $root = $this->realProjectBasePath ?? $this->app->basePath();

        return $relative === '' ? $root : $root.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }
}
