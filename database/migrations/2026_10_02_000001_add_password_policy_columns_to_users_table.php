<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 테이블에 비밀번호 정책(변경일·만료·관리자 지정 변경 요구) 컬럼을 추가합니다.
 *
 * - password_changed_at             : 비밀번호를 마지막으로 바꾼 시각. NULL 이면 기능 도입 전 회원이라
 *                                     만료 기준일은 정책 시작일(`security.password_expiry_started_at`)이 된다.
 *                                     로그인 재해시(평문 불변)는 이 값을 바꾸지 않는다 — 서비스가 명시 기록한다.
 * - password_change_required        : 관리자가 정해 준 비밀번호라 다음 로그인 때 회원이 바꿔야 하는지.
 * - password_expiry_policy          : 회원 개별 만료 적용 (inherit 따름 / apply 적용 / exempt 제외).
 * - password_expiry_notified_at     : 현재 주기의 만료 사전 안내 발송 시각(표지 — 배치 멱등).
 * - password_expired_processed_at   : 현재 주기의 만료일 처리(만료 알림·로그인 해제) 시각(표지).
 * - password_change_postponed_until : '다음에 변경 허용' 모드에서 연기가 끝나는 시각.
 *
 * 인덱스 password_changed_at: 만료 배치가 이 값의 경계로 대상을 고른다.
 *
 * @since 7.1.0
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')
                    ->nullable()
                    ->after('password')
                    ->comment('비밀번호 마지막 변경 일시 (NULL: 기능 도입 전 — 만료 기준은 정책 시작일)');
            }

            if (! Schema::hasColumn('users', 'password_change_required')) {
                $table->boolean('password_change_required')
                    ->default(false)
                    ->after('password_changed_at')
                    ->comment('다음 로그인 때 비밀번호 변경 필수 여부 (관리자가 정해 준 비밀번호)');
            }

            if (! Schema::hasColumn('users', 'password_expiry_policy')) {
                $table->string('password_expiry_policy', 10)
                    ->default('inherit')
                    ->after('password_change_required')
                    ->comment('비밀번호 만료 개별 설정 (inherit: 따름, apply: 적용, exempt: 제외)');
            }

            if (! Schema::hasColumn('users', 'password_expiry_notified_at')) {
                $table->timestamp('password_expiry_notified_at')
                    ->nullable()
                    ->after('password_expiry_policy')
                    ->comment('현재 주기 비밀번호 만료 사전 안내 발송 일시');
            }

            if (! Schema::hasColumn('users', 'password_expired_processed_at')) {
                $table->timestamp('password_expired_processed_at')
                    ->nullable()
                    ->after('password_expiry_notified_at')
                    ->comment('현재 주기 비밀번호 만료일 처리(만료 알림·로그인 해제) 일시');
            }

            if (! Schema::hasColumn('users', 'password_change_postponed_until')) {
                $table->timestamp('password_change_postponed_until')
                    ->nullable()
                    ->after('password_expired_processed_at')
                    ->comment('비밀번호 만료 변경 연기 종료 일시 (다음에 변경 허용 모드)');
            }
        });

        // 인덱스는 컬럼과 따로 가드한다 — 컬럼 추가 뒤 이 단계에서 중단되면 MySQL 은 DDL 을
        // 되돌리지 않으므로, 컬럼 가드만 있으면 재실행이 "Duplicate key name" 으로 영구 실패한다.
        if (! Schema::hasIndex('users', 'users_password_changed_at_index')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('password_changed_at', 'users_password_changed_at_index');
            });
        }
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        if (Schema::hasIndex('users', 'users_password_changed_at_index')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_password_changed_at_index');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'password_change_postponed_until',
                'password_expired_processed_at',
                'password_expiry_notified_at',
                'password_expiry_policy',
                'password_change_required',
                'password_changed_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
