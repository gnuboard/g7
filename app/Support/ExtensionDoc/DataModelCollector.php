<?php

namespace App\Support\ExtensionDoc;

use Illuminate\Support\Facades\File;

/**
 * 확장 데이터 모델 수집기
 *
 * 모델·Enum·마이그레이션·Repository 계약을 `_bundled` 소스에서 수집합니다.
 * 모델 클래스를 로드하지 않고 소스를 파싱하므로 DB 연결이나 확장 활성화 상태와 무관하게
 * 동작합니다 (문서 생성은 설치되지 않은 확장에도 수행되어야 합니다).
 */
class DataModelCollector
{
    /**
     * Eloquent 관계 정의 메서드.
     *
     * @var array<int, string>
     */
    private const RELATION_METHODS = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
        'hasOneThrough', 'hasManyThrough',
        'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany',
    ];

    /**
     * 확장의 데이터 모델 표면을 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @return array{models: array<int, array<string, mixed>>, enums: array<int, array<string, mixed>>, migrations: array<int, array<string, mixed>>, tables: array<int, string>, repositories: array<int, array<string, mixed>>}
     */
    public function collect(array $record): array
    {
        $models = $this->collectModels($record);
        $migrations = $this->collectMigrations($record);

        $tables = [];
        foreach ($migrations as $migration) {
            foreach ($migration['creates'] as $table) {
                $tables[$table] = true;
            }
        }
        foreach ($models as $model) {
            if ($model['table'] !== null) {
                $tables[$model['table']] = true;
            }
        }
        $tables = array_keys($tables);
        sort($tables);

        return [
            'models' => $models,
            'enums' => $this->collectEnums($record),
            'migrations' => $migrations,
            'tables' => $tables,
            'repositories' => $this->collectRepositories($record),
        ];
    }

    /**
     * `src/Models/**` 의 모델을 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array<string, mixed>> 모델 목록
     */
    private function collectModels(array $record): array
    {
        $models = [];

        foreach ($this->filesIn($record, 'src/Models') as $file) {
            $content = (string) file_get_contents($file);
            $short = basename($file, '.php');

            $models[] = [
                'class' => $short,
                'relFile' => $this->relative($record, $file),
                'table' => $this->stringProperty($content, 'table'),
                'fillable' => $this->arrayPropertyCount($content, 'fillable'),
                'softDeletes' => (bool) preg_match('/\buse\s+[^;]*\bSoftDeletes\b/', $content),
                'userOverrides' => str_contains($content, 'HasUserOverrides'),
                'searchable' => str_contains($content, 'FulltextSearchable') || str_contains($content, 'Laravel\Scout\Searchable'),
                'relations' => $this->collectRelations($content),
                'summary' => $this->classDocSummary($content),
            ];
        }

        usort($models, static fn (array $a, array $b): int => $a['class'] <=> $b['class']);

        return $models;
    }

    /**
     * 모델 소스에서 관계 정의를 수집합니다.
     *
     * @param  string  $content  모델 소스
     * @return array<int, array{method: string, type: string, target: string|null}> 관계 목록
     */
    private function collectRelations(string $content): array
    {
        $relations = [];
        $alternation = implode('|', self::RELATION_METHODS);

        $pattern = '/public\s+function\s+(\w+)\s*\([^)]*\)[^{]*\{(?:[^{}]|\{[^{}]*\})*?\$this->('
            .$alternation
            .')\s*\(\s*(?:([A-Za-z_\\\\]+)::class)?/s';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $relations[] = [
                    'method' => $m[1],
                    'type' => $m[2],
                    'target' => ($m[3] ?? '') !== '' ? $this->shortName($m[3]) : null,
                ];
            }
        }

        return $relations;
    }

    /**
     * `src/Enums/**` 의 Enum 을 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array<string, mixed>> Enum 목록
     */
    private function collectEnums(array $record): array
    {
        $enums = [];

        foreach ($this->filesIn($record, 'src/Enums') as $file) {
            $content = (string) file_get_contents($file);

            $backing = null;
            if (preg_match('/^\s*enum\s+\w+\s*:\s*(\w+)/m', $content, $bm)) {
                $backing = $bm[1];
            }

            $cases = [];
            if (preg_match_all("/^\s*case\s+(\w+)\s*(?:=\s*'([^']*)')?/m", $content, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $cases[] = ['name' => $c[1], 'value' => $c[2] ?? null];
                }
            }

            $enums[] = [
                'class' => basename($file, '.php'),
                'relFile' => $this->relative($record, $file),
                'backing' => $backing,
                'cases' => $cases,
                'summary' => $this->classDocSummary($content),
            ];
        }

        usort($enums, static fn (array $a, array $b): int => $a['class'] <=> $b['class']);

        return $enums;
    }

    /**
     * `database/migrations/**` 을 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array<string, mixed>> 마이그레이션 목록 (파일명 정렬)
     */
    private function collectMigrations(array $record): array
    {
        $migrations = [];

        foreach ($this->filesIn($record, 'database/migrations') as $file) {
            $content = (string) file_get_contents($file);

            $creates = [];
            if (preg_match_all("/Schema::(?:connection\([^)]*\)->)?create\s*\(\s*'([^']+)'/", $content, $m)) {
                $creates = array_values(array_unique($m[1]));
            }

            $alters = [];
            if (preg_match_all("/Schema::(?:connection\([^)]*\)->)?table\s*\(\s*'([^']+)'/", $content, $m)) {
                $alters = array_values(array_unique($m[1]));
            }

            $migrations[] = [
                'file' => basename($file),
                'relFile' => $this->relative($record, $file),
                'creates' => $creates,
                'alters' => $alters,
                'hasDown' => (bool) preg_match('/function\s+down\s*\(/', $content),
            ];
        }

        usort($migrations, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return $migrations;
    }

    /**
     * Repository 계약과 구현을 수집합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array<string, mixed>> Repository 목록
     */
    private function collectRepositories(array $record): array
    {
        $repositories = [];

        foreach (['src/Repositories', 'src/Contracts/Repositories'] as $sub) {
            foreach ($this->filesIn($record, $sub) as $file) {
                $content = (string) file_get_contents($file);

                $repositories[] = [
                    'class' => basename($file, '.php'),
                    'relFile' => $this->relative($record, $file),
                    'isInterface' => (bool) preg_match('/^\s*interface\s+\w+/m', $content),
                    'summary' => $this->classDocSummary($content),
                ];
            }
        }

        usort($repositories, static fn (array $a, array $b): int => $a['class'] <=> $b['class']);

        return $repositories;
    }

    /**
     * `protected $x = '...'` 형태의 문자열 프로퍼티 값을 읽습니다.
     *
     * @param  string  $content  소스
     * @param  string  $name  프로퍼티명
     * @return string|null 값 (없으면 null)
     */
    private function stringProperty(string $content, string $name): ?string
    {
        if (preg_match('/\$'.preg_quote($name, '/')."\s*=\s*'([^']*)'/", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * `protected $x = [...]` 형태의 배열 프로퍼티 원소 수를 셉니다.
     *
     * 근사치입니다 — 문서의 규모 감을 주기 위한 값이며 계약 판정에 쓰지 않습니다.
     *
     * @param  string  $content  소스
     * @param  string  $name  프로퍼티명
     * @return int|null 원소 수 (프로퍼티 없으면 null)
     */
    private function arrayPropertyCount(string $content, string $name): ?int
    {
        if (! preg_match('/\$'.preg_quote($name, '/').'\s*=\s*\[(.*?)\];/s', $content, $m)) {
            return null;
        }

        return preg_match_all("/'[^']*'/", $m[1]);
    }

    /**
     * 클래스 docblock 의 첫 문장을 요약으로 뽑습니다.
     *
     * @param  string  $content  소스
     * @return string|null 요약 (없으면 null)
     */
    private function classDocSummary(string $content): ?string
    {
        if (! preg_match('#/\*\*(.*?)\*/\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum|interface|trait)\s+\w+#s', $content, $m)) {
            return null;
        }

        foreach (explode("\n", $m[1]) as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line) ?? '');
            if ($line !== '' && ! str_starts_with($line, '@')) {
                return $line;
            }
        }

        return null;
    }

    /**
     * FQCN 에서 클래스 짧은 이름을 뽑습니다.
     *
     * @param  string  $fqcn  클래스명
     * @return string 짧은 이름
     */
    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));

        return end($parts) ?: $fqcn;
    }

    /**
     * 확장 하위 디렉토리의 PHP 파일을 열거합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $sub  확장 루트 기준 하위 경로
     * @return array<int, string> PHP 파일 절대 경로
     */
    private function filesIn(array $record, string $sub): array
    {
        $dir = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub);
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * 확장 루트 기준 상대 경로로 변환합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @param  string  $absolute  절대 경로
     * @return string 상대 경로 (POSIX 구분자)
     */
    private function relative(array $record, string $absolute): string
    {
        $base = rtrim((string) $record['path'], '/\\').DIRECTORY_SEPARATOR;
        $rel = str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : $absolute;

        return str_replace('\\', '/', $rel);
    }
}
