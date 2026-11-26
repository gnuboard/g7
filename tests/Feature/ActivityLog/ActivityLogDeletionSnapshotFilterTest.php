<?php

namespace Tests\Feature\ActivityLog;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Concerns\GrantsExtensionAdminToken;
use Tests\TestCase;

/**
 * 활동 로그 목록의 「삭제 직전 기록만 보기」(`deletion_snapshot`) 필터.
 *
 * 켜면 영구 삭제(`*.force_deleted`)·선언 정리(`*.sync_deleted`)·커스텀 번역 삭제(`template_custom_translation.deleted`) 직전
 * 기록만 남는다 — 목록 행의 펼침 요약(`snapshot`)과 같은 판정(`ActivityLog::isDeletionSnapshotAction()`). 끄면 종전과 같다.
 *
 * @scenario-manifest tests/scenarios/activity-log-deletion-snapshot-filter.yaml
 */
class ActivityLogDeletionSnapshotFilterTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsExtensionAdminToken;

    /** @var list<string> 이 테스트가 만드는 액션 — 다른 행과 섞이지 않게 이것만 센다 */
    private const ACTIONS = [
        'probe888_layout.force_deleted',
        'probe888_menu.sync_deleted',
        'template_custom_translation.deleted',
        'probe888_user.update',
        'probe888_user.deleted',
        'template_custom_translation.updated',
    ];

    /**
     * 시험 행을 만들고 이 테스트의 액션만 거르는 목록 요청 결과(액션 이름 목록)를 돌려줍니다.
     *
     * @param  string  $query  추가 질의 문자열 (예: `deletion_snapshot=1`)
     * @return array{0: list<string>, 1: list<array<string, mixed>>} [정렬한 액션 목록, 응답 행]
     */
    private function listActions(string $query): array
    {
        $token = $this->extensionAdminToken(['core.activities.read']);
        $response = $this->withToken($token)->getJson('/api/admin/activity-logs?per_page=100&'.$query);
        $response->assertOk();

        $rows = array_values(array_filter($response->json('data.data') ?? [], fn (array $row) => in_array($row['action'], self::ACTIONS, true)
            && ($row['properties']['probe'] ?? null) === 'issue888'));
        $actions = array_column($rows, 'action');
        sort($actions);

        return [$actions, $rows];
    }

    /**
     * 시험 행 6건을 만듭니다 (삭제 직전 기록 3건, 아닌 것 3건).
     *
     * @return void 반환값 없음
     */
    private function seedLogs(): void
    {
        foreach (self::ACTIONS as $action) {
            ActivityLog::create([
                'log_type' => ActivityLogType::System,
                'action' => $action,
                'description_key' => 'activity_log.description.user_update',
                'description_params' => [],
                'properties' => ['probe' => 'issue888', 'key' => $action],
                'ip_address' => '127.0.0.1',
            ]);
        }
    }

    /**
     * 켜면 삭제 직전 기록만 나오고, 그 행은 모두 펼침 요약이 있다.
     *
     * @scenario filter=on
     *
     * @effects deletion_snapshot_filter_keeps_only_snapshot_rows
     *
     * @return void 반환값 없음
     */
    public function test_filter_on_returns_only_deletion_snapshot_rows(): void
    {
        $this->seedLogs();

        [$actions, $rows] = $this->listActions('deletion_snapshot=1');

        $this->assertSame(['probe888_layout.force_deleted', 'probe888_menu.sync_deleted', 'template_custom_translation.deleted'], $actions);
        foreach ($rows as $row) {
            $this->assertNotNull($row['snapshot'] ?? null, '거른 행은 모두 펼침 요약(snapshot)이 있는 행이어야 한다');
        }
    }

    /**
     * 끄거나(`0`) 주지 않으면 종전처럼 전부 나온다.
     *
     * @scenario filter=off
     *
     * @effects deletion_snapshot_filter_off_keeps_previous_list
     *
     * @return void 반환값 없음
     */
    public function test_filter_off_or_absent_returns_all_rows(): void
    {
        $this->seedLogs();

        $this->assertCount(6, $this->listActions('deletion_snapshot=0')[0]);
        $this->assertCount(6, $this->listActions('')[0]);
    }

    /**
     * 참·거짓이 아닌 값은 422 로 거절한다.
     *
     * @scenario filter=invalid
     *
     * @effects deletion_snapshot_filter_rejects_non_boolean
     *
     * @return void 반환값 없음
     */
    public function test_filter_rejects_non_boolean_value(): void
    {
        $token = $this->extensionAdminToken(['core.activities.read']);

        $this->withToken($token)->getJson('/api/admin/activity-logs?deletion_snapshot=yes')->assertStatus(422);
    }
}
