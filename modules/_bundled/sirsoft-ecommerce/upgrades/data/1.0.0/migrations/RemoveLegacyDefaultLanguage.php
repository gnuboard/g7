<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 구 기본 언어 설정 키(language_currency.default_language)를 정리. (A1-⑤, D-LANG)
 *
 * 모듈 default_language 는 읽는 코드가 0(orphan)이며 사이트 언어는 코어 일반설정으로 일원화한다.
 * 기존 환경의 storage settings JSON 에 남아있는 default_language 키를 제거한다(런타임 무해하나
 * 스키마 정합). idempotent: 키가 없으면 no-op.
 *
 * V-1 안전: Illuminate\Support\Facades\File + 로컬 헬퍼만 사용.
 */
class RemoveLegacyDefaultLanguage implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    public function name(): string
    {
        return 'RemoveLegacyDefaultLanguage';
    }

    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.0.0] language_currency.json 미존재 — default_language 정리 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings)) {
            $context->logger->warning('[ecommerce:1.0.0] language_currency.json 파싱 불가 — default_language 정리 스킵');

            return;
        }

        if (! array_key_exists('default_language', $settings)) {
            // 이미 정리됨 (idempotent)
            return;
        }

        unset($settings['default_language']);

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info('[ecommerce:1.0.0] 구 default_language 키 정리 완료 (코어 언어 일원화)');
    }

    /**
     * language_currency.json 의 운영 저장 경로를 반환합니다.
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        return storage_path('app/modules/'.self::MODULE_IDENTIFIER.'/settings/language_currency.json');
    }
}
