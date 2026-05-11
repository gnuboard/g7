<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board 모듈 1.0.0-beta.4 업그레이드 스텝
 *
 * 코어 user_overrides 인프라가 dot-path sub-key 단위로 확장됨에 따라,
 * board_types.user_overrides 의 컬럼명 항목(`'name'`)을 활성 locale 별 dot-path
 * 항목(`'name.ko', 'name.en', 'name.ja'`)으로 일괄 변환한다.
 *
 * 변환 규칙: 코어 Upgrade_7_0_0_beta_4::migrateUserOverridesToDotPath() 와 동일.
 *   - user_overrides=null/[] : 변환 없음
 *   - 항목이 dot-path 포함 : 그대로 유지 (idempotent)
 *   - 'name' (다국어 컬럼명) : 활성 locale 별 dot-path 로 확장
 *   - 그 외 항목 : 그대로 유지
 *
 * @upgrade-path B
 */
class Upgrade_1_0_0_beta_4 implements UpgradeStepInterface
{
    /** @var array<int, string> board_types 모델의 translatableTrackableFields 와 일치 */
    private const TRANSLATABLE_COLUMNS = ['name'];

    public function run(UpgradeContext $context): void
    {
        $context->logger->info('[board:1.0.0-beta.4] board_types.user_overrides dot-path 마이그레이션 시작');

        $locales = $this->resolveSupportedLocales();
        $this->migrateBoardTypes($context, $locales);

        $context->logger->info('[board:1.0.0-beta.4] 마이그레이션 완료');
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedLocales(): array
    {
        $locales = config('app.supported_locales', ['ko', 'en']);
        if (! is_array($locales) || empty($locales)) {
            return ['ko', 'en'];
        }

        return array_values(array_filter($locales, 'is_string'));
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function migrateBoardTypes(UpgradeContext $context, array $locales): void
    {
        if (! Schema::hasTable('board_types') || ! Schema::hasColumn('board_types', 'user_overrides')) {
            $context->logger->warning('[board:1.0.0-beta.4] board_types.user_overrides 미존재 — 스킵');

            return;
        }

        $rows = DB::table('board_types')
            ->whereNotNull('user_overrides')
            ->where('user_overrides', '!=', '')
            ->where('user_overrides', '!=', '[]')
            ->where('user_overrides', '!=', 'null')
            ->get(['id', 'user_overrides']);

        $converted = 0;
        foreach ($rows as $row) {
            $existing = json_decode((string) $row->user_overrides, true);
            if (! is_array($existing) || empty($existing)) {
                continue;
            }
            $migrated = $this->expandColumnNamesToDotPaths($existing, $locales);
            if ($migrated === $existing) {
                continue;
            }
            DB::table('board_types')->where('id', $row->id)->update([
                'user_overrides' => json_encode(array_values(array_unique($migrated))),
            ]);
            $converted++;
        }

        $context->logger->info("[board:1.0.0-beta.4] board_types: {$converted} 건 변환");
    }

    /**
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    private function expandColumnNamesToDotPaths(array $existing, array $locales): array
    {
        $result = [];
        foreach ($existing as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (str_contains($entry, '.')) {
                $result[] = $entry;

                continue;
            }
            if (in_array($entry, self::TRANSLATABLE_COLUMNS, true)) {
                foreach ($locales as $locale) {
                    $result[] = "{$entry}.{$locale}";
                }

                continue;
            }
            $result[] = $entry;
        }

        return $result;
    }
}
