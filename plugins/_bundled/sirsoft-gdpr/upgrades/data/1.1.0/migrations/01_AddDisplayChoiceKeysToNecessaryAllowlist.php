<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 필수 저장 항목 허용목록에 세 항목을 보강합니다.
 *
 * 배경:
 *
 * 1.0.4 가 허용목록을 운영자 설정으로 옮기면서 그 시점의 카탈로그를 설정 파일에 못박았고,
 * 그 카탈로그에 세 항목이 빠져 있었습니다 — 방문자가 고른 **표시 통화**·**배송 국가**와
 * 관리자 화면의 **쿠폰 목록 필터** 입니다. 앞의 둘은 언어·테마와 같은 「사용자가 화면에서
 * 직접 고른 표시 설정」이고, 마지막은 형제 필터 접두사(`g7_filters_` 등)와 같은 부류인데
 * 명명만 갈려 함께 등재되지 못했습니다.
 *
 * 빠져 있는 동안 그 값들은 **쓰기는 통과하는데 다음 방문에 회수**됐습니다. 쓰기 경로에는
 * 사용자 조작 면제가 있지만 회수 경로(`cleanupFunctionalArtifacts`)는 이 목록만 보기
 * 때문이며, 화면에는 오류도 안내도 남지 않아 「그런 기능이 없는 것」과 구분되지 않았습니다.
 *
 * 소스 카탈로그는 교정했으나 그것은 **신규 설치**에만 반영됩니다. 이미 설정 파일을 가진
 * 사이트에는 1.0.4 가 못박은 목록이 그대로 남으므로 여기서 합집합으로 보강합니다.
 *
 * 멱등: 이미 있는 항목은 다시 넣지 않습니다. 운영자가 편집한 나머지 항목은 건드리지
 * 않습니다 — 세 항목은 어느 판본에도 실린 적이 없어 「운영자가 뺀 것」일 수 없습니다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다 —
 *     업그레이드 시점에는 그 클래스가 교체 중일 수 있으므로 값을 이 파일에 동결한다)
 */
final class AddDisplayChoiceKeysToNecessaryAllowlist implements DataMigration
{
    /**
     * 보강할 항목 (1.1.0 시점 동결).
     *
     * @var array<string, array<int, string>>
     */
    private const ADDITIONS = [
        'localStorage' => [
            'g7_preferred_currency',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_preferred_shipping_country',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
            'g7_coupon_filter_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
        ],
        'sessionStorage' => [
            'g7_coupon_filter_*',  // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
        ],
    ];

    /**
     * 마이그레이션 이름을 돌려줍니다.
     *
     * @return string 이름
     */
    public function name(): string
    {
        return 'AddDisplayChoiceKeysToNecessaryAllowlist';
    }

    /**
     * 설정 파일의 허용목록에 누락 항목을 합집합으로 더합니다. idempotent.
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
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 허용목록 보강 skip');

            return;
        }

        $allowlist = $settings['necessary_storage_allowlist'] ?? null;

        if (! is_array($allowlist)) {
            // 1.0.4 백필 전이거나 형식이 다르다 — 그 스텝이 카탈로그를 통째로 시드하므로 여기서는 손대지 않는다.
            $context->logger->info('[sirsoft-gdpr] 허용목록 미존재 — 1.0.4 백필이 카탈로그를 시드하므로 skip');

            return;
        }

        $added = [];

        foreach (self::ADDITIONS as $scope => $keys) {
            $current = is_array($allowlist[$scope] ?? null) ? array_values($allowlist[$scope]) : [];

            foreach ($keys as $key) {
                if (! in_array($key, $current, true)) {
                    $current[] = $key;
                    $added[] = $scope.'/'.$key;
                }
            }

            $allowlist[$scope] = $current;
        }

        if ($added === []) {
            $context->logger->info('[sirsoft-gdpr] 허용목록에 세 항목이 이미 있음 — 변경 없음');

            return;
        }

        $settings['necessary_storage_allowlist'] = $allowlist;

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 필수 저장 항목 허용목록 보강 완료', ['added' => $added]);
    }
}
