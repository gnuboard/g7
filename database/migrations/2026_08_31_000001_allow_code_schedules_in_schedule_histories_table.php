<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 실행 이력 표를 **코드로 등록된 예약**까지 담도록 넓힙니다.
     *
     * 지금까지 이 표는 운영자가 화면에서 등록한 예약(`schedules` 행)에만 이력을 달 수
     * 있었다 — `schedule_id` 가 NOT NULL 이라 코드에 박힌 예약(사이트맵 생성·GC 등)은
     * 구조적으로 들어갈 수 없었고, 그래서 웹 크론 러너는 직전 1회 요약만 파일에 남겼다.
     * 그러면 「어젯밤 사이트맵 생성이 실패했나」를 나중에 확인할 방법이 없다.
     *
     * 두 계통이 한 표를 공유하게 두는 이유는, 예약 관리 화면이 정식 공개될 때 이력이
     * 두 군데로 갈리지 않게 하기 위해서다. 어느 계통의 행인지는 `source` 가 가른다.
     */
    public function up(): void
    {
        // 컬럼마다 존재 가드를 둔다 — 중간에 끊긴 migrate 를 다시 돌리면 가드 없는 추가는
        // "Column already exists" 로 죽고, 그 지점부터 뒤 마이그레이션이 전부 멈춘다.
        Schema::table('schedule_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule_histories', 'source')) {
                $table->string('source', 20)->default('database')->after('id')
                    ->comment('이력 출처: database(화면 등록 예약), code(코드 등록 예약)');
            }

            if (! Schema::hasColumn('schedule_histories', 'event_key')) {
                $table->string('event_key', 64)->nullable()->after('schedule_id')
                    ->comment('코드 예약 식별 키 (source=code 일 때, 러너가 도출)');
            }

            if (! Schema::hasColumn('schedule_histories', 'event_name')) {
                $table->string('event_name', 255)->nullable()->after('event_key')
                    ->comment('코드 예약 표시명 (source=code 일 때, 실행 시점 스냅샷)');
            }

            if (! Schema::hasColumn('schedule_histories', 'event_expression')) {
                $table->string('event_expression', 100)->nullable()->after('event_name')
                    ->comment('코드 예약 실행 주기 (source=code 일 때, 실행 시점 스냅샷)');
            }
        });

        if (! $this->indexExists('idx_source_started_at')) {
            Schema::table('schedule_histories', function (Blueprint $table) {
                // 코드 예약의 최근 이력을 시각 역순으로 뽑는 조회를 위한 인덱스.
                $table->index(['source', 'started_at'], 'idx_source_started_at');
            });
        }

        // 코드 예약에는 대응하는 `schedules` 행이 없다 — FK 를 유지한 채 NULL 을 허용한다.
        // 컬럼을 nullable 로 바꾸려면 FK 를 먼저 떼었다가 다시 붙여야 한다.
        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
        });

        Schema::table('schedule_histories', function (Blueprint $table) {
            // audit:allow migration-idempotency-guard 컬럼 추가가 아니라 기존 컬럼의 change() 다 — hasColumn 가드는 성립하지 않고(언제나 참), 같은 값으로의 재실행은 무해하다.
            $table->foreignId('schedule_id')->nullable()
                ->comment('스케줄 ID (source=code 면 NULL)')->change();
        });

        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('schedules')->cascadeOnDelete();
        });
    }

    /**
     * 인덱스가 이미 있는지 판정합니다.
     *
     * 재실행 안전성을 위해서다 — 인덱스 추가는 컬럼 추가와 달리 `Schema::hasColumn`
     * 으로 가릴 수 없다.
     *
     * @param  string  $name  인덱스 이름
     * @return bool 존재하면 true
     */
    private function indexExists(string $name): bool
    {
        $table = DB::getTablePrefix().'schedule_histories';

        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    /**
     * Reverse the migrations.
     *
     * 코드 예약 이력은 되돌릴 자리가 없으므로 먼저 걷어낸다 — 남겨 두면 `schedule_id`
     * 를 NOT NULL 로 되돌리는 순간 그 행들 때문에 실패한다.
     */
    public function down(): void
    {
        DB::table('schedule_histories')->where('source', 'code')->delete();

        if ($this->indexExists('idx_source_started_at')) {
            Schema::table('schedule_histories', function (Blueprint $table) {
                $table->dropIndex('idx_source_started_at');
            });
        }

        Schema::table('schedule_histories', function (Blueprint $table) {
            foreach (['source', 'event_key', 'event_name', 'event_expression'] as $column) {
                if (Schema::hasColumn('schedule_histories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
        });

        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable(false)->change();
        });

        Schema::table('schedule_histories', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('id')->on('schedules')->cascadeOnDelete();
        });
    }
};
