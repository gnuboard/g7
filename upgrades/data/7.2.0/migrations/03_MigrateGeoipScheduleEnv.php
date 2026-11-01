<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 환경변수 GeoIP 갱신 주기(`GEOIP_SCHEDULE_FREQUENCY`·`GEOIP_SCHEDULE_TIME`)를 GeoIP 갱신 스케줄 행으로 옮깁니다 (7.2.0).
 *
 * 7.1.x 는 `config/geoip.php` 의 `schedule` 블록(위 두 환경변수, 기본 weekly·03:00)으로 `routes/console.php` 에서
 * `geoip:update` 를 등록했다. 7.2.0 은 그 블록을 지우고 관리자 > 스케줄 관리의 코어 행 `geoip-update`(카탈로그 기본
 * `0 3 * * 3`, 사이트 시간대)가 주기를 정한다. 환경변수를 바꿔 둔 사이트만 옮길 것이 있다.
 *
 * 변환(7.1.x `routes/console.php` 의미 그대로 — 동결):
 *  - daily → `{분} {시} * * *` (옛 `->dailyAt()`)
 *  - 그 밖(weekly·알 수 없는 값) → `{분} {시} * * 3` (옛 `->weeklyOn(3, …)` = 수요일)
 *  - 시각 형식이 틀리면 옛 기본 03:00
 *
 * 두 환경변수가 모두 없으면 아무것도 하지 않는다. 결과가 카탈로그 기본(`0 3 * * 3`)과 같으면 행은 그대로 둔다. 다르면 행의
 * cron 을 바꾸고 운영자 수정 표식(`user_overrides` 에 `expression`)을 남긴다. 운영자가 이미 그 행의 cron 을 바꿨으면
 * 존중한다. 환경변수 파일(`.env`)은 고치지 않는다 — 남은 줄은 더 이상 읽히지 않으며, 지워도 된다고 로그로 안내한다.
 * 같은 값이면 다시 돌려도 결과가 같다(멱등 — 두 번째 실행은 표식이 있어 행을 유지).
 *
 * 환경변수는 설정 캐시가 있으면 `env()` 로 읽히지 않으므로 프로세스 환경(getenv)과 `.env` 파일을 직접 읽는다.
 * V-1 안전 격리: File/DB/Schema 파사드만 쓴다.
 */
final class MigrateGeoipScheduleEnv implements DataMigration
{
    /** 7.1.x 환경변수 이름 */
    private const FREQUENCY_ENV = 'GEOIP_SCHEDULE_FREQUENCY';

    private const TIME_ENV = 'GEOIP_SCHEDULE_TIME';

    /** 7.1.x 기본 시각 (동결) */
    private const LEGACY_DEFAULT_TIME = '03:00';

    /** 7.2.0 코어 카탈로그 `geoip-update` 기본 cron (동결) */
    private const CATALOG_EXPRESSION = '0 3 * * 3';

    /** 코어 스케줄 이름 */
    private const SCHEDULE_NAME = 'geoip-update';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MigrateGeoipScheduleEnv';
    }

    /**
     * 환경변수 GeoIP 주기를 스케줄 행으로 옮깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $frequency = $this->readEnv(self::FREQUENCY_ENV);
        $time = $this->readEnv(self::TIME_ENV);

        if ($frequency === null && $time === null) {
            $context->logger->info('[7.2.0] GEOIP_SCHEDULE_* 환경변수 없음 — GeoIP 주기 이관 skip (기본값 사용 중)');

            return;
        }

        $expression = $this->expressionFrom($frequency, $time);

        if ($expression === self::CATALOG_EXPRESSION) {
            $context->logger->info('[7.2.0] GEOIP_SCHEDULE_* 가 기본값과 같음 — 행 유지. 환경변수 줄은 더 이상 쓰이지 않으므로 지워도 됩니다');

            return;
        }

        if ($this->applyToCoreSchedule($expression, $context)) {
            $context->logger->info('[7.2.0] GEOIP_SCHEDULE_* 는 더 이상 쓰이지 않습니다 — 주기는 관리자 > 스케줄 관리에서 바꾸며, 환경변수 줄은 지워도 됩니다', [
                'frequency' => $frequency,
                'time' => $time,
                'expression' => $expression,
            ]);
        }
    }

    /**
     * 7.1.x 환경변수 값을 cron 식으로 바꿉니다 (7.1.x routes/console.php 의미 동결).
     *
     * @param  string|null  $frequency  주기 (daily|weekly)
     * @param  string|null  $time  시각 (HH:mm)
     * @return string cron 식
     */
    private function expressionFrom(?string $frequency, ?string $time): string
    {
        $time = $time !== null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $time) ? $time : self::LEGACY_DEFAULT_TIME;
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $frequency === 'daily' ? "{$minute} {$hour} * * *" : "{$minute} {$hour} * * 3";
    }

    /**
     * 환경변수를 프로세스 환경 → `.env` 파일 순으로 읽습니다.
     *
     * @param  string  $name  환경변수 이름
     * @return string|null 값 (없거나 빈 문자열이면 null)
     */
    private function readEnv(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $path = base_path('.env');
        if (! File::exists($path)) {
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', (string) File::get($path)) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($name, '/').'\s*=\s*(.*)$/', $line, $m)) {
                $raw = trim(preg_replace('/\s+#.*$/', '', $m[1]) ?? '');
                $raw = trim($raw, "\"'");

                return $raw === '' ? null : $raw;
            }
        }

        return null;
    }

    /**
     * 코어 GeoIP 스케줄 행의 cron 을 바꾸고 운영자 수정 표식을 남깁니다.
     *
     * @param  string  $expression  옮길 cron 식
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return bool 행을 찾았으면 true (운영자가 이미 바꾼 행이면 고치지 않고 true)
     */
    private function applyToCoreSchedule(string $expression, UpgradeContext $context): bool
    {
        if (! Schema::hasTable('schedules') || ! Schema::hasColumn('schedules', 'user_overrides')) {
            $context->logger->warning('[7.2.0] 스케줄 표가 준비되지 않음 — GeoIP 주기 이관 보류');

            return false;
        }

        $row = DB::table('schedules')
            ->where('extension_type', 'core')
            ->where('extension_identifier', 'core')
            ->where('name', self::SCHEDULE_NAME)
            ->first(['id', 'expression', 'user_overrides', 'is_active', 'timezone']);

        if ($row === null) {
            $context->logger->warning('[7.2.0] GeoIP 갱신 스케줄 행 없음 — GeoIP 주기 이관 보류. 스텝 00 실패 여부를 확인하세요');

            return false;
        }

        $overrides = json_decode((string) ($row->user_overrides ?? ''), true);
        $overrides = is_array($overrides) ? array_values($overrides) : [];

        if (in_array('expression', $overrides, true)) {
            $context->logger->info('[7.2.0] GeoIP 갱신 스케줄 주기는 이미 바뀌어 있음(운영자 수정 또는 이전 실행의 이관) — 행 유지', [
                'schedule_id' => $row->id,
                'expression' => $row->expression,
            ]);

            return true;
        }

        $overrides[] = 'expression';
        DB::table('schedules')->where('id', $row->id)->update([
            'expression' => $expression,
            'next_run_at' => $this->nextRunAt($expression, $row->timezone, (bool) $row->is_active),
            'user_overrides' => json_encode($overrides),
            'updated_at' => now(),
        ]);

        $context->logger->info('[7.2.0] 환경변수 GeoIP 주기를 스케줄 행으로 옮겼습니다', [
            'schedule_id' => $row->id,
            'from' => $row->expression,
            'to' => $expression,
        ]);

        return true;
    }

    /**
     * 새 cron 식의 다음 실행 시각을 구합니다 — 모델 Schedule::calculateNextRunAt() 와 같은 규칙.
     *
     * 식은 행의 시간대(없으면 사이트 설정 시간대 app.schedule_timezone)로 해석하고 저장 시간대(app.timezone)로
     * 되돌려 저장한다. 비활성 행이나 해석할 수 없는 식이면 null 이다. 업그레이드 스텝은 모델을 거치지 않고
     * DB::table 로 행을 고치므로(이전 버전 클래스 격리) 같은 계산을 여기서 한다 — 빠뜨리면 첫 실행 전까지
     * 목록의 「다음 실행」이 옛 주기 기준 시각으로 남는다.
     *
     * @param  string  $expression  새 cron 식
     * @param  string|null  $timezone  행의 시간대
     * @param  bool  $active  행 활성 여부
     * @return string|null 다음 실행 시각(저장 시간대, Y-m-d H:i:s) 또는 null
     */
    private function nextRunAt(string $expression, ?string $timezone, bool $active): ?string
    {
        if (! $active) {
            return null;
        }

        try {
            $storeTimezone = (string) config('app.timezone', 'UTC');
            $scheduleTimezone = $timezone ?: (string) config('app.schedule_timezone', $storeTimezone);
            $nextRun = (new CronExpression($expression))->getNextRunDate('now', 0, false, $scheduleTimezone);

            return Carbon::instance($nextRun)->setTimezone($storeTimezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
