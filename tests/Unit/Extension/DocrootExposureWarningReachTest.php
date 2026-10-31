<?php

declare(strict_types=1);

namespace Tests\Unit\Extension;

use App\Extension\Helpers\StorageLinkHelper;
use Tests\TestCase;

/**
 * 「공개 파일이 지금 웹에서 열린다」 통보가 그 통보를 만들 수 있는 **모든 프로세스**에서
 * 운영자에게 도달한다.
 *
 * 이 통보는 정적 버퍼에 담기고 **소비자만** 비운다. 그래서 통보를 만드는 흐름이 도는
 * 프로세스에 소비자가 없으면, 통보는 그 프로세스 종료와 함께 폐기되고 `upgrade` 로그
 * 파일에만 남는다 — 콘솔에도 업데이트 기록에도 한 줄이 뜨지 않는다. 실제로 업그레이드
 * 스텝을 별도 프로세스에서 도는 `core:execute-upgrade-steps` 가 그 상태였다.
 *
 * 개별 지점을 열거하지 않는다 — 열거는 새 실행 경로가 생길 때 함께 늘지 않는다.
 * 「업그레이드 스텝을 도는 커맨드」를 모집단으로 도출하고 그 전부에 소비자를 요구한다.
 *
 * @scenario process_shape=spawn_child, process_shape=standalone, warning_channel=console, warning_channel=update_record
 */
class DocrootExposureWarningReachTest extends TestCase
{
    /** @var string 통보를 만드는 복구 흐름을 부르는 호출 표식 */
    private const PRODUCER_CALL = 'runUpgradeSteps(';

    /** @var string 버퍼를 비우는 소비 표식 */
    private const CONSUMER_CALL = 'takeDocrootExposureWarnings(';

    /** @var string 커맨드가 버퍼를 콘솔·업데이트 기록으로 흘리는 메서드 이름 */
    private const CONSUMER_METHOD = 'surfaceDocrootExposureWarnings';

    /**
     * 업그레이드 스텝을 도는 **커맨드** 클래스를 도출한다.
     *
     * @return array<string, string> 상대 경로 → 내용
     */
    private function upgradeStepCommands(): array
    {
        $found = [];

        // `glob()` 은 형제 룰(`hosting-blocked-function-requires-guard`)이 이 경로(코어 커맨드)에서
        // 차단 가능 함수로 다루는 이름이다 — 게이트 자신이 그 이름으로 모집단을 만들면 「glob 이 막힌
        // 호스팅」을 재는 검사들과 같은 트리에서 규약이 갈린다. `scandir` 로 도출한다 (15차 F-50).
        $dir = base_path('app/Console/Commands/Core');
        $entries = scandir($dir) ?: [];
        sort($entries);

        foreach ($entries as $entry) {
            if (! str_ends_with($entry, '.php')) {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (! str_contains($source, self::PRODUCER_CALL)) {
                continue;
            }

            $found['app/Console/Commands/Core/'.basename($path)] = $source;
        }

        return $found;
    }

    /**
     * 업그레이드 스텝을 도는 커맨드를 두 개 이상 도출하고, 각 커맨드가 노출 통보 버퍼를 비우며 소비 메서드를 선언만 하지 않고 실제로 부르는지 소스로 확인한다.
     *
     * @scenario process_shape=standalone, warning_channel=console
     *
     * @effects docroot_exposure_warning_reaches_the_operator_from_every_process_that_can_produce_it
     *
     * @return void 반환값 없음
     */
    public function test_every_command_that_runs_upgrade_steps_drains_the_warning_buffer(): void
    {
        $commands = $this->upgradeStepCommands();

        // 모집단이 비면 이 검사는 아무것도 재지 않는다 — 그 상태를 통과로 두지 않는다.
        $this->assertGreaterThanOrEqual(
            2,
            count($commands),
            '업그레이드 스텝을 도는 커맨드 도출이 2개 미만이다 — 도출 규칙이 실물과 어긋났다'
        );

        foreach ($commands as $relative => $source) {
            $this->assertStringContainsString(
                self::CONSUMER_CALL,
                $source,
                $relative.' 이 업그레이드 스텝을 돌면서 노출 통보 버퍼를 비우지 않는다 — '
                .'그 프로세스에서 만들어진 통보가 종료와 함께 폐기되어 로그 파일에만 남는다'
            );

            // 버퍼를 비우는 문장이 **어딘가 있다**는 것만으로는 부족하다. 그 문장이
            // 소비 메서드 자신의 본문에 있으면, 그 메서드를 부르는 자리를 전부 지워도
            // 이 검사는 초록이다 — 형제 축(:137-139)이 부모에 대해 이미 그 형태를
            // 겪고 `$this->` 접두 대조로 막았는데, 이 축만 그대로 남아 있었다.
            //
            // 그 상태의 증상: 자식 프로세스가 만든 「공개 파일 전량 사본이 지금 웹에서
            // 열린다」가 종료와 함께 사라지고 로그 파일에만 남는다.
            if (! str_contains($source, 'private function '.self::CONSUMER_METHOD)) {
                continue; // 소비를 메서드로 감싸지 않은 커맨드는 위 단언이 곧 호출 대조다
            }

            $callCount = substr_count($source, '$this->'.self::CONSUMER_METHOD.'(');

            $this->assertGreaterThan(
                0,
                $callCount,
                $relative.' 은 노출 통보 소비 메서드를 선언만 하고 **한 번도 부르지 않는다** — '
                .'선언이 그 자리를 채워 위 문자열 대조는 통과하지만, 통보는 아무 데도 도달하지 않는다'
            );
        }
    }

    /**
     * 자식 커맨드가 [DOCROOT_EXPOSURE] 라인으로 통보를 넘기고, 부모 커맨드가 그 라인을 읽어 자기 기록 통로로 되돌리는지 소스로 확인한다.
     *
     * @scenario process_shape=spawn_child, warning_channel=update_record
     *
     * @effects docroot_exposure_warning_reaches_the_operator_from_every_process_that_can_produce_it
     *
     * @return void 반환값 없음
     */
    public function test_spawn_child_hands_the_warning_to_the_parent_and_parent_reads_it(): void
    {
        $child = (string) file_get_contents(
            base_path('app/Console/Commands/Core/ExecuteUpgradeStepsCommand.php')
        );
        $parent = (string) file_get_contents(
            base_path('app/Console/Commands/Core/CoreUpdateCommand.php')
        );

        $this->assertStringContainsString(
            '[DOCROOT_EXPOSURE] ',
            $child,
            '자식이 통보를 부모에게 넘기는 라인 프로토콜을 발행하지 않는다'
        );

        $this->assertStringContainsString(
            "'[DOCROOT_EXPOSURE] '",
            $parent,
            '부모가 자식의 통보 라인을 읽지 않는다 — 자식 통보가 콘솔 중계로만 남고 업데이트 기록에 닿지 않는다'
        );

        $this->assertStringContainsString(
            'recordDocrootExposureWarning(',
            $parent,
            '부모가 받은 통보를 자기 통로(콘솔 + 업데이트 기록)로 되돌리지 않는다'
        );
    }

    /**
     * 부모의 **실패 경로**에도 배수 지점이 있다.
     *
     * 자식이 `[DOCROOT_EXPOSURE]` 라인으로 실어 보낸 통보는 부모의 버퍼에 담기는데, 그
     * 버퍼는 소비자만 비운다. 실패 경로에 배수가 없으면 그 통보는 콘솔·업데이트 기록
     * **양쪽에서** 사라진다 — 접두사를 모르는 구버전 부모가 원시 라인을 그대로 중계하던
     * 것보다 못한 조합이다. 롤백은 `public/storage` 를 다시 일반 디렉토리로 되돌릴 수
     * 있는 바로 그 경로이므로, 통보가 가장 필요한 자리이기도 하다.
     *
     * @scenario parent_path=failure, parent_path=handoff, cleanup_outcome=ok, cleanup_outcome=throws
     *
     * @effects docroot_exposure_warning_reaches_the_operator_from_every_process_that_can_produce_it
     *
     * @return void 반환값 없음
     */
    public function test_parent_drains_the_buffer_on_failure_and_handoff_paths(): void
    {
        $parent = (string) file_get_contents(
            base_path('app/Console/Commands/Core/CoreUpdateCommand.php')
        );

        $handoffAt = strpos($parent, 'catch (UpgradeHandoffException $e) {');
        $failureAt = strpos($parent, 'catch (\Throwable $e) {');

        $this->assertIsInt($handoffAt, '핸드오프 catch 블록을 찾지 못했다 — 이 검사가 아무것도 재지 않는다');
        $this->assertIsInt($failureAt, '실패 catch 블록을 찾지 못했다 — 이 검사가 아무것도 재지 않는다');
        $this->assertGreaterThan($handoffAt, $failureAt, '두 블록의 순서 전제가 어긋났다');

        $handoffRegion = substr($parent, $handoffAt, $failureAt - $handoffAt);
        $failureRegion = substr($parent, $failureAt);

        // 찾는 것은 **호출**이지 선언이 아니다. 접두사 없이 이름만 찾으면 파일 끝의
        // `private function surfaceDocrootExposureWarnings(...)` 선언이 그 자리를 대신
        // 채워, 호출을 통째로 지워도 초록이 된다(이 검사를 만들며 실제로 그 형태였다).
        $call = '$this->'.self::CONSUMER_METHOD.'(';

        $this->assertStringContainsString(
            $call,
            $failureRegion,
            '실패 경로가 노출 통보를 비우지 않는다 — 자식이 보낸 통보가 콘솔·업데이트 기록 양쪽에서 사라진다'
        );

        // 핸드오프 경로의 배수는 **cleanup 성패와 무관**해야 한다. try 안에 있으면 그 앞
        // 단계(버전 갱신·캐시 정리·소유권 복원·링크 복구)가 던지는 순간 통보가 사라진다.
        //
        // 재는 것은 **`finally` 안에 있는가**이지 「catch 키워드보다 뒤에 있는가」가 아니다.
        // 위치만 보면 배수를 `catch (\Throwable $cleanupError)` 블록 **안**으로 옮기는
        // 변이가 그대로 통과하는데, 그 형태는 cleanup 이 성공한 정상 핸드오프(대다수)에서
        // 통보를 통째로 떨군다 — 원 결함보다 나쁘다(실측으로 이 검사가 그 변이를 놓쳤다).
        $cleanupCatchAt = strpos($handoffRegion, 'catch (\Throwable $cleanupError)');
        $finallyAt = strpos($handoffRegion, '} finally {');
        $drainAt = strpos($handoffRegion, $call);

        $this->assertIsInt($cleanupCatchAt, '핸드오프 cleanup catch 를 찾지 못했다');
        $this->assertIsInt(
            $finallyAt,
            '핸드오프 cleanup 에 `finally` 가 없다 — 배수가 어느 분기에 얹혀 cleanup 성패에 좌우된다'
        );
        $this->assertIsInt($drainAt, '핸드오프 경로가 노출 통보를 비우지 않는다');
        $this->assertGreaterThan(
            $cleanupCatchAt,
            $drainAt,
            '핸드오프 배수가 cleanup try 블록 **안**에 있다 — 그 앞 단계가 던지면 통보가 통째로 사라진다'
        );
        $this->assertGreaterThan(
            $finallyAt,
            $drainAt,
            '핸드오프 배수가 `finally` 밖(try 또는 catch 블록 안)에 있다 — cleanup 성패에 따라 통보가 사라진다'
        );

        // **성공 경로에도 배수가 있어야 한다.** 종전에는 실패·핸드오프 둘만 쟀는데, 그
        // 둘은 예외 경로다. 실제로 통보가 가장 많이 만들어지는 자리는 정상 완주다 —
        // in-process 로 돈 업그레이드 스텝과 공개 스토리지 링크 복구가 거기서 통보를
        // 남긴다. 그 배수가 빠지면 「업데이트가 성공했는데 공개 파일이 웹에 열린 채로
        // 남았다」가 아무 데도 나타나지 않는다.
        $exceptionRegionAt = min($handoffAt, $failureAt);
        $successRegion = substr($parent, 0, $exceptionRegionAt);

        $this->assertStringContainsString(
            $call,
            $successRegion,
            '정상 완주 경로가 노출 통보를 비우지 않는다 — 예외 경로 둘만 배선되어 있고, '
            .'통보가 가장 많이 만들어지는 성공 경로에서는 그대로 폐기된다'
        );
    }

    /**
     * 기록한 통보가 빈 문구를 뺀 순서 그대로 한 번만 돌아오고, 소비한 뒤에는 버퍼가 비는지 확인한다.
     *
     * @effects docroot_exposure_warning_buffer_is_consumed_exactly_once
     *
     * @return void 반환값 없음
     */
    public function test_recorded_warning_is_returned_once_and_then_cleared(): void
    {
        StorageLinkHelper::takeDocrootExposureWarnings();

        StorageLinkHelper::recordDocrootExposureWarning('노출 사유 A');
        StorageLinkHelper::recordDocrootExposureWarning('');
        StorageLinkHelper::recordDocrootExposureWarning('노출 사유 B');

        $this->assertSame(
            ['노출 사유 A', '노출 사유 B'],
            StorageLinkHelper::takeDocrootExposureWarnings(),
            '빈 문구가 섞이거나 순서가 어긋난다'
        );

        $this->assertSame(
            [],
            StorageLinkHelper::takeDocrootExposureWarnings(),
            '소비 후에도 버퍼가 비지 않는다 — 같은 통보가 두 번 나간다'
        );
    }
}
