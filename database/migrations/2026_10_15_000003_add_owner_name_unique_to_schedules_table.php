<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schedules 에 소유자·이름 유니크 인덱스를 추가합니다 (#17, 7.2.0).
 *
 * 코어·확장 선언 스케줄은 (extension_type, extension_identifier, name) 으로 동기화된다.
 * 인덱스를 걸기 전에 소유 행 중 같은 키의 중복은 가장 작은 id 만 남긴다.
 * 직접 등록 행(extension_type NULL)은 MySQL 유니크가 NULL 을 서로 다르게 보므로 대상이 아니다.
 */
return new class extends Migration
{
    /**
     * 소유 행 중복을 정리하고 유니크 인덱스를 추가합니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedules') || Schema::hasIndex('schedules', 'uq_schedules_owner_name')) {
            return;
        }

        $duplicates = DB::table('schedules')
            ->select('extension_type', 'extension_identifier', 'name', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('extension_type')
            ->groupBy('extension_type', 'extension_identifier', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('schedules')
                ->where('extension_type', $duplicate->extension_type)
                ->where('extension_identifier', $duplicate->extension_identifier)
                ->where('name', $duplicate->name)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->unique(['extension_type', 'extension_identifier', 'name'], 'uq_schedules_owner_name');
        });
    }

    /**
     * 유니크 인덱스를 제거합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('schedules') || ! Schema::hasIndex('schedules', 'uq_schedules_owner_name')) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropUnique('uq_schedules_owner_name');
        });
    }
};
