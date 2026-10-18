<?php

namespace Tests\Feature\Upgrades;

use App\Enums\ExtensionOwnerType;
use App\Extension\UpgradeContext;
use App\Jobs\GenerateSitemapJob;
use App\Models\Schedule;
use App\Upgrades\Data\V7_2_0\Migrations\MigrateGeoipScheduleEnv;
use App\Upgrades\Data\V7_2_0\Migrations\MigrateSitemapScheduleSetting;
use Cron\CronExpression;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 7.2.0 업그레이드 — 설정·환경변수에 있던 예약 주기를 코어 스케줄 행으로 이관 (D10)
 *
 * 7.1.x 는 사이트맵 주기를 SEO 설정(`seo.sitemap_schedule(_time)`), GeoIP 갱신 주기를 환경변수(`GEOIP_SCHEDULE_*`)로
 * 받았다. 7.2.0 은 스케줄 관리 행이 주기를 정하므로, 이관이 틀리면 운영자가 바꿔 둔 주기가 업데이트 직후 기본값으로
 * 돌아가거나(옮기지 않음), 스케줄 관리에서 이미 바꾼 값을 옛 설정이 덮는다(표식 무시). 둘 다 오류 없이 실행 시각만 바뀐다.
 */
class Upgrade720ScheduleSettingMigrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var string 이 시험 전용 settings 디스크 루트 */
    private string $settingsRoot;

    /**
     * 테스트 환경 준비 — 데이터 스텝 적재.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 데이터 스텝은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once base_path('upgrades/data/7.2.0/migrations/02_MigrateSitemapScheduleSetting.php');
        require_once base_path('upgrades/data/7.2.0/migrations/03_MigrateGeoipScheduleEnv.php');

        // 공용 가짜 디스크 경로(storage/framework/testing/disks/settings)는 다른 PHPUnit 프로세스의 Storage::fake() 가
        // 통째로 지운다 — 이 시험만의 루트로 settings 디스크를 바꿔 끼운다.
        $this->settingsRoot = storage_path('framework/testing/upgrade720-'.uniqid());
        File::ensureDirectoryExists($this->settingsRoot);
        Storage::set('settings', Storage::build(['driver' => 'local', 'root' => $this->settingsRoot]));
    }

    /**
     * 테스트가 넣은 프로세스 환경변수를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        putenv('GEOIP_SCHEDULE_FREQUENCY');
        putenv('GEOIP_SCHEDULE_TIME');
        File::deleteDirectory($this->settingsRoot);

        parent::tearDown();
    }

    /**
     * 사이트맵 저장값 × 행 상태 전 조합의 이관 결과·설정 키 정리·재실행 멱등을 확인합니다.
     *
     * @effects sitemap_custom_period_moves_to_row, operator_schedule_is_kept, legacy_seo_keys_removed, keys_kept_when_row_missing, migration_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_setting_matrix(): void
    {
        // [저장 주기, 저장 시각(null=키 없음), 행 상태, 기대 cron(null=행 없음), 기대 키 잔존, 옮김(표식 추가)]
        $cases = [
            /** @scenario surface=sitemap_setting, stored=absent, schedule_row=present */
            ['absent', null, 'present', '0 2 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=absent, schedule_row=operator_overridden */
            ['absent', null, 'operator_overridden', '15 6 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=absent, schedule_row=missing */
            ['absent', null, 'missing', null, false, false],
            /** @scenario surface=sitemap_setting, stored=default, schedule_row=present */
            ['daily', '02:00', 'present', '0 2 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=default, schedule_row=operator_overridden */
            ['daily', '02:00', 'operator_overridden', '15 6 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=default, schedule_row=missing */
            ['daily', '02:00', 'missing', null, false, false],
            /** @scenario surface=sitemap_setting, stored=hourly, schedule_row=present */
            ['hourly', '05:00', 'present', '0 * * * *', false, true],
            /** @scenario surface=sitemap_setting, stored=hourly, schedule_row=operator_overridden */
            ['hourly', '05:00', 'operator_overridden', '15 6 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=hourly, schedule_row=missing */
            ['hourly', '05:00', 'missing', null, true, false],
            /** @scenario surface=sitemap_setting, stored=daily_custom, schedule_row=present */
            ['daily', '04:30', 'present', '30 4 * * *', false, true],
            /** @scenario surface=sitemap_setting, stored=daily_custom, schedule_row=operator_overridden */
            ['daily', '04:30', 'operator_overridden', '15 6 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=daily_custom, schedule_row=missing */
            ['daily', '04:30', 'missing', null, true, false],
            /** @scenario surface=sitemap_setting, stored=weekly_custom, schedule_row=present */
            ['weekly', '01:15', 'present', '15 1 * * 0', false, true],
            /** @scenario surface=sitemap_setting, stored=weekly_custom, schedule_row=operator_overridden */
            ['weekly', '01:15', 'operator_overridden', '15 6 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=weekly_custom, schedule_row=missing */
            ['weekly', '01:15', 'missing', null, true, false],
            /** @scenario surface=sitemap_setting, stored=invalid_time, schedule_row=present */
            ['daily', '25:00', 'present', '0 2 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=invalid_time, schedule_row=operator_overridden */
            ['daily', '25:00', 'operator_overridden', '15 6 * * *', false, false],
            /** @scenario surface=sitemap_setting, stored=invalid_time, schedule_row=missing */
            ['daily', '25:00', 'missing', null, false, false],
        ];

        foreach ($cases as [$frequency, $time, $rowState, $expected, $keysKept, $moved]) {
            $context = "{$frequency}/".($time ?? '-')."/{$rowState}";
            $row = $this->coreRow('sitemap-generate', '0 2 * * *', '15 6 * * *', $rowState);
            $seo = ['sitemap_enabled' => true, 'other' => 'kept'];
            if ($frequency !== 'absent') {
                $seo['sitemap_schedule'] = $frequency;
                $seo['sitemap_schedule_time'] = $time;
            }
            Storage::disk('settings')->put('seo.json', json_encode($seo));

            $this->runStep(new MigrateSitemapScheduleSetting);
            $this->runStep(new MigrateSitemapScheduleSetting); // 멱등

            $after = json_decode(Storage::disk('settings')->get('seo.json'), true);
            $this->assertSame($keysKept, array_key_exists('sitemap_schedule', $after), $context);
            $this->assertSame($keysKept, array_key_exists('sitemap_schedule_time', $after), $context);
            $this->assertSame([true, 'kept'], [$after['sitemap_enabled'], $after['other']], $context);
            $this->assertRow($row, $expected, $rowState === 'operator_overridden' || $moved, $context);
            if ($moved) {
                // 주기를 옮긴 행은 「다음 실행」도 새 주기로 다시 계산돼야 한다 — 첫 실행 전까지 옛 시각이 보이면 안 된다
                $this->assertNextRunFollowsExpression($row->id, $context);
            }
        }
    }

    /**
     * 비활성 행으로 주기를 옮기면 다음 실행 시각은 비운다(모델 규칙과 같음).
     *
     * @scenario surface=sitemap_setting, stored=hourly, schedule_row=present
     *
     * @effects sitemap_custom_period_moves_to_row
     *
     * @return void 반환값 없음
     */
    public function test_moved_period_on_inactive_row_clears_next_run(): void
    {
        $row = $this->coreRow('sitemap-generate', '0 2 * * *', '15 6 * * *', 'present');
        DB::table('schedules')->where('id', $row->id)->update(['is_active' => false, 'next_run_at' => now()->subYears(5)]);
        Storage::disk('settings')->put('seo.json', json_encode(['sitemap_schedule' => 'hourly', 'sitemap_schedule_time' => '05:00']));

        $this->runStep(new MigrateSitemapScheduleSetting);

        $this->assertSame('0 * * * *', DB::table('schedules')->where('id', $row->id)->value('expression'));
        $this->assertNextRunFollowsExpression($row->id, 'inactive');
    }

    /**
     * GeoIP 환경변수 × 행 상태 전 조합의 이관 결과와 재실행 멱등을 확인합니다.
     *
     * @effects geoip_env_period_moves_to_row, operator_schedule_is_kept, migration_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_geoip_env_matrix(): void
    {
        // [주기 env(null=없음), 시각 env(null=없음), 행 상태, 기대 cron(null=행 없음), 옮김(표식 추가)]
        $cases = [
            /** @scenario surface=geoip_env, stored=absent, schedule_row=present */
            [null, null, 'present', '0 3 * * 3', false],
            /** @scenario surface=geoip_env, stored=absent, schedule_row=operator_overridden */
            [null, null, 'operator_overridden', '45 23 * * 1', false],
            /** @scenario surface=geoip_env, stored=absent, schedule_row=missing */
            [null, null, 'missing', null, false],
            /** @scenario surface=geoip_env, stored=default, schedule_row=present */
            ['weekly', '03:00', 'present', '0 3 * * 3', false],
            /** @scenario surface=geoip_env, stored=default, schedule_row=operator_overridden */
            ['weekly', '03:00', 'operator_overridden', '45 23 * * 1', false],
            /** @scenario surface=geoip_env, stored=default, schedule_row=missing */
            ['weekly', '03:00', 'missing', null, false],
            /** @scenario surface=geoip_env, stored=daily_custom, schedule_row=present */
            ['daily', '04:00', 'present', '0 4 * * *', true],
            /** @scenario surface=geoip_env, stored=daily_custom, schedule_row=operator_overridden */
            ['daily', '04:00', 'operator_overridden', '45 23 * * 1', false],
            /** @scenario surface=geoip_env, stored=daily_custom, schedule_row=missing */
            ['daily', '04:00', 'missing', null, false],
            /** @scenario surface=geoip_env, stored=weekly_custom, schedule_row=present */
            ['weekly', '05:30', 'present', '30 5 * * 3', true],
            /** @scenario surface=geoip_env, stored=weekly_custom, schedule_row=operator_overridden */
            ['weekly', '05:30', 'operator_overridden', '45 23 * * 1', false],
            /** @scenario surface=geoip_env, stored=weekly_custom, schedule_row=missing */
            ['weekly', '05:30', 'missing', null, false],
            /** @scenario surface=geoip_env, stored=invalid_time, schedule_row=present */
            ['daily', '99', 'present', '0 3 * * *', true],
            /** @scenario surface=geoip_env, stored=invalid_time, schedule_row=operator_overridden */
            ['daily', '99', 'operator_overridden', '45 23 * * 1', false],
            /** @scenario surface=geoip_env, stored=invalid_time, schedule_row=missing */
            ['daily', '99', 'missing', null, false],
        ];

        foreach ($cases as [$frequency, $time, $rowState, $expected, $moved]) {
            $context = ($frequency ?? '-').'/'.($time ?? '-')."/{$rowState}";
            $row = $this->coreRow('geoip-update', '0 3 * * 3', '45 23 * * 1', $rowState);
            putenv($frequency === null ? 'GEOIP_SCHEDULE_FREQUENCY' : "GEOIP_SCHEDULE_FREQUENCY={$frequency}");
            putenv($time === null ? 'GEOIP_SCHEDULE_TIME' : "GEOIP_SCHEDULE_TIME={$time}");

            $this->runStep(new MigrateGeoipScheduleEnv);
            $this->runStep(new MigrateGeoipScheduleEnv); // 멱등 — 두 번째는 표식이 있어 행 유지

            $this->assertRow($row, $expected, $rowState === 'operator_overridden' || $moved, $context);
            if ($moved) {
                $this->assertNextRunFollowsExpression($row->id, $context);
            }
        }
    }

    /**
     * 코어 소유 스케줄 행을 상태대로 준비합니다 (시더가 만든 같은 이름 행은 먼저 지운다).
     *
     * @param  string  $name  코어 스케줄 이름
     * @param  string  $catalogExpression  카탈로그 기본 cron
     * @param  string  $operatorExpression  운영자가 바꾼 cron (operator_overridden 일 때)
     * @param  string  $state  행 상태 (present·operator_overridden·missing)
     * @return Schedule|null 준비한 행 (missing 이면 null)
     */
    private function coreRow(string $name, string $catalogExpression, string $operatorExpression, string $state): ?Schedule
    {
        DB::table('schedules')->where('extension_type', 'core')->where('extension_identifier', 'core')->where('name', $name)->delete();
        if ($state === 'missing') {
            return null;
        }

        $row = Schedule::factory()->ownedBy(ExtensionOwnerType::Core, 'core')->create([
            'name' => $name,
            'type' => $name === 'sitemap-generate' ? 'job' : 'artisan',
            'command' => $name === 'sitemap-generate' ? GenerateSitemapJob::class : 'geoip:update',
            'expression' => $state === 'operator_overridden' ? $operatorExpression : $catalogExpression,
        ]);
        if ($state === 'operator_overridden') {
            DB::table('schedules')->where('id', $row->id)->update(['user_overrides' => json_encode(['expression'])]);
        }

        return $row;
    }

    /**
     * 행의 cron·운영자 수정 표식을 단언합니다.
     *
     * @param  Schedule|null  $row  준비한 행
     * @param  string|null  $expected  기대 cron (null 이면 행이 없어야 한다)
     * @param  bool  $marked  `expression` 표식이 있어야 하는지
     * @param  string  $context  실패 메시지 문맥
     * @return void 반환값 없음
     */
    private function assertRow(?Schedule $row, ?string $expected, bool $marked, string $context): void
    {
        if ($expected === null) {
            $this->assertNull($row, $context);

            return;
        }

        $fresh = DB::table('schedules')->where('id', $row->id)->first();
        $this->assertSame($expected, $fresh->expression, $context);
        $overrides = json_decode((string) $fresh->user_overrides, true) ?? [];
        $this->assertSame($marked, in_array('expression', $overrides, true), $context);
        $this->assertSame(count($overrides), count(array_unique($overrides)), $context);
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
     * 데이터 스텝을 실행합니다.
     *
     * @param  object  $step  데이터 스텝 인스턴스
     * @return void 반환값 없음
     */
    private function runStep(object $step): void
    {
        $step->run(new UpgradeContext(
            fromVersion: '7.1.1',
            toVersion: '7.2.0',
            currentStep: '7.2.0',
            logChannel: 'upgrade',
        ));
    }
}
