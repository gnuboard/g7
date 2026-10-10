<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 대상 테이블 */
    private const TABLE = 'ecommerce_mileage_transactions';

    /**
     * Run the migrations.
     *
     * 회원을 완전 삭제해도 마일리지 거래 기록은 남긴다(E3). 종전 외래키는 회원 삭제 시 거래 행을 함께 지웠다
     * (`cascadeOnDelete`). 이제 회원 연결만 끊는다(`user_id` NULL 허용 + `nullOnDelete`) — 주문
     * (`ecommerce_orders.user_id`)·동의 이력과 같은 규약이다. 서비스가 삭제 직전에 연결을 명시로 끊고, 외래키는
     * 안전망이다. 기존 행은 그대로 둔다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'user_id')) {
            return;
        }

        $foreignKey = DB::getTablePrefix().self::TABLE.'_user_id_foreign';

        if ($this->hasForeignKey($foreignKey)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()
                ->comment('회원 ID (회원 완전 삭제 시 NULL — 거래 기록은 보존)')->change();
        });

        if (! $this->hasForeignKey($foreignKey)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * 회원 연결이 끊긴 행(user_id NULL)이 있으면 NOT NULL·CASCADE 로 되돌릴 수 없다 — 그 행을 지우지 않고 그대로 둔다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'user_id')) {
            return;
        }

        if (DB::table(self::TABLE)->whereNull('user_id')->exists()) {
            return;
        }

        $foreignKey = DB::getTablePrefix().self::TABLE.'_user_id_foreign';

        if ($this->hasForeignKey($foreignKey)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->comment('회원 ID')->change();
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * 테이블에 이름이 같은 외래키가 있는지 확인합니다.
     *
     * @param  string  $name  외래키 이름 (접두사 포함)
     * @return bool 있으면 true
     */
    private function hasForeignKey(string $name): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
