<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_4\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 저장된 SEO 제목 틀이 옛 기본값이면 쇼핑몰명을 뺀 새 기본값으로 바꿉니다.
 *
 * 코어 7.1.5 는 검색엔진 제목과 방문자 브라우저 탭 제목을 한 규칙으로 정하고, 사이트 이름은 코어 「타이틀 접미사」
 * (비면 ` - {사이트 이름}`)로만 붙인다. 옛 기본 틀 `{commerce_name} - {상품명}` 이 그대로면 「쇼핑몰명 - 상품명 - 사이트 이름」
 * 이 된다. 그래서 1.3.4 의 기본 틀은 쇼핑몰명을 빼고(`{product_name}` 등) 화면 정보만 둔다.
 *
 * 운영자가 저장한 값(`settings/seo.json`)이 **옛 기본값과 글자 그대로 같을 때만** 바꾼다. 운영자가 고친 틀은 그대로 둔다.
 * 키가 없으면(저장한 적 없음) 새 기본값(defaults.json)이 그대로 쓰이므로 손대지 않는다.
 *
 * 1.3.4 시점 판정을 이 파일에 동결한다(버전별 데이터 스냅샷 규약 — 모듈 모델·서비스를 부르지 않는다).
 *
 * idempotent: 이미 바뀌었거나 다른 값이면 no-op. V-1 안전: Illuminate\Support\Facades\File + 로컬 헬퍼만 사용.
 */
class DropShopNameFromSeoTitleTemplates implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /** @var array<string, array{0: string, 1: string}> 설정 키 => [옛 기본값, 새 기본값] */
    private const REPLACEMENTS = [
        'meta_category_title' => ['{commerce_name} - {category_name}', '{category_name}'],
        'meta_search_title' => ['{commerce_name} - {keyword_name}', '{keyword_name}'],
        'meta_product_title' => ['{commerce_name} - {product_name}', '{product_name}'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'DropShopNameFromSeoTitleTemplates';
    }

    /**
     * 옛 기본값 그대로인 제목 틀만 새 기본값으로 바꿉니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.3.4] seo.json 미존재 — 제목 틀 기본값 전환 스킵(새 기본값 적용)');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings)) {
            $context->logger->warning('[ecommerce:1.3.4] seo.json 파싱 불가 — 제목 틀 기본값 전환 스킵');

            return;
        }

        $changed = [];
        foreach (self::REPLACEMENTS as $key => [$old, $new]) {
            if (($settings[$key] ?? null) === $old) {
                $settings[$key] = $new;
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            $context->logger->info('[ecommerce:1.3.4] SEO 제목 틀이 옛 기본값이 아님 — 운영자 값 유지(전환 없음)');

            return;
        }

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // sudo 코어 업데이트 경로에서 root 로 실행되면 새로 만든 파일이 root 소유로 남는다 — 부모 소유권 상속 (#651)
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[ecommerce:1.3.4] 옛 기본값 그대로인 SEO 제목 틀에서 쇼핑몰명 제거: '.implode(', ', $changed));
    }

    /**
     * seo.json 의 운영 저장 경로를 반환합니다.
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        return ExtensionStoragePath::module(self::MODULE_IDENTIFIER, 'settings').'/seo.json';
    }
}
