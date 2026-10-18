<?php

namespace Tests\Feature\Analytics\Api;

use App\Analytics\AnalyticsClock;
use App\Analytics\VisitorRecognizer;
use App\Contracts\Repositories\Analytics\SaltRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\VisitorType;
use App\Jobs\Analytics\AnonymizeLedgerJob;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Support\Carbon;
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
     * 조회 응답이 개인정보 모드를 함께 내는지 확인합니다.
     *
     * 화면의 익명 모드 안내는 이 값을 읽는다. 조회 권한(`diagnostics`)에서 읽으면
     * 개인정보 권한만 가진 담당자에게 403 이고, 삼킨 뒤 남은 fallback 이 「full」로
     * 굳어 안내가 영영 뜨지 않았다 — 0건을 받고도 「기록 없음」인지 「IP 를 저장하지
     * 않음」인지 구분 못 해 GDPR 요청에 잘못 답할 수 있다(#682 S3 라운드 5).
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects privacy_subjects_carry_the_privacy_mode
     */
    public function test_the_lookup_carries_the_privacy_mode(): void
    {
        $this->visit(['ip' => '203.0.113.9']);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.9');

        $response->assertOk();
        $this->assertSame(
            'full',
            $response->json('data.mode'),
            '이 화면은 자기 권한 API 에서 모드를 읽어야 한다'
        );
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
     * 조회 응답이 내보내기와 같은 파일명을 알려 주는지 확인합니다.
     *
     * 내보내기는 토큰 동반 blob 으로 받아 화면이 저장 이름을 정한다. 서버 이름을 몰라 화면이 검색값을
     * 넣었더니 방문자 IP 가 다운로드 폴더에 남았다(#682 S3 라운드 15). 날짜는 사이트 기준이라 화면이
     * 계산할 수 없어 서버가 이름을 알려 준다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_export_filename_comes_from_the_server
     */
    public function test_the_lookup_names_the_export_file_the_way_the_download_does(): void
    {
        $this->visit(['ip' => '203.0.113.52']);
        $admin = $this->privacyAdmin();

        $lookup = $this->actingAs($admin)->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.52');
        $export = $this->actingAs($admin)->postJson('/api/admin/analytics/privacy/subjects/export', ['ip' => '203.0.113.52']);

        $lookup->assertOk();
        $export->assertOk();
        $filename = (string) $lookup->json('data.export_filename');

        $this->assertMatchesRegularExpression('/^analytics-subject-\d{8}\.json$/', $filename);
        $this->assertSame('attachment; filename="'.$filename.'"', (string) $export->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('203.0.113', $filename, '파일명에 검색한 주소가 들어가면 안 된다');
    }

    /**
     * 내보내기 파일 이름의 날짜가 사이트 표준시 날짜인지 확인합니다.
     *
     * 사이트가 Asia/Seoul 이고 UTC 로는 아직 전날인 시각(KST 06:50 = UTC 전날 21:50)에 받으면
     * 파일 이름이 전날 날짜로 나왔다(7.1.0 실측 E-D6) — 화면의 모든 날짜는 사이트 표준시다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_export_filename_uses_the_site_date
     */
    public function test_the_export_filename_uses_the_site_date(): void
    {
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 21:50:00', 'UTC'));

        try {
            $response = $this->actingAs($this->privacyAdmin())
                ->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.99');

            $response->assertOk();
            $this->assertSame('analytics-subject-20261006.json', $response->json('data.export_filename'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 코어 방문이 0건인 조회도 내보내기 파일 이름을 싣는지 확인합니다.
     *
     * 정보주체 화면은 이 이름이 왔는지로 「검색 응답이 도착했다」를 알고 내보내기·삭제를 켠다. 코어
     * 원장이 보존 기간으로 비었어도 확장은 자기 표에 그 사람의 기록을 가질 수 있어 0건에서도 두 버튼이
     * 눌려야 한다 — 0건 응답이 이름을 빼면 확장 기록을 지울 길이 화면에서 사라진다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects zero_visit_lookup_still_names_the_export_file
     */
    public function test_a_lookup_without_core_visits_still_names_the_export_file(): void
    {
        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.99');

        $response->assertOk();
        $this->assertSame([], $response->json('data.visits'));
        $this->assertMatchesRegularExpression('/^analytics-subject-\d{8}\.json$/', (string) $response->json('data.export_filename'));
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
     * 찾은 방문 행이 화면이 그리는 값을 모두 싣는지 확인합니다.
     *
     * 정보주체 화면의 표는 주소·페이지뷰·액션 수를 열로 두는데, 응답에 그 값이 없으면 운영자는 「IP -,
     * 페이지뷰 0, 액션 0」인 빈 표로 사람을 골라야 한다(브라우저 실측 §8 M5-T4).
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_rows_carry_what_the_screen_shows
     */
    public function test_found_visits_carry_the_columns_the_screen_shows(): void
    {
        $this->visit(['ip' => '203.0.113.51', 'pageviews_count' => 4, 'actions_count' => 6]);

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.51');

        $response->assertOk();
        $row = $response->json('data.visits.0');

        $this->assertSame('203.0.113.51', $row['ip'], '주소로 찾은 사람의 주소는 그 자리에서 보여야 한다');
        $this->assertSame(4, $row['pageviews_count']);
        $this->assertSame(6, $row['actions_count']);
        $this->assertSame(VisitorType::Guest->value, $row['visitor_type'], '유형 칸이 모두를 게스트로 적으면 안 된다');
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

    /**
     * 정보주체 조회 응답이 마스킹 사실을 함께 싣는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects masked_ip_is_labelled_as_masked
     */
    public function test_the_subject_search_tells_the_screen_the_address_is_masked(): void
    {
        // 이 표도 주소를 그린다 — 설정이 뒤 옥텟을 지운 값을 원본으로 읽으면 정보주체에게
        // 그 주소로 잘못 답한다. 형제 경로(`VisitorController::show`)와 같은 키를 싣는다
        // (#682 S3 라운드 6, 계획서 §4.10 「IP(마스킹 표시)」).
        config(['g7_settings.core.analytics.ip_mask_octets' => 1]);

        $this->visit(['ip' => '203.0.113.7']);

        $this->assertSame(
            1,
            $this->actingAs($this->privacyAdmin())
                ->getJson('/api/admin/analytics/privacy/subjects?ip=203.0.113.7')
                ->assertOk()
                ->json('data.ip_mask_octets'),
        );
    }

    /**
     * 검색 액션의 결과 건수가 타임라인 응답에 실리는지 확인합니다.
     *
     * 검색어만 실으면 방문자 상세 화면에서 「빈손으로 돌아간 검색」을 가려낼 수 없어 운영자가
     * 행동 화면의 「결과 없음」 열로 따로 건너가야 한다(§4.10 「검색어(+결과 수)」·§4.11
     * 「검색 "그누보드" 결과 12건」). 원장에는 수집 시점부터 있던 값이다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects subject_timeline_carries_search_result_count
     */
    public function test_export_timeline_carries_search_result_count(): void
    {
        $visit = $this->visit(['user_id' => 101]);
        $term = $this->actionName('search', 'gnuboard');

        // 0 건 검색도 실려야 한다 — 그것이 이 화면에서 가려낼 값이다.
        foreach ([[12, 'gnuboard'], [0, 'gnuboard-none']] as [$count, $slug]) {
            AnalyticsAction::factory()->create([
                'visit_id' => $visit->id,
                'visitor_key' => $visit->visitor_key,
                'action_type' => ActionType::SiteSearch,
                'action_name_id' => $slug === 'gnuboard' ? $term->id : $this->actionName('search', $slug)->id,
                'occurred_at' => Carbon::now('UTC'),
                'search_result_count' => $count,
            ]);
        }

        $response = $this->actingAs($this->privacyAdmin())
            ->postJson('/api/admin/analytics/privacy/subjects/export', ['user_id' => 101]);

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true);
        $actions = $payload['visits'][0]['actions'];

        // 모집단이 비면 아래 단언이 조용히 통과한다 — 그 자체를 먼저 잰다.
        $searches = array_values(array_filter(
            $actions,
            static fn (array $action): bool => $action['action_type'] === ActionType::SiteSearch->value,
        ));
        $this->assertCount(2, $searches, '검색 액션이 응답에 실려야 한다');

        $counts = array_map(static fn (array $action) => $action['search_result_count'] ?? null, $searches);
        sort($counts);
        $this->assertSame([0, 12], $counts, '결과 건수가 0 건까지 그대로 실려야 한다');

        // 검색이 아닌 액션에는 건수가 없다(키는 있고 값이 null).
        $pageviews = array_values(array_filter(
            $actions,
            static fn (array $action): bool => $action['action_type'] === ActionType::Pageview->value,
        ));
        $this->assertNotSame([], $pageviews, '대조군인 페이지뷰 액션');
        foreach ($pageviews as $action) {
            $this->assertArrayHasKey('search_result_count', $action);
            $this->assertNull($action['search_result_count']);
        }
    }

    /**
     * 익명 모드로 모은 방문은 어제·오늘 솔트로 만든 주소 해시로 찾아지고, 그보다 오래된 것은 찾아지지 않는다.
     *
     * 익명 모드는 주소를 저장하지 않고 그날 솔트로 만든 해시만 남긴다. 정보주체가 「이 주소의 기록」을
     * 물으면 남아 있는 솔트로 해시 후보를 만들어 대 본다 — 솔트가 지워진 날은 원리상 찾을 수 없고,
     * 화면이 그 한계를 알린다. 조회가 오늘 솔트만 쓰면 어제 방문을 「없음」으로 답하게 된다.
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects anonymous_visits_are_found_by_recent_salt_hashes
     */
    public function test_anonymous_visits_are_found_with_the_recent_salts(): void
    {
        $ip = '203.0.113.77';
        $salts = app(SaltRepositoryInterface::class);
        $today = Carbon::parse(AnalyticsClock::today());

        foreach ([0 => 'today', 1 => 'yesterday', 3 => 'three_days_ago'] as $offset => $label) {
            $salt = $salts->forDate($today->copy()->subDays($offset)->toDateString());
            $this->visit(['ip' => null, 'ip_hash' => hash_hmac('sha256', $ip, $salt), 'visitor_key' => str_pad($label, 32, 'x')]);
        }

        $response = $this->actingAs($this->privacyAdmin())
            ->getJson('/api/admin/analytics/privacy/subjects?ip='.$ip)
            ->assertOk();

        $this->assertCount(2, $response->json('data.visits'), '오늘·어제 솔트의 방문만 찾아져야 한다');
    }

    /**
     * 주소를 그리는 행마다 그 주소가 실제로 가려진 값인지 싣는지 확인합니다.
     *
     * 마스킹을 켜면서 소급을 건너뛰면 그 전 기록은 원래 주소 그대로 남는다. 열 이름만 설정을 따라
     * 「IP (마스킹됨)」으로 바뀌면 원래 주소가 가려진 값처럼 읽힌다(7.1.0 실측 O-E8).
     *
     * @return void 없음
     *
     * @scenario endpoint=privacy_subjects, role=privacy
     *
     * @effects each_address_row_says_whether_it_is_masked
     */
    public function test_each_subject_row_says_whether_its_address_is_masked(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 2]);

        // 가린 값이 공인 대역으로 나가지 않도록 사설 대역으로 잰다(문서용 대역도 두 옥텟을 지우면 범위를 벗어난다).
        $raw = $this->visit(['ip' => '10.20.30.40', 'user_id' => 4242]);
        $masked = $this->visit(['ip' => '10.20.0.0', 'user_id' => 4242]);

        $rows = collect(
            $this->actingAs($this->privacyAdmin())
                ->getJson('/api/admin/analytics/privacy/subjects?user_id=4242')
                ->assertOk()
                ->json('data.visits'),
        )->keyBy('visit_id');

        $this->assertFalse($rows[$raw->id]['ip_masked'] ?? null, '설정 전 원래 주소는 가려진 값이 아니다');
        $this->assertTrue($rows[$masked->id]['ip_masked'] ?? null, '설정대로 뒤 옥텟이 지워진 주소');
    }
}
