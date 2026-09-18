<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\VisitorRecognizer;
use App\Enums\Analytics\ArchiveRunType;
use App\Jobs\Analytics\AnonymizeLedgerJob;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Support\Facades\Queue;

/**
 * 개인정보 API — 되돌릴 수 없는 길에 문이 몇 개 달려 있는가.
 *
 * 조건 없는 정보주체 삭제는 원장 전체를 지우는 사고가 된다. 그래서 「무엇이 없으면 돌지
 * 않는가」를 먼저 잠그고, 돌았을 때 집계가 그대로 남는지를 확인한다.
 *
 * @scenario endpoint=privacy_subjects, role=privacy
 */
class PrivacyControllerTest extends AnalyticsApiTestCase
{
    /**
     * 조건 없이는 조회·삭제가 되지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_lookup_requires_at_least_one_criterion
     */
    public function test_a_lookup_without_criteria_is_rejected(): void
    {
        $admin = $this->privacyAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/privacy/subjects')
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['confirm' => true])
            ->assertStatus(422);
    }

    /**
     * 확인 없이 삭제가 되지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy, confirm=missing
     *
     * @effects subject_delete_requires_confirmation
     */
    public function test_delete_requires_confirmation(): void
    {
        $visit = $this->visit(['user_id' => 77]);

        $this->actingAs($this->privacyAdmin())
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 77])
            ->assertStatus(422);

        $this->assertDatabaseHas('analytics_visits', ['id' => $visit->id]);
    }

    /**
     * 정보주체 삭제가 원장만 지우고 집계는 남기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy, confirm=given
     *
     * @effects subject_delete_leaves_aggregates_intact
     */
    public function test_delete_removes_the_ledger_but_keeps_aggregates(): void
    {
        $this->visit(['user_id' => 77]);
        $this->visit(['user_id' => 88]);
        $this->daily(now('UTC')->toDateString(), ['visits' => 42]);

        $response = $this->actingAs($this->privacyAdmin())
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 77, 'confirm' => true]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.deleted.visits'));
        $this->assertSame(1, $response->json('data.deleted.actions'));

        $this->assertSame(1, AnalyticsVisit::query()->count(), '다른 사람의 방문은 남는다');
        $this->assertSame(1, AnalyticsAction::query()->count());
        $this->assertSame(
            42,
            (int) AnalyticsStatsDaily::query()->value('visits'),
            '집계에는 개인을 가리키는 값이 없어 지울 것이 없고, 함께 지우면 그 기간 통계가 통째로 사라진다',
        );
    }

    /**
     * 가명화된 구간도 회원 번호로 찾아지는지 확인합니다.
     *
     * 가명화가 켜지면 `user_id` 가 지워지고 `user_hash` 만 남는다. 조회가 해시를
     * 함께 보지 않으면 그 구간의 기록이 한 건도 잡히지 않아, 열람·삭제 요청이
     * 「없음」으로 조용히 끝난다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects pseudonymized_visits_are_found_by_member_number
     */
    public function test_a_pseudonymized_visit_is_found_by_its_member_number(): void
    {
        // 가명화되어 저장된 방문 — 번호는 지워지고 해시만 남는다.
        $this->visit([
            'user_id' => null,
            'user_hash' => VisitorRecognizer::userHash(77),
        ]);
        $this->visit(['user_id' => null, 'user_hash' => VisitorRecognizer::userHash(88)]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?user_id=77');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.visits'), '가명화된 구간도 회원 번호로 찾아야 한다');
    }

    /**
     * 가명화된 구간의 삭제 요청이 실제로 지우는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects pseudonymized_visits_are_found_by_member_number
     */
    public function test_deleting_a_pseudonymized_subject_removes_its_ledger(): void
    {
        $this->visit(['user_id' => null, 'user_hash' => VisitorRecognizer::userHash(77)]);
        $this->visit(['user_id' => null, 'user_hash' => VisitorRecognizer::userHash(88)]);

        $response = $this->actingAs($this->privacyAdmin())
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 77, 'confirm' => true]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.deleted.visits'), '삭제 요청이 0건으로 조용히 끝나면 안 된다');
        $this->assertSame(1, AnalyticsVisit::query()->count(), '다른 사람의 방문은 남는다');
    }

    /**
     * 번호로 저장된 구간과 해시로 저장된 구간이 함께 나오는지 확인합니다.
     *
     * 소급 익명화를 중간에 돌리면 한 사람의 기록이 두 모양으로 나뉘어 남는다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects pseudonymized_visits_are_found_by_member_number
     */
    public function test_a_lookup_spans_both_plain_and_pseudonymized_visits(): void
    {
        $this->visit(['user_id' => 77, 'user_hash' => null]);
        $this->visit(['user_id' => null, 'user_hash' => VisitorRecognizer::userHash(77)]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?user_id=77');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.visits'), '두 모양으로 남은 같은 사람의 기록이 함께 나와야 한다');
    }

    /**
     * 내보내기가 방문과 활동을 함께 담는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_lookup_requires_at_least_one_criterion
     */
    public function test_export_carries_visits_and_their_actions(): void
    {
        $this->visit(['user_id' => 99]);

        $response = $this->actingAs($this->privacyAdmin())
            ->postJson('/api/admin/analytics/privacy/subjects/export', ['user_id' => 99]);

        $response->assertOk();

        // 열람권 이행의 산출물이라 운영자가 요청한 사람에게 그대로 건넬 수 있어야 한다 —
        // 화면에 그려 주는 JSON 이 아니라 내려받을 파일로 온다.
        $response->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename="analytics-subject-',
            (string) $response->headers->get('Content-Disposition'),
        );

        $payload = json_decode($response->streamedContent(), true);

        $this->assertCount(1, $payload['visits']);
        $this->assertCount(1, $payload['visits'][0]['actions']);
        $this->assertArrayHasKey('exported_at', $payload);
    }

    /**
     * 주소로 찾을 때 한계 안내가 함께 오는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_lookup_requires_at_least_one_criterion
     */
    public function test_an_address_lookup_carries_its_limitation_notice(): void
    {
        $this->visit(['ip' => '203.0.113.50']);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.50');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.visits'));
        $this->assertNotNull(
            $response->json('data.notice'),
            '익명 모드 구간은 오래되면 주소로 찾을 수 없다 — 화면이 그 한계를 알려야 한다',
        );
    }

    /**
     * 소급 익명화가 확인과 대상 지정을 요구하고 워커로 넘어가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy, confirm=missing
     *
     * @effects anonymize_requires_a_mask_or_hash
     */
    public function test_anonymize_requires_confirmation_and_a_target(): void
    {
        Queue::fake();

        $admin = $this->updateAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/privacy/anonymize', ['mask_octets' => 2])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/privacy/anonymize', ['confirm' => true])
            ->assertStatus(422);

        Queue::assertNothingPushed();

        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/privacy/anonymize', ['mask_octets' => 2, 'confirm' => true])
            ->assertStatus(202);

        Queue::assertPushed(AnonymizeLedgerJob::class);
    }

    /**
     * 익명화 실행 이력이 정해진 종류로 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy, confirm=given
     *
     * @effects anonymize_requires_a_mask_or_hash
     */
    public function test_anonymize_run_type_is_recorded(): void
    {
        $this->visit(['ip' => '203.0.113.50']);

        $this->actingAs($this->updateAdmin())
            ->postJson('/api/admin/analytics/privacy/anonymize', ['mask_octets' => 2, 'confirm' => true])
            ->assertStatus(202);

        $this->assertDatabaseHas('analytics_archive_runs', ['run_type' => ArchiveRunType::Anonymize->value]);
    }
}
