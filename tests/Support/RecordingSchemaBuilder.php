<?php

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use LogicException;

/**
 * 마이그레이션을 **실제 DDL 없이** 돌려 보고, 어떤 스키마 변경을 내려 했는지 기록하는 가짜 스키마 빌더.
 *
 * ## 왜 필요한가
 *
 * 마이그레이션 재실행 가드는 "앞선 실행이 중간에 멈춘 상태" 에서 다시 돌 때 드러난다. 그 상태를
 * 실제 테스트 DB 에 만들려면 컬럼·인덱스를 지워야 하는데, MySQL 은 DDL 을 트랜잭션으로 되돌리지
 * 않고 실행 직전에 열린 트랜잭션을 암묵 커밋한다. 공유 테스트 DB 의 테이블이 망가지고 격리도 깨진다.
 *
 * 그래서 `Schema` 파사드를 이 기록기로 바꿔 끼우고, 테스트가 선언한 **가상 스키마 상태**를 기준으로
 * `hasTable`/`hasColumn`/`hasIndex`/`getForeignKeys`/`getColumns` 에 답한다. `Schema::table`·`create`
 * 에 넘어온 콜백은 실제 {@see Blueprint} 에 적용해 **무엇을 추가·변경·삭제하려 했는지만** 읽고,
 * SQL 로 컴파일하거나 실행하지 않는다. 기록한 변경은 가상 상태에 반영하므로 같은 마이그레이션을
 * 두 번 돌려 두 번째 실행이 아무것도 내지 않는지도 볼 수 있다.
 *
 * 상태 형태:
 * ```php
 * ['orders' => [
 *     'columns' => ['id', 'status'],               // 컬럼 이름 목록
 *     'indexes' => ['orders_status_index'],         // 인덱스·유니크 이름 목록
 *     'foreign_keys' => ['fk_orders_user_id'],      // 외래키 제약 이름 목록
 *     'comments' => ['status' => '주문 상태'],       // 컬럼 주석 (getColumns 가 돌려준다)
 * ]]
 * ```
 */
class RecordingSchemaBuilder
{
    /**
     * 가상 스키마 상태.
     *
     * @var array<string, array{columns: array<int, string>, indexes: array<int, string>, foreign_keys: array<int, string>, comments: array<string, string|null>}>
     */
    private array $tables = [];

    /**
     * 기록된 스키마 변경 목록.
     *
     * @var array<int, array{operation: string, table: string, added: array<int, string>, changed: array<int, array{column: string, comment: string|null}>, commands: array<int, array{name: string, index: string|null}>}>
     */
    private array $operations = [];

    /**
     * 가상 스키마 상태로 기록기를 만듭니다.
     *
     * @param  array<string, array<string, mixed>>  $tables  테이블별 상태 (클래스 docblock 형태)
     */
    public function __construct(array $tables)
    {
        foreach ($tables as $name => $state) {
            $this->tables[$name] = [
                'columns' => array_values($state['columns'] ?? []),
                'indexes' => array_values($state['indexes'] ?? []),
                'foreign_keys' => array_values($state['foreign_keys'] ?? []),
                'comments' => $state['comments'] ?? [],
            ];
        }
    }

    /**
     * 마이그레이션 파일의 `up()`(또는 `down()`)을 이 기록기 위에서 실행하고 기록을 돌려줍니다.
     *
     * 실행 동안만 `Schema` 파사드를 바꿔 끼우고, 끝나면(예외여도) 원래 바인딩으로 되돌린다.
     *
     * @param  Application  $app  애플리케이션 컨테이너
     * @param  string  $migrationPath  마이그레이션 파일 절대 경로
     * @param  string  $method  실행할 메서드 (`up` 또는 `down`)
     * @return array<int, array<string, mixed>> 기록된 스키마 변경 목록
     */
    public function run(Application $app, string $migrationPath, string $method = 'up'): array
    {
        $migration = require $migrationPath;

        $app->instance('db.schema', $this);
        Facade::clearResolvedInstance('db.schema');

        try {
            $migration->{$method}();
        } finally {
            // bind() 는 같은 이름의 instance 를 버리므로 원래 해석 경로로 돌아간다.
            $app->bind('db.schema', fn ($app) => $app['db']->connection()->getSchemaBuilder());
            Facade::clearResolvedInstance('db.schema');
        }

        return $this->operations;
    }

    /**
     * 지금까지 기록된 스키마 변경 목록을 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> 기록된 스키마 변경 목록
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * 기록을 비웁니다 (가상 상태는 유지 — 두 번째 실행을 따로 보기 위해).
     *
     * @return void 반환값 없음
     */
    public function resetOperations(): void
    {
        $this->operations = [];
    }

    /**
     * 테이블 존재 여부.
     *
     * @param  string  $table  테이블명
     * @return bool 존재하면 true
     */
    public function hasTable($table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * 컬럼 존재 여부.
     *
     * @param  string  $table  테이블명
     * @param  string  $column  컬럼명
     * @return bool 존재하면 true
     */
    public function hasColumn($table, $column): bool
    {
        return in_array($column, $this->tables[$table]['columns'] ?? [], true);
    }

    /**
     * 인덱스 존재 여부 (이름으로만 판정한다).
     *
     * @param  string  $table  테이블명
     * @param  string|array<int, string>  $index  인덱스 이름
     * @param  string|null  $type  인덱스 종류 (무시)
     * @return bool 존재하면 true
     */
    public function hasIndex($table, $index, $type = null): bool
    {
        return is_string($index) && in_array($index, $this->tables[$table]['indexes'] ?? [], true);
    }

    /**
     * 인덱스 목록.
     *
     * @param  string  $table  테이블명
     * @return array<int, array<string, mixed>> 인덱스 목록
     */
    public function getIndexes($table): array
    {
        return array_map(
            fn (string $name) => ['name' => $name, 'columns' => [], 'type' => 'btree', 'unique' => false, 'primary' => false],
            $this->tables[$table]['indexes'] ?? [],
        );
    }

    /**
     * 외래키 목록.
     *
     * @param  string  $table  테이블명
     * @return array<int, array<string, mixed>> 외래키 목록
     */
    public function getForeignKeys($table): array
    {
        return array_map(
            fn (string $name) => ['name' => $name, 'columns' => []],
            $this->tables[$table]['foreign_keys'] ?? [],
        );
    }

    /**
     * 컬럼 목록 (이름·주석만 채운다).
     *
     * @param  string  $table  테이블명
     * @return array<int, array<string, mixed>> 컬럼 목록
     */
    public function getColumns($table): array
    {
        $state = $this->tables[$table] ?? ['columns' => [], 'comments' => []];

        return array_map(
            fn (string $name) => ['name' => $name, 'comment' => $state['comments'][$name] ?? null, 'nullable' => true, 'type' => null],
            $state['columns'],
        );
    }

    /**
     * 기존 테이블 변경 — 콜백을 Blueprint 에 적용해 변경 내용만 기록합니다.
     *
     * @param  string  $table  테이블명
     * @param  Closure  $callback  Blueprint 콜백
     * @return void 반환값 없음
     */
    public function table($table, Closure $callback): void
    {
        $this->record('table', $table, $callback);
    }

    /**
     * 테이블 생성 — 이미 있으면 실제 DB 처럼 실패한다.
     *
     * @param  string  $table  테이블명
     * @param  Closure  $callback  Blueprint 콜백
     * @return void 반환값 없음
     */
    public function create($table, Closure $callback): void
    {
        if ($this->hasTable($table)) {
            throw new LogicException("Base table or view already exists: {$table}");
        }

        $this->tables[$table] = ['columns' => [], 'indexes' => [], 'foreign_keys' => [], 'comments' => []];
        $this->record('create', $table, $callback);
    }

    /**
     * 테이블 삭제 (있을 때만).
     *
     * @param  string  $table  테이블명
     * @return void 반환값 없음
     */
    public function dropIfExists($table): void
    {
        if ($this->hasTable($table)) {
            unset($this->tables[$table]);
            $this->operations[] = ['operation' => 'drop', 'table' => $table, 'added' => [], 'changed' => [], 'commands' => []];
        }
    }

    /**
     * 지원하지 않는 스키마 호출은 조용히 넘기지 않는다 — 기록 누락이 곧 거짓 초록이 되기 때문이다.
     *
     * @param  string  $method  호출된 메서드
     * @param  array<int, mixed>  $arguments  인자
     * @return never 반환하지 않고 항상 예외를 던진다
     */
    public function __call($method, $arguments)
    {
        throw new LogicException("RecordingSchemaBuilder 가 지원하지 않는 스키마 호출: {$method}");
    }

    /**
     * Blueprint 콜백을 적용해 변경 내용을 기록하고 가상 상태에 반영합니다.
     *
     * @param  string  $operation  `table` 또는 `create`
     * @param  string  $table  테이블명
     * @param  Closure  $callback  Blueprint 콜백
     * @return void 반환값 없음
     */
    private function record(string $operation, string $table, Closure $callback): void
    {
        $blueprint = new Blueprint(DB::connection(), $table);
        if ($operation === 'create') {
            $blueprint->create();
        }
        $callback($blueprint);

        $added = [];
        $changed = [];
        foreach ($blueprint->getColumns() as $column) {
            if ($column->change) {
                $changed[] = ['column' => $column->name, 'comment' => $column->comment];
                $this->tables[$table]['comments'][$column->name] = $column->comment;

                continue;
            }

            // 실제 DB 가 거절하는 것은 여기서도 거절한다 — 가드 공백이 조용히 통과하지 않게.
            if ($this->hasColumn($table, $column->name)) {
                throw new LogicException("Duplicate column name: {$table}.{$column->name}");
            }

            $added[] = $column->name;
            $this->tables[$table]['columns'][] = $column->name;
            $this->tables[$table]['comments'][$column->name] = $column->comment;
        }

        $commands = [];
        foreach ($blueprint->getCommands() as $command) {
            // 변경 모드의 Blueprint 는 컬럼 정의도 명령 목록에 싣는다 — 컬럼은 위에서 이미 읽었다.
            if ($command instanceof ColumnDefinition || $command->name === 'create') {
                continue;
            }

            $name = $command->name;
            $index = is_string($command->index ?? null) ? $command->index : null;
            $commands[] = ['name' => $name, 'index' => $index];

            $this->assertApplicable($table, $name, $index, (array) ($command->columns ?? []));

            match ($name) {
                'index', 'unique', 'fulltext', 'spatialIndex' => $this->tables[$table]['indexes'][] = $index,
                'foreign' => $this->tables[$table]['foreign_keys'][] = $index,
                'dropIndex', 'dropUnique' => $this->tables[$table]['indexes'] = array_values(array_diff($this->tables[$table]['indexes'], [$index])),
                'dropForeign' => $this->tables[$table]['foreign_keys'] = array_values(array_diff($this->tables[$table]['foreign_keys'], [$index])),
                'dropColumn' => $this->tables[$table]['columns'] = array_values(array_diff($this->tables[$table]['columns'], (array) $command->columns)),
                default => null,
            };
        }

        if ($operation === 'table' && $added === [] && $changed === [] && $commands === []) {
            return; // 빈 Blueprint 는 SQL 을 내지 않는다
        }

        $this->operations[] = [
            'operation' => $operation,
            'table' => $table,
            'added' => $added,
            'changed' => $changed,
            'commands' => $commands,
        ];
    }

    /**
     * 실제 DB 가 오류로 거절할 명령이면 같은 방식으로 실패시킵니다.
     *
     * 중복 인덱스·외래키 추가(`Duplicate key name`), 없는 인덱스·외래키·컬럼 삭제(`Can't DROP`)가
     * 대상이다. 이 검사가 없으면 가드가 빠진 마이그레이션도 기록기 위에서는 초록이 된다.
     *
     * @param  string  $table  테이블명
     * @param  string  $name  명령 이름
     * @param  string|null  $index  인덱스·제약 이름
     * @param  array<int, string>  $columns  명령 대상 컬럼
     * @return void 반환값 없음
     */
    private function assertApplicable(string $table, string $name, ?string $index, array $columns): void
    {
        $state = $this->tables[$table] ?? ['columns' => [], 'indexes' => [], 'foreign_keys' => []];

        $failure = match (true) {
            in_array($name, ['index', 'unique', 'fulltext', 'spatialIndex'], true) && in_array($index, $state['indexes'], true) => "Duplicate key name: {$index}",
            $name === 'foreign' && in_array($index, $state['foreign_keys'], true) => "Duplicate foreign key constraint name: {$index}",
            in_array($name, ['dropIndex', 'dropUnique'], true) && ! in_array($index, $state['indexes'], true) => "Can't DROP index {$index}; check that it exists",
            $name === 'dropForeign' && ! in_array($index, $state['foreign_keys'], true) => "Can't DROP foreign key {$index}; check that it exists",
            $name === 'dropColumn' && array_diff($columns, $state['columns']) !== [] => "Can't DROP column ".implode(', ', array_diff($columns, $state['columns'])).'; check that it exists',
            default => null,
        };

        if ($failure !== null) {
            throw new LogicException("{$table}: {$failure}");
        }
    }
}
