<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * SEO 설정의 사이트맵 생성 주기·시각을 사이트맵 생성 스케줄 행으로 옮깁니다 (7.2.0).
 *
 * 7.1.x 는 `seo.sitemap_schedule`(hourly|daily|weekly)·`seo.sitemap_schedule_time`(HH:mm)을 읽어 `routes/console.php`
 * 에서 사이트맵 Job 을 등록했다. 7.2.0 은 그 칸을 SEO 설정에서 없애고 관리자 > 스케줄 관리의 코어 행
 * `sitemap-generate`(카탈로그 기본 `0 2 * * *`, 사이트 시간대)가 주기를 정한다.
 *
 * 변환(7.1.x `routes/console.php` 의미 그대로 — 동결):
 *  - hourly → `0 * * * *` (시각 무시 — 옛 `->hourly()`)
 *  - weekly → `{분} {시} * * 0` (옛 `->weekly()->at()` = 일요일)
 *  - 그 밖(daily·알 수 없는 값) → `{분} {시} * * *` (옛 `match` 의 default)
 *  - 시각이 형식에 맞지 않으면 옛 기본 02:00
 *
 * 결과가 카탈로그 기본(`0 2 * * *`)과 같으면 행은 그대로 둔다. 다르면 행의 cron 을 바꾸고 운영자 수정 표식
 * (`user_overrides` 에 `expression`)을 남겨 다음 재동기화가 되돌리지 않게 한다. 운영자가 이미 스케줄 관리에서 그 행의
 * cron 을 바꿨으면(표식 있음) 그 값을 존중한다. 옮긴 뒤(또는 옮길 것이 없을 때) 두 설정 키를 지운다. 행이 없으면
 * 키를 남기고 경고만 한다 — 같은 스텝을 다시 돌리면 옮길 수 있다.
 *
 * `seo.sitemap_enabled`(사이트맵 켜기)는 그대로 둔다 — 행의 실행 조건이 그 값을 읽는다.
 * 멱등: 두 번째 실행에서는 키가 없으므로 아무것도 쓰지 않는다. `seo.json` 이 없거나 깨졌으면 skip.
 *
 * V-1 안전 격리: Storage/DB/Schema 파사드만 쓴다. 서비스·모델을 해석하지 않고, 표식 형식·카탈로그 기본값은 7.2.0 값으로 동결한다.
 */
final class MigrateSitemapScheduleSetting implements DataMigration
{
    /** 설정 카테고리 파일 (settings 디스크 기준) */
    private const SEO_SETTINGS_FILE = 'seo.json';

    /** 7.1.x 설정 키 */
    private const FREQUENCY_KEY = 'sitemap_schedule';

    private const TIME_KEY = 'sitemap_schedule_time';

    /** 7.1.x 기본 시각 (동결) */
    private const LEGACY_DEFAULT_TIME = '02:00';

    /** 7.2.0 코어 카탈로그 `sitemap-generate` 기본 cron (동결) */
    private const CATALOG_EXPRESSION = '0 2 * * *';

    /** 코어 스케줄 이름 */
    private const SCHEDULE_NAME = 'sitemap-generate';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MigrateSitemapScheduleSetting';
    }

    /**
     * 저장된 사이트맵 주기를 스케줄 행으로 옮기고 설정 키를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $disk = Storage::disk('settings');
        if (! $disk->exists(self::SEO_SETTINGS_FILE)) {
            $context->logger->info('[7.2.0] seo.json 없음 — 사이트맵 주기 이관 skip (기본값 사용 중)');

            return;
        }

        $settings = json_decode((string) $disk->get(self::SEO_SETTINGS_FILE), true);
        if (! is_array($settings)) {
            $context->logger->warning('[7.2.0] seo.json 형식 비정상 — 사이트맵 주기 이관 skip');

            return;
        }

        if (! array_key_exists(self::FREQUENCY_KEY, $settings) && ! array_key_exists(self::TIME_KEY, $settings)) {
            $context->logger->info('[7.2.0] 사이트맵 주기 설정 키 없음 — 변경 없음');

            return;
        }

        $expression = $this->expressionFrom($settings[self::FREQUENCY_KEY] ?? null, $settings[self::TIME_KEY] ?? null);

        if ($expression !== self::CATALOG_EXPRESSION && ! $this->applyToCoreSchedule($expression, $context)) {
            return;
        }

        unset($settings[self::FREQUENCY_KEY], $settings[self::TIME_KEY]);
        $disk->put(self::SEO_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info('[7.2.0] SEO 사이트맵 주기 설정 키를 지웠습니다 (스케줄 관리로 이동)', [
            'expression' => $expression,
            'moved' => $expression !== self::CATALOG_EXPRESSION,
        ]);
    }

    /**
     * 7.1.x 저장값을 cron 식으로 바꿉니다 (7.1.x routes/console.php 의미 동결).
     *
     * @param  mixed  $frequency  저장된 주기
     * @param  mixed  $time  저장된 시각 (HH:mm)
     * @return string cron 식
     */
    private function expressionFrom(mixed $frequency, mixed $time): string
    {
        if ($frequency === 'hourly') {
            return '0 * * * *';
        }

        $time = is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) ? $time : self::LEGACY_DEFAULT_TIME;
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $frequency === 'weekly' ? "{$minute} {$hour} * * 0" : "{$minute} {$hour} * * *";
    }

    /**
     * 코어 사이트맵 스케줄 행의 cron 을 바꾸고 운영자 수정 표식을 남깁니다.
     *
     * @param  string  $expression  옮길 cron 식
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return bool 행을 찾았으면 true (운영자가 이미 바꾼 행이면 고치지 않고 true)
     */
    private function applyToCoreSchedule(string $expression, UpgradeContext $context): bool
    {
        if (! Schema::hasTable('schedules') || ! Schema::hasColumn('schedules', 'user_overrides')) {
            $context->logger->warning('[7.2.0] 스케줄 표가 준비되지 않음 — 사이트맵 주기 이관 보류(설정 키 유지)');

            return false;
        }

        $row = DB::table('schedules')
            ->where('extension_type', 'core')
            ->where('extension_identifier', 'core')
            ->where('name', self::SCHEDULE_NAME)
            ->first(['id', 'expression', 'user_overrides', 'is_active', 'timezone']);

        if ($row === null) {
            $context->logger->warning('[7.2.0] 사이트맵 생성 스케줄 행 없음 — 사이트맵 주기 이관 보류(설정 키 유지). 스텝 00 실패 여부를 확인하세요');

            return false;
        }

        $overrides = json_decode((string) ($row->user_overrides ?? ''), true);
        $overrides = is_array($overrides) ? array_values($overrides) : [];

        if (in_array('expression', $overrides, true)) {
            $context->logger->info('[7.2.0] 사이트맵 스케줄 주기는 이미 바뀌어 있음(운영자 수정 또는 이전 실행의 이관) — 행 유지', [
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

        $context->logger->info('[7.2.0] 저장된 사이트맵 주기를 스케줄 행으로 옮겼습니다', [
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
