<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schedules.description 을 다국어 JSON 으로 바꿉니다 (17, 7.2.0).
 *
 * 평문 설명은 {app.locale: 원문} 으로 감싼 뒤 컬럼을 json 으로 변경한다.
 * 행 변환과 컬럼 변경 사이에 중단돼도 재실행에 안전하다 — 이미 JSON 객체로 해석되는 값은 건너뛴다.
 */
return new class extends Migration
{
    /**
     * 평문 설명을 다국어 JSON 으로 감싸고 컬럼을 json 으로 바꿉니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedules') || $this->columnType() === 'json') {
            return;
        }

        $locale = (string) config('app.locale', 'ko');

        DB::table('schedules')->whereNotNull('description')->orderBy('id')
            ->chunkById(500, function ($rows) use ($locale) {
                foreach ($rows as $row) {
                    if ($this->isLocaleObject($row->description)) {
                        continue;
                    }

                    DB::table('schedules')->where('id', $row->id)->update([
                        'description' => json_encode([$locale => (string) $row->description], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });

        Schema::table('schedules', function (Blueprint $table) {
            $table->json('description')->nullable()
                ->comment('다국어 설명 (예: {"ko":"설명","en":"Description"})')->change();
        });
    }

    /**
     * 다국어 JSON 설명을 평문으로 되돌립니다.
     *
     * app.locale 값을 우선 쓰고, 없으면 첫 번째 로케일 값을 쓴다.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable('schedules') || $this->columnType() !== 'json') {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->text('description')->nullable()->comment('설명')->change();
        });

        $locale = (string) config('app.locale', 'ko');

        DB::table('schedules')->whereNotNull('description')->orderBy('id')
            ->chunkById(500, function ($rows) use ($locale) {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->description, true);
                    if (! is_array($decoded)) {
                        continue;
                    }

                    $plain = $decoded[$locale] ?? (array_values($decoded)[0] ?? null);
                    DB::table('schedules')->where('id', $row->id)->update([
                        'description' => is_string($plain) ? $plain : null,
                    ]);
                }
            });
    }

    /**
     * 값이 이미 로케일 키를 가진 JSON 객체인지 확인합니다.
     *
     * @param  mixed  $value  description 원값
     * @return bool JSON 객체(연관 배열) 여부
     */
    private function isLocaleObject(mixed $value): bool
    {
        if (! is_string($value) || ! str_starts_with(ltrim($value), '{')) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && ! array_is_list($decoded);
    }

    /**
     * description 컬럼의 DB 타입명(소문자)을 반환합니다.
     *
     * @return string|null 타입명, 컬럼이 없으면 null
     */
    private function columnType(): ?string
    {
        foreach (Schema::getColumns('schedules') as $info) {
            if ($info['name'] === 'description') {
                return strtolower((string) $info['type_name']);
            }
        }

        return null;
    }
};
