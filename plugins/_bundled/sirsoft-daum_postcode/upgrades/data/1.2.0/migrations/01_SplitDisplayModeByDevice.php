<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftDaumPostcode\V1_2_0\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;

/**
 * 표시 방식 한 칸(`display_mode`)을 기기별 두 칸(`display_mode_pc`·`display_mode_mobile`)으로 나눈다.
 *
 * 배경:
 *
 * 1.1.x 는 표시 방식이 하나(popup·layer)라 휴대폰에서도 PC 와 같은 레이어가 떠 좌우가 잘렸다. 1.2.0 은 PC(popup·layer·
 * inline, 기본 layer)와 모바일(inline·fullscreen, 기본 inline)을 따로 고른다. 옛 키는 설정 스키마에서
 * 빠지므로 남겨 두면 쓰이지 않는 값이 된다.
 *
 * - 옛 값이 PC 선택지(popup·layer·inline)면 `display_mode_pc` 로 옮긴다 — 이미 `display_mode_pc` 가 있으면 그것을 둔다
 * - `display_mode_mobile` 이 없으면 inline 으로 둔다
 * - 옛 키는 지운다
 * - 옛 키가 없으면(저장값 없음) 아무것도 쓰지 않는다 — 조회가 스키마 기본값(PC layer · 모바일 inline)으로 폴백한다
 *
 * 멱등: 두 번째 실행에서는 옛 키가 없어 아무것도 쓰지 않는다. 설정 파일이 없거나 JSON 이 깨졌으면 skip.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - 파일 시스템 + 코어 경로 해석기 + FilePermissionHelper 만 사용
 *   - Service / Manager / Repository 컨테이너 해석 없음 (플러그인 클래스도 해석하지 않는다)
 */
final class SplitDisplayModeByDevice implements DataMigration
{
    /**
     * 옛 키 (1.1.x).
     */
    private const LEGACY_KEY = 'display_mode';

    /**
     * PC 표시 방식으로 옮길 수 있는 값 (1.2.0 시점 값 동결).
     *
     * @var array<int, string>
     */
    private const PC_MODES = ['popup', 'layer', 'inline'];

    /**
     * 모바일 표시 방식 초깃값 (1.2.0 시점 값 동결).
     */
    private const MOBILE_DEFAULT = 'inline';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'SplitDisplayModeByDevice';
    }

    /**
     * 옛 표시 방식을 기기별 두 칸으로 나눈다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $path = ExtensionStoragePath::plugin('sirsoft-daum_postcode', 'settings').'/setting.json';

        if (! File::exists($path)) {
            $context->logger->info('[sirsoft-daum_postcode] 설정 파일 없음 — 표시 방식 분리 skip');

            return;
        }

        $settings = json_decode(File::get($path), true);

        if (! is_array($settings)) {
            $context->logger->warning('[sirsoft-daum_postcode] 설정 JSON 형식 비정상 — 표시 방식 분리 skip');

            return;
        }

        if (! array_key_exists(self::LEGACY_KEY, $settings)) {
            $context->logger->info('[sirsoft-daum_postcode] 옛 표시 방식 키 없음 — 변경 없음(기본값 폴백)');

            return;
        }

        $legacy = $settings[self::LEGACY_KEY];
        unset($settings[self::LEGACY_KEY]);

        $moved = false;
        if (! array_key_exists('display_mode_pc', $settings) && is_string($legacy) && in_array($legacy, self::PC_MODES, true)) {
            $settings['display_mode_pc'] = $legacy;
            $moved = true;
        }

        if (! array_key_exists('display_mode_mobile', $settings)) {
            $settings['display_mode_mobile'] = self::MOBILE_DEFAULT;
        }

        File::put($path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        FilePermissionHelper::inheritOwnershipFromParent($path);

        $context->logger->info('[sirsoft-daum_postcode] 표시 방식을 기기별로 나눴습니다', [
            'legacy' => $legacy,
            'moved_to_pc' => $moved,
            'display_mode_pc' => $settings['display_mode_pc'] ?? null,
            'display_mode_mobile' => $settings['display_mode_mobile'],
        ]);
    }
}
