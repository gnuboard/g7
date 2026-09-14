<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 정의에 「관련 사용자」 relation 키 선언을 더합니다.
 *
 * 수신자 타입 `related_user` 는 리스너가 `context.related_users` 에 넣어 준 키를 찾는다.
 * 그런데 그 키는 **알림 유형마다 다르고** 대부분의 정의에는 하나도 없다 — 그런 정의에서
 * 「관련 사용자」를 고르면 수신자가 **0명**이 되고 예외도 로그도 남지 않는다. 화면이 그
 * 선택지를 세울지 말지 판단하려면 "이 정의가 무엇을 지원하는가" 가 데이터에 있어야 한다.
 *
 * 값 예: `["post_author", "parent_comment_author"]`. 선언이 없으면(`null`·빈 배열)
 * 화면은 「관련 사용자」 자체를 감춘다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        if (Schema::hasColumn('notification_definitions', 'relations')) {
            return;
        }

        Schema::table('notification_definitions', function (Blueprint $table) {
            $table->json('relations')
                ->nullable()
                ->after('hooks')
                ->comment('이 정의가 지원하는 관련 사용자 relation 키 목록 (선언이 없으면 화면이 「관련 사용자」를 감춘다)');
        });
    }

    /**
     * 마이그레이션을 되돌립니다.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('notification_definitions', 'relations')) {
            return;
        }

        Schema::table('notification_definitions', function (Blueprint $table) {
            $table->dropColumn('relations');
        });
    }
};
