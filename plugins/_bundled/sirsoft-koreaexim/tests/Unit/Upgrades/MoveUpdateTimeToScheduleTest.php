<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Models\Schedule;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftKoreaexim\V1_0_2\Migrations\MoveUpdateTimeToSchedule;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

/**
 * 1.0.2 업그레이드 스텝 — 갱신 시각 설정을 스케줄 관리의 정기 갱신 행으로 이관
 *
 * 이관이 틀리면 기설치본이 저장해 둔 갱신 시각이 업데이트 직후 11:30 으로 돌아가거나(옮기지 않음), 운영자가
 * 스케줄 관리에서 이미 바꾼 시각을 옛 설정값이 덮는다(표식 무시). 둘 다 오류·로그 없이 갱신 시각만 바뀐다.
 */
class MoveUpdateTimeToScheduleTest extends PluginTestCase
{
    /** @var string 설정 파일 절대 경로 */
    private string $path;

    /**
     * 테스트 환경 준비 — 마이그레이션 적재.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 마이그레이션은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once __DIR__.'/../../../upgrades/data/1.0.2/migrations/01_MoveUpdateTimeToSchedule.php';

        $this->path = ExtensionStoragePath::plugin('sirsoft-koreaexim', 'settings').'/setting.json';
    }

    /**
     * 저장값 × 스케줄 행 상태 전 조합의 이관 결과와 재실행 멱등을 확인합니다.
     *
     * @effects upgrade_moves_custom_time_to_schedule_row, upgrade_keeps_operator_schedule, upgrade_removes_legacy_key, upgrade_keeps_key_when_row_missing, upgrade_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_stored_time_and_schedule_row_matrix(): void
    {
        // [저장값(null=키 없음), 행 상태, 기대 행 cron(null=행 없음), 기대 키 잔존]
        $cases = [
            /** @scenario stored_update_time=absent, schedule_row=present */
            [null, 'present', '30 11 * * *', false],
            /** @scenario stored_update_time=absent, schedule_row=operator_overridden */
            [null, 'operator_overridden', '0 14 * * *', false],
            /** @scenario stored_update_time=absent, schedule_row=missing */
            [null, 'missing', null, false],
            /** @scenario stored_update_time=default, schedule_row=present */
            ['11:30', 'present', '30 11 * * *', false],
            /** @scenario stored_update_time=default, schedule_row=operator_overridden */
            ['11:30', 'operator_overridden', '0 14 * * *', false],
            /** @scenario stored_update_time=default, schedule_row=missing */
            ['11:30', 'missing', null, false],
            /** @scenario stored_update_time=custom, schedule_row=present */
            ['09:05', 'present', '5 9 * * *', false],
            /** @scenario stored_update_time=custom, schedule_row=operator_overridden */
            ['09:05', 'operator_overridden', '0 14 * * *', false],
            /** @scenario stored_update_time=custom, schedule_row=missing */
            ['09:05', 'missing', null, true],
            /** @scenario stored_update_time=invalid, schedule_row=present */
            ['25:99', 'present', '30 11 * * *', false],
            /** @scenario stored_update_time=invalid, schedule_row=operator_overridden */
            ['25:99', 'operator_overridden', '0 14 * * *', false],
            /** @scenario stored_update_time=invalid, schedule_row=missing */
            ['25:99', 'missing', null, false],
        ];

        foreach ($cases as [$stored, $rowState, $expectedExpression, $keyKept]) {
            $context = ($stored ?? 'absent').'/'.$rowState;
            Schedule::query()->where('extension_identifier', 'sirsoft-koreaexim')->delete();
            $row = match ($rowState) {
                'present' => $this->useUpdateSchedule('30 11 * * *'),
                'operator_overridden' => $this->useUpdateSchedule('0 14 * * *'),
                'missing' => null,
            };
            if ($rowState === 'operator_overridden') {
                DB::table('schedules')->where('id', $row->id)->update(['user_overrides' => json_encode(['expression'])]);
            }
            $settings = ['auth_key' => 'kept', 'run_days' => [1, 2, 3]];
            if ($stored !== null) {
                $settings['update_time'] = $stored;
            }
            $this->writeSettings($settings);

            $this->runStep();
            $this->runStep(); // 멱등 — 두 번째 실행은 아무것도 바꾸지 않는다

            $after = $this->readSettings();
            $this->assertSame($keyKept, array_key_exists('update_time', $after), $context);
            $this->assertSame(['kept', [1, 2, 3]], [$after['auth_key'], $after['run_days']], $context);
            if ($expectedExpression === null) {
                $this->assertSame(0, Schedule::query()->where('extension_identifier', 'sirsoft-koreaexim')->count(), $context);

                continue;
            }
            $fresh = DB::table('schedules')->where('id', $row->id)->first();
            $this->assertSame($expectedExpression, $fresh->expression, $context);
            $overrides = json_decode((string) $fresh->user_overrides, true) ?? [];
            $this->assertSame($rowState === 'operator_overridden' || ($stored === '09:05'), in_array('expression', $overrides, true), $context);
            $this->assertSame(count($overrides), count(array_unique($overrides)), $context);
            if ($rowState === 'present' && $stored === '09:05') {
                // 갱신 시각을 옮긴 행은 「다음 실행」도 새 시각으로 다시 계산돼야 한다
                $this->assertNextRunFollowsExpression($row->id, $context);
            }
        }
    }

    /**
     * 옮긴 행의 다음 실행 시각이 새 cron 기준인지 단언합니다 — 모델 calculateNextRunAt() 와 같은 규칙
     * (행 시간대, 없으면 app.schedule_timezone 으로 해석해 저장 시간대로 되돌린다. 비활성 행은 null).
     *
     * @param  int  $id  스케줄 행 ID
     * @param  string  $context  실패 메시지 문맥
     * @return void 반환값 없음
     */
    private function assertNextRunFollowsExpression(int $id, string $context): void
    {
        $fresh = DB::table('schedules')->where('id', $id)->first();
        if (! $fresh->is_active) {
            $this->assertNull($fresh->next_run_at, $context.' next_run_at(비활성)');

            return;
        }
        $store = (string) config('app.timezone', 'UTC');
        $tz = $fresh->timezone ?: (string) config('app.schedule_timezone', $store);
        // 스텝이 계산한 순간과 지금 사이에 정각을 넘으면 기대값이 한 칸 밀리므로 60초 전 기준값도 정답으로 본다.
        $cron = new CronExpression($fresh->expression);
        $expected = array_unique(array_map(
            fn (string $base) => Carbon::instance($cron->getNextRunDate($base, 0, false, $tz))->setTimezone($store)->format('Y-m-d H:i:s'),
            ['-60 seconds', 'now'],
        ));
        $this->assertContains(Carbon::parse($fresh->next_run_at, $store)->format('Y-m-d H:i:s'), $expected, $context.' next_run_at');
    }

    /**
     * 설정 파일이 없거나 JSON 이 깨졌으면 건너뛰고 스케줄 행도 건드리지 않는지 확인합니다.
     *
     * @effects upgrade_skips_missing_or_broken_settings_file
     *
     * @return void 반환값 없음
     */
    public function test_skips_missing_or_broken_settings_file(): void
    {
        $row = $this->useUpdateSchedule('30 11 * * *');
        if (File::exists($this->path)) {
            File::delete($this->path);
        }
        $this->runStep();
        $this->assertFalse(File::exists($this->path));

        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{broken');
        $this->runStep();
        $this->assertSame('{broken', File::get($this->path));
        $this->assertSame('30 11 * * *', DB::table('schedules')->where('id', $row->id)->value('expression'));
    }

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new MoveUpdateTimeToSchedule)->run(
            new UpgradeContext(
                fromVersion: '1.0.0',
                toVersion: '1.0.2',
                currentStep: '1.0.2',
                logChannel: 'extension-upgrade',
            )
        );
    }

    /**
     * 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  설정 내용
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 설정 파일을 읽습니다.
     *
     * @return array<string, mixed> 설정 내용
     */
    private function readSettings(): array
    {
        return json_decode(File::get($this->path), true);
    }
}
