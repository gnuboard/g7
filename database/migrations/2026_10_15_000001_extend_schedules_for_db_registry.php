<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schedules 테이블을 DB 스케줄 등록부로 확장합니다 (17, 7.2.0).
 *
 * - type enum 에 job, frequency enum 에 everyThirtySeconds 추가
 * - without_overlapping 을 boolean 에서 "잠금 유지 분(0=끔)" 정수로 변경 (기존 1 → 1440)
 * - 실행 방식·이력 방식·실패 알림·활성 조건·다국어 명칭·시간대·커맨드 클래스 컬럼 추가
 *
 * 모든 단계는 컬럼 존재·타입 가드로 재실행에 안전하다(부분 적용 후 재실행 포함).
 */
return new class extends Migration
{
    /**
     * 확장 컬럼과 enum 값을 추가합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedules')) {
            return;
        }

        $this->extendTypeEnum();
        $this->extendFrequencyEnum();
        $this->convertWithoutOverlappingToMinutes();

        Schema::table('schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('schedules', 'on_one_server')) {
                $table->boolean('on_one_server')->default(false)->after('without_overlapping')
                    ->comment('단일 서버 실행 여부: 0(모든 서버), 1(한 서버만 — 공유 캐시 잠금)');
            }
            if (! Schema::hasColumn('schedules', 'dispatch_mode')) {
                $table->enum('dispatch_mode', ['sync', 'queue'])->default('sync')->after('on_one_server')
                    ->comment('실행 방식: sync(스케줄러 프로세스에서 바로 실행), queue(큐 Job 으로 보냄)');
            }
            if (! Schema::hasColumn('schedules', 'queue_connection')) {
                $table->string('queue_connection', 50)->nullable()->after('dispatch_mode')
                    ->comment('큐 연결명 (queue 실행 시, NULL 이면 워커 등록부 기본 배치)');
            }
            if (! Schema::hasColumn('schedules', 'queue_name')) {
                $table->string('queue_name', 100)->nullable()->after('queue_connection')
                    ->comment('큐 이름 (queue 실행 시, NULL 이면 워커 등록부 기본 배치)');
            }
            if (! Schema::hasColumn('schedules', 'history_mode')) {
                $table->enum('history_mode', ['all', 'failures_only'])->default('all')->after('queue_name')
                    ->comment('실행 이력 기록 방식: all(모든 실행), failures_only(실패만)');
            }
            if (! Schema::hasColumn('schedules', 'notify_on_failure')) {
                $table->boolean('notify_on_failure')->default(true)->after('history_mode')
                    ->comment('실패 알림 발송 여부: 0(미발송), 1(발송 — 스케줄당 하루 1회)');
            }
            if (! Schema::hasColumn('schedules', 'enabled_condition')) {
                $table->json('enabled_condition')->nullable()->after('is_active')
                    ->comment('실행 조건 (AND): [{"source":"core_setting|module_setting|plugin_setting|config","key":"...","operator":"truthy|not_empty|equals","value":...,"default":...}]');
            }
            if (! Schema::hasColumn('schedules', 'label')) {
                $table->json('label')->nullable()->after('name')
                    ->comment('표시용 다국어 명칭 (예: {"ko":"사이트맵 생성","en":"Generate sitemap"}). name 은 기계 식별자');
            }
            if (! Schema::hasColumn('schedules', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('expression')
                    ->comment('Cron 해석 시간대 (NULL 이면 app.schedule_timezone)');
            }
            if (! Schema::hasColumn('schedules', 'command_class')) {
                $table->string('command_class', 255)->nullable()->after('command')
                    ->comment('Artisan 커맨드 클래스 FQCN (웹 컨텍스트 실행 시 등록용, 선택)');
            }
        });

        $this->backfillLabels();
    }

    /**
     * 확장 컬럼과 enum 값을 되돌립니다.
     *
     * 옛 스키마에 없는 값을 가진 행은 되돌리기 전에 정리한다 — job 유형 행은 옛 스키마로
     * 표현할 수 없어 삭제하고, 30초 주기 행은 custom 주기로 바꾼다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('schedules')) {
            return;
        }

        $columns = array_values(array_filter([
            'on_one_server', 'dispatch_mode', 'queue_connection', 'queue_name', 'history_mode',
            'notify_on_failure', 'enabled_condition', 'label', 'timezone', 'command_class',
        ], fn (string $column) => Schema::hasColumn('schedules', $column)));

        if ($columns !== []) {
            Schema::table('schedules', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }

        if ($this->columnType('without_overlapping') !== 'tinyint') {
            DB::table('schedules')->where('without_overlapping', '>', 0)->update(['without_overlapping' => 1]);
            Schema::table('schedules', function (Blueprint $table) {
                $table->boolean('without_overlapping')->default(false)
                    ->comment('중복 실행 방지 여부: 0(허용), 1(방지)')->change();
            });
        }

        if ($this->enumHas('frequency', 'everyThirtySeconds')) {
            DB::table('schedules')->where('frequency', 'everyThirtySeconds')->update(['frequency' => 'custom']);
            Schema::table('schedules', function (Blueprint $table) {
                $table->enum('frequency', ['everyMinute', 'hourly', 'daily', 'weekly', 'monthly', 'custom'])->default('custom')
                    ->comment('실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의)')->change();
            });
        }

        if ($this->enumHas('type', 'job')) {
            DB::table('schedules')->where('type', 'job')->delete();
            Schema::table('schedules', function (Blueprint $table) {
                $table->enum('type', ['artisan', 'shell', 'url'])
                    ->comment('작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출)')->change();
            });
        }
    }

    /**
     * type enum 에 job 을 추가합니다.
     *
     * @return void
     */
    private function extendTypeEnum(): void
    {
        if ($this->enumHas('type', 'job')) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->enum('type', ['artisan', 'shell', 'url', 'job'])
                ->comment('작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출), job(Job 클래스)')->change();
        });
    }

    /**
     * frequency enum 에 everyThirtySeconds 를 추가합니다.
     *
     * @return void
     */
    private function extendFrequencyEnum(): void
    {
        if ($this->enumHas('frequency', 'everyThirtySeconds')) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->enum('frequency', ['everyThirtySeconds', 'everyMinute', 'hourly', 'daily', 'weekly', 'monthly', 'custom'])->default('custom')
                ->comment('실행 주기: everyThirtySeconds(30초마다), everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의)')->change();
        });
    }

    /**
     * without_overlapping 을 잠금 유지 분 단위 정수로 바꿉니다.
     *
     * 타입이 boolean(tinyint) 일 때만 바꾼다 — 이미 정수 분이면 1 은 "1분" 이라 다시 변환하면 안 된다.
     * 옛 값 1(방지)은 Laravel withoutOverlapping() 기본 잠금 시간과 같은 1440분으로 옮긴다.
     *
     * @return void
     */
    private function convertWithoutOverlappingToMinutes(): void
    {
        if ($this->columnType('without_overlapping') !== 'tinyint') {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('without_overlapping')->default(0)
                ->comment('중복 실행 방지 잠금 유지 시간 (분, 0=끔, 최대 10080)')->change();
        });

        DB::table('schedules')->where('without_overlapping', 1)->update(['without_overlapping' => 1440]);
    }

    /**
     * label 이 빈 행에 {app.locale: name} 을 채웁니다.
     *
     * @return void
     */
    private function backfillLabels(): void
    {
        $locale = (string) config('app.locale', 'ko');

        DB::table('schedules')->whereNull('label')->orderBy('id')->chunkById(500, function ($rows) use ($locale) {
            foreach ($rows as $row) {
                DB::table('schedules')->where('id', $row->id)->update([
                    'label' => json_encode([$locale => (string) $row->name], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    /**
     * 컬럼의 DB 타입명(소문자)을 반환합니다.
     *
     * @param  string  $column  컬럼명
     * @return string|null 타입명 (예: tinyint, smallint, enum), 컬럼이 없으면 null
     */
    private function columnType(string $column): ?string
    {
        foreach (Schema::getColumns('schedules') as $info) {
            if ($info['name'] === $column) {
                return strtolower((string) $info['type_name']);
            }
        }

        return null;
    }

    /**
     * enum 컬럼 정의에 값이 들어 있는지 확인합니다.
     *
     * @param  string  $column  컬럼명
     * @param  string  $value  찾을 enum 값
     * @return bool 포함 여부
     */
    private function enumHas(string $column, string $value): bool
    {
        foreach (Schema::getColumns('schedules') as $info) {
            if ($info['name'] === $column) {
                return str_contains((string) $info['type'], "'{$value}'");
            }
        }

        return false;
    }
};
