<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\Http\Controllers\Api\Admin\CoreUpdateController;
use App\Services\CoreUpdateService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * CLI 와 웹이 **같은 업데이트**를 수행하는지 잰다 (#609 G6).
 *
 * 설계는 「오케스트레이션만 신설하고 로직은 복제하지 않는다」이다. 실제 작업은 전부
 * `CoreUpdateService` 가 갖고 있고 두 진입점(콘솔 커맨드 · 웹 오케스트레이터)은 그것을
 * 순서대로 부르기만 한다. 그런데 **순서를 아는 지식**은 두 곳에 있으므로, 한쪽에 단계가
 * 늘어도 다른 쪽은 따라가지 못한다 — 그 상태는 오류가 아니라 「어느 한쪽에서만 나는
 * 결함」으로만 드러난다.
 *
 * 그래서 이 검사는 **CLI 가 부르는 서비스 메서드 집합**을 소스에서 도출해 웹 경로가 그
 * 전부를 덮는지 본다. 웹이 일부러 부르지 않는 것은 아래 표에 **사유와 함께** 선언하고,
 * 그 선언이 낡으면(웹이 실제로 부르게 되면) 그것도 red 로 만든다.
 *
 * 커버 축: core_update_web pipeline_parity
 */
class CoreUpdatePipelineParityTest extends TestCase
{
    /**
     * 웹이 일부러 부르지 않는 CLI 전용 서비스 메서드 (사유 필수).
     *
     * 값은 사유다 — 목록만 있으면 왜 빠졌는지가 사람의 기억에 남고, 그 기억이 사라지면
     * 다음 사람이 「누락」인지 「의도」인지 판정할 수 없다.
     *
     * @var array<string, string>
     */
    private const WEB_OMITS = [
        // `--source` / `--local` 은 개발·수동 복구용 CLI 경로다. 웹에는 그 입력 표면이
        // 없고(D27), 운영자가 올린 ZIP 은 `extractZipToPending()` 이 받는다 (㉯ — D18).
        'validatePendingUpdate' => '--source 전용 (웹은 ZIP 경로만 제공)',
        'copySourceToPending' => '--source 전용 (웹 미노출)',
        'prepareLocalSource' => '--local 전용 (개발용, 웹 미노출)',

        // 웹은 업그레이드 스텝을 러너(`core:execute-upgrade-steps`)로 돌린다. 그 단계가
        // **파일 적용 다음 요청**에서 실행되므로 요청 경계 자체가 신버전 부팅을 준다
        // (§7 R25 · A-7 설계 3). 서비스의 in-process 호출은 CLI 의 spawn 실패 폴백이다.
        'runUpgradeSteps' => '웹은 러너 경유 — 요청 경계가 신버전 부팅을 보장한다',

        // 콘솔이 실패·성공 로그 파일 이름을 만드는 자리. 웹은 같은 사실을 진행 기록과
        // 실패 리포트 경로(`generateFailureReport()` 반환값)로 화면에 나른다.
        'updateReportPath' => '콘솔 로그 파일 이름 도출 전용 — 웹은 리포트 경로를 응답에 싣는다',

        // 웹은 한 요청의 시간 몫 안에서 멈추는 커서 판을 부른다. 명령줄의 아래 메서드는 같은 커서 판을
        // 멈춤 없이 부르는 래퍼라 하는 일이 같다(백업·복원), 또는 같은 안전 해제를 쓴다(내려받기·ZIP 추출).
        'createBackup' => '웹은 createBackupChunk() — createBackup() 은 같은 함수를 멈춤 없이 부르는 래퍼',
        'restoreFromBackup' => '웹은 restoreFromBackupChunk() — restoreFromBackup() 은 같은 함수를 멈춤 없이 부르는 래퍼',
        'downloadUpdate' => '웹은 fetchReleaseArchive() + extractArchiveChunk() 로 내려받기와 풀기를 다른 요청에서 한다',
        'extractZipToPending' => '웹은 extractArchiveChunk() — 같은 SafeArchiveExtractor 안전 해제를 항목 순번 커서로 나눈다',
        'restoreOwnership' => '웹은 restoreOwnershipChunk() — restoreOwnership() 은 같은 함수를 멈춤 없이 부르는 래퍼',
        'getLastPermissionWarnings' => '웹은 restoreOwnershipChunk() 결과의 warnings 를 읽는다 — 요청을 넘어 진행분에 실려 온다',
    ];

    /**
     * CLI 가 부르는 서비스 메서드를 웹 경로가 모두 덮는지 확인합니다.
     *
     * @scenario run_state=running, execution_mode=subprocess
     *
     * @effects the_web_pipeline_covers_every_service_call_the_cli_makes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_web_pipeline_covers_every_service_call_the_cli_makes(): void
    {
        $cli = $this->serviceCallsIn(CoreUpdateCommand::class);
        $web = $this->webServiceCalls();

        $this->assertNotEmpty($cli, 'CLI 소스에서 서비스 호출을 찾지 못했다 — 추출식이 낡았는지 확인할 것.');

        $missing = array_values(array_diff($cli, $web, array_keys(self::WEB_OMITS)));

        $this->assertSame(
            [],
            $missing,
            'CLI 는 부르는데 웹은 부르지 않는 단계가 있다: '.implode(', ', $missing)
            ."\n웹에 그 단계를 추가하거나, 부르지 않는 사유를 WEB_OMITS 에 선언하세요.",
        );
    }

    /**
     * 낡은 면제 선언을 걷어냅니다.
     *
     * 웹이 실제로 부르게 된 메서드가 면제 목록에 남아 있으면, 그 목록은 「지금 무엇이
     * 빠져 있는가」를 더 이상 말해 주지 못한다 — 다음 사람이 그 표를 믿고 판단한다.
     *
     * @scenario run_state=running, execution_mode=subprocess
     *
     * @effects no_stale_omission_declarations_remain
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_no_stale_omission_declarations_remain(): void
    {
        $web = $this->webServiceCalls();
        $cli = $this->serviceCallsIn(CoreUpdateCommand::class);

        foreach (self::WEB_OMITS as $method => $reason) {
            $this->assertNotSame('', trim($reason), "면제 선언 `{$method}` 에 사유가 없다.");

            $this->assertNotContains(
                $method,
                $web,
                "면제로 선언한 `{$method}` 를 웹이 실제로 부르고 있다 — 선언을 지우세요.",
            );
        }

        $unusedOmissions = array_values(array_diff(array_keys(self::WEB_OMITS), $cli, $this->publicServiceMethods()));

        $this->assertSame(
            [],
            $unusedOmissions,
            '더 이상 존재하지 않는 메서드가 면제 목록에 남아 있다: '.implode(', ', $unusedOmissions),
        );
    }

    /**
     * 파일 적용과 업그레이드 스텝이 **같은 메서드**에서 이어지지 않는지 확인합니다.
     *
     * 순서 불변식(§7 R25)은 단계 목록만으로는 지켜지지 않는다 — 한 단계 구현이 둘을
     * 이어 하면 목록은 그대로인 채 경계만 사라진다.
     *
     * @scenario run_state=running, handoff=none
     *
     * @effects apply_and_upgrade_steps_live_in_different_step_methods
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_apply_and_upgrade_steps_live_in_different_step_methods(): void
    {
        $reflection = new ReflectionClass(CoreUpdateOrchestrator::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $applyBody = $this->methodBody($source, 'stepApply');
        $upgradeBody = $this->methodBody($source, 'stepUpgradeSteps');

        $this->assertStringContainsString('applyUpdate(', $applyBody);
        $this->assertStringNotContainsString(
            'core:execute-upgrade-steps',
            $applyBody,
            '파일 적용 단계가 업그레이드 스텝까지 이어 하면 요청 경계가 사라진다 (§7 R25).',
        );

        $this->assertStringContainsString('core:execute-upgrade-steps', $upgradeBody);
        $this->assertStringNotContainsString(
            'applyUpdate(',
            $upgradeBody,
            '업그레이드 스텝 단계가 파일을 다시 적용하면 안 된다.',
        );
    }

    /**
     * 업그레이드 스텝 단계가 스폰 실패 모드를 `fallback` 으로 강제하는지 확인합니다.
     *
     * 강제하지 않으면 배포 기본값(`abort`)이 그대로 걸린다 — 웹에서는 `.env` 의
     * `APP_VERSION` 이 Finalize 까지 옛 값이라 `runUpgradeSteps` 의 stale 메모리 가드가
     * **항상** 참이 되고, 매 요청이 같은 핸드오프를 던져 상한까지 반복한 뒤 정상 적용된
     * 코어가 되돌려진다. 그 판정의 전제(부모가 구버전 코드를 들고 있다)는 웹에서
     * 성립하지 않는다 — 이 단계는 파일 적용 다음 요청이다 (A-7 설계 3 · §7 R26).
     *
     * @scenario run_state=running, execution_mode=inprocess, spawn_fallback=forced_inprocess
     *
     * @effects the_upgrade_step_stage_forces_the_inprocess_fallback_mode
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_upgrade_step_stage_forces_the_inprocess_fallback_mode(): void
    {
        $reflection = new ReflectionClass(CoreUpdateOrchestrator::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $this->assertStringContainsString(
            'forceSpawnFailureFallback()',
            $this->methodBody($source, 'stepUpgradeSteps'),
            '업그레이드 스텝 단계가 스폰 실패 모드를 강제하지 않으면 배포 기본값 abort 가 걸린다.',
        );

        // 배포 기본값에서 출발한다 — 이 값이 이미 fallback 이면 강제가 무엇을 했는지
        // 알 수 없어 되돌려도 초록인 가드가 된다.
        config(['app.update.spawn_failure_mode' => 'abort']);
        unset($_ENV['G7_UPDATE_SPAWN_FAILURE_MODE'], $_SERVER['G7_UPDATE_SPAWN_FAILURE_MODE']);

        $orchestrator = app(CoreUpdateOrchestrator::class);
        $method = $reflection->getMethod('forceSpawnFailureFallback');
        $method->setAccessible(true);
        $method->invoke($orchestrator);

        $this->assertSame(
            CoreUpdateOrchestrator::SPAWN_FAILURE_MODE,
            config('app.update.spawn_failure_mode'),
            'in-process 러너는 이 설정값을 읽는다.',
        );
        $this->assertSame(
            CoreUpdateOrchestrator::SPAWN_FAILURE_MODE,
            $_ENV['G7_UPDATE_SPAWN_FAILURE_MODE'] ?? null,
            '서브프로세스 자식은 상속 env 로만 이 값을 받는다.',
        );

        // 프로세스 수명에 남는 채널(`putenv`)은 쓰지 않는다 — 같은 워커의 다음 요청까지
        // 모드가 바뀐다.
        $this->assertStringNotContainsString(
            'putenv(',
            $this->methodBody($source, 'forceSpawnFailureFallback'),
        );
    }

    /**
     * 되돌리기가 성공 경로와 **같은 강도**의 마무리를 수행하는지 확인합니다.
     *
     * 되돌리기는 파일을 제자리에 놓는 것으로 끝나지 않는다 — 소유권·퍼미션을 되돌려야
     * 「사이트는 정상 동작합니다」가 성립하고(§7 R29), 컴파일 캐시는 **파일만** 지워야
     * 한다(§7 R25 — 커맨드를 부르면 되돌린 디스크를 신버전 프로세스가 재발견한다).
     * 그리고 파일을 쓰는 구간이므로 단계 수행과 같은 잠금 안에서 돌아야 한다.
     *
     * @scenario run_state=rolling_back, rollback_outcome=restored
     *
     * @effects the_rollback_path_finishes_as_strongly_as_the_success_path
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_rollback_path_finishes_as_strongly_as_the_success_path(): void
    {
        $reflection = new ReflectionClass(CoreUpdateOrchestrator::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $rollback = $this->methodBody($source, 'performRollback');

        $this->assertStringContainsString(
            'restoreOwnershipSnapshot($shouldPause)',
            $rollback,
            '되돌린 사이트가 쓰기 권한을 잃은 채 남으면 되돌리기의 목적이 성립하지 않는다 (§7 R29).',
        );
        $this->assertStringContainsString('dropCompiledCaches()', $rollback);
        // 호출 형태로 본다 — 주석은 왜 부르지 않는지를 설명하느라 그 이름을 쓴다.
        $this->assertStringNotContainsString(
            '->service->clearAllCaches(',
            $rollback,
            '파일 복원 직후 같은 요청에서 커맨드를 부르면 §7 R25 가 말하는 자리가 된다.',
        );

        // 성공 경로도 같은 지점을 지난다 — 한쪽만 두면 사본이 갈린다.
        $this->assertStringContainsString(
            'restoreOwnershipSnapshot($shouldPause)',
            $this->methodBody($source, 'stepFinalize'),
        );

        // 파일을 쓰는 구간은 단계 수행과 같은 잠금을 잡는다.
        $guarded = $this->methodBody($source, 'runRollbackGuarded');
        $this->assertStringContainsString('acquireRunLock()', $guarded);
        $this->assertStringContainsString('releaseRunLock()', $guarded);
        $this->assertStringContainsString(
            'runRollbackGuarded()',
            $this->methodBody($source, 'advance'),
            '되돌리기가 잠금 밖에서 돌면 동시에 도는 CLI 와 같은 디스크를 반대 방향으로 바꾼다.',
        );
    }

    /**
     * 사전 진단이 **웹이 실제로 쓰는 경로 전부**를 재는지 확인합니다 (D26).
     *
     * `vendor` 는 3-way 적용 대상이 아니라 `update.targets` 에 없지만, 웹 흐름은 그 경로를
     * 통째로 덮어쓰고(`stepVendorCopy`) 되돌리기도 그리로 복원한다. 빠뜨리면 D26 이
     * 막으려던 「진단은 통과했는데 적용에서 실패」가 그대로 재현된다.
     *
     * @scenario run_state=idle, failing_step=preflight
     *
     * @effects preflight_covers_every_path_the_web_run_writes_to
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_preflight_covers_every_path_the_web_run_writes_to(): void
    {
        $preflight = app(CoreUpdatePreflight::class);
        $reflection = new ReflectionClass(CoreUpdatePreflight::class);
        $method = $reflection->getMethod('writableCandidates');
        $method->setAccessible(true);

        /** @var list<string> $candidates */
        $candidates = $method->invoke($preflight);

        $this->assertNotEmpty($candidates, '후보가 비면 이 검사는 아무것도 재지 않는다.');

        foreach ((array) config('app.update.targets', []) as $target) {
            $this->assertContains(trim((string) $target), $candidates);
        }

        foreach ((array) config('app.update.backup_only', []) as $target) {
            $this->assertContains(
                trim((string) $target),
                $candidates,
                '되돌리기가 복원하는 경로를 진단이 재지 않으면 되돌리기까지 부분 실패한다.',
            );
        }

        foreach (['storage', 'bootstrap/cache'] as $runtime) {
            $this->assertContains($runtime, $candidates);
        }
    }

    /**
     * 착수 화면이 사전 조건 진단을 **누르기 전에** 받는지 확인합니다 (W6 「사전 조건 미충족」).
     *
     * 실행 1단계에서만 재면 쓰기 권한이 없는 호스팅의 운영자는 사이트를 점검 모드로
     * 보낸 뒤에야 그 사실을 안다.
     *
     * @scenario actor=with_permission, run_state=idle
     *
     * @effects the_start_screen_receives_the_preflight_verdict
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_the_start_screen_receives_the_preflight_verdict(): void
    {
        $summary = app(CoreUpdateOrchestrator::class)->preflightSummary();

        foreach (['ok', 'blocked', 'checked', 'disk', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }

        // `ok` 는 `(bool)` 캐스팅이라 타입 단언이 실패할 수 없고, `checked` 는 런타임 경로
        // 둘이 하드코딩돼 있어 언제나 2 이상이다 — 둘 다 되돌려도 red 가 되지 않는다.
        // 실제로 재야 하는 것은 **요약이 진단 대상 전부를 셌는가** 이므로 후보 수와 견준다.
        $preflight = app(CoreUpdatePreflight::class);
        $candidates = (new ReflectionClass(CoreUpdatePreflight::class))->getMethod('writableCandidates');
        $candidates->setAccessible(true);

        /** @var list<string> $expected */
        $expected = $candidates->invoke($preflight);

        $this->assertNotEmpty($expected, '후보가 비면 이 대조는 아무것도 재지 않는다.');
        $this->assertSame(
            count($expected),
            $summary['checked'],
            '요약이 진단 후보 전부를 세지 않으면 착수 화면이 통과라고 말한 뒤 1단계가 막는다.',
        );

        // 화면을 여는 요청에 실리므로 **얕게** 본다 — 하위 전수 순회는 이 저장소에서만
        // 13초가 걸린다(실측). 전수 판정은 착수 1단계가 소유한다.
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(CoreUpdateOrchestrator::class))->getFileName(),
        );
        $this->assertStringContainsString(
            'deep: false',
            $this->methodBody($source, 'preflightSummary'),
            '착수 화면 요약이 전수 순회를 돌면 탭을 여는 것만으로 요청이 수 초 멈춘다.',
        );

        $controller = (string) file_get_contents(
            (new ReflectionClass(CoreUpdateController::class))->getFileName(),
        );

        $this->assertStringContainsString(
            'preflightSummary()',
            $controller,
            '진단 결과가 상태 응답에 실리지 않으면 화면은 그것을 그릴 수 없다.',
        );
    }

    /**
     * 웹 경로(오케스트레이터 + 사전 진단)가 부르는 서비스 메서드 집합.
     *
     * @return list<string> 메서드 이름
     */
    private function webServiceCalls(): array
    {
        return array_values(array_unique(array_merge(
            $this->serviceCallsIn(CoreUpdateOrchestrator::class),
            $this->serviceCallsIn(CoreUpdatePreflight::class),
        )));
    }

    /**
     * 클래스 소스에서 `CoreUpdateService` 호출 이름을 뽑습니다.
     *
     * 주석·문자열 안의 언급을 세지 않도록 **호출 형태**(`->service->name(`)만 본다.
     *
     * @param  class-string  $class  대상 클래스
     * @return list<string> 메서드 이름
     */
    private function serviceCallsIn(string $class): array
    {
        $file = (new ReflectionClass($class))->getFileName();
        $source = $file === false ? '' : (string) file_get_contents($file);

        $names = [];

        // `$service->foo(` (커맨드) · `$this->service->foo(` (오케스트레이터·진단)
        if (preg_match_all('/\$(?:this->)?service->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches) > 0) {
            $names = $matches[1];
        }

        // `CoreUpdateService::foo(` 정적 호출
        if (preg_match_all('/CoreUpdateService::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $staticMatches) > 0) {
            $names = array_merge($names, $staticMatches[1]);
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * `CoreUpdateService` 의 public 메서드 이름 전수.
     *
     * @return list<string> 메서드 이름
     */
    private function publicServiceMethods(): array
    {
        return array_map(
            static fn ($method): string => $method->getName(),
            (new ReflectionClass(CoreUpdateService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
    }

    /**
     * 소스에서 한 메서드의 본문을 대략적으로 잘라 냅니다.
     *
     * 중괄호 균형으로 자른다 — 문자열·주석 안의 중괄호가 있으면 경계가 밀릴 수 있으나,
     * 이 검사가 보는 것은 「그 안에 이 호출이 있는가」이므로 근사로 충분하다.
     *
     * @param  string  $source  클래스 소스
     * @param  string  $method  메서드 이름
     * @return string 본문 (찾지 못하면 빈 문자열)
     */
    /**
     * `advance()` 가 **한 요청에 한 단계만** 수행하는지 확인합니다 (§7 R25).
     *
     * 무CLI 호스팅에서 이 분리는 편의가 아니라 **안전 요건**이다 — 파일을 교체한
     * 프로세스가 사라진 클래스를 참조하면 치명 오류가 나는데, CLI 는 그 격리를 스폰으로
     * 얻고 스폰이 막힌 호스팅에는 그 수단이 없다. 요청 경계가 그것을 대신하므로, 한
     * 요청 안에서 단계를 이어 돌리면 **단계 목록은 그대로인 채 경계만 사라진다.**
     *
     * 그래서 여기서 재는 것은 「단계가 분리돼 있는가」(형제 테스트의 몫)가 아니라
     * **「한 번의 호출이 한 단계에서 멈추는가」** 다. 반복문을 넣어 여러 단계를 이어
     * 돌리는 회귀가 이 축의 유일한 파괴 형태이므로 그것을 직접 잡는다.
     *
     * @scenario run_state=running
     *
     * @effects advance_runs_exactly_one_step_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_advance_runs_exactly_one_step_per_request(): void
    {
        $reflection = new ReflectionClass(CoreUpdateOrchestrator::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $advance = $this->methodBody($source, 'advance');

        $this->assertNotSame('', $advance, '본문을 못 읽으면 이 검사는 아무것도 재지 않는다.');

        // 단계 수행·마무리·되돌리기는 각각 **한 번씩만** 나타난다. 두 번 이상이면
        // 한 요청이 두 단계를 밀고 있다는 뜻이다.
        foreach (['runStepGuarded(', 'finishSuccessfully(', 'runRollbackGuarded('] as $call) {
            $this->assertSame(
                1,
                substr_count($advance, $call),
                $call.' 가 한 요청에 두 번 이상 나타나면 요청 경계가 사라진다 (§7 R25).',
            );
        }

        // 반복 구문이 있으면 그 안에서 단계를 이어 돌릴 수 있다. `advance()` 는 분기만
        // 하고 끝나는 메서드이므로 반복문이 필요한 자리가 없다.
        foreach (['while (', 'while(', 'for (', 'for(', 'foreach (', 'foreach(', 'do {'] as $loop) {
            $this->assertStringNotContainsString(
                $loop,
                $advance,
                'advance() 안의 반복문은 한 요청에 여러 단계를 돌리는 통로가 된다 (§7 R25).',
            );
        }

        // 위 부재 단언이 죽지 않았음을 같은 파일에서 확인한다 — 이 클래스에는 실제
        // 반복문이 있고(모집단 非0), 그래서 「반복문이 없다」가 의미를 갖는다.
        $this->assertStringContainsString(
            'foreach (',
            $source,
            '이 클래스에 반복문이 하나도 없으면 위 부재 단언은 무엇을 넣어도 통과한다.',
        );
    }

    /**
     * 소스에서 이름이 같은 메서드를 찾아 여는 중괄호부터 짝이 맞는 닫는 중괄호까지의 본문을 잘라 낸다.
     *
     * @param  string  $source  검사할 PHP 소스
     * @param  string  $method  찾을 메서드 이름
     * @return string 메서드 본문(찾지 못하면 빈 문자열)
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');

        if ($start === false) {
            return '';
        }

        $open = strpos($source, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
