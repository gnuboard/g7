<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftKoreaexim\V1_0_2\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 저장된 환율 갱신 시각(`update_time`)을 스케줄 관리의 정기 갱신 행으로 옮깁니다.
 *
 * 배경:
 *
 * 1.0.0 은 갱신 시각을 플러그인 설정(`update_time`, 한국시간 HH:mm, 기본 11:30)으로 받아 스케줄 등록 때 cron 으로
 * 바꿨습니다. 1.0.2 부터는 코어 스케줄 관리의 `update-rates` 행(cron `30 11 * * *`, 시간대 Asia/Seoul)이
 * 시각의 원천이고 설정 칸은 없습니다.
 *
 *  - 저장값이 기본값(11:30)이거나 키가 없으면 행은 그대로 둔다 (선언 기본과 같다)
 *  - 저장값이 다른 올바른 시각이면 행의 cron 을 `{분} {시} * * *` 로 바꾸고 운영자 수정 표식(`user_overrides`)에
 *    `expression` 을 더한다 — 다음 갱신의 재동기화가 되돌리지 않는다
 *  - 운영자가 이미 스케줄 관리에서 그 행의 cron 을 바꿨으면(표식에 `expression` 있음) 그 값을 존중하고 행을 고치지 않는다
 *  - 저장값이 형식에 맞지 않으면 1.0.0 도 11:30 으로 폴백했으므로 행은 그대로 둔다
 *
 * 옛 키는 행 반영 뒤(또는 반영할 것이 없을 때) 지운다. 행을 찾지 못하면 키를 남기고 경고만 한다 — 같은 스텝을
 * 다시 돌리면 옮길 수 있다.
 *
 * 멱등: 두 번째 실행에서는 키가 없으므로 아무것도 쓰지 않는다. 설정 파일이 없거나 JSON 이 깨졌으면 skip 한다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + DB/Schema 파사드 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음, 스케줄 모델도 쓰지 않는다 (표식 형식은 1.0.2 시점 값으로 동결)
 */
final class MoveUpdateTimeToSchedule implements DataMigration
{
    /** 1.0.0 설정 키 */
    private const LEGACY_KEY = 'update_time';

    /** 1.0.0 기본 갱신 시각 (한국시간, 1.0.0 시점 값 동결) */
    private const LEGACY_DEFAULT = '11:30';

    /** 1.0.2 정기 갱신 스케줄 이름 (plugin.php 선언과 같은 값, 동결) */
    private const SCHEDULE_NAME = 'update-rates';

    /** 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-koreaexim';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'MoveUpdateTimeToSchedule';
    }

    /**
     * 저장된 갱신 시각을 스케줄 행으로 옮기고 옛 설정 키를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin(self::IDENTIFIER, 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-koreaexim] 설정 파일 없음 — 갱신 시각 이관 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-koreaexim] 설정 JSON 형식 비정상 — 갱신 시각 이관 skip');

            return;
        }

        if (! array_key_exists(self::LEGACY_KEY, $settings)) {
            $context->logger->info('[sirsoft-koreaexim] 갱신 시각 설정 키 없음 — 변경 없음');

            return;
        }

        $time = $settings[self::LEGACY_KEY];
        $expression = $this->expressionFromTime($time);

        if ($expression !== null && ! $this->applyToSchedule($expression, $context)) {
            // 행을 못 찾았다 — 키를 남겨 다음 실행에서 옮길 수 있게 한다
            return;
        }

        unset($settings[self::LEGACY_KEY]);
        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-koreaexim] 갱신 시각 설정 키를 지웠습니다', [
            'stored' => $time,
            'moved_expression' => $expression,
        ]);
    }

    /**
     * 1.0.0 저장값을 스케줄 cron 식으로 바꿉니다.
     *
     * @param  mixed  $time  저장값 (한국시간 HH:mm)
     * @return string|null cron 식, 기본값이거나 형식이 틀려 옮길 필요가 없으면 null
     */
    private function expressionFromTime(mixed $time): ?string
    {
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $time, $m) || $time === self::LEGACY_DEFAULT) {
            return null;
        }

        return ((int) $m[2]).' '.((int) $m[1]).' * * *';
    }

    /**
     * 정기 갱신 행의 cron 을 바꾸고 운영자 수정 표식을 남깁니다.
     *
     * @param  string  $expression  옮길 cron 식
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return bool 행을 찾았으면 true (운영자가 이미 바꾼 행이면 고치지 않고 true)
     */
    private function applyToSchedule(string $expression, UpgradeContext $context): bool
    {
        if (! Schema::hasTable('schedules') || ! Schema::hasColumn('schedules', 'user_overrides')) {
            $context->logger->warning('[sirsoft-koreaexim] 스케줄 표가 준비되지 않음 — 갱신 시각 이관 보류(설정 키 유지)');

            return false;
        }

        $row = DB::table('schedules')
            ->where('extension_type', 'plugin')
            ->where('extension_identifier', self::IDENTIFIER)
            ->where('name', self::SCHEDULE_NAME)
            ->first(['id', 'expression', 'user_overrides', 'is_active', 'timezone']);

        if ($row === null) {
            $context->logger->warning('[sirsoft-koreaexim] 정기 갱신 스케줄 행 없음 — 갱신 시각 이관 보류(설정 키 유지)');

            return false;
        }

        $overrides = json_decode((string) ($row->user_overrides ?? ''), true);
        $overrides = is_array($overrides) ? array_values($overrides) : [];

        if (in_array('expression', $overrides, true)) {
            $context->logger->info('[sirsoft-koreaexim] 운영자가 스케줄 관리에서 이미 시각을 바꿈 — 행 유지', [
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

        $context->logger->info('[sirsoft-koreaexim] 저장된 갱신 시각을 스케줄 행으로 옮겼습니다', [
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
