<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 상품·카테고리 SEO 제목/설명(meta_title/meta_description)의 기존 평문 값을
 * 기본 로케일(ko) 키의 다국어 JSON 으로 변환합니다.
 *
 * 예: "우리나라" → {"ko":"우리나라"}
 *
 * 스키마 타입 변경(string→text)은 database/migrations 가 선행하며, 본 DataMigration 은
 * 그 위에 남은 평문 row 만 JSON 으로 감싼다.
 *
 * idempotent: 이미 JSON 객체로 디코딩되는 값은 건너뛴다. V-1 안전: Facades\DB/Schema 만 사용.
 */
class ConvertSeoMetaPlainToJson implements DataMigration
{
    /** @var array<int, string> 변환 대상 테이블 */
    private const TABLES = ['ecommerce_products', 'ecommerce_categories'];

    /** @var array<int, string> 변환 대상 컬럼 */
    private const COLUMNS = ['meta_title', 'meta_description'];

    public function name(): string
    {
        return 'ConvertSeoMetaPlainToJson';
    }

    public function run(UpgradeContext $context): void
    {
        $defaultLocale = config('app.locale', 'ko');
        $converted = 0;

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                $converted += $this->wrapPlainValues($tableName, $column, $defaultLocale);
            }
        }

        $context->logger->info(
            "[ecommerce:1.0.0] SEO meta 평문→다국어 JSON 변환 완료 — {$converted}건"
        );
    }

    /**
     * 컬럼의 평문 값을 {기본로케일: 값} JSON 으로 감쌉니다 (이미 JSON 이면 건너뜀).
     *
     * @param  string  $tableName  대상 테이블명
     * @param  string  $column  대상 컬럼명
     * @param  string  $defaultLocale  기본 로케일 키
     * @return int 변환한 row 수
     */
    private function wrapPlainValues(string $tableName, string $column, string $defaultLocale): int
    {
        $count = 0;

        DB::table($tableName)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($tableName, $column, $defaultLocale, &$count) {
                foreach ($rows as $row) {
                    $raw = (string) $row->{$column};

                    // 이미 다국어 JSON 객체면 건너뜀 (idempotent)
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        continue;
                    }

                    $wrapped = json_encode(
                        [$defaultLocale => $raw],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );

                    DB::table($tableName)->where('id', $row->id)->update([$column => $wrapped]);
                    $count++;
                }
            });

        return $count;
    }
}
