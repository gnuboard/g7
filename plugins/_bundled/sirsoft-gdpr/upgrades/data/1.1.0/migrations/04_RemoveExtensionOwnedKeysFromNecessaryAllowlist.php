<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 필수 저장 허용목록에서 1.0.4 가 시드한 확장 소유 키를 걷어냅니다.
 *
 * 배경:
 *
 * 1.0.4 는 결제·본인인증·쇼핑 확장이 쓰는 저장 키를 이 플러그인의 출하 카탈로그에 직접 적어
 * 설정 파일에 못박았습니다. 그러면 이 플러그인이 특정 확장의 존재를 전제하게 되고, 확장이 키를
 * 바꾸거나 새로 도입할 때마다 이 플러그인을 고쳐야 합니다. 1.1.0 부터 확장 소유 키는 각 확장이
 * 계약 훅(`sirsoft-gdpr.necessary_storage.filter_provided_keys`)으로 제공하고, 판정은 요청마다
 * 그 훅에서 모은 집합을 더합니다. 설정 파일에 남은 사본은 제공 확장이 비활성·삭제돼도 허용된
 * 채로 남으므로 걷어냅니다.
 *
 * **1.0.4 가 시드한 항목만, 시드했던 저장소 종류에서만** 지웁니다. 운영자가 추가한 나머지 항목과
 * 다른 설정 키는 보존합니다. 목록은 1.0.4 시점 값으로 동결합니다 — 현재 카탈로그나 확장 코드에서
 * 도출하면 업그레이드 시점의 코드 상태에 따라 지우는 범위가 흔들립니다.
 *
 * 멱등: 지울 항목이 없으면 아무것도 쓰지 않습니다. 설정 파일이 없거나 JSON 이 깨졌거나 허용목록
 * 키 자체가 없으면 skip 합니다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다)
 */
final class RemoveExtensionOwnedKeysFromNecessaryAllowlist implements DataMigration
{
    /**
     * 1.0.4 가 시드한 확장 소유 키 (저장소 종류별, 1.0.4 시점 값 동결).
     *
     * @var array<string, array<int, string>>
     */
    private const SEEDED_EXTENSION_KEYS = [
        'localStorage' => [
            'g7_cart_key',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_guest_order_token',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_guest_order_number',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_guest_order_expires_at',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            '__sirsoftKginicisMobilePaymentReturnPending',
            'sirsoft-verification_nhnkcp.formStash',
        ],
        'sessionStorage' => [
            'g7:sirsoft-pay_kginicis:pendingClose',
            'g7:sirsoft-pay_nhnkcp:pendingClose',
            'g7:sirsoft-tosspayments:pendingClose',
            'sirsoft-verification_nhnkcp.formStash',
            '__sirsoftKginicisMobilePaymentReturnPending',
        ],
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'RemoveExtensionOwnedKeysFromNecessaryAllowlist';
    }

    /**
     * 저장된 허용목록에서 1.0.4 시드 확장 키를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 확장 소유 키 정리 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 확장 소유 키 정리 skip');

            return;
        }

        if (! is_array($settings['necessary_storage_allowlist'] ?? null)) {
            $context->logger->info('[sirsoft-gdpr] 허용목록 키 부재 — 확장 소유 키 정리 skip');

            return;
        }

        $allowlist = $settings['necessary_storage_allowlist'];
        $removed = [];

        foreach (self::SEEDED_EXTENSION_KEYS as $scope => $keys) {
            if (! is_array($allowlist[$scope] ?? null)) {
                continue;
            }

            $kept = array_values(array_filter(
                $allowlist[$scope],
                static fn ($item) => ! in_array($item, $keys, true),
            ));

            if (count($kept) !== count($allowlist[$scope])) {
                $removed[$scope] = array_values(array_intersect($allowlist[$scope], $keys));
                $allowlist[$scope] = $kept;
            }
        }

        if ($removed === []) {
            $context->logger->info('[sirsoft-gdpr] 정리할 확장 소유 키 없음 — 변경 없음');

            return;
        }

        $settings['necessary_storage_allowlist'] = $allowlist;

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 허용목록에서 확장 소유 키를 걷어냈습니다 (확장이 계약 훅으로 제공)', [
            'removed' => $removed,
        ]);
    }
}
