<?php

namespace App\Benchmark;

use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Enums\BenchmarkAxis;
use Illuminate\Support\Facades\DB;

/**
 * 계측 결과 출력기 — 표준출력 표 / JSON / 마크다운 리포트
 *
 * 축을 모르고도 출력할 수 있는 이유는 `BenchmarkResult` 가 표시용 표와 기계 판독용 수치를
 * 함께 담기 때문입니다. 축이 늘어도 이 클래스는 고치지 않습니다.
 *
 * 마크다운 리포트에 환경 정보를 반드시 함께 적습니다 — 계측값은 실행 머신·DB 버전·
 * OPcache 여부에 종속되므로, 환경이 빠진 수치는 다른 리포트와 비교할 수 없는 숫자입니다.
 * 문서에 옮길 수치는 이 경로로만 산출해 눈대중 기재를 막는 것이 `--report` 의 목적입니다.
 */
class BenchmarkReporter
{
    /**
     * 실행 환경 정보를 수집합니다.
     *
     * @return array<string, string> 항목 → 값
     */
    public function environment(): array
    {
        return [
            'APP_ENV' => (string) config('app.env'),
            'G7 버전' => (string) config('app.version'),
            'DB 연결' => (string) config('database.default'),
            'DB 스키마' => $this->databaseName(),
            'DB 버전' => $this->databaseVersion(),
            'PHP 버전' => PHP_VERSION,
            'OPcache' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false ? 'on' : 'off',
            'memory_limit' => (string) ini_get('memory_limit'),
            'config:cache' => app()->configurationIsCached() ? 'on' : 'off',
            '실행 머신' => php_uname('s').' '.php_uname('r').' / '.php_uname('m'),
        ];
    }

    /**
     * JSON 으로 직렬화합니다.
     *
     * @param  array<int, BenchmarkResult>  $results  계측 결과
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @param  array<int, string>  $warnings  프로파일 수집 경고
     * @return string JSON 문자열
     */
    public function toJson(array $results, BenchmarkRunOptions $options, array $warnings = []): string
    {
        return (string) json_encode([
            'generated_at' => now()->toIso8601String(),
            'environment' => $this->environment(),
            'options' => $options->toArray(),
            'warnings' => $warnings,
            'results' => array_map(fn (BenchmarkResult $result) => $result->toArray(), $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 마크다운 리포트를 만듭니다.
     *
     * @param  array<int, BenchmarkResult>  $results  계측 결과
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @param  array<int, string>  $warnings  프로파일 수집 경고
     * @return string 마크다운 문서
     */
    public function toMarkdown(array $results, BenchmarkRunOptions $options, array $warnings = []): string
    {
        $lines = [
            '# G7 성능 점검 리포트',
            '',
            '- 생성 시각: '.now()->toDateTimeString(),
            '',
            '## 실행 환경',
            '',
            '| 항목 | 값 |',
            '| ------ | ------ |',
        ];

        foreach ($this->environment() as $name => $value) {
            $lines[] = "| {$name} | {$value} |";
        }

        $lines[] = '';
        $lines[] = '계측값은 위 환경에 종속됩니다. 다른 리포트와 비교할 때는 환경이 같은지 먼저 확인하세요.';
        $lines[] = '';
        $lines[] = '## 실행 조건';
        $lines[] = '';
        $lines[] = '| 항목 | 값 |';
        $lines[] = '| ------ | ------ |';

        foreach ($options->toArray() as $name => $value) {
            $lines[] = sprintf('| %s | %s |', $name, $this->stringify($value));
        }

        if ($warnings !== []) {
            $lines[] = '';
            $lines[] = '## 무시된 프로파일 선언';
            $lines[] = '';

            foreach ($warnings as $warning) {
                $lines[] = '- '.$warning;
            }
        }

        foreach (BenchmarkAxis::cases() as $axis) {
            $axisResults = array_values(array_filter(
                $results,
                fn (BenchmarkResult $result) => $result->profile->axis === $axis
            ));

            if ($axisResults === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = sprintf('## %s (%s)', $axis->label(), $axis->value);

            foreach ($axisResults as $result) {
                $lines = array_merge($lines, $this->markdownSection($result));
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 결과 1건의 마크다운 절을 만듭니다.
     *
     * @param  BenchmarkResult  $result  계측 결과
     * @return array<int, string> 마크다운 줄 목록
     */
    private function markdownSection(BenchmarkResult $result): array
    {
        $title = $result->profile->qualifiedKey();

        if ($result->profile->label !== null) {
            $title .= ' — '.$result->profile->label;
        }

        $lines = ['', '### '.$title, ''];

        if ($result->skipped) {
            $lines[] = '측정하지 않음: '.$result->skipReason;

            return $lines;
        }

        $lines[] = '| '.implode(' | ', $result->headers).' |';
        $lines[] = '| '.implode(' | ', array_fill(0, count($result->headers), '------')).' |';

        foreach ($result->rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        if ($result->notes !== []) {
            $lines[] = '';

            foreach ($result->notes as $note) {
                // 실행 계획/SQL 은 표 안에서 깨지므로 코드 스팬으로 감싼다
                $lines[] = '- '.(str_contains($note, '|') ? '`'.$note.'`' : $note);
            }
        }

        return $lines;
    }

    /**
     * 옵션 값을 표에 넣을 문자열로 바꿉니다.
     *
     * @param  mixed  $value  옵션 값
     * @return string 표시 문자열
     */
    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(', ', array_map(fn (mixed $item) => (string) $item, $value)),
            $value === null => '-',
            default => (string) $value,
        };
    }

    /**
     * 계측이 사용한 DB 스키마명을 반환합니다.
     *
     * 읽기/쓰기 분리 설정에서는 스키마명이 `write`/`read` 하위에만 있어 최상위 `database`
     * 가 비어 있습니다. 리포트에 스키마가 비면 어느 DB 를 잰 것인지 알 수 없으므로
     * 세 위치를 순서대로 확인합니다.
     *
     * @return string 스키마명 (확인 불가 시 '-')
     */
    private function databaseName(): string
    {
        $connection = (string) config('database.default');

        foreach (['database', 'write.database', 'read.database'] as $key) {
            $name = config("database.connections.{$connection}.{$key}");

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return '-';
    }

    /**
     * DB 서버 버전을 조회합니다.
     *
     * @return string DB 버전 (조회 실패 시 '-')
     */
    private function databaseVersion(): string
    {
        try {
            $row = DB::selectOne('select version() as version');

            return (string) ($row->version ?? '-');
        } catch (\Throwable) {
            return '-';
        }
    }
}
