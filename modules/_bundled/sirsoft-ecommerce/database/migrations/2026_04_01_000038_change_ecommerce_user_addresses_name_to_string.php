<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_user_addresses')) {
            return;
        }

        $columnType = Schema::getColumnType('ecommerce_user_addresses', 'name');

        if ($columnType !== 'text') {
            return;
        }

        $locale = app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'ko');

        // 1. 컬럼 타입 변경: json → string (기존 JSON 텍스트가 문자열로 보존됨)
        Schema::table('ecommerce_user_addresses', function (Blueprint $table) {
            $table->string('name', 100)->comment('배송지 별칭')->change();
        });

        // 2. 보존된 JSON 문자열을 현재 로케일 값으로 변환
        //    예: '{"ko": "집", "en": "Home"}' → '집'
        DB::table('ecommerce_user_addresses')
            ->orderBy('id')
            ->chunk(100, function ($addresses) use ($locale, $fallbackLocale) {
                foreach ($addresses as $address) {
                    $decoded = json_decode($address->name, true);

                    if (is_array($decoded)) {
                        $value = $decoded[$locale]
                            ?? $decoded[$fallbackLocale]
                            ?? reset($decoded)
                            ?: '';

                        DB::table('ecommerce_user_addresses')
                            ->where('id', $address->id)
                            ->update(['name' => $value]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_user_addresses')) {
            return;
        }

        Schema::table('ecommerce_user_addresses', function (Blueprint $table) {
            $table->text('name')->comment('배송지 별칭 (다국어: {"ko": "집", "en": "Home"})')->change();
        });
    }
};
