<?php

namespace Tests\Feature\Analytics\Api;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Enums\Analytics\CollectFailureReason;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsCollectFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Concerns\FakesConsentProviders;

/**
 * 진단 API — 「왜 아무것도 안 세어지는가」에 답하는 화면.
 *
 * 수집 실패는 전부 조용하다. 물어볼 곳이 없으면 운영자는 기능이 고장 난 줄만 알기 때문에,
 * 이 응답에 무엇이 들어 있는지가 곧 진단의 범위다.
 *
 * @scenario endpoint=diagnostics, role=read
 */
class DiagnosticsControllerTest extends AnalyticsApiTestCase
{
    use FakesConsentProviders;

    /**
     * 진단이 수집·큐·집계·저장·환경을 함께 보고하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_queue_archive_and_proxy_state
     */
    public function test_diagnostics_cover_every_silent_failure_path(): void
    {
        $this->visit();

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics');

        $response->assertOk();
        $data = $response->json('data');

        foreach (['collection', 'queue', 'archive', 'storage', 'geoip', 'trusted_proxy', 'broadcasting', 'cloud_ranges'] as $section) {
            $this->assertArrayHasKey($section, $data, "진단에 {$section} 이 빠지면 그 실패를 물어볼 곳이 없다");
        }

        $this->assertArrayHasKey('failures_24h', $data);
        $this->assertArrayHasKey('worker_hint', $data['queue']);
        $this->assertArrayHasKey('backlogged', $data['queue']);
        $this->assertArrayHasKey('lag_hours', $data['archive']);
        $this->assertArrayHasKey('excluded_today', $data['collection']);
        $this->assertSame(1, $data['collection']['tracked_today']);
    }

    /**
     * 진단의 `broadcasting.configured` 가 실제 방송 게이트와 같은 답을 내는지 확인합니다.
     *
     * 화면은 이 값 하나로 「방송을 기다릴지, 2초 폴링을 걸지」를 가른다. 그래서 이 값은 「키가
     * 있는가」가 아니라 **「방송이 실제로 나가는가」**여야 한다 — 키만 보고 판정하면 키는 넣었지만
     * 드라이버가 `null`/`log` 이거나 호스트가 빈 사이트에서 방송도 폴링도 없어 진행률 바가
     * 멈춘 채 남는다(#682 S3 라운드 3, 설계 결정으로 판정식을 `HookManager` 와 하나로 합쳤다).
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_queue_archive_and_proxy_state
     */
    public function test_broadcasting_is_reported_as_configured_only_when_broadcasts_go_out(): void
    {
        $this->visit();
        $admin = $this->readAdmin();

        $configured = fn (): ?bool => $this->actingAs($admin)
            ->getJson('/api/admin/analytics/diagnostics')
            ->json('data.broadcasting.configured');

        // 키는 있는데 드라이버가 로그/널 — `HookManager::broadcast()` 가 첫 줄에서 돌아선다.
        config(['broadcasting.default' => 'log', 'broadcasting.connections.reverb.key' => 'local-key']);
        $this->assertFalse($configured(), '방송이 안 나가면 폴링으로 내려가야 한다');
        $this->assertSame(HookManager::broadcastingEnabled(), $configured());

        // 드라이버는 맞는데 호스트가 비었다 — 연결 시도 없이 건너뛴다.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => '']);
        $this->assertFalse($configured(), '호스트가 없으면 방송은 나가지 않는다');
        $this->assertSame(HookManager::broadcastingEnabled(), $configured());

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        $this->assertTrue($configured());
        $this->assertSame(HookManager::broadcastingEnabled(), $configured());
    }

    /**
     * 집계가 돌았으면 그 시각이 보고되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_queue_archive_and_proxy_state
     */
    public function test_archive_progress_is_reported(): void
    {
        AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Day->value,
            'status' => ArchiveRunStatus::Success->value,
            'finished_at' => now('UTC')->subHours(3),
        ]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics');

        $response->assertOk();
        $this->assertNotNull($response->json('data.archive.last_day_run'));
        $this->assertSame(3, $response->json('data.archive.lag_hours'), '집계가 밀리면 화면 숫자가 그 시점에 멈춘다');
    }

    /**
     * 진단 카드가 스케줄러 판정과 화면 열 때 집계 켜짐 여부를 받는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_scheduler_and_on_view_state
     */
    public function test_archive_reports_scheduler_and_on_view_state(): void
    {
        // 예약 실행이 3시간 전이 마지막 — 스케줄러가 멈췄다.
        AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Hour->value,
            'trigger' => 'scheduled',
            'status' => ArchiveRunStatus::Success->value,
            'started_at' => now('UTC')->subHours(3),
            'finished_at' => now('UTC')->subHours(3),
        ]);

        $response = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics')->assertOk();

        $this->assertFalse($response->json('data.archive.scheduler.alive'));
        $this->assertNotNull($response->json('data.archive.scheduler.last_run_at'));
        // 바탕 클래스가 테스트용으로 끈 값이 그대로 실린다.
        $this->assertFalse($response->json('data.archive.on_view_enabled'));
    }

    /**
     * 적재 실패 목록이 사유로 걸러지는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=collect_failures, role=read, period=valid
     *
     * @effects collect_failures_can_be_filtered_by_reason
     */
    public function test_collect_failures_can_be_filtered(): void
    {
        AnalyticsCollectFailure::factory()->create(['reason' => CollectFailureReason::Validation->value]);
        AnalyticsCollectFailure::factory()->create(['reason' => CollectFailureReason::DbError->value]);

        $admin = $this->readAdmin();

        $all = $this->actingAs($admin)->getJson('/api/admin/analytics/collect-failures');
        $all->assertOk();
        $this->assertSame(2, $all->json('data.pagination.total'));

        $filtered = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/collect-failures?reason='.CollectFailureReason::Validation->value);
        $this->assertSame(1, $filtered->json('data.pagination.total'));

        $this->actingAs($admin)
            ->getJson('/api/admin/analytics/collect-failures?reason=there_is_no_such_reason')
            ->assertStatus(422);
    }

    /**
     * 진단 응답이 동의 제공자 선택 상태와 제공자 이름을 싣는지 확인합니다 (#639 §4.2).
     *
     * 동의 출처(`provider`)만 실으면 제공자를 고르지 못한 상태(ambiguous·missing)가 정상 연동과 구별되지
     * 않는다 — 화면은 그때 「제공자 선택 필요」 배지를 띄워야 한다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects diagnostics_report_consent_selection_and_provider_label
     */
    public function test_diagnostics_report_consent_selection_and_provider_label(): void
    {
        $this->resetConsentProviders();
        $read = fn () => $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/diagnostics')->assertOk()->json('data.collection');

        $none = $read();
        $this->assertSame('none', $none['consent_selection']);
        $this->assertSame('none', $none['consent_source']);
        $this->assertNull($none['consent_provider_label']);

        $this->fakeConsentProvider('alpha');
        $auto = $read();
        $this->assertSame('auto', $auto['consent_selection']);
        $this->assertSame('provider', $auto['consent_source']);
        $this->assertSame('Fake alpha', $auto['consent_provider_label']);

        $this->fakeConsentProvider('beta');
        $ambiguous = $read();
        $this->assertSame('ambiguous', $ambiguous['consent_selection']);
        $this->assertNull($ambiguous['consent_provider_label']);
    }

    /**
     * 진단 응답이 실패 로그 주소의 마스킹 사실을 함께 싣는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects masked_ip_is_labelled_as_masked
     */
    public function test_diagnostics_tells_the_screen_the_failure_address_is_masked(): void
    {
        // 실패 로그의 주소도 방문 원장과 같은 마스킹 규칙을 받는다(마이그레이션 주석). 그 사실을
        // 싣지 않으면 운영자가 뒤 옥텟이 지워진 값을 원래 주소로 읽고 그 주소로 차단·문의한다
        // (#682 S3 라운드 6).
        config(['g7_settings.core.analytics.ip_mask_octets' => 3]);

        $this->assertSame(
            3,
            $this->actingAs($this->readAdmin())
                ->getJson('/api/admin/analytics/diagnostics')
                ->assertOk()
                ->json('data.collection.ip_mask_octets'),
        );
    }

    /**
     * 실패 목록의 주소는 개인정보 권한이 있을 때만 채워진다.
     *
     * 실패 기록은 수집 모드대로 가린 주소를 남기지만, 가림이 0 옥텟(기본값)이면 원래 주소다.
     * 현재 접속자 화면은 같은 권한 경계에서 주소를 비우는데 실패 목록만 조회 권한에게 주소를
     * 보이면, 조회만 준 계정이 방문자 주소를 보는 우회로가 된다.
     *
     * @return void 없음
     *
     * @scenario endpoint=collect_failures, role=read
     *
     * @effects collect_failures_hide_addresses_without_the_privacy_permission
     */
    public function test_failure_addresses_need_the_privacy_permission(): void
    {
        AnalyticsCollectFailure::factory()->create(['ip' => '203.0.113.50']);

        $read = $this->actingAs($this->readAdmin())->getJson('/api/admin/analytics/collect-failures')->assertOk();
        $this->assertNull($read->json('data.data.0.ip'), '조회 권한만으로 방문자 주소가 보이면 안 된다');

        $privacy = $this->actingAs($this->privacyAdmin())->getJson('/api/admin/analytics/collect-failures')->assertOk();
        $this->assertSame('203.0.113.50', $privacy->json('data.data.0.ip'));
    }

    /**
     * 통계 응답의 시각이 사이트 표준시로 나가는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects analytics_times_are_rendered_in_the_site_timezone
     */
    public function test_analytics_responses_carry_site_timezone_times(): void
    {
        // 집계 경계는 사이트 자정에 잠겨 있다(`AnalyticsClock::dayRangeUtc`). 시각 표기가 UTC 로
        // 나가면 KST 사이트에서 「기간」이 15:00~15:00 으로 보이고, 보는 사람 기준으로 나가면
        // 「오늘 KPI」와 목록의 날짜가 서로 다른 하루를 가리킨다(#682 S3 라운드 6, 설계 결정).
        // 오프셋이 붙으면 브라우저가 자기 시간대로 다시 옮기므로 **오프셋 없는** 문자열이어야 한다.
        // 사이트 표준시를 명시해 둔다 — 테스트 환경 기본값은 UTC 라 그대로 두면 이 축이 성립하지 않는다.
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);
        $this->travelTo(Carbon::parse('2026-09-22 00:30:00', 'UTC'));

        $this->visit(['last_action_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC')]);

        $body = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/diagnostics')
            ->assertOk()
            ->json('data');

        $this->assertSame('Asia/Seoul', $body['timezone'] ?? null, '화면이 안내에 쓸 사이트 표준시가 실려야 한다');

        $lastTracked = $body['collection']['last_tracked_at'] ?? null;
        $this->assertNotNull($lastTracked);
        // 00:30 UTC = 09:30 KST. UTC 로 나가면 '00:30', 오프셋이 붙으면 '+' 가 남는다.
        $this->assertStringContainsString('09:30', (string) $lastTracked);
        $this->assertStringNotContainsString('+', (string) $lastTracked, '오프셋이 붙으면 브라우저가 다시 옮긴다');
        $this->assertStringNotContainsString('T', (string) $lastTracked);
    }

    /**
     * 진단 응답의 **모든** 시각이 사이트 표준시인지 확인합니다.
     *
     * 키 하나만 재면 나머지가 샌다 — 라운드 6 은 `last_tracked_at` 한 자리만 쟀고, 그 사이
     * `cloud_ranges.updated_at` 은 오프셋 붙은 ISO8601 로 남아 있었다(#682 S3 라운드 7).
     * 그래서 모집단을 **응답에서 파생**한다: 페이로드를 재귀로 훑어 시각처럼 보이는 문자열을
     * 전부 모으고, 그중 하나라도 `T`·오프셋을 달고 있으면 red 다. 키를 새로 더해도 자동으로
     * 검사 대상이 된다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects analytics_times_are_rendered_in_the_site_timezone
     */
    public function test_every_time_in_the_diagnostics_payload_is_site_standard(): void
    {
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);
        $this->travelTo(Carbon::parse('2026-09-22 00:30:00', 'UTC'));

        $this->visit(['last_action_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC')]);
        AnalyticsCollectFailure::factory()->create(['occurred_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC')]);
        AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Hour,
            'status' => ArchiveRunStatus::Success,
            'started_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC'),
        ]);
        // 클라우드 목록 갱신 시각은 목록 **파일**에 ISO8601 로 적힌다 — 응답 경계가 바꾸지 않으면 샌다.
        $listPath = storage_path('app/'.config('analytics.cloud_ip_ranges.storage_path', 'analytics/cloud-ip-ranges.json'));
        File::ensureDirectoryExists(dirname($listPath));
        File::put($listPath, json_encode(['updated_at' => '2026-09-22T00:30:00+00:00', 'ranges' => ['203.0.113.0/24']]));

        $body = $this->actingAs($this->readAdmin())
            ->getJson('/api/admin/analytics/diagnostics')
            ->assertOk()
            ->json('data');

        $offenders = [];
        $times = self::collectTimeLikeStrings($body, '', $offenders);

        // 모집단이 비면 red — 파생이 아무것도 못 찾았는데 초록이면 이 가드는 아무 말도 안 한 것이다.
        $this->assertGreaterThanOrEqual(
            3,
            count($times),
            '응답에서 시각을 찾지 못했다 — 파생이 깨졌거나 픽스처가 비었다',
        );
        $this->assertSame([], $offenders, '사이트 표준시가 아닌 시각: '.implode(' · ', $offenders));
    }

    /**
     * 시각을 싣는 다른 통계 응답도 **모든** 시각이 사이트 표준시인지 확인합니다.
     *
     * 진단 응답만 재면 같은 원장 시각을 싣는 방문자 목록·상세·현재 접속자·실행 이력·실패 목록이
     * 무가드로 남는다. 응답마다 같은 재귀 파생으로 훑고, 응답마다 시각을 하나 이상 찾아야 한다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitors, role=privacy
     *
     * @effects analytics_times_are_rendered_in_the_site_timezone
     */
    public function test_every_time_in_other_time_bearing_payloads_is_site_standard(): void
    {
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);
        $this->travelTo(Carbon::parse('2026-09-22 00:30:00', 'UTC'));

        $visit = $this->visit(['last_action_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC')]);
        AnalyticsCollectFailure::factory()->create(['occurred_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC')]);
        AnalyticsArchiveRun::factory()->create([
            'run_type' => ArchiveRunType::Hour,
            'status' => ArchiveRunStatus::Success,
            'started_at' => Carbon::parse('2026-09-22 00:30:00', 'UTC'),
            'finished_at' => Carbon::parse('2026-09-22 00:31:00', 'UTC'),
        ]);

        $admin = $this->privacyAdmin();
        $endpoints = [
            '/api/admin/analytics/realtime',
            '/api/admin/analytics/visitors?preset=today',
            '/api/admin/analytics/visitors/'.$visit->visitor_key,
            '/api/admin/analytics/archive-runs',
            '/api/admin/analytics/collect-failures',
        ];

        foreach ($endpoints as $endpoint) {
            $body = $this->actingAs($admin)->getJson($endpoint)->assertOk()->json();

            $offenders = [];
            $times = self::collectTimeLikeStrings($body, '', $offenders);

            $this->assertNotEmpty($times, "{$endpoint}: 응답에서 시각을 찾지 못했다 — 파생이 깨졌거나 픽스처가 비었다");
            $this->assertSame([], $offenders, "{$endpoint}: 사이트 표준시가 아닌 시각: ".implode(' · ', $offenders));
        }
    }

    /**
     * 페이로드에서 시각처럼 보이는 문자열을 재귀로 모읍니다.
     *
     * `Y-m-d H:i:s`(사이트 표준시 규약)와 ISO8601 을 함께 잡고, 후자는 위반으로 적습니다.
     * 일자만 있는 값(`Y-m-d`)은 시각이 아니므로 세지 않습니다.
     *
     * @param  mixed  $node  페이로드 조각
     * @param  string  $path  현재 경로 (오류 메시지용)
     * @param  array<int, string>  $offenders  위반 목록 (참조로 채운다)
     * @return array<int, string> 찾은 시각 경로 목록
     */
    private static function collectTimeLikeStrings(mixed $node, string $path, array &$offenders): array
    {
        if (is_array($node)) {
            $found = [];
            foreach ($node as $key => $child) {
                $found = array_merge($found, self::collectTimeLikeStrings($child, $path === '' ? (string) $key : "{$path}.{$key}", $offenders));
            }

            return $found;
        }

        if (! is_string($node) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $node)) {
            return [];
        }

        // `T` 구분자나 오프셋(`+09:00`·`Z`)이 있으면 브라우저가 자기 시간대로 다시 옮긴다.
        if (str_contains($node, 'T') || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $node)) {
            $offenders[] = "{$path} = {$node}";
        }

        return [$path];
    }

    /**
     * 사용자 시간대가 사이트와 다를 때만 안내 플래그가 서는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=diagnostics, role=read
     *
     * @effects analytics_times_are_rendered_in_the_site_timezone
     */
    public function test_timezone_notice_flag_stands_only_when_the_viewer_differs(): void
    {
        // 같은 시간대인 운영자에게 배너를 띄우면 군더더기다(설계 결정 「다를 때만」).
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul', 'app.default_user_timezone' => 'Asia/Seoul']);
        $same = $this->readAdmin();
        $same->forceFill(['timezone' => 'Asia/Seoul'])->save();

        $this->assertFalse(
            $this->actingAs($same)->getJson('/api/admin/analytics/diagnostics')->assertOk()->json('data.timezone_differs'),
        );

        $other = $this->readAdmin();
        $other->forceFill(['timezone' => 'Europe/London'])->save();

        $this->assertTrue(
            $this->actingAs($other)->getJson('/api/admin/analytics/diagnostics')->assertOk()->json('data.timezone_differs'),
            '시간대가 다른 운영자는 시각 기준을 알아야 한다',
        );
    }

    /**
     * 적재 실패 행마다 그 주소가 실제로 가려진 값인지 싣는지 확인합니다.
     *
     * 열 이름이 설정을 따라 「IP (마스킹됨)」이 되어도 설정 전 행은 원래 주소다(7.1.0 실측 O-E8).
     *
     * @return void 없음
     *
     * @scenario endpoint=collect_failures, role=privacy
     *
     * @effects each_address_row_says_whether_it_is_masked
     */
    public function test_each_failure_row_says_whether_its_address_is_masked(): void
    {
        config(['g7_settings.core.analytics.ip_mask_octets' => 1]);

        $raw = AnalyticsCollectFailure::factory()->create(['ip' => '198.51.100.23']);
        $masked = AnalyticsCollectFailure::factory()->create(['ip' => '198.51.100.0']);

        $rows = collect(
            $this->actingAs($this->privacyAdmin())
                ->getJson('/api/admin/analytics/collect-failures')
                ->assertOk()
                ->json('data.data'),
        )->keyBy('id');

        $this->assertFalse($rows[$raw->id]['ip_masked'] ?? null, '설정 전 원래 주소');
        $this->assertTrue($rows[$masked->id]['ip_masked'] ?? null, '설정대로 가려진 주소');
    }
}
