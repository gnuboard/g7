<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_0_4\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 설정 파일에 필수 저장 항목 허용목록을 백필합니다.
 *
 * 배경:
 *
 * 이 버전에서 허용목록이 코드 상수에서 운영자 설정(`necessary_storage_allowlist`)으로
 * 옮겨졌습니다. `config/settings/defaults.json` 은 설치 시점에 설정 파일을 한 번 시드할 뿐
 * 이후 조회 폴백에 참여하지 않으므로, 이미 설치된 사이트의 설정 파일에는 이 키가 생기지
 * 않습니다. 그 상태에서도 조회는 플러그인 기본값으로 폴백해 동작하지만, 관리자 화면이
 * "운영자가 편집한 값" 을 보여주는 자리에 저장된 값이 없으면 이후 카탈로그가 바뀔 때마다
 * 운영자 모르게 목록이 함께 바뀝니다. 그래서 지금 시점의 카탈로그를 파일에 못박습니다.
 *
 * 멱등: 키가 이미 있으면 값을 덮어쓰지 않습니다 — 운영자가 이미 편집했으면 그대로 둡니다.
 *
 * 잠금 항목(`auth_token` / `XSRF-TOKEN` / 세션 쿠키 / `gdpr_session`)은 시드하지 않습니다.
 * 설정에 담기면 API 로 지울 수 있어 잠금이 아니게 되기 때문이며, 판정에는 코드가 언제나
 * 합집합으로 얹습니다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다 —
 *     업그레이드 시점에는 그 클래스가 교체 중일 수 있으므로 값을 이 파일에 동결한다)
 */
final class SeedNecessaryStorageAllowlist implements DataMigration
{
    /**
     * 백필할 허용목록 (1.0.4 시점 카탈로그 동결).
     *
     * `plugin.php::DEFAULT_NECESSARY_ALLOWLIST_CATALOG` 의 사본이지만 의도적으로 동결한
     * 값입니다 — 업그레이드 스텝은 "그 버전으로 올라오는 순간의 상태" 를 재현해야 하므로,
     * 나중에 카탈로그가 바뀌어도 이 스텝의 결과는 달라지지 않아야 합니다.
     *
     * @var array<string, array<int, string>>
     */
    private const CATALOG = [
        'localStorage' => [
            'g7_locale',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_color_scheme',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_cache_version',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_asset_url_mode*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_cart_key',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7-devtools-panel',
            'g7_guest_order_token',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_guest_order_number',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_guest_order_expires_at',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_devtools_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_filters_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_columns_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_order_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_admin_sidebar_collapsed',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_filter_visibility_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_dismissed_warnings',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7le.*',
            '__sirsoftKginicisMobilePaymentReturnPending',
            'g7.identity.redirectStash',
            'sirsoft-verification_nhnkcp.formStash',
        ],
        'sessionStorage' => [
            'g7:sirsoft-pay_kginicis:pendingClose',
            'g7:sirsoft-pay_nhnkcp:pendingClose',
            'g7:sirsoft-tosspayments:pendingClose',
            'g7.identity.redirectStash',
            'sirsoft-verification_nhnkcp.formStash',
            '__sirsoftKginicisMobilePaymentReturnPending',
            'g7le.*',
            'g7_devtools_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_filters_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_columns_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_order_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_filter_visibility_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
        ],
        'cookie' => [
            'laravel_maintenance',
        ],
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'SeedNecessaryStorageAllowlist';
    }

    /**
     * 설정 파일에 허용목록 키를 추가합니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        // 절대 경로는 코어 해석기가 디스크 root 를 기준으로 조립한다 — 확장마다 직접 조립하면
        // 테스트 환경에서 운영 설정 파일을 그대로 건드리게 된다.
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 설치 시 기본값이 시드하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 허용목록 백필 skip');

            return;
        }

        if (array_key_exists('necessary_storage_allowlist', $settings)) {
            $context->logger->info('[sirsoft-gdpr] 허용목록이 이미 존재 — 운영자 편집값 보존, 변경 없음');

            return;
        }

        $settings['necessary_storage_allowlist'] = self::CATALOG;

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 필수 저장 항목 허용목록 백필 완료', [
            'scopes' => array_keys(self::CATALOG),
        ]);
    }
}
