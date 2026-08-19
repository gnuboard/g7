<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftCkeditor5\V1_0_2\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 플러그인 설정 파일에 미사용 이미지 정리 설정 두 키를 백필한다.
 *
 * 배경:
 *
 * `config/settings/defaults.json` 은 플러그인 설치 시점에 저장 설정 파일을 한 번 시드할 뿐,
 * 이후 조회 경로(PluginSettingsService::get)의 폴백 체계에는 포함되지 않는다. 그래서 이미
 * 설치된 사이트에는 새 설정 키가 생기지 않는다.
 *
 * 자동 정리는 기본 꺼짐이어야 하므로 `unusedImageCleanup` 은 false 로 시드한다. 커맨드가
 * `--scheduled` 에서 false 폴백으로 다시 확인하므로 이 백필이 없어도 자동 삭제는 일어나지
 * 않지만, 관리자 설정 화면이 저장값을 그대로 보여주도록 파일에도 명시해 둔다.
 *
 * 멱등: 키가 이미 있으면 값을 덮어쓰지 않는다 (운영자가 켠 설정을 되돌리지 않기 위함).
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class SeedUnusedImageCleanupSettings implements DataMigration
{
    /**
     * 플러그인 설정 저장 경로.
     */
    private const SETTINGS_PATH = 'app/plugins/sirsoft-ckeditor5/settings/setting.json';

    /**
     * 백필할 기본값 (키 => 기본값).
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'unusedImageCleanup' => false,
        'unusedImageRetentionDays' => 30,
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'SeedUnusedImageCleanupSettings';
    }

    /**
     * 설정 파일에 누락된 정리 설정 키를 추가한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        $path = storage_path(self::SETTINGS_PATH);

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-ckeditor5] 설정 파일 없음 — 설치 시 defaults.json 이 시드하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-ckeditor5] 설정 JSON 형식 비정상 — 정리 설정 백필 skip');

            return;
        }

        $added = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $settings)) {
                continue;
            }

            $settings[$key] = $default;
            $added[] = $key;
        }

        if ($added === []) {
            $context->logger->info('[sirsoft-ckeditor5] 정리 설정 키가 이미 존재 — 변경 없음');

            return;
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-ckeditor5] 미사용 이미지 정리 설정 백필 완료', [
            'added_keys' => $added,
        ]);
    }
}
