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
        // 1. 기존 varchar 데이터를 JSON 형식으로 변환 (NULL은 유지)
        $columns = ['product_option_name', 'option_name', 'option_value'];

        foreach ($columns as $column) {
            DB::table('ecommerce_order_options')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($column) {
                    foreach ($rows as $row) {
                        $value = $row->{$column};

                        // 이미 JSON 형식이면 스킵
                        if (is_string($value) && str_starts_with($value, '{')) {
                            continue;
                        }

                        DB::table('ecommerce_order_options')
                            ->where('id', $row->id)
                            ->update([$column => json_encode(['ko' => $value], JSON_UNESCAPED_UNICODE)]);
                    }
                });
        }

        // 2. 컬럼 타입 변경: varchar → json
        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            $table->text('product_option_name')->nullable()->comment('옵션 조합명 (다국어, 예: {"ko": "빨강/XL", "en": "Red/XL"})')->change();
            $table->text('option_name')->nullable()->comment('옵션명 (다국어, 예: {"ko": "빨강/XL", "en": "Red/XL"})')->change();
            $table->text('option_value')->nullable()->comment('옵션값 요약 (다국어, 예: {"ko": "색상: 빨강, 사이즈: L", "en": "Color: Red, Size: L"})')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_order_options')) {
            return;
        }

        // 1. JSON → ko 값 추출
        $columns = ['product_option_name', 'option_name', 'option_value'];

        foreach ($columns as $column) {
            DB::table('ecommerce_order_options')
                ->whereNotNull($column)
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($column) {
                    foreach ($rows as $row) {
                        $value = $row->{$column};
                        $decoded = is_string($value) ? json_decode($value, true) : $value;

                        if (is_array($decoded)) {
                            $koValue = $decoded['ko'] ?? reset($decoded) ?: '';

                            DB::table('ecommerce_order_options')
                                ->where('id', $row->id)
                                ->update([$column => $koValue]);
                        }
                    }
                });
        }

        // 2. 컬럼 타입 복원: json → varchar
        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            $table->string('product_option_name', 255)->nullable()->comment('옵션 조합명 (예: "빨강 / XL")')->change();
            $table->string('option_name', 255)->nullable()->comment('옵션명 (예: "색상", "사이즈")')->change();
            $table->string('option_value', 255)->nullable()->comment('옵션값 (예: "빨강", "XL")')->change();
        });
    }
};
