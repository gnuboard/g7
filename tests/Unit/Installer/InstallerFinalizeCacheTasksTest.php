<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 설치 마무리 단계의 캐시 굽기 태스크 회귀 가드 (이슈 #599 후속).
 *
 * 회귀: 설치 마무리 태스크 목록에 `config_cache`·`static_publish` 는 있는데
 * **`route_cache` 만 없었다.** 그래서 갓 설치한 사이트는 라우트 캐시가 **빈 채로**
 * 시작한다.
 *
 * 왜 설치 중에 구워지지 않는가:
 *   확장 install·activate 는 `RouteCacheHelper::rebuild()` 를 경유하지만, 그 헬퍼는
 *   `isInstalled()` 가 false 면 `route:clear` 만 하고 재생성을 건너뛴다(불완전한 부팅을
 *   캐시에 박제하지 않으려는 의도된 설계). 확장 설치는 전부 `complete_flag` **이전**에
 *   일어나므로 그 호출들은 전부 clear 로 끝난다. 그리고 완료 플래그 이후에는 재생성
 *   트리거가 확장 라이프사이클에만 묶여 있어 **아무도 굽지 않는다.**
 *
 * 증상이 조용한 이유: 라우트 캐시가 없어도 웹은 정상 동작한다(매 요청 라우트 파일
 * 스캔 — 느릴 뿐). 그래서 브라우저로 접속해 봐도 드러나지 않는다. 드러나는 곳은
 * `php artisan test` 다 — `artisan` 은 `test` 명령일 때 확장 오토로드 등록을
 * 건너뛰면서(`artisan:18`, "tests/bootstrap.php 에 위임") `G7_EXTENSION_AUTOLOAD_REGISTERED`
 * 상수는 무조건 정의하므로(`artisan:14`) `CoreServiceProvider` 의 등록까지 함께 막힌다.
 * 평소에는 부모 부팅이 **캐시된 라우트를 로드**해 라우트 파일을 아예 실행하지 않아
 * 무해하지만, 캐시가 비어 있으면 라우트 파일이 실행되면서 확장 클래스를 만나
 * `Class "Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum" not found` 로 죽는다.
 * 즉 **갓 설치한 사이트에서는 테스트를 한 건도 돌릴 수 없다.**
 *
 * 노출 창이 좁아 오래 숨어 있었다 — 확장을 하나라도 설치·삭제·업데이트하면
 * (완료 플래그 이후이므로) 그때 캐시가 구워지고 그 뒤로 유지된다. 재설치 직후
 * 확장을 아무것도 건드리지 않은 상태에서만 빈 채로 남는다.
 *
 * 본 테스트가 고정하는 계약:
 *   1. 설치 마무리 태스크 목록에 `route_cache` 가 `complete_flag` **뒤에** 있다
 *      (`installer_completed=true` 여야 `RouteCacheHelper` 가 재생성까지 한다).
 *   2. 그 태스크는 `best_effort` 다 — 캐시 굽기 실패가 설치 자체를 실패시키면 안 된다
 *      (비캐시 부팅은 느릴 뿐 정상).
 *   3. 실행 함수가 실제로 선언돼 있고 `route:cache` 를 호출한다.
 *   4. `isTaskInvalidatedByDbCleanup()` 이 `route_cache` 를 무효화 대상으로 안다 —
 *      DB 를 비우고 재시도하면 라우트 소스(활성 확장)가 달라지므로 다시 구워야 한다.
 *
 * 정적 소스 분석으로 검증한다: 태스크 목록은 SSE 워커 안에서만 조립되어 호출 없이
 * 배열을 얻을 수 없고, 실제 실행은 artisan 자식 프로세스를 띄운다.
 */
class InstallerFinalizeCacheTasksTest extends TestCase
{
    private string $taskRunnerSource = '';

    private string $installerStateSource = '';

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 3);

        $this->taskRunnerSource = (string) file_get_contents(
            $root.'/public/install/includes/task-runner.php'
        );
        $this->installerStateSource = (string) file_get_contents(
            $root.'/public/install/includes/installer-state.php'
        );
    }

    /**
     * 마무리 태스크 등록 줄에서 태스크 id 를 등장 순서대로 뽑는다.
     *
     * @return array<int, string>
     */
    private function registeredFinalizeTaskIds(): array
    {
        // $tasks[] = ['id' => 'config_cache', 'function' => '...'];
        preg_match_all(
            "/\\\$tasks\\[\\]\s*=\s*\\[\s*'id'\s*=>\s*'([a-z_]+)'/",
            $this->taskRunnerSource,
            $matches
        );

        return $matches[1] ?? [];
    }

    /**
     * @scenario cache_lifecycle_point=install_finalize, task_ordering=after_complete_flag, failure_tolerance=best_effort
     *
     * @effects route_cache_task_is_registered_in_finalize, installer_never_finishes_with_an_empty_route_cache
     */
    #[Test]
    public function 설치_마무리에_라우트_캐시_굽기_태스크가_있다(): void
    {
        $this->assertContains(
            'route_cache',
            $this->registeredFinalizeTaskIds(),
            '설치 마무리 태스크 목록에 route_cache 가 없다. 갓 설치한 사이트가 라우트 캐시 없이 '
            .'시작해 `php artisan test` 가 부모 부팅에서 죽는다(확장 클래스 not found).'
        );
    }

    /**
     * @scenario cache_lifecycle_point=install_finalize, task_ordering=after_complete_flag, failure_tolerance=best_effort
     *
     * @effects route_cache_task_runs_after_complete_flag, installer_never_finishes_with_an_empty_route_cache
     */
    #[Test]
    public function 라우트_캐시_태스크는_완료_플래그_뒤에_있다(): void
    {
        $ids = $this->registeredFinalizeTaskIds();

        $completeFlagPosition = array_search('complete_flag', $ids, true);
        $routeCachePosition = array_search('route_cache', $ids, true);

        $this->assertNotFalse($completeFlagPosition, 'complete_flag 태스크가 없다.');
        $this->assertNotFalse($routeCachePosition, 'route_cache 태스크가 없다.');

        $this->assertGreaterThan(
            $completeFlagPosition,
            $routeCachePosition,
            'route_cache 는 complete_flag 뒤여야 한다. installer_completed 가 서기 전에는 '
            .'RouteCacheHelper 가 clear 만 하고 재생성을 건너뛴다.'
        );
    }

    /**
     * @scenario cache_lifecycle_point=install_finalize, task_ordering=after_complete_flag, failure_tolerance=best_effort
     *
     * @effects route_cache_task_is_best_effort
     */
    #[Test]
    public function 라우트_캐시_태스크는_best_effort_다(): void
    {
        $this->assertMatchesRegularExpression(
            "/'id'\s*=>\s*'route_cache'.*'best_effort'\s*=>\s*true/s",
            $this->taskRunnerSource,
            'route_cache 는 best_effort 여야 한다 — 캐시 굽기 실패가 설치를 실패시키면 안 된다 '
            .'(비캐시 부팅은 느릴 뿐 정상 동작).'
        );
    }

    /**
     * @scenario cache_lifecycle_point=install_finalize, task_ordering=after_complete_flag, failure_tolerance=best_effort
     *
     * @effects route_cache_task_invokes_route_cache_command
     */
    #[Test]
    public function 라우트_캐시_실행_함수가_route_cache_를_호출한다(): void
    {
        $this->assertStringContainsString(
            'function optimizeRouteCacheSSE',
            $this->taskRunnerSource,
            'optimizeRouteCacheSSE() 가 선언돼 있지 않다.'
        );

        $this->assertMatchesRegularExpression(
            "/function optimizeRouteCacheSSE.*artisanCommand:\s*'route:cache'/s",
            $this->taskRunnerSource,
            'optimizeRouteCacheSSE() 가 route:cache 를 호출하지 않는다.'
        );
    }

    /**
     * @scenario cache_lifecycle_point=install_finalize, task_ordering=before_complete_flag, failure_tolerance=best_effort
     *
     * @effects route_cache_marker_is_invalidated_by_db_cleanup, installer_never_finishes_with_an_empty_route_cache
     */
    #[Test]
    public function db_정리_재시도는_라우트_캐시_태스크를_무효화한다(): void
    {
        // isTaskInvalidatedByDbCleanup() 의 정적 목록에 route_cache 가 있어야 한다.
        // DB 를 비우고 재시도하면 활성 확장 구성이 달라져 라우트 소스가 바뀌므로,
        // 완료 마커가 살아남으면 낡은 라우트 캐시를 그대로 안고 설치가 끝난다.
        $this->assertMatchesRegularExpression(
            "/static \\\$invalidated = \\[[^\\]]*'route_cache'\s*=>\s*true/s",
            $this->installerStateSource,
            'isTaskInvalidatedByDbCleanup() 이 route_cache 를 무효화 대상으로 알지 못한다.'
        );
    }
}
