<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_0_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 네이버페이 전용 브랜드 버튼 설정 키를 KG 이니시스 간편결제 공통 브랜드 버튼 설정 키로 이관한다.
 *
 * 구 키(`easy_pay_naverpay_brand_button`)의 사용자 설정값을 신 키
 * (`easy_pay_show_brand_button`)로 보존 이동한다. 신 키가 이미 있으면 덮어쓰지 않는다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md):
 *   - 파일 시스템 + FilePermissionHelper 만 사용 (이전 버전에도 존재하던 표면)
 */
final class MigrateNaverpayBrandButtonKey implements DataMigration
{
    private const OLD_KEY = 'easy_pay_naverpay_brand_button';

    private const NEW_KEY = 'easy_pay_show_brand_button';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'MigrateNaverpayBrandButtonKey';
    }

    /**
     * 네이버페이 브랜드 버튼 설정 키를 공통 키로 이관한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        // 설정 파일 저장 경로. 절대 경로는 코어 해석기가 디스크 root 를 기준으로 조립한다 — 확장마다
        // 경로를 직접 조립하면 테스트 환경에서 운영 설정 파일을 그대로 건드리게 된다.
        $path = ExtensionStoragePath::plugin('sirsoft-pay_kginicis', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[v1.0.0] KG 이니시스 설정 파일 없음 — 기본값으로 동작하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);
        if (! is_array($settings)) {
            $context->logger->warning('[v1.0.0] KG 이니시스 설정 JSON 형식 비정상 — 브랜드 버튼 키 이관 skip');

            return;
        }

        if (! array_key_exists(self::OLD_KEY, $settings)) {
            $context->logger->info('[v1.0.0] 기존 네이버페이 브랜드 버튼 설정 키 없음 — 변경 없음');

            return;
        }

        $oldValue = $settings[self::OLD_KEY];
        unset($settings[self::OLD_KEY]);

        $copied = false;
        if (! array_key_exists(self::NEW_KEY, $settings)) {
            $settings[self::NEW_KEY] = $oldValue;
            $copied = true;
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[v1.0.0] 브랜드 버튼 설정 키 이관 완료', [
            'from' => self::OLD_KEY,
            'to' => self::NEW_KEY,
            'copied' => $copied,
        ]);
    }
}
