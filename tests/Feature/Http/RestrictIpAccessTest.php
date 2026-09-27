<?php

namespace Tests\Feature\Http;

use App\Enums\ActivityLogType;
use App\Extension\HookManager;
use App\Models\ActivityLog;
use App\Support\IpAccessMatcher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * IP 접근 제한 전역 미들웨어(RestrictIpAccess)의 실제 요청 경로 동작을 고정합니다.
 *
 * 판정식 자체는 IpAccessMatcherTest 가 잠그고, 여기서는 그 판정이 **요청 파이프라인의 올바른
 * 자리에서** 쓰이는지를 본다 — 신뢰 프록시 해석 뒤의 IP 로, 라우팅보다 먼저, 모든 관리자
 * 표면(셸·코어 API·확장 API·로그인 API)에서, 인바운드 콜백은 비켜서, 실패하면 열어 두되 기록을
 * 남기는지. 어느 하나가 어긋나도 요청은 정상 처리되거나 조용히 403 이 되어 원인이 드러나지 않는다.
 *
 * DB 는 활동 로그 행만 쓰므로 스키마를 새로 만들지 않고 트랜잭션으로 격리한다.
 */
class RestrictIpAccessTest extends TestCase
{
    use DatabaseTransactions;

    /** 허용 목록 밖의 방문자 */
    private const OUTSIDE_IP = '198.51.100.7';

    /** 허용 목록 안의 사무실 IP */
    private const OFFICE_IP = '203.0.113.9';

    /** 사무실 대역 */
    private const OFFICE_RANGE = '203.0.113.0/24';

    /**
     * 테스트가 남긴 정적 상태를 정리합니다.
     *
     * TrustProxies 는 요청 중 신뢰 프록시를 Request 의 정적 상태로 남기고, 훅 필터도 정적
     * 저장소다 — 비우지 않으면 다음 테스트의 IP 해석·제외 경로가 오염된다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        HookManager::clearFilter(IpAccessMatcher::EXEMPT_PATHS_HOOK);

        parent::tearDown();
    }

    /**
     * IP 접근 제한 설정을 켭니다 (부팅 미러 값을 이 테스트 전제로 덮는다).
     *
     * @param  array<string, mixed>  $security  덮어쓸 security 키
     * @return void 반환값 없음
     */
    private function restrict(array $security): void
    {
        config([
            'g7_settings.core.security.ip_restriction_enabled' => $security['ip_restriction_enabled'] ?? true,
            'g7_settings.core.security.admin_allowed_ips' => $security['admin_allowed_ips'] ?? [],
            'g7_settings.core.security.ip_restriction_rules' => $security['ip_restriction_rules'] ?? [],
        ]);
    }

    /**
     * 다음 요청의 접속 IP 를 지정합니다.
     *
     * @param  string  $ip  REMOTE_ADDR 로 쓸 IP
     * @return static 체이닝용 자신
     */
    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /**
     * 지정 IP 들로 남은 IP 차단 활동 로그 건수를 셉니다.
     *
     * @param  array<int, string>  $ips  대상 IP
     * @return int 건수
     */
    private function blockLogCount(array $ips): int
    {
        return ActivityLog::query()
            ->where('action', 'security.ip_blocked')
            ->whereIn('ip_address', $ips)
            ->count();
    }

    /**
     * 번들 확장 라우트 파일을 확장 로더와 같은 접두사·이름 규칙으로 등록합니다.
     *
     * 코어 테스트는 확장을 허용목록으로만 로드하므로 라우트 테이블에 확장 라우트가 없다. 로더
     * (Module/PluginRouteServiceProvider)와 같은 규칙으로 번들 원본을 직접 실어야 모집단이
     * 저장소 전체가 된다.
     *
     * @return void 반환값 없음
     */
    private function registerBundledExtensionRoutes(): void
    {
        foreach (['modules', 'plugins'] as $type) {
            foreach (File::directories(base_path("{$type}/_bundled")) as $directory) {
                $identifier = basename($directory);

                foreach (['api' => "api/{$type}/{$identifier}", 'web' => "{$type}/{$identifier}"] as $group => $prefix) {
                    $file = "{$directory}/src/routes/{$group}.php";

                    if (! File::exists($file)) {
                        continue;
                    }

                    Route::prefix($prefix)
                        ->name("{$group}.{$type}.{$identifier}.")
                        ->middleware($group)
                        ->group($file);
                }
            }
        }

        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * 마스터 토글이 꺼져 있으면 목록 밖 IP 도 라우트까지 도달합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=off, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects master_toggle_off_allows_everything
     */
    public function test_master_toggle_off_lets_every_request_through(): void
    {
        $this->restrict(['ip_restriction_enabled' => false, 'admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/admin/settings')->assertStatus(401);
    }

    /**
     * 관리자 API 는 목록 밖 IP 를 403 JSON 으로 끊습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client, ip_source=direct
     *
     * @effects admin_area_blocks_outside_ip_with_json_403
     */
    public function test_outside_ip_is_blocked_from_core_admin_api_with_json_403(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OUTSIDE_IP)
            ->getJson('/api/admin/settings')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => __('errors.ip_restricted.message'),
            ]);
    }

    /**
     * 목록 안 IP 는 라우트까지 도달합니다 (인증은 그다음 단계가 판정).
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=contains_client, ip_source=direct
     *
     * @effects allowed_ip_passes_through
     */
    public function test_allowed_ip_passes_to_the_route(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OFFICE_IP)->getJson('/api/admin/settings')->assertStatus(401);
    }

    /**
     * 관리자 셸 차단은 접속 IP 를 보여 주는 403 페이지이며 홈 버튼은 사이트 루트로 갑니다.
     *
     * 홈 버튼이 관리자 루트를 가리키면 방금 막힌 그 영역으로 되돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=admin_shell, admin_list=excludes_client
     *
     * @effects web_block_renders_403_page_with_client_ip
     */
    public function test_admin_shell_block_renders_403_page_with_client_ip(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $response = $this->fromIp(self::OUTSIDE_IP)->get('/admin/settings');

        $response->assertStatus(403);
        $response->assertSee(__('errors.ip_restricted.title'));
        $response->assertSee(self::OUTSIDE_IP);
        $response->assertSee('href="/"', false);
        $response->assertDontSee('href="/admin"', false);
    }

    /**
     * 확장 관리자 API·관리자 로그인 API 도 같은 목록으로 막습니다.
     *
     * 라우트 등록 여부와 무관하다 — 전역 미들웨어는 라우팅보다 먼저 돈다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=extension_admin_api, admin_list=excludes_client
     *
     * @effects extension_admin_api_is_covered, admin_login_api_is_covered
     */
    public function test_extension_admin_api_and_admin_login_api_are_covered(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/modules/sirsoft-ecommerce/admin/orders')->assertStatus(403);
        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/plugins/sirsoft-gdpr/admin/settings')->assertStatus(403);
        $this->fromIp(self::OUTSIDE_IP)->postJson('/api/auth/admin/login', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(403);
    }

    /**
     * 관리자 허용 목록은 관리자 영역 밖 경로에 영향을 주지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=public_path, admin_list=excludes_client, rule=none
     *
     * @effects non_admin_path_is_not_affected_by_admin_list
     */
    public function test_admin_list_does_not_touch_public_paths(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/__ip-probe-public')->assertStatus(404);
    }

    /**
     * 규칙에 맞는 경로는 규칙의 IP 만 통과시킵니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=public_path, admin_list=empty, rule=blocks_client
     *
     * @effects rule_blocks_outside_ip
     */
    public function test_rule_blocks_outside_ip_on_matching_path(): void
    {
        $this->restrict(['ip_restriction_rules' => [
            ['pattern' => '/api/__ip-probe*', 'ips' => [self::OFFICE_IP], 'memo' => 'probe'],
        ]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/__ip-probe-x')->assertStatus(403);
        $this->fromIp(self::OFFICE_IP)->getJson('/api/__ip-probe-x')->assertStatus(404);
    }

    /**
     * 꺼진 규칙은 건너뛰고, 사용 중인 첫 매칭 규칙 하나만 적용합니다.
     *
     * 뒤 규칙이 허용해도 앞 규칙이 막으면 막힌다 — 순서가 곧 우선순위다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=public_path, rule=disabled
     *
     * @effects first_enabled_matching_rule_applies
     */
    public function test_first_enabled_matching_rule_decides(): void
    {
        $this->restrict(['ip_restriction_rules' => [
            ['pattern' => '/api/__ip-probe/*', 'ips' => [self::OFFICE_IP], 'enabled' => false],
            ['pattern' => '/api/__ip-probe/strict*', 'ips' => [self::OFFICE_IP]],
            ['pattern' => '/api/__ip-probe/*', 'ips' => [self::OUTSIDE_IP]],
        ]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/__ip-probe/strict-area')->assertStatus(403);
        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/__ip-probe/open-area')->assertStatus(404);
    }

    /**
     * 결제 콜백·웹훅은 모든 경로를 막는 규칙 아래에서도 통과합니다.
     *
     * 외부 서버가 부르는 경로라 발신 IP 를 미리 알 수 없다. 막히면 결제가 미결로 남고 실패는 PG
     * 재시도 로그에만 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=inbound_callback, rule=blocks_client
     *
     * @effects payment_callbacks_are_exempt_from_rules
     */
    public function test_payment_callbacks_survive_a_catch_all_rule(): void
    {
        $this->restrict(['ip_restriction_rules' => [
            ['pattern' => '*', 'ips' => [self::OFFICE_IP]],
        ]]);

        $this->assertNotSame(403, $this->fromIp(self::OUTSIDE_IP)->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify')->getStatusCode());
        $this->assertNotSame(403, $this->fromIp(self::OUTSIDE_IP)->postJson('/api/plugins/sirsoft-message_bizppurio/webhook')->getStatusCode());
        $this->assertNotSame(403, $this->fromIp(self::OUTSIDE_IP)->post('/plugins/sirsoft-tosspayments/webhook/deposit')->getStatusCode());

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/__ip-probe-x')->assertStatus(403);
    }

    /**
     * 확장은 훅으로 자기 인바운드 경로를 제외 목록에 더할 수 있습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=inbound_callback, rule=blocks_client
     *
     * @effects extension_hook_can_exempt_its_callback
     */
    public function test_extension_can_exempt_its_own_callback_through_the_hook(): void
    {
        HookManager::addFilter(IpAccessMatcher::EXEMPT_PATHS_HOOK, fn (array $patterns) => [...$patterns, 'plugins/acme-pay/notify']);
        $this->restrict(['ip_restriction_rules' => [
            ['pattern' => 'plugins/*', 'ips' => [self::OFFICE_IP]],
        ]]);

        $this->assertNotSame(403, $this->fromIp(self::OUTSIDE_IP)->post('/plugins/acme-pay/notify')->getStatusCode());
        $this->fromIp(self::OUTSIDE_IP)->get('/plugins/acme-pay/other')->assertStatus(403);
    }

    /**
     * 훅이 관리자 영역 패턴을 등록해도 관리자 영역은 열리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects exemption_never_opens_admin_area
     */
    public function test_hook_cannot_open_the_admin_area(): void
    {
        HookManager::addFilter(IpAccessMatcher::EXEMPT_PATHS_HOOK, fn (array $patterns) => [...$patterns, 'admin/*', 'api/admin/*', '*']);
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/admin/settings')->assertStatus(403);
    }

    /**
     * 신뢰 프록시가 없으면 전달 헤더로 IP 를 속일 수 없습니다 (양방향).
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, ip_source=forwarded_untrusted
     *
     * @effects forwarded_header_cannot_spoof_without_trusted_proxy
     */
    public function test_forwarded_header_is_ignored_without_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => null]);
        $this->restrict(['admin_allowed_ips' => ['10.0.0.5']]);

        $this->fromIp('10.0.0.5')
            ->withHeader('X-Forwarded-For', self::OUTSIDE_IP)
            ->getJson('/api/admin/settings')
            ->assertStatus(401);

        $this->fromIp(self::OUTSIDE_IP)
            ->withHeader('X-Forwarded-For', '10.0.0.5')
            ->getJson('/api/admin/settings')
            ->assertStatus(403);
    }

    /**
     * 신뢰 프록시 뒤에서는 프록시가 전한 방문자 IP 로 판정합니다.
     *
     * 판정이 TrustProxies 보다 먼저 돌면 모든 요청이 프록시 주소 하나(10.0.0.5)로 보인다 — 이
     * 테스트는 그 배치 결함을 잡는다(첫 요청이 403 이 된다).
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, ip_source=forwarded_trusted
     *
     * @effects ip_is_resolved_after_trusted_proxies
     */
    public function test_client_ip_is_resolved_after_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_IP]]);

        $this->fromIp('10.0.0.5')
            ->withHeader('X-Forwarded-For', self::OFFICE_IP)
            ->getJson('/api/admin/settings')
            ->assertStatus(401);

        $this->fromIp('10.0.0.5')
            ->withHeader('X-Forwarded-For', self::OUTSIDE_IP)
            ->getJson('/api/admin/settings')
            ->assertStatus(403);
    }

    /**
     * 이중 스택 소켓의 IPv4-mapped 주소도 IPv4 목록으로 통과합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=contains_client, ip_source=ipv4_mapped
     *
     * @effects ipv4_mapped_address_matches_ipv4_entry
     */
    public function test_ipv4_mapped_client_matches_ipv4_list(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp('::ffff:'.self::OFFICE_IP)->getJson('/api/admin/settings')->assertStatus(401);
    }

    /**
     * 설정 값의 형태가 깨지면 열어 두고(fail-open) 오류를 기록합니다.
     *
     * 닫아 두면 설정 한 줄이 깨졌을 때 관리자를 포함한 전원이 잠기고 복구 수단이 셸뿐이 된다.
     * 설정 파일 자체의 손상은 JsonConfigRepository 가 기본값(꺼짐)으로 떨어뜨리므로, 여기서는
     * 그 너머의 실패(목록 자리에 배열이 아닌 값)를 Config 주입으로 대리 재현한다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api
     *
     * @effects malformed_settings_fail_open_with_error_log
     */
    public function test_malformed_settings_fail_open_with_an_error_log(): void
    {
        config([
            'g7_settings.core.security.ip_restriction_enabled' => true,
            'g7_settings.core.security.admin_allowed_ips' => self::OFFICE_RANGE,
        ]);
        Log::spy();

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/admin/settings')->assertStatus(401);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'IP 접근 제한 판정 실패'))
            ->once();
    }

    /**
     * 차단은 시스템 유형 활동 로그로 남고, 액션·설명 라벨이 번역됩니다.
     *
     * 활동 로그 i18n 감사 룰은 리스너만 재므로 미들웨어가 쓰는 키는 여기서 직접 확인한다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects block_is_recorded_as_system_activity
     */
    public function test_block_is_recorded_as_a_system_activity(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/admin/users')->assertStatus(403);

        $log = ActivityLog::query()
            ->where('action', 'security.ip_blocked')
            ->where('ip_address', self::OUTSIDE_IP)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, '차단 활동 로그가 남지 않았습니다.');
        $this->assertSame(ActivityLogType::System, $log->log_type);
        $this->assertSame('admin', $log->properties['axis'] ?? null);
        $this->assertSame('/api/admin/users', $log->properties['path'] ?? null);
        $this->assertSame(__('activity_log.action.ip_blocked'), $log->action_label);
        $this->assertNotSame('activity_log.action.ip_blocked', $log->action_label, '액션 라벨이 원문 키로 노출됩니다.');
        $this->assertStringContainsString(self::OUTSIDE_IP, $log->localized_description);
    }

    /**
     * 같은 IP 의 반복 차단은 간격 안에서 한 건만 기록합니다.
     *
     * 활동 로그는 요청마다 동기 DB 쓰기라, 제한이 없으면 차단 IP 의 반복 요청이 그대로 쓰기
     * 증폭기가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects block_log_is_throttled_per_ip
     */
    public function test_block_log_is_throttled_per_ip(): void
    {
        $this->restrict(['admin_allowed_ips' => [self::OFFICE_RANGE]]);
        $otherIp = '192.0.2.44';
        $before = $this->blockLogCount([self::OUTSIDE_IP, $otherIp]);

        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/admin/users')->assertStatus(403);
        $this->fromIp(self::OUTSIDE_IP)->getJson('/api/admin/roles')->assertStatus(403);
        $this->fromIp($otherIp)->getJson('/api/admin/users')->assertStatus(403);

        $this->assertSame(2, $this->blockLogCount([self::OUTSIDE_IP, $otherIp]) - $before);
    }

    /**
     * 관리자 영역 판정은 코어·번들 확장의 관리자 라우트 전부와 정확히 일치합니다.
     *
     * 모집단은 손으로 적은 목록이 아니라 라우트 테이블이다 — 확장이 새 관리자 라우트를 다른
     * 접두사로 만들면 이 테스트가 먼저 알린다. 역방향(관리자 영역인데 관리자 이름이 아닌 라우트)은
     * 이름 없는 관리자 셸 catch-all 만 허용한다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=extension_admin_api
     *
     * @effects admin_area_matches_every_admin_route
     */
    public function test_admin_area_matches_every_admin_route_of_core_and_bundled_extensions(): void
    {
        $this->registerBundledExtensionRoutes();

        $adminNamed = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();
            $isAdminNamed = is_string($name) && preg_match('/(^|\.)admin(\.|$)/', $name) === 1;

            if ($isAdminNamed) {
                $adminNamed++;
                $this->assertTrue(IpAccessMatcher::isAdminArea($uri), "관리자 라우트 {$name} ({$uri}) 가 관리자 영역 판정 밖입니다 — IP 보호에서 빠집니다.");
            } elseif (IpAccessMatcher::isAdminArea($uri)) {
                $this->assertNull($name, "관리자 영역 {$uri} 에 관리자 이름이 아닌 라우트({$name})가 있습니다 — 사용자 경로를 관리자 영역으로 오판합니다.");
            }
        }

        $this->assertGreaterThan(100, $adminNamed, '모집단이 비었습니다 — 라우트가 적재되지 않았습니다.');
    }

    /**
     * 번들 플러그인의 인바운드 콜백은 전부 제외 경로에 해당합니다.
     *
     * 모집단은 CSRF 검증을 뺀 웹 라우트(외부 서버가 토큰 없이 POST 하는 자리)와 웹훅 API 다.
     * 관례를 벗어난 새 콜백이 생기면 이 테스트가 red 가 된다 — 경로를 관례에 맞추거나
     * `core.security.ip_restriction_exempt_paths` 훅으로 등록해야 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=inbound_callback
     *
     * @effects every_bundled_inbound_callback_is_exempt
     */
    public function test_every_inbound_callback_of_bundled_plugins_is_exempt(): void
    {
        $this->registerBundledExtensionRoutes();

        $inbound = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            $isCsrfExemptWeb = str_starts_with($name, 'web.plugins.')
                && in_array(ValidateCsrfToken::class, $route->excludedMiddleware(), true);
            $isWebhookApi = str_starts_with($name, 'api.plugins.')
                && preg_match('/\.webhook(\.|$)/', $name) === 1;

            if ($isCsrfExemptWeb || $isWebhookApi) {
                $inbound[] = $route->uri();
            }
        }

        $this->assertGreaterThanOrEqual(10, count($inbound), '인바운드 콜백 모집단이 예상보다 작습니다 — 라우트 적재를 확인하세요.');

        foreach ($inbound as $uri) {
            $this->assertTrue(IpAccessMatcher::isExempt($uri), "인바운드 콜백 {$uri} 가 제외 경로 밖입니다 — 운영자 규칙에 걸리면 결제·인증 통보가 403 으로 끊깁니다.");
        }
    }
}
