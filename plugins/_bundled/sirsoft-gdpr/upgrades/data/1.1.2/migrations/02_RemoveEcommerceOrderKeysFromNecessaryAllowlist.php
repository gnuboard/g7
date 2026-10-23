<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_2\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 기설치본의 필수 저장 허용목록에서 이커머스 주문 목록 열 키(`g7_order_*`)를 걷어냅니다.
 *
 * 배경:
 *
 * `g7_order_*` 는 이커머스 관리자 주문 목록의 표시 열 선택(`g7_order_visible_columns_{uuid}`)이다. 1.0.4 가 이 플러그인의
 * 출하 카탈로그에 적어 설정 파일에 시드했는데, 1.1.0 의 확장 소유 키 정리(04)에서 빠진 잔여다. 1.1.2 부터 이커머스(1.3.2+)가
 * 계약 훅(`sirsoft-gdpr.necessary_storage.filter_provided_keys`)으로 직접 제공하므로, 설정 파일에 남은 사본은 이커머스가
 * 비활성·삭제돼도 허용된 채로 남는다 — 걷어낸다.
 *
 * 1.0.4 가 시드했던 두 저장소 종류(localStorage·sessionStorage)에서 이 항목만 지운다. 운영자가 추가한 나머지 항목과 다른 설정
 * 키는 보존한다. **이커머스가 아직 훅으로 제공하지 않는 옛 버전(1.3.2 미만)으로 설치돼 있으면 남긴다** — 그 상태에서 지우면
 * 쿠키 미동의 관리자의 열 선택이 새로고침마다 초기화된다(1.1.0/04 와 같은 규칙).
 *
 * 멱등: 지울 항목이 없으면 아무것도 쓰지 않는다. 설정 파일이 없거나 JSON 이 깨졌거나 허용목록 키 자체가 없으면 skip 한다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper + `Illuminate\Support\Facades\{DB,Schema}` 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다)
 */
final class RemoveEcommerceOrderKeysFromNecessaryAllowlist implements DataMigration
{
    /**
     * 걷어낼 항목 (1.1.2 시점 값 동결).
     */
    private const KEY = 'g7_order_*'; // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)

    /**
     * 1.0.4 가 이 항목을 시드한 저장소 종류.
     *
     * @var array<int, string>
     */
    private const SCOPES = ['localStorage', 'sessionStorage'];

    /**
     * 키를 제공하는 확장과 계약 훅으로 제공하기 시작한 버전 (시점 값 동결).
     *
     * @var array{0: string, 1: string, 2: string} [표 이름, 식별자, 제공 시작 버전]
     */
    private const PROVIDER = ['modules', 'sirsoft-ecommerce', '1.3.2'];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'RemoveEcommerceOrderKeysFromNecessaryAllowlist';
    }

    /**
     * 저장된 허용목록에서 `g7_order_*` 를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 이커머스 주문 열 키 정리 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 이커머스 주문 열 키 정리 skip');

            return;
        }

        if (! is_array($settings['necessary_storage_allowlist'] ?? null)) {
            $context->logger->info('[sirsoft-gdpr] 허용목록 키 부재 — 이커머스 주문 열 키 정리 skip');

            return;
        }

        if ($this->providerIsOlderThanHook()) {
            $context->logger->warning('[sirsoft-gdpr] 이커머스가 아직 옛 버전이라 g7_order_* 는 허용목록에 남깁니다 — 이커머스를 업데이트하면 이커머스가 직접 제공합니다');

            return;
        }

        $allowlist = $settings['necessary_storage_allowlist'];
        $removed = [];

        foreach (self::SCOPES as $scope) {
            if (! is_array($allowlist[$scope] ?? null) || ! in_array(self::KEY, $allowlist[$scope], true)) {
                continue;
            }

            $allowlist[$scope] = array_values(array_filter($allowlist[$scope], static fn ($item) => $item !== self::KEY));
            $removed[] = $scope;
        }

        if ($removed === []) {
            $context->logger->info('[sirsoft-gdpr] 허용목록에 g7_order_* 없음 — 변경 없음');

            return;
        }

        $settings['necessary_storage_allowlist'] = $allowlist;

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 허용목록에서 g7_order_* 를 걷어냈습니다 (이커머스가 계약 훅으로 제공)', [
            'scopes' => $removed,
        ]);
    }

    /**
     * 이커머스가 계약 훅으로 g7_order_* 를 제공하기 전 버전으로 설치돼 있는지 판정합니다.
     *
     * 설치돼 있지 않거나 표가 없으면 false(지워도 된다) — 키를 쓸 확장이 없다.
     *
     * @return bool 옛 버전으로 설치돼 있으면 true(남긴다)
     */
    private function providerIsOlderThanHook(): bool
    {
        [$table, $identifier, $sinceVersion] = self::PROVIDER;

        if (! Schema::hasTable($table)) {
            return false;
        }

        $installed = DB::table($table)->where('identifier', $identifier)->value('version');

        return $installed !== null && version_compare((string) $installed, $sinceVersion, '<');
    }
}
