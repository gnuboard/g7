<?php

namespace App\Services\Upgrade;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\AbstractUpgradeStep;
use App\Extension\CoreVersionChecker;
use App\Extension\Update\PreviewEntryLoader;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * 실행 대기 중인 마이그레이션의 SQL 과 업그레이드 스텝 목록을 쓰기 없이 계산합니다 (`g7:migrate-plan`).
 *
 * 마이그레이션은 Laravel 연결의 pretend 로 SQL 만 모은다. 출처(`source`)는 `core`(database/migrations)·`custom`
 * (`database/migrations/custom` 등 마이그레이터에 등록된 비확장 경로)·`module:<id>`·`plugin:<id>` 이다. 확장은 번들 폴더
 * (새 판) 기준이다. 마이그레이션 안에서 DB 를 조회해 분기하는 코드는 pretend 에서 조회가 실행되지 않아 실제와 다를 수 있다.
 *
 * @since 7.2.0
 */
class MigrationPlanService
{
    /**
     * 파괴적 SQL 판정 패턴 (대소문자 무시).
     */
    private const DESTRUCTIVE_PATTERNS = [
        'drop' => '/\bdrop\s+(table|column|index|foreign|primary|unique|constraint|view)\b|\bdrop\b/i',
        'rename' => '/\brename\b/i',
        'change' => '/\balter\s+table\b.*\b(change|modify)\b/is',
        'not_null' => '/\balter\s+table\b.*\b(change|modify|alter\s+column)\b.*\bnot\s+null\b/is',
    ];

    /**
     * 스테이지 기간(옛 코드와 새 스키마가 함께 도는 동안) 미루기를 권하는 종류 — 컬럼 삭제·이름 변경·타입 변경·NOT NULL 전환.
     */
    private const DEFER_KINDS = ['drop_column', 'rename', 'change', 'not_null'];

    /**
     * @param  Migrator  $migrator  Laravel 마이그레이터
     * @param  ModuleRepositoryInterface  $modules  모듈 저장소
     * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
     * @param  PreviewEntryLoader  $entryLoader  새 판 진입 클래스 로더
     */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ModuleRepositoryInterface $modules,
        private readonly PluginRepositoryInterface $plugins,
        private readonly PreviewEntryLoader $entryLoader,
    ) {}

    /**
     * 계획을 만듭니다 — DB·파일 무변경.
     *
     * @param  string  $scope  범위 (all|core|extensions)
     * @param  array<string, string>|null  $pathSources  시험용 — 경로 → 출처 (주면 이 경로만 본다)
     * @return array{warning: string, migrations: array<int, array<string, mixed>>, upgrade_steps: array<int, array<string, mixed>>} 계획
     */
    public function plan(string $scope = 'all', ?array $pathSources = null): array
    {
        $paths = $pathSources ?? $this->defaultPathSources($scope);

        return [
            'warning' => (string) __('common.console.migrate_plan.branching_warning'),
            'migrations' => $this->pendingMigrations($paths),
            'upgrade_steps' => $pathSources === null ? $this->pendingUpgradeSteps($scope) : [],
        ];
    }

    /**
     * 범위별 마이그레이션 경로 → 출처.
     *
     * core 범위는 마이그레이터에 등록된 모든 비확장 경로다(`database/migrations` + `Migrator::paths()` — custom 포함).
     * custom 경로가 없으면 그냥 빠진다.
     *
     * @param  string  $scope  범위
     * @return array<string, string> 경로 → 출처
     */
    public function defaultPathSources(string $scope): array
    {
        $paths = [];
        if ($scope !== 'extensions') {
            $paths[database_path('migrations')] = 'core';
            foreach ($this->migrator->paths() as $path) {
                if (! is_dir($path) || $this->isExtensionPath($path)) {
                    continue;
                }
                $paths[$path] = $this->samePath($path, database_path('migrations')) ? 'core' : 'custom';
            }
        }
        if ($scope !== 'core') {
            foreach (['module' => $this->modules, 'plugin' => $this->plugins] as $type => $repository) {
                foreach ($repository->getAll() as $row) {
                    $id = (string) $row->identifier;
                    $bundled = base_path("{$type}s/_bundled/{$id}/database/migrations");
                    $active = base_path("{$type}s/{$id}/database/migrations");
                    $path = is_dir($bundled) ? $bundled : $active;
                    if (is_dir($path)) {
                        $paths[$path] = "{$type}:{$id}";
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * 경로들의 대기 마이그레이션과 그 SQL.
     *
     * @param  array<string, string>  $pathSources  경로 → 출처
     * @return array<int, array{name: string, source: string, file: string, queries: array<int, string>, destructive: bool, kinds: array<int, string>, defer_recommended: bool}> 목록
     */
    private function pendingMigrations(array $pathSources): array
    {
        $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];
        $out = [];

        foreach ($pathSources as $path => $source) {
            if (! is_dir($path)) {
                continue;
            }
            foreach ($this->migrator->getMigrationFiles([$path]) as $name => $file) {
                if (in_array($name, $ran, true)) {
                    continue;
                }
                $queries = $this->pretendQueries($file);
                $kinds = $this->classify($queries);
                $out[] = [
                    'name' => $name,
                    'source' => $source,
                    'file' => $this->relative($file),
                    'queries' => $queries,
                    'destructive' => $kinds !== [],
                    'kinds' => $kinds,
                    'defer_recommended' => array_intersect($kinds, self::DEFER_KINDS) !== [],
                ];
            }
        }

        return $out;
    }

    /**
     * 마이그레이션 한 파일의 up() SQL 을 pretend 로 모읍니다.
     *
     * @param  string  $file  마이그레이션 파일
     * @return array<int, string> SQL 목록
     */
    private function pretendQueries(string $file): array
    {
        // resolvePath() 는 protected 다 — 마이그레이터가 실제 실행에 쓰는 해석과 같은 식을 쓰려고 그 안에서 부른다
        $migration = (fn (string $path) => $this->resolvePath($path))->call($this->migrator, $file);
        $connection = DB::connection($migration->getConnection());

        try {
            $logged = $connection->pretend(function () use ($migration): void {
                if (method_exists($migration, 'up')) {
                    $migration->up();
                }
            });
        } catch (\Throwable $e) {
            return ['-- '.__('common.console.migrate_plan.pretend_failed', ['error' => $e->getMessage()])];
        }

        return array_values(array_map(fn (array $q) => (string) $q['query'], $logged));
    }

    /**
     * SQL 의 파괴적 종류를 가릅니다.
     *
     * @param  array<int, string>  $queries  SQL 목록
     * @return array<int, string> 종류 (drop_table|drop_column|drop|rename|change|not_null)
     */
    public function classify(array $queries): array
    {
        $kinds = [];
        foreach ($queries as $sql) {
            if (preg_match('/\bdrop\s+table\b/i', $sql)) {
                $kinds[] = 'drop_table';
            } elseif (preg_match('/\balter\s+table\b.*\bdrop\s+(column\s+)?[`"\w]/is', $sql) && ! preg_match('/\bdrop\s+(index|foreign|primary|unique|constraint|key)\b/i', $sql)) {
                $kinds[] = 'drop_column';
            } elseif (preg_match(self::DESTRUCTIVE_PATTERNS['drop'], $sql)) {
                $kinds[] = 'drop';
            }
            if (preg_match(self::DESTRUCTIVE_PATTERNS['rename'], $sql)) {
                $kinds[] = 'rename';
            }
            if (preg_match(self::DESTRUCTIVE_PATTERNS['not_null'], $sql)) {
                $kinds[] = 'not_null';
            } elseif (preg_match(self::DESTRUCTIVE_PATTERNS['change'], $sql)) {
                $kinds[] = 'change';
            }
        }

        return array_values(array_unique($kinds));
    }

    /**
     * 현재 판 → 새 판 범위의 업그레이드 스텝 목록.
     *
     * @param  string  $scope  범위
     * @return array<int, array{source: string, version: string, summary: string|null, destructive: bool|null}> 목록
     */
    private function pendingUpgradeSteps(string $scope): array
    {
        $out = [];
        if ($scope !== 'extensions') {
            $current = CoreVersionChecker::getCoreVersion();
            foreach ((array) File::glob(base_path('upgrades/Upgrade_*.php')) as $file) {
                if (! preg_match('/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/', pathinfo($file, PATHINFO_FILENAME), $m)) {
                    continue;
                }
                $version = "{$m[1]}.{$m[2]}.{$m[3]}".(! empty($m[4]) ? '-'.str_replace('_', '.', $m[4]) : '');
                if (version_compare($version, $current, '<=')) {
                    continue;
                }
                $out[] = ['source' => 'core', 'version' => $version] + $this->describeCoreStep($file);
            }
        }
        if ($scope !== 'core') {
            foreach (['module' => $this->modules, 'plugin' => $this->plugins] as $type => $repository) {
                foreach ($repository->getAll() as $row) {
                    $id = (string) $row->identifier;
                    $root = base_path("{$type}s/_bundled/{$id}");
                    if (! is_dir($root)) {
                        continue;
                    }
                    try {
                        $entry = $this->entryLoader->load($type, $id, $root);
                    } catch (\Throwable) {
                        $entry = null;
                    }
                    foreach ($entry?->upgrades() ?? [] as $version => $step) {
                        if (version_compare((string) $version, (string) $row->version, '<=')) {
                            continue;
                        }
                        $out[] = ['source' => "{$type}:{$id}", 'version' => (string) $version] + $this->describeStep($step);
                    }
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['source'], $a['version']] <=> [$b['source'], $b['version']]);

        return $out;
    }

    /**
     * 코어 스텝 파일의 설명.
     *
     * @param  string  $file  스텝 파일
     * @return array{summary: string|null, destructive: bool|null} 설명
     */
    private function describeCoreStep(string $file): array
    {
        try {
            require_once $file;
            $class = 'App\\Upgrades\\'.pathinfo($file, PATHINFO_FILENAME);

            return class_exists($class) ? $this->describeStep(new $class) : ['summary' => null, 'destructive' => null];
        } catch (\Throwable) {
            return ['summary' => null, 'destructive' => null];
        }
    }

    /**
     * 스텝 인스턴스의 설명 (`AbstractUpgradeStep::describe()`, 없으면 null).
     *
     * @param  mixed  $step  스텝
     * @return array{summary: string|null, destructive: bool|null} 설명
     */
    private function describeStep(mixed $step): array
    {
        // 설명은 확장이 쓴 코드다 — 한 스텝의 describe() 가 예외를 던져도 계획 출력 전체가 멈추지 않게 「설명 없음」으로 둔다
        try {
            $described = $step instanceof AbstractUpgradeStep ? $step->describe() : null;
        } catch (\Throwable) {
            $described = null;
        }

        return [
            'summary' => is_array($described) ? (string) ($described['summary'] ?? '') : null,
            'destructive' => is_array($described) ? (bool) ($described['destructive'] ?? false) : null,
        ];
    }

    /**
     * 확장 경로인지.
     *
     * @param  string  $path  경로
     * @return bool 확장 경로면 true
     */
    private function isExtensionPath(string $path): bool
    {
        $norm = str_replace('\\', '/', $path);

        return str_contains($norm, '/modules/') || str_contains($norm, '/plugins/') || str_contains($norm, '/templates/');
    }

    /**
     * 두 경로가 같은지 (구분자·끝 슬래시 무시).
     *
     * @param  string  $a  경로
     * @param  string  $b  경로
     * @return bool 같으면 true
     */
    private function samePath(string $a, string $b): bool
    {
        return rtrim(str_replace('\\', '/', $a), '/') === rtrim(str_replace('\\', '/', $b), '/');
    }

    /**
     * 저장소 기준 상대 경로.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_replace($base, '', str_replace('\\', '/', $path));
    }
}
