<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 기설치본의 처리방침 슬러그 설정을 처리방침 주소 설정으로 옮깁니다.
 *
 * 배경:
 *
 * 1.0.x 는 처리방침 링크를 슬러그(`privacy_policy_slug`)로 저장하고 페이지 모듈의 주소 규칙
 * (`/page/{slug}`)으로 조합했습니다. 이 플러그인이 선언하지 않은 확장의 주소 규칙에 기대는
 * 구조라, 1.1.0 부터 운영자가 처리방침 주소(`privacy_policy_url`)를 직접 적습니다.
 *
 * 기설치본이 보던 링크가 업그레이드로 바뀌거나 사라지지 않도록 그 시절 링크가 가리키던 경로를
 * 그대로 옮깁니다.
 *
 *  - 슬러그 `x` → `/page/x`
 *  - 빈 슬러그 → 빈 주소 (링크를 숨기던 상태 유지)
 *  - 슬러그 키가 아예 없음 → 1.0.x 기본 슬러그(`privacy`)가 적용되던 상태이므로 `/page/privacy`
 *  - 주소가 이미 있으면 그 값을 유지하고 슬러그 키만 지운다
 *
 * 옛 슬러그 키는 지웁니다 — 남겨 두면 설정 화면이 저장할 때마다 쓰이지 않는 값이 함께 남는다.
 * 정책 버전 스냅샷(`gdpr_policy_versions.snapshot`)은 발행 당시의 기록이라 고치지 않으며,
 * 화면이 읽을 때 옛 슬러그를 같은 규칙으로 풀어 보여 줍니다.
 *
 * 멱등: 두 번째 실행에서는 슬러그 키가 없고 주소 키가 있으므로 아무것도 쓰지 않습니다.
 * 설정 파일이 없거나 JSON 이 깨졌으면 skip 합니다 — 파일 생성은 설치 시드의 몫입니다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다 —
 *     조합 규칙은 1.0.x 시점 값으로 동결한다)
 */
final class ConvertPrivacyPolicySlugToUrl implements DataMigration
{
    /**
     * 1.0.x 슬러그 설정 키
     *
     * @var string
     */
    private const LEGACY_KEY = 'privacy_policy_slug';

    /**
     * 1.1.0 주소 설정 키
     *
     * @var string
     */
    private const URL_KEY = 'privacy_policy_url';

    /**
     * 1.0.x 가 슬러그로 링크를 조합하던 경로 접두사 (1.0.x 시점 값 동결)
     *
     * @var string
     */
    private const LEGACY_PAGE_PREFIX = '/page/';

    /**
     * 1.0.x 기본 슬러그 (슬러그 키가 없던 설치본이 실제로 쓰던 값, 1.0.x 시점 값 동결)
     *
     * @var string
     */
    private const LEGACY_DEFAULT_SLUG = 'privacy';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'ConvertPrivacyPolicySlugToUrl';
    }

    /**
     * 저장된 슬러그를 처리방침 주소로 옮기고 슬러그 키를 지웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin('sirsoft-gdpr', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-gdpr] 설정 파일 없음 — 처리방침 주소 이관 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-gdpr] 설정 JSON 형식 비정상 — 처리방침 주소 이관 skip');

            return;
        }

        $hasLegacy = array_key_exists(self::LEGACY_KEY, $settings);
        $hasUrl = array_key_exists(self::URL_KEY, $settings);

        if ($hasUrl && ! $hasLegacy) {
            $context->logger->info('[sirsoft-gdpr] 처리방침 주소가 이미 있음 — 변경 없음');

            return;
        }

        if (! $hasUrl) {
            $slug = $hasLegacy ? $settings[self::LEGACY_KEY] : self::LEGACY_DEFAULT_SLUG;
            $settings[self::URL_KEY] = $this->urlFromSlug($slug);
        }

        unset($settings[self::LEGACY_KEY]);

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-gdpr] 처리방침 슬러그를 처리방침 주소로 옮겼습니다', [
            'privacy_policy_url' => $settings[self::URL_KEY],
            'kept_existing_url' => $hasUrl,
        ]);
    }

    /**
     * 1.0.x 슬러그를 그 시절 링크가 가리키던 경로로 바꿉니다.
     *
     * @param  mixed  $slug  1.0.x 슬러그 저장값
     * @return string `/page/{slug}` 경로, 슬러그가 비었거나 문자열이 아니면 빈 문자열
     */
    private function urlFromSlug(mixed $slug): string
    {
        if (! is_string($slug) || trim($slug) === '') {
            return '';
        }

        return self::LEGACY_PAGE_PREFIX.rawurlencode(trim($slug));
    }
}
