<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * JSON 컬럼의 \uXXXX 유니코드 이스케이프를 실제 UTF-8 문자로 변환합니다.
 *
 * PHP json_encode() 기본 동작은 멀티바이트 문자를 \uXXXX로 인코딩합니다.
 * MySQL FULLTEXT(ngram)은 실제 UTF-8 바이트만 토큰화하므로,
 * 기존 데이터를 JSON_UNESCAPED_UNICODE 형식으로 변환해야 합니다.
 *
 * 사용 예:
 *   php artisan json:convert-unicode --dry-run   # 변환 대상 건수 확인
 *   php artisan json:convert-unicode              # 실제 변환 실행
 */
class ConvertJsonUnicodeEscapes extends Command
{
    /**
     * 콘솔 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'json:convert-unicode
                            {--dry-run : 실제 변환 없이 대상 건수만 표시}
                            {--table= : 특정 테이블만 변환 (예: ecommerce_products)}
                            {--chunk=500 : 청크 크기}';

    /**
     * 콘솔 커맨드 설명
     *
     * @var string
     */
    protected $description = 'JSON 컬럼의 \\uXXXX 유니코드 이스케이프를 실제 UTF-8 문자로 변환합니다.';

    /**
     * 변환 대상 테이블 및 컬럼 정의
     *
     * @var array<string, string[]>
     */
    private const TARGETS = [
        'ecommerce_products' => ['name', 'description'],
        'ecommerce_categories' => ['name', 'description'],
        'ecommerce_brands' => ['name'],
        'ecommerce_promotion_coupons' => ['name', 'description'],
        'ecommerce_product_common_infos' => ['name', 'content'],
        'boards' => ['name', 'description'],
        'boards_report_logs' => ['snapshot'],
        'pages' => ['title', 'content'],
    ];

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $targetTable = $this->option('table');
        $chunkSize = (int) $this->option('chunk');

        if ($dryRun) {
            $this->components->info('Dry-run 모드: 실제 변환 없이 대상 건수만 표시합니다.');
        }

        $targets = self::TARGETS;
        if ($targetTable) {
            if (! isset($targets[$targetTable])) {
                $this->components->error("알 수 없는 테이블: {$targetTable}");
                $this->components->info('사용 가능한 테이블: '.implode(', ', array_keys($targets)));

                return self::FAILURE;
            }
            $targets = [$targetTable => $targets[$targetTable]];
        }

        $totalConverted = 0;
        $totalSkipped = 0;

        foreach ($targets as $table => $columns) {
            $prefix = DB::getTablePrefix();

            if (! $this->tableExists($prefix.$table)) {
                $this->components->warn("{$table} 테이블이 존재하지 않습니다. 스킵합니다.");

                continue;
            }

            foreach ($columns as $column) {
                [$converted, $skipped] = $this->processColumn($table, $column, $chunkSize, $dryRun);
                $totalConverted += $converted;
                $totalSkipped += $skipped;
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->components->info("변환 대상: {$totalConverted}건, 스킵(이미 UTF-8): {$totalSkipped}건");
        } else {
            $this->components->info("변환 완료: {$totalConverted}건, 스킵(이미 UTF-8): {$totalSkipped}건");
        }

        return self::SUCCESS;
    }

    /**
     * 테이블 존재 여부를 확인합니다.
     *
     * @param string $table 테이블명 (프리픽스 포함)
     * @return bool 존재 여부
     */
    private function tableExists(string $table): bool
    {
        try {
            DB::select("SELECT 1 FROM `{$table}` LIMIT 1");

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * 특정 테이블의 특정 컬럼을 변환합니다.
     *
     * @param string $table 테이블명
     * @param string $column 컬럼명
     * @param int $chunkSize 청크 크기
     * @param bool $dryRun 드라이런 모드
     * @return array{int, int} [변환 건수, 스킵 건수]
     */
    private function processColumn(string $table, string $column, int $chunkSize, bool $dryRun): array
    {
        $converted = 0;
        $skipped = 0;

        // \uXXXX 패턴이 포함된 행만 조회 (이미 변환된 행은 스킵)
        $query = DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'LIKE', '%\\\\u%');

        $total = $query->count();

        if ($total === 0) {
            $this->components->twoColumnDetail("{$table}.{$column}", '<fg=green>변환 대상 없음</>');

            return [0, 0];
        }

        $this->components->twoColumnDetail("{$table}.{$column}", "대상 {$total}건 처리 중...");

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'LIKE', '%\\\\u%')
            ->orderBy('id')
            ->chunk($chunkSize, function ($rows) use ($table, $column, $dryRun, &$converted, &$skipped) {
                foreach ($rows as $row) {
                    $original = $row->{$column};
                    $decoded = json_decode($original, true);

                    if ($decoded === null) {
                        $skipped++;

                        continue;
                    }

                    $reencoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    // 변환 전후가 동일하면 스킵
                    if ($reencoded === $original) {
                        $skipped++;

                        continue;
                    }

                    if (! $dryRun) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([$column => $reencoded]);
                    }

                    $converted++;
                }
            });

        $label = $dryRun ? '변환 대상' : '변환 완료';
        $this->components->twoColumnDetail(
            "  └ {$label}",
            "<fg=yellow>{$converted}건</> (스킵: {$skipped}건)"
        );

        return [$converted, $skipped];
    }
}
