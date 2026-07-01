<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * name/phone/birthday 를 nullable 로 변경한다.
     * 누락 필드를 Crypt::encryptString('') 로 "암호화된 빈 문자열" 저장하면 복호화 시 빈 칸이
     * 나오는 오염 레코드가 되므로, 누락 시 null 로 정합 저장하기 위해 NOT NULL 제약을 해제한다.
     * (name/phone/birthday 는 모두 신원 핵심값이라 verify() 가드(INCOMPLETE_IDENTITY)가 누락을
     *  차단하므로 정상 경로에선 항상 채워진다. nullable 화는 가드를 우회한 비정상 입력이 암호화된
     *  빈 문자열로 오염 저장되는 것을 막기 위한 방어적 통일이다.)
     */
    public function up(): void
    {
        if (! Schema::hasTable('inicis_identity_records')) {
            return;
        }

        Schema::table('inicis_identity_records', function (Blueprint $table) {
            $table->text('name_encrypted')->nullable()->comment('실명 (Crypt::encrypt — userName. 누락 시 null)')->change();
            $table->text('phone_encrypted')->nullable()->comment('휴대폰 (Crypt::encrypt — userPhone. 채널에 따라 미제공 시 null)')->change();
            $table->text('birthday_encrypted')->nullable()->comment('생년월일 YYYYMMDD (Crypt::encrypt — userBirthday. 누락 시 null)')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * 빈 컬럼이 있으면 NOT NULL 복귀가 실패할 수 있으므로, 컬럼 존재 확인 후 NOT NULL 로 되돌린다.
     * (실제 롤백 시점에 null 행이 있으면 DB 가 거부 — 운영자가 데이터 정리 후 재시도)
     */
    public function down(): void
    {
        if (! Schema::hasTable('inicis_identity_records')) {
            return;
        }

        Schema::table('inicis_identity_records', function (Blueprint $table) {
            $table->text('name_encrypted')->nullable(false)->comment('실명 (Crypt::encrypt — 이니시스 userName 복호화 후 저장)')->change();
            $table->text('phone_encrypted')->nullable(false)->comment('휴대폰 (Crypt::encrypt — userPhone)')->change();
            $table->text('birthday_encrypted')->nullable(false)->comment('생년월일 YYYYMMDD (Crypt::encrypt — userBirthday)')->change();
        });
    }
};
