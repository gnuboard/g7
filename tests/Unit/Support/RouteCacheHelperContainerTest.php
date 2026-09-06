<?php

namespace Tests\Unit\Support;

use App\Services\SettingsService;
use App\Support\RouteCacheHelper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `route:cache` 도 `config:cache` 와 같은 부수효과를 남긴다 — 새 Application 을 부팅하며 전역
 * `Container` 인스턴스를 일회용 앱으로 바꿔 놓는다. 되돌리지 않으면 그 뒤에 등록되는
 * `app()->terminating()` 이 종료되지 않는 앱에 걸려 실행되지 않는다(정적 재게시 예약 소실).
 * 단독 실행 `core:execute-upgrade-steps` 가 라우트 캐시 재생성 **뒤에** 캐시 버전을 올리므로
 * 그 경로에서 실제로 새어 나갔다 (2026-09-06 전수조사).
 *
 * 테스트 환경은 `route:cache` 자체를 스킵하므로 소스 구조로 잠근다(`ConfigCacheHelperContainerTest` 와 동형).
 */
class RouteCacheHelperContainerTest extends TestCase
{
    /**
     * @effects route_cache_rebuild_preserves_container_instance
     */
    #[Test]
    public function rebuild_는_route_cache_를_보존_래퍼로_감싼다(): void
    {
        $body = $this->methodBody(RouteCacheHelper::class, 'rebuild');

        $this->assertStringContainsString("withPreservedContainer(static fn () => Artisan::call('route:cache'))", $body);
        $this->assertStringNotContainsString("\n            Artisan::call('route:cache');", $body);
    }

    /**
     * 관리자 「시스템 최적화」 도 같은 두 명령을 부르므로 같은 래퍼를 거친다.
     *
     * @effects route_cache_rebuild_preserves_container_instance
     */
    #[Test]
    public function optimize_system_은_config_route_cache_를_보존_래퍼로_감싼다(): void
    {
        $body = $this->methodBody(SettingsService::class, 'optimizeSystem');

        $this->assertStringContainsString('withPreservedContainer(', $body);
        $this->assertStringNotContainsString("\n            Artisan::call('config:cache');", $body);
        $this->assertStringNotContainsString("\n            Artisan::call('route:cache');", $body);
    }

    /**
     * 새 Application 을 부팅하는 Artisan 명령은 두 헬퍼 밖에서 직접 호출되지 않는다.
     *
     * 모집단은 `app/` 전체 PHP 파일에서 파생한다 — 헬퍼를 우회하는 호출이 하나라도 생기면
     * 그 자리 뒤의 `terminating` 예약이 조용히 사라진다.
     *
     * @effects route_cache_rebuild_preserves_container_instance
     */
    #[Test]
    public function 새_앱을_부팅하는_artisan_호출은_헬퍼_밖에서_직접_부르지_않는다(): void
    {
        $allowed = [
            realpath(app_path('Support/ConfigCacheHelper.php')),
            realpath(app_path('Support/RouteCacheHelper.php')),
            realpath(app_path('Services/SettingsService.php')),
        ];
        $pattern = "/(?:Artisan::call|->call)\(\s*'(?:config:cache|route:cache|event:cache|optimize)'/";

        $files = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)));
        $offenders = [];
        $scanned = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $content = (string) file_get_contents($file->getPathname());
            if (! preg_match($pattern, $content)) {
                continue;
            }
            if (in_array(realpath($file->getPathname()), $allowed, true)) {
                continue;
            }
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }

        $this->assertGreaterThan(100, $scanned, '모집단이 비었다 — app/ 스캔 실패');
        $this->assertSame([], $offenders, '새 Application 을 부팅하는 Artisan 명령은 ConfigCacheHelper/RouteCacheHelper 를 경유한다 (컨테이너 보존)');
    }

    /**
     * 메서드 본문 소스를 돌려준다.
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new \ReflectionMethod($class, $method);
        $lines = file($ref->getFileName()) ?: [];

        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }
}
