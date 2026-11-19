<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateOrchestrator;
use App\Exceptions\CoreUpdateOperationException;
use App\Extension\Custom\CustomUpdatePreflight;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 웹 단계 코어 업데이트가 CLI 와 같은 **배포본 판정 두 가지**를 거치는지 잰다.
 *
 * 7.2.0 은 CLI(`core:update`)에 두 판정을 더했다 — 공식 번들 배치(#146, 이 사이트가 설치하지 않은 번들은 파일 적용에서
 * 건너뛰고 기록한다)와 custom 자리 사전 검사(#792, 배포본이 custom 예약 자리에 파일을 싣거나 개발자 패키지가 새 코어와
 * 맞지 않으면 파일을 바꾸기 전에 멈춘다). 웹 단계 실행(#609)은 그 전에 만들어져 두 판정을 몰랐다. 그래서 무CLI 사이트만
 * 미설치 번들을 통째로 받고, custom 충돌이 있는 배포본도 그대로 적용했다 — 오류 없이 두 경로의 결과만 갈린다.
 *
 * @scenario source=github_download, failing_step=download
 */
class CoreUpdateWebBundledAndCustomPreflightTest extends TestCase
{
    /** @var string 테스트가 만든 임시 배포본 */
    private string $pending;

    /**
     * 임시 배포본 디렉터리를 만들고 이전 중단 기록을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pending = storage_path('app/core_pending/test_web_preflight_'.uniqid());
        File::ensureDirectoryExists($this->pending);
        CustomUpdatePreflight::clearRecord();
    }

    /**
     * 임시 배포본과 기록을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->pending);
        CustomUpdatePreflight::clearRecord();

        parent::tearDown();
    }

    /**
     * 계획과 적용 단계가 CLI 와 같은 미배치 건너뛰기 경로를 쓰는지 소스로 확인합니다.
     *
     * CLI 는 판정의 `skipPaths()` 를 신규 파일 기록·증분 목록·파일 적용 세 자리에 모두 넘긴다. 한 자리라도 빠지면
     * 미배치 번들이 그 자리에서만 새어 들어온다(적용은 건너뛰는데 되돌리기 기록에는 남는 식).
     *
     * @scenario source=github_download, bundled_extensions=off
     *
     * @effects the_web_run_skips_unplaced_bundles_like_the_cli,
     *          the_web_run_restores_the_bundled_placement_record_on_rollback
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_plan_and_apply_pass_the_unplaced_bundle_skip_paths_everywhere_the_cli_does(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(CoreUpdateOrchestrator::class))->getFileName());

        $plan = $this->methodBody($source, 'stepPlan');
        $this->assertStringContainsString('planBundledPlacement($pendingPath)', $plan);
        // 계획 단계는 요청 시간 제한 안에서 나눠 이어 가므로 순회는 커서 판(`*Chunk`)을 쓴다. 건너뛸 경로는
        // 진행분에 실려 요청을 넘어가므로, 이어 가는 요청도 같은 목록을 넘기는지 본다.
        $this->assertMatchesRegularExpression('/collectNewFilesChunk\([^;]*\$skipPaths,/s', $plan, '신규 파일 기록에서 미배치 번들을 빼야 되돌리기가 그 경로를 지우지 않는다');
        $this->assertMatchesRegularExpression('/computeApplyListChunk\([^;]*\$skipPaths,/s', $plan);
        $this->assertStringContainsString("'skip_paths' => \$bundledPlan->skipPaths()", $plan, '건너뛸 경로가 진행분에 실리지 않으면 이어 가는 요청이 미배치 번들을 다시 넣는다');

        $apply = $this->methodBody($source, 'stepApply');
        $this->assertStringContainsString('skipPaths: $bundledPlan->skipPaths()', $apply);
        $this->assertStringContainsString('->writeRecord($bundledPlan', $apply, '미배치 기록이 없으면 관리자 목록 안내와 core:bundled-sources 가 근거를 잃는다');

        $this->assertStringContainsString('restoreBundledRecord()', $this->methodBody($source, 'performRollback'));
    }

    /**
     * 배포본이 custom 예약 자리에 파일을 실으면 내려받기 단계에서 멈추고 중단 기록을 남기는지 확인합니다.
     *
     * @scenario source=github_download, failing_step=download, rollback_outcome=not_needed
     *
     * @effects the_web_run_stops_before_backup_when_the_release_hits_a_custom_reserved_path
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_release_carrying_a_custom_reserved_file_stops_the_web_run_and_records_why(): void
    {
        config(['app.update.reserved_paths' => ['custom/routes']]);
        File::ensureDirectoryExists($this->pending.'/custom/routes');
        File::put($this->pending.'/custom/routes/web.php', '<?php');

        try {
            $this->invokeGuard('9.9.9');
            $this->fail('custom 예약 자리를 덮는 배포본이 통과했다 — 운영자 파일을 지운다');
        } catch (CoreUpdateOperationException $e) {
            $this->assertSame('settings.core_update.custom_preflight_blocked', $e->errorKey);
            $this->assertSame('9.9.9', $e->params['version'] ?? null);
        }

        $record = CustomUpdatePreflight::lastRecord();
        $this->assertNotNull($record, '중단 사유가 기록되지 않으면 관리자 업데이트 화면이 같은 새 버전의 중단을 알아보지 못한다');
        $this->assertSame('9.9.9', $record['to_version']);
        $this->assertContains('custom/routes/web.php', $record['reserved_files']);
    }

    /**
     * 막을 것이 없는 배포본은 통과하고 이전 중단 기록을 지우는지 확인합니다.
     *
     * @scenario source=manual_zip, failing_step=none
     *
     * @effects the_web_run_stops_before_backup_when_the_release_hits_a_custom_reserved_path
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_clean_release_passes_and_clears_a_previous_block_record(): void
    {
        config(['app.update.reserved_paths' => ['custom/routes']]);
        CustomUpdatePreflight::record('9.9.8', ['reserved_files' => ['custom/routes/old.php'], 'conflicts' => []]);

        $this->invokeGuard('9.9.9');

        $this->assertNull(CustomUpdatePreflight::lastRecord(), '통과한 업데이트 뒤에 낡은 중단 기록이 남으면 화면이 없는 충돌을 알린다');
    }

    /**
     * 오케스트레이터의 custom 사전 검사를 호출합니다.
     *
     * @param  string  $toVersion  새 코어 버전
     * @return void 반환값 없음
     */
    private function invokeGuard(string $toVersion): void
    {
        $orchestrator = app(CoreUpdateOrchestrator::class);
        $method = new ReflectionMethod($orchestrator, 'guardCustomPreflight');
        $method->invoke($orchestrator, $this->pending, $toVersion);
    }

    /**
     * 소스에서 메서드 본문을 잘라냅니다.
     *
     * @param  string  $source  클래스 소스
     * @param  string  $method  메서드 이름
     * @return string 본문
     */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        $this->assertNotFalse($start, $method.' 메서드를 찾지 못했다');
        $next = strpos($source, "\n    private function ", $start + 1);
        $nextPublic = strpos($source, "\n    public function ", $start + 1);
        $ends = array_filter([$next, $nextPublic], fn ($v) => $v !== false);

        return substr($source, $start, ($ends === [] ? strlen($source) : min($ends)) - $start);
    }
}
