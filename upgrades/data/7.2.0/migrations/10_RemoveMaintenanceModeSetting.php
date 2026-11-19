<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Storage;

/**
 * 저장된 일반 설정에서 읽히지 않던 점검 모드 키(`general.maintenance_mode`)를 지웁니다 (7.2.0).
 *
 * 7.1.x 까지 일반 설정 기본값에 `maintenance_mode` 가 있었지만, 그 값을 읽어 사이트를 막는 코드는 없었다 — 점검 모드는
 * 처음부터 `php artisan down` / `php artisan up` 이 맡았다. 7.2.0 은 기본값·저장 검증에서 키를 없앤다. 저장 파일에 남은 값은
 * 아무 일도 하지 않지만, 설정 백업·내보내기에 남아 「켜 두면 점검 모드가 된다」고 오해하게 하므로 지운다.
 *
 * 하는 일: `general.json` 이 JSON 객체이고 `maintenance_mode` 키가 있을 때만 그 키 하나를 지운다. 파일의 다른 키는 그대로 둔다.
 *
 * 아무것도 하지 않는 경우(모두 로그만 남긴다):
 *  - `general.json` 이 없다(기본값을 쓰는 사이트)
 *  - 파일이 JSON 객체가 아니다
 *  - 키가 없다
 *
 * 멱등: 두 번째 실행에서는 키가 없어 파일을 쓰지 않는다.
 *
 * V-1 안전 격리: Storage 파사드만 쓴다. 저장 형식(들여쓰기·유니코드 그대로)은 7.2.0 설정 저장소와 같게 동결한다.
 * 확장 설정용 `SettingsMigrator` 는 모듈·플러그인 설정 디렉토리만 다루므로 코어 설정 파일에는 쓰지 않는다.
 */
final class RemoveMaintenanceModeSetting implements DataMigration
{
    /** 설정 범주 파일 (settings 디스크 기준) */
    private const GENERAL_SETTINGS_FILE = 'general.json';

    /** 지울 키 */
    private const REMOVED_KEY = 'maintenance_mode';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RemoveMaintenanceModeSetting';
    }

    /**
     * 저장된 일반 설정에서 점검 모드 키를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $disk = Storage::disk('settings');
        if (! $disk->exists(self::GENERAL_SETTINGS_FILE)) {
            $context->logger->info('[7.2.0] general.json 없음 — 점검 모드 설정 키 정리 skip (기본값 사용 중)');

            return;
        }

        $settings = json_decode((string) $disk->get(self::GENERAL_SETTINGS_FILE), true);
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            $context->logger->warning('[7.2.0] general.json 형식 비정상 — 점검 모드 설정 키 정리 skip');

            return;
        }

        if (! array_key_exists(self::REMOVED_KEY, $settings)) {
            $context->logger->info('[7.2.0] 점검 모드 설정 키 없음 — 변경 없음');

            return;
        }

        $previous = $settings[self::REMOVED_KEY];
        unset($settings[self::REMOVED_KEY]);
        $disk->put(self::GENERAL_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $context->logger->info('[7.2.0] 읽히지 않던 점검 모드 설정 키를 지웠습니다 (점검 모드는 artisan down/up 이 맡는다)', [
            'previous' => $previous,
        ]);
    }
}
