<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 실패한 CloudFront 캐시 무효화 대기열 테이블을 만듭니다.
     *
     * 한 행이 무효화 요청 한 건(경로 묶음)이다. 성공하면 행을 지우므로 남아 있는 행은 모두 실패 이력이다 —
     * next_attempt_at 이 있으면 재시도 대기, 없으면 시도 상한에 닿아 멈춘 건이다. caller_reference 는 첫 시도에
     * 보낸 CallerReference 라서, 응답만 잃은 첫 요청을 재시도가 같은 참조로 다시 보내면 CloudFront 가 새 무효화를 만들지 않는다.
     */
    public function up(): void
    {
        if (Schema::hasTable('cloudfront_purges')) {
            return;
        }

        Schema::create('cloudfront_purges', function (Blueprint $table) {
            $table->id()->comment('무효화 대기 ID');
            $table->uuid('caller_reference')->unique('uniq_cloudfront_purges_caller_reference')->comment('첫 시도에 쓴 무효화 호출자 참조 (재시도도 같은 값)');
            $table->json('paths')->comment('무효화할 CDN 경로 목록');
            $table->unsignedTinyInteger('attempts')->default(0)->comment('실패한 시도 횟수');
            $table->text('last_error')->nullable()->comment('마지막 실패 사유 (자격 증명 제외)');
            $table->timestamp('next_attempt_at')->nullable()->comment('다음 재시도 시각 (null 이면 재시도 중단)');
            $table->timestamp('created_at')->nullable()->comment('생성 시각');
            $table->timestamp('updated_at')->nullable()->comment('수정 시각');
            $table->index('next_attempt_at', 'idx_cloudfront_purges_next_attempt_at');
        });
    }

    /**
     * 테이블을 지웁니다.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloudfront_purges');
    }
};
