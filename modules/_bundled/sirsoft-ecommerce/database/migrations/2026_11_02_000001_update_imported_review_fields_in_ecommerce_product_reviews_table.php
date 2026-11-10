<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 후기 표가 회원·주문 없이 등록한 후기(다른 쇼핑몰에서 이관한 후기 등)를 담을 수 있게 합니다.
 *
 * - `order_option_id`·`user_id` 를 NULL 허용으로 바꾼다(외래키는 그대로 둔다 — NULL 은 참조 검사를 받지 않는다).
 * - 작성자 표시 이름 `author_name` 을 추가한다. 회원 후기는 비워 두고 회원 이름을 쓴다.
 */
return new class extends Migration
{
    private const TABLE = 'ecommerce_product_reviews';

    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            // ->change() 는 이미 NULL 허용이어도 같은 정의로 다시 적용될 뿐이라 재실행에 안전하다
            $table->unsignedBigInteger('order_option_id')
                ->nullable()
                ->comment('주문 옵션 ID (회원·주문 없이 등록한 후기는 NULL)')
                ->change();
            $table->unsignedBigInteger('user_id')
                ->nullable()
                ->comment('작성자 ID (회원·주문 없이 등록한 후기는 NULL)')
                ->change();
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'author_name')) {
                $table->string('author_name', 50)
                    ->nullable()
                    ->after('user_id')
                    ->comment('작성자 표시 이름 (회원·주문 없이 등록한 후기 — 다른 쇼핑몰 이관 등)');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     *
     * 회원·주문 없는 후기가 남아 있으면 NOT NULL 로 되돌릴 수 없으므로 그 컬럼은 NULL 허용으로 둔다.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'author_name')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('author_name');
            });
        }

        $restore = [
            'order_option_id' => '주문 옵션 ID',
            'user_id' => '작성자 ID',
        ];

        foreach ($restore as $column => $comment) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            if (DB::table(self::TABLE)->whereNull($column)->exists()) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column, $comment) {
                $table->unsignedBigInteger($column)
                    ->nullable(false)
                    ->comment($comment)
                    ->change();
            });
        }
    }
};
