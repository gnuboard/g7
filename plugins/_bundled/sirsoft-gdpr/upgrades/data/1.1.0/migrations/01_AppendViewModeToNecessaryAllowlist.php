<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 필수 저장 허용목록에 확장 목록 보기 방식 키를 추가합니다.
 *
 * 배경:
 *
 * 관리자 확장 관리 4화면(모듈·플러그인·템플릿·언어팩)에 갤러리/목록 보기 전환이
 * 생기면서 그 선택을 브라우저에 기억하는 키(`g7_view_mode_*`)가 추가됐습니다.
 * 이 키가 허용목록에 없으면 functional 미동의 상태에서 부팅 때 파기되어, 보기를
 * 바꿔도 새로고침하면 갤러리로 되돌아갑니다 — 화면 테마가 같은 함정을 밟은 전례가
 * 있습니다(dev-g7#640).
 *
 * 1.0.4 스텝이 목록을 파일에 못박았으므로 기설치본의 파일에는 이 항목만 없습니다.
 * 그래서 목록 전체를 다시 쓰지 않고 **없을 때만 한 항목을 덧붙입니다**.
 *
 * 멱등: 이미 있으면 아무것도 쓰지 않습니다. 운영자가 편집한 나머지 항목은 보존합니다.
 * 설정 파일이 없거나 JSON 이 깨졌거나 키 자체가 없으면 skip 합니다 — 파일 생성은
 * 설치 시드와 1.0.4 스텝의 몫이고, 여기서 새로 만들면 운영자가 의도적으로 비운
 * 상태를 되살리게 됩니다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지
 *     않는다 — 업그레이드 시점에는 그 클래스가 교체 중일 수 있으므로 값을 동결한다)
 */
final class AppendViewModeToNecessaryAllowlist implements DataMigration
{
    /**
     * 추가할 저장소 종류.
     *
     * 보기 방식은 localStorage 에만 쓴다 — sessionStorage 절은 손대지 않는다.
     *
     * @var string
     */
    private const SCOPE = 'localStorage';

    /**
     * 추가할 허용목록 항목 (1.1.0 시점 값 동결).
     *
     * 접두사 매칭이라 4화면이 공유하는 단일 키와 앞으로 늘어날 보기 키를 함께 덮습니다.
     *
     * @var string
     */
    // audit:allow raw-literal-db-prefix 브라우저 저장소 키 이름 (DB 테이블·색인명 아님)
    private const PATTERN = 'g7_view_mode_*';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'AppendViewModeToNecessaryAllowlist';
    }

    /**
     * 저장된 허용목록에 보기 방식 키를 덧붙입니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        // 절대 경로는 코어 해석기가 디스크 root 를 기준으로 조립한다 — 확장마다 직접
        // 조립하면 테스트 환경에서 운영 설정 파일을 그대로 건드리게 된다.
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 설치 시 기본값이 시드하므로 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 보기 방식 키 추가 skip');

            return;
        }

        if (! array_key_exists('necessary_storage_allowlist', $settings)
            || ! is_array($settings['necessary_storage_allowlist'])) {
            $context->logger->info('[sirsoft-gdpr] 허용목록 키 부재 — 설치 시드·1.0.4 스텝의 몫이므로 skip');

            return;
        }

        $allowlist = $settings['necessary_storage_allowlist'];
        $scope = is_array($allowlist[self::SCOPE] ?? null) ? $allowlist[self::SCOPE] : [];

        if (in_array(self::PATTERN, $scope, true)) {
            $context->logger->info('[sirsoft-gdpr] 보기 방식 키가 이미 존재 — 변경 없음');

            return;
        }

        $scope[] = self::PATTERN;
        $allowlist[self::SCOPE] = array_values($scope);
        $settings['necessary_storage_allowlist'] = $allowlist;

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 확장 목록 보기 방식 키를 허용목록에 추가했습니다', [
            'scope' => self::SCOPE,
            'pattern' => self::PATTERN,
        ]);
    }
}
