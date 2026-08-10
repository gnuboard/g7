<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 목록(활동 로그·알림 발송 이력·회원)의 정렬 색인 끝에 기본키를 덧붙입니다.
 *
 * 세 목록 모두 `created_at DESC, id DESC` 로 정렬한다. 색인이 `created_at` 에서 끝나면
 * 동률 구간의 `id` 순서를 인덱스로 만들 수 없어 filesort 가 붙고, 지연 조인의 inner 가
 * 인덱스 순서 그대로 끝나지 못해 깊은 OFFSET 개선 폭이 사라진다. 활동 로그처럼 같은 초에
 * 여러 건이 쌓이는 테이블에서 동률 구간은 예외가 아니라 일상이다.
 *
 * 필요한 색인은 계측 프로파일 선언(filters / order / soft_delete)에서 도출한 것이며,
 * `ListIndexCoverageTest` 가 같은 규칙으로 전 목록을 검사한다.
 *
 * - activity_logs : (created_at) → (created_at, id) 교체
 * - notification_logs : (created_at, id) 신설 (기존 정렬 색인 없음)
 * - users : (created_at) → (created_at, id) 교체
 *
 * 교체 대상 단일 색인은 새 색인의 좌측 프리픽스라 그대로 두면 쓰기 비용만 늘어난다.
 * 새 색인을 먼저 만들고 기존 색인을 나중에 지우므로, 중간에 중단되어도 조회가 색인 없이
 * 남는 구간은 없다.
 */
return new class extends Migration
{
    /**
     * 대상 [테이블 => [신규 색인명, 컬럼, 교체 대상 기존 색인명(없으면 null)]]
     *
     * @var array<string, array{0: string, 1: array<int, string>, 2: string|null}>
     */
    private const TARGETS = [
        'activity_logs' => ['idx_activity_logs_created_id', ['created_at', 'id'], 'g7_activity_logs_created_at_index'],
        'notification_logs' => ['idx_notification_logs_created_id', ['created_at', 'id'], null],
        'users' => ['idx_users_created_id', ['created_at', 'id'], 'idx_users_created_at'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TARGETS as $table => [$newIndex, $columns, $oldIndex]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($existing, $newIndex, $columns, $oldIndex) {
                if (! in_array($newIndex, $existing, true)) {
                    $blueprint->index($columns, $newIndex);
                }

                if ($oldIndex !== null && in_array($oldIndex, $existing, true)) {
                    $blueprint->dropIndex($oldIndex);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TARGETS as $table => [$newIndex, $columns, $oldIndex]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($existing, $newIndex, $oldIndex) {
                if ($oldIndex !== null && ! in_array($oldIndex, $existing, true)) {
                    $blueprint->index(['created_at'], $oldIndex);
                }

                if (in_array($newIndex, $existing, true)) {
                    $blueprint->dropIndex($newIndex);
                }
            });
        }
    }
};
