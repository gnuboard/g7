<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 확장 신뢰 키(운영자가 등록한 제작자 공개키) 표를 만듭니다.
 *
 * 확장·언어팩 서명 검증에 쓰는 제작자 Ed25519 공개키를 둔다. 코어에 박힌 sirsoft 키는 이 표가 아니라 코드 상수다.
 * 키마다 믿는 vendor 범위(`vendor_scope`)가 하나 이상 있어야 하고, 범위 밖 vendor 패키지에 그 키 서명이 붙어 있으면
 * 「미신뢰 키」로 차단한다. 추가·삭제는 관리자 비밀번호 재확인을 거친 전용 API 와 `extension:trust-key` 명령만 한다.
 */
return new class extends Migration
{
    /**
     * 확장 신뢰 키 표를 만듭니다 (이미 있으면 건너뜀).
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('extension_trust_keys')) {
            return;
        }

        Schema::create('extension_trust_keys', function (Blueprint $table) {
            $table->id()->comment('신뢰 키 ID');
            $table->string('key_id', 64)->unique()->comment('키 식별자 ({label 소문자}:{sha256(raw 공개키) 앞 16 hex} — 서버가 계산)');
            $table->string('public_key', 64)->comment('Ed25519 공개키 (raw 32바이트 표준 base64)');
            $table->string('label', 32)->comment('서명자 이름 (key_id 앞부분, 영문·숫자·_·-)');
            $table->json('vendor_scope')->comment('이 키를 믿는 vendor 목록 (하나 이상, sirsoft 불가)');
            $table->foreignId('created_by')->nullable()->comment('등록한 관리자 ID (관리자 삭제 시 NULL)')->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * 확장 신뢰 키 표를 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('extension_trust_keys');
    }
};
