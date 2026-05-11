<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ecommerce 모듈 1.0.0-beta.3 업그레이드 스텝
 *
 * 코어 user_overrides 인프라가 dot-path sub-key 단위로 확장됨에 따라,
 * 다음 3개 테이블의 user_overrides 컬럼명 항목(`'name'`)을 활성 locale 별
 * dot-path 항목으로 일괄 변환한다.
 *
 * 처리 대상:
 *   - ecommerce_claim_reasons.user_overrides    : ['name'] 다국어
 *   - ecommerce_shipping_types.user_overrides   : ['name'] 다국어
 *   - ecommerce_shipping_carriers.user_overrides: ['name'] 다국어
 *
 * 변환 규칙: 코어 Upgrade_7_0_0_beta_4::migrateUserOverridesToDotPath() 와 동일.
 *
 * @upgrade-path B
 */
class Upgrade_1_0_0_beta_3 implements UpgradeStepInterface
{
    /** @var array<string, array<int, string>> 테이블별 다국어 컬럼명 (translatableTrackableFields 와 일치) */
    private const TABLE_TRANSLATABLE_COLUMNS = [
        'ecommerce_claim_reasons' => ['name'],
        'ecommerce_shipping_types' => ['name'],
        'ecommerce_shipping_carriers' => ['name'],
    ];

    public function run(UpgradeContext $context): void
    {
        $context->logger->info('[ecommerce:1.0.0-beta.3] user_overrides dot-path 마이그레이션 시작');

        $locales = $this->resolveSupportedLocales();
        $context->logger->info('[ecommerce:1.0.0-beta.3] 활성 locale: '.implode(', ', $locales));

        foreach (self::TABLE_TRANSLATABLE_COLUMNS as $table => $translatableColumns) {
            $this->migrateTable($context, $table, $translatableColumns, $locales);
        }

        $context->logger->info('[ecommerce:1.0.0-beta.3] 마이그레이션 완료');
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
     * @param  array<int, string>  $translatableColumns
     * @param  array<int, string>  $locales
     */
    private function migrateTable(UpgradeContext $context, string $table, array $translatableColumns, array $locales): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_overrides')) {
            $context->logger->warning("[ecommerce:1.0.0-beta.3] {$table}.user_overrides 미존재 — 스킵");

            return;
        }

        $rows = DB::table($table)
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
            $migrated = $this->expandColumnNamesToDotPaths($existing, $translatableColumns, $locales);
            if ($migrated === $existing) {
                continue;
            }
            DB::table($table)->where('id', $row->id)->update([
                'user_overrides' => json_encode(array_values(array_unique($migrated))),
            ]);
            $converted++;
        }

        $context->logger->info("[ecommerce:1.0.0-beta.3] {$table}: {$converted} 건 변환");
    }

    /**
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $translatableColumns
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    private function expandColumnNamesToDotPaths(array $existing, array $translatableColumns, array $locales): array
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
            if (in_array($entry, $translatableColumns, true)) {
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
