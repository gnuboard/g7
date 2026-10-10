<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JSON 컬럼의 \uXXXX 유니코드 이스케이프를 실제 UTF-8 문자로 변환합니다.
 *
 * PHP json_encode() 기본 동작은 멀티바이트 문자를 \uXXXX로 인코딩합니다.
 * MySQL FULLTEXT(ngram)은 실제 UTF-8 바이트만 토큰화하므로,
 * 기존 데이터를 JSON_UNESCAPED_UNICODE 형식으로 변환해야 합니다.
 *
 * 대상은 DB 스키마에서 찾는다 — FULLTEXT 인덱스에 든 컬럼 전부(`id` 키가 있는 테이블). 이 변환이 필요한
 * 까닭이 FULLTEXT 토큰화이므로 그 인덱스가 곧 대상이다. 다국어 값은 JSON 타입이 아니라 TEXT 컬럼에 JSON
 * 문자열로 담기므로 컬럼 타입으로는 찾을 수 없다. JSON 이 아닌 값(일반 본문)은 디코드되지 않아 건너뛴다.
 * 코어가 확장 테이블 이름을 적어 두지 않으므로 확장이 설치·삭제돼도 대상 목록이 저절로 맞는다
 * (2026-09-28 결정).
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
                            {--table= : 특정 테이블만 변환 (프리픽스 제외 이름, 예: boards)}
                            {--chunk=500 : 청크 크기}';

    /**
     * 콘솔 커맨드 설명
     *
     * @var string
     */
    protected $description = 'JSON 컬럼의 \\uXXXX 유니코드 이스케이프를 실제 UTF-8 문자로 변환합니다.';

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

        $targets = $this->discoverTargets();
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

        if ($targets === []) {
            $this->components->info('JSON 컬럼을 가진 테이블이 없습니다.');
        }

        foreach ($targets as $table => $columns) {
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
     * DB 스키마에서 변환 대상(FULLTEXT 인덱스 컬럼)을 찾습니다.
     *
     * 현재 연결의 데이터베이스에서 프리픽스가 맞는 테이블만 보고, 키셋 순회(`chunkById`)에 쓸 `id` 컬럼이
     * 없는 테이블은 건너뛴다.
     *
     * @return array<string, array<int, string>> 프리픽스 제외 테이블명 → 컬럼명 목록 (이름순)
     */
    private function discoverTargets(): array
    {
        $prefix = DB::getTablePrefix();
        $targets = [];

        foreach (Schema::getTables(DB::connection()->getDatabaseName()) as $table) {
            $name = (string) $table['name'];

            if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
                continue;
            }

            $logical = substr($name, strlen($prefix));

            $indexed = [];
            foreach (Schema::getIndexes($logical) as $index) {
                if (strtolower((string) $index['type']) === 'fulltext') {
                    $indexed = array_merge($indexed, $index['columns']);
                }
            }

            if ($indexed === [] || ! Schema::hasColumn($logical, 'id')) {
                continue;
            }

            $indexed = array_values(array_unique($indexed));
            sort($indexed);
            $targets[$logical] = $indexed;
        }

        ksort($targets);

        return $targets;
    }

    /**
     * 특정 테이블의 특정 컬럼을 변환합니다.
     *
     * @param  string  $table  테이블명
     * @param  string  $column  컬럼명
     * @param  int  $chunkSize  청크 크기
     * @param  bool  $dryRun  드라이런 모드
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

        // chunkById(키셋 순회) 필수 — 콜백이 필터 조건 컬럼을 UTF-8 로 다시 써서 처리된
        // 행이 `LIKE '%\u%'` 결과에서 이탈한다. OFFSET 기반 chunk() 는 그만큼 앞으로 밀려
        // 아직 변환되지 않은 행을 건너뛴다. (`id` 가 없는 테이블은 탐색 단계에서 이미 뺐다)
        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'LIKE', '%\\\\u%')
            ->chunkById($chunkSize, function ($rows) use ($table, $column, $dryRun, &$converted, &$skipped) {
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
