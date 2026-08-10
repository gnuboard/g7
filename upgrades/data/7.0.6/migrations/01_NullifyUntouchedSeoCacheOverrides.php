<?php

namespace App\Upgrades\Data\V7_0_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEO 캐시 설정을 "미설정(null) = 고급 탭 값 사용" 의미로 이행 (결정 D19).
 *
 * 배경:
 *   캐시 설정은 `cache.*`(고급 탭)로 이관되었으나 SEO 탭의 옛 칸(`seo.cache_enabled`,
 *   `seo.cache_ttl`, `seo.sitemap_cache_ttl`)이 제거되지 않았고, 코드가
 *   `g7_core_settings('cache.X', g7_core_settings('seo.Y', 기본값))` 형태로 cache 를
 *   **항상** 우선시켜 SEO 탭 입력이 조용히 무시되었다(운영자가 저장해도 무효).
 *
 * 7.0.6 의 새 의미:
 *   고급 탭 = 메인, SEO 탭에 **별도 지정이 있으면** 그것이 오버라이드.
 *   "별도 지정" 판정은 null 여부로 하므로 미설정 상태를 표현할 수 있어야 한다.
 *
 * 본 마이그레이션의 동작 (옛 기본값 기준 추정):
 *
 *   | 저장된 seo.* 값  | 판정                  | 동작                     |
 *   |------------------|-----------------------|--------------------------|
 *   | 키 부재          | 미설정                | no-op                    |
 *   | 이미 null        | 미설정                | no-op (멱등)             |
 *   | 옛 기본값과 동일 | 안 건드린 것으로 추정 | null 로 비움             |
 *   | 옛 기본값과 다름 | 의도적으로 바꾼 것    | 보존 → 오버라이드로 발동 |
 *
 *   즉 값을 실제로 바꿔둔 운영자의 의도만 살아난다. 그 결과 **업그레이드 후 동작이
 *   바뀔 수 있다** — 예: `seo.cache_ttl=3600` 을 설정해 둔 사이트는 그동안 무시된 채
 *   `cache.seo_ttl`(7200)로 돌았으나 이제 3600 이 발동한다. 이것이 원래 의도였으므로
 *   의도된 변경이며 CHANGELOG 에 고지한다.
 *
 * 멱등: 재실행 시 이미 null 인 키는 건드리지 않는다.
 *
 * 실패 정책: 설정 정리 실패가 업그레이드 전체를 막지 않는다(경고 후 계속). 비우지 못하면
 * 해당 키는 옛 기본값을 유지한 채 오버라이드로 동작하는데, 그 값이 곧 고급 탭 기본값과
 * 같으므로 관측되는 동작은 변하지 않는다.
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §13):
 *   `SeoCacheSettings` 등 신규 코어 클래스를 참조하지 않는다. 옛 기본값 목록과 설정
 *   읽기/쓰기를 본 클래스 안에 로컬로 인라인 구현해 V-1 안전을 확보한다.
 */
final class NullifyUntouchedSeoCacheOverrides implements DataMigration
{
    /**
     * 설정 카테고리 파일 (settings 디스크 기준 상대 경로)
     */
    private const SEO_SETTINGS_FILE = 'seo.json';

    /**
     * 7.0.x 시점의 seo 카테고리 캐시 키 기본값.
     *
     * 저장된 값이 이 값과 같으면 "운영자가 안 건드린 것" 으로 추정해 null 로 비운다.
     * 격리를 위해 defaults.json 을 읽지 않고 로컬 상수로 고정한다 — 미래 버전에서
     * defaults.json 이 바뀌어도 본 스텝의 판정 기준은 동결되어야 한다.
     */
    private const LEGACY_DEFAULTS = [
        'cache_enabled' => true,
        'cache_ttl' => 7200,
        'sitemap_cache_ttl' => 86400,
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'NullifyUntouchedSeoCacheOverrides';
    }

    /**
     * 옛 기본값 그대로인 SEO 캐시 키를 null(미설정)로 비웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            // 업그레이드 중단 금지 — 설정 정리 실패가 업그레이드 전체를 막지 않는다.
            $context->logger->warning(sprintf(
                '[7.0.6] NullifyUntouchedSeoCacheOverrides 실패 (seo.json 보존, 계속 진행): %s',
                $e->getMessage(),
            ));
            Log::warning('7.0.6 NullifyUntouchedSeoCacheOverrides 실패', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 실제 이행 로직.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function runInternal(UpgradeContext $context): void
    {
        $settings = $this->readSeoSettings($context);
        if ($settings === null) {
            return;
        }

        $cleared = [];
        $kept = [];

        foreach (self::LEGACY_DEFAULTS as $key => $legacyDefault) {
            if (! array_key_exists($key, $settings) || $settings[$key] === null) {
                continue;
            }

            // 옛 기본값과 완전히 같을 때만 "안 건드림" 으로 본다 (타입까지 일치 요구)
            if ($settings[$key] === $legacyDefault) {
                $settings[$key] = null;
                $cleared[] = $key;

                continue;
            }

            $kept[$key] = $settings[$key];
        }

        foreach ($kept as $key => $value) {
            $context->logger->info(sprintf(
                '[7.0.6] seo.%s = %s 는 옛 기본값과 달라 오버라이드로 보존 — 이제부터 실제 적용됩니다',
                $key,
                var_export($value, true),
            ));
        }

        if ($cleared === []) {
            $context->logger->info('[7.0.6] SEO 캐시 설정 — 비울 대상 없음 (이미 이행 완료이거나 전부 오버라이드)');

            return;
        }

        $this->writeSeoSettings($settings);
        $context->logger->info('[7.0.6] SEO 캐시 설정 — 미설정(null)으로 비운 키: '.implode(', ', $cleared));
    }

    /**
     * seo 설정 카테고리를 읽습니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array<string, mixed>|null 설정 배열 (파일 부재/손상 시 null)
     */
    private function readSeoSettings(UpgradeContext $context): ?array
    {
        if (! Storage::disk('settings')->exists(self::SEO_SETTINGS_FILE)) {
            $context->logger->info('[7.0.6] seo.json 부재 — 정리 대상 없음, skip');

            return null;
        }

        $decoded = json_decode((string) Storage::disk('settings')->get(self::SEO_SETTINGS_FILE), true);

        if (! is_array($decoded)) {
            // 손상 파일을 덮어쓰지 않는다 (파괴 금지)
            $context->logger->warning('[7.0.6] seo.json 파싱 실패 — 파일 보존하고 skip');

            return null;
        }

        return $decoded;
    }

    /**
     * seo 설정 카테고리를 저장합니다.
     *
     * @param  array<string, mixed>  $settings  저장할 설정 배열
     */
    private function writeSeoSettings(array $settings): void
    {
        Storage::disk('settings')->put(
            self::SEO_SETTINGS_FILE,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
