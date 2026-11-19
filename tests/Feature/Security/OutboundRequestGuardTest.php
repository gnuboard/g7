<?php

namespace Tests\Feature\Security;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Enums\ScheduleType;
use App\Exceptions\LanguagePackOperationException;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Rules\PublicOutboundUrl;
use App\Services\DriverConnectionTester;
use App\Services\LanguagePackService;
use App\Services\Schedule\ScheduleDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 서버가 대신 보내는 outbound 요청이 내부망을 타격하지 못하는지 검증한다 (SSRF 방어).
 *
 * 각 케이스는 `Http::fake()` 로 실제 통신을 가로챈 뒤 `Http::assertNothingSent()` 로
 * "요청 자체가 나가지 않았음" 을 확인한다 — 게이트 도입 전에는 요청이 실제로 전송된다.
 */
class OutboundRequestGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response('ok', 200)]);

        // 개발 PC 에서 관리자 화면으로 「내부 주소 허용」을 켜 두면(storage/app/settings/security.json) 부팅 때
        // 설정으로 스며든다 — 기본값(차단)을 전제로 하는 단언이 환경에 따라 뒤집히지 않게 고정한다.
        Config::set('g7_settings.core.security.allow_internal_outbound_urls', false);
    }

    /**
     * 언어팩 설치는 내부 네트워크 주소에서 내려받지 않는다.
     *
     * @param  string  $url  내부망을 가리키는 다운로드 URL
     *
     * @scenario host_form=ideographic_dot_u3002, target=loopback_or_private, entry_point=language_pack_url
     *
     * @effects language_pack_install_from_url_rejects_normalized_internal_host
     *
     * @return void 반환값 없음
     */
    #[DataProvider('internalUrlProvider')]
    public function test_language_pack_install_from_url_blocks_internal_addresses(string $url): void
    {
        $this->expectException(LanguagePackOperationException::class);

        try {
            app(LanguagePackService::class)->installFromUrl($url, null);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * 언어팩 다운로드는 원격 코드를 가져오므로, 내부 주소 허용 설정을 켜도 계속 차단한다.
     *
     * @scenario entry_point=language_pack_url, target=loopback_or_private
     *
     * @effects language_pack_install_from_url_rejects_normalized_internal_host
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_install_stays_blocked_even_when_internal_is_allowed(): void
    {
        $this->allowInternalOutboundUrls();

        $this->expectException(LanguagePackOperationException::class);

        try {
            app(LanguagePackService::class)->installFromUrl('http://127.0.0.1/pack.zip', null);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * URL 호출 스케줄은 내부 네트워크 주소를 호출하지 않는다.
     *
     * @param  string  $url  내부망을 가리키는 스케줄 URL
     *
     * @scenario host_form=ideographic_dot_u3002, target=loopback_or_private, entry_point=schedule_url
     *
     * @effects schedule_url_rejects_normalized_internal_host
     *
     * @return void 반환값 없음
     */
    #[DataProvider('internalUrlProvider')]
    public function test_url_schedule_blocks_internal_addresses(string $url): void
    {
        $this->assertUrlScheduleIsBlocked($url);
    }

    /**
     * 사내 엔드포인트 주기 호출은 내부 주소 허용 설정으로 가능하다 (운영 옵트인).
     *
     * @scenario host_form=ascii, target=loopback_or_private, entry_point=schedule_url
     *
     * @effects allow_internal_outbound_setting_keeps_structural_check_only
     *
     * @return void 반환값 없음
     */
    public function test_url_schedule_allows_internal_address_when_setting_is_enabled(): void
    {
        $this->allowInternalOutboundUrls();

        $history = $this->runSchedule($this->makeUrlSchedule('http://192.168.0.10/cron'));

        $this->assertSame(ScheduleResultStatus::Success, $history->status);
        Http::assertSentCount(1);
    }

    /**
     * 내부 주소를 허용해도 userinfo(@) 로 목적지를 위장한 URL 은 계속 차단한다.
     *
     * @scenario host_form=ascii, target=loopback_or_private, entry_point=schedule_url
     *
     * @effects userinfo_disguised_host_is_rejected, allow_internal_outbound_setting_keeps_structural_check_only
     *
     * @return void 반환값 없음
     */
    public function test_url_schedule_blocks_userinfo_disguise_even_when_internal_is_allowed(): void
    {
        $this->allowInternalOutboundUrls();

        $this->assertUrlScheduleIsBlocked('http://example.com@169.254.169.254/latest/meta-data/');
    }

    /**
     * FormRequest 게이트(`PublicOutboundUrl` Rule)도 같은 정규화를 거친다.
     *
     * 이 Rule 이 스케줄 생성·언어팩 URL 설치 등의 프론트도어다. 서비스 계층만 막고
     * 여기가 열려 있으면 저장 자체는 성공해 위조 URL 이 설정에 남는다.
     *
     * @param  string  $url  내부망을 가리키는 URL
     *
     * @scenario host_form=ideographic_dot_u3002, target=loopback_or_private, entry_point=form_request_rule
     *
     * @effects form_request_rule_rejects_normalized_internal_host
     *
     * @return void 반환값 없음
     */
    #[DataProvider('internalUrlProvider')]
    public function test_public_outbound_url_rule_blocks_internal_addresses(string $url): void
    {
        $validator = validator(
            ['endpoint' => $url],
            ['endpoint' => [new PublicOutboundUrl(['http', 'https'])]],
        );

        $this->assertTrue(
            $validator->fails(),
            "차단되어야 하는 URL 이 FormRequest 게이트를 통과함: {$url}"
        );
    }

    /**
     * 정규화가 정상 외부 URL 까지 막지는 않는다 (프론트도어 회귀 방지).
     *
     * @scenario host_form=ascii, target=public, entry_point=form_request_rule
     * @scenario host_form=unicode_u_label, target=public, entry_point=form_request_rule
     *
     * @effects public_ascii_domain_still_allowed, real_internationalized_domain_still_allowed, punycode_a_label_still_allowed, non_standard_port_public_url_still_allowed
     *
     * @return void 반환값 없음
     */
    public function test_public_outbound_url_rule_still_allows_public_urls(): void
    {
        $urls = [
            'https://api.example.com/shipping/fee',
            'https://api.example.com:8443/shipping/fee',
            'https://github.com/owner/repo/releases/download/v1/pack.zip',
            // 정당한 국제화 도메인은 정규화 후에도 통과해야 한다.
            "https://\u{4F8B}\u{3048}.jp/shipping/fee",
            'https://xn--r8jz45g.jp/shipping/fee',
        ];

        foreach ($urls as $url) {
            $validator = validator(
                ['endpoint' => $url],
                ['endpoint' => [new PublicOutboundUrl(['http', 'https'])]],
            );

            $this->assertFalse($validator->fails(), "정상 URL 이 차단됨: {$url}");
        }
    }

    /**
     * URL 스케줄 실행이 차단되고 요청이 전송되지 않았음을 단언합니다.
     *
     * 실행기는 실행 실패를 예외로 전파하지 않고 실패 이력을 돌려준다. 차단도 그 경로를 그대로 탄다.
     *
     * @param  string  $url  호출 대상 URL
     * @return void 반환값 없음
     */
    private function assertUrlScheduleIsBlocked(string $url): void
    {
        $schedule = $this->makeUrlSchedule($url);

        $history = $this->runSchedule($schedule);

        $this->assertNotNull($history, "차단된 URL 스케줄의 실행 이력이 남지 않음: {$url}");
        $this->assertSame(
            ScheduleResultStatus::Failed,
            $history->status,
            "차단되어야 하는 URL 스케줄이 실행됨: {$url}"
        );
        Http::assertNothingSent();
        $this->assertSame(
            ScheduleResultStatus::Failed,
            $schedule->fresh()->last_result,
            "차단된 스케줄이 실패로 기록되지 않음: {$url}"
        );
    }

    /**
     * 웹소켓 연결 테스트는 localhost·사설 IP 를 정상 구성으로 허용한다 (기본 설치 동작).
     *
     * @param  string  $host  드라이버 설정의 websocket_host
     *
     * @scenario entry_point=websocket_host, target=loopback_or_private
     *
     * @effects websocket_test_allows_local_and_private_hosts
     *
     * @return void 반환값 없음
     */
    #[DataProvider('legitimateWebsocketHostProvider')]
    public function test_websocket_test_allows_local_and_private_hosts(string $host): void
    {
        $result = app(DriverConnectionTester::class)->testWebsocket([
            'websocket_host' => $host,
            'websocket_port' => 8080,
            'websocket_scheme' => 'http',
        ]);

        $this->assertTrue($result['success']);
    }

    /**
     * 웹소켓 연결 테스트는 정상 설정에서 나올 수 없는 위조 host 를 거부한다.
     *
     * @param  string  $host  구조적으로 위조된 websocket_host
     *
     * @scenario entry_point=websocket_host
     *
     * @effects websocket_test_rejects_structurally_forged_hosts
     *
     * @return void 반환값 없음
     */
    #[DataProvider('forgedWebsocketHostProvider')]
    public function test_websocket_test_rejects_structurally_forged_hosts(string $host): void
    {
        $result = app(DriverConnectionTester::class)->testWebsocket([
            'websocket_host' => $host,
            'websocket_port' => 8080,
            'websocket_scheme' => 'https',
        ]);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    /**
     * 내부망 URL 목록.
     *
     * @return array<string, array{string}> 데이터 케이스 (케이스 이름 → 테스트 인자)
     */
    public static function internalUrlProvider(): array
    {
        return [
            '클라우드 메타데이터' => ['http://169.254.169.254/latest/meta-data/'],
            '루프백' => ['http://127.0.0.1:8080/pack.zip'],
            'localhost' => ['http://localhost/pack.zip'],
            '사설 IP' => ['http://10.0.0.5/pack.zip'],
            'userinfo 위장' => ['https://github.com@127.0.0.1/pack.zip'],

            // 점 동등 유니코드 문자(U+3002·U+FF0E·U+FF61)와 전각 슬래시(U+FF0F)는
            // 연결 계층의 UTS#46 정규화에서 ASCII 로 바뀐다. 게이트가 원문으로만 판정하면
            // 검증 시점과 접속 시점의 host 가 달라져 내부망이 열린다.
            'U+3002 로 감춘 localhost' => ["http://localhost\u{3002}/pack.zip"],
            'U+FF0E 로 감춘 localhost' => ["http://localhost\u{FF0E}/pack.zip"],
            'U+FF61 로 감춘 localhost' => ["http://localhost\u{FF61}/pack.zip"],
            'U+3002 로 감춘 루프백 IP' => ["http://127\u{3002}0\u{3002}0\u{3002}1/pack.zip"],
            'U+FF0E 로 감춘 메타데이터' => ["http://169\u{FF0E}254\u{FF0E}169\u{FF0E}254/latest/meta-data/"],
            '전각 슬래시로 감춘 루프백' => ["http://127.0.0.1\u{FF0F}.example.com/pack.zip"],
            '후행 점 localhost' => ['http://localhost./pack.zip'],
        ];
    }

    /**
     * 정상 운영에서 쓰이는 웹소켓 host 목록 (기본값 포함).
     *
     * @return array<string, array{string}> 데이터 케이스 (케이스 이름 → 테스트 인자)
     */
    public static function legitimateWebsocketHostProvider(): array
    {
        return [
            '기본값 localhost' => ['localhost'],
            '루프백 IP' => ['127.0.0.1'],
            '사내 사설 IP' => ['192.168.0.10'],
            '공개 도메인' => ['ws.example.com'],
        ];
    }

    /**
     * 구조적으로 위조된 웹소켓 host 목록.
     *
     * @return array<string, array{string}> 데이터 케이스 (케이스 이름 → 테스트 인자)
     */
    public static function forgedWebsocketHostProvider(): array
    {
        return [
            'userinfo 위장' => ['example.com@169.254.169.254'],
            '제어문자 주입' => ["example.com\r\nX-Injected: 1"],
        ];
    }

    /**
     * 수동 실행 진입점(수동 실행 API 와 같은 경로)으로 스케줄을 실행합니다.
     *
     * @param  Schedule  $schedule  실행 대상 스케줄
     * @return ScheduleHistory|null 실행 이력
     */
    private function runSchedule(Schedule $schedule): ?ScheduleHistory
    {
        return app(ScheduleDispatcher::class)->dispatch($schedule->fresh(), ScheduleTriggerType::Manual);
    }

    /**
     * URL 호출 스케줄을 생성합니다.
     *
     * @param  string  $url  호출 대상 URL
     * @return Schedule 생성된 스케줄
     */
    private function makeUrlSchedule(string $url): Schedule
    {
        return Schedule::create([
            'name' => 'outbound 가드 테스트 스케줄',
            'type' => ScheduleType::Url,
            'command' => $url,
            'expression' => '* * * * *',
            'is_active' => true,
        ]);
    }

    /**
     * 관리자 환경설정에서 내부 주소 호출 허용을 켭니다.
     *
     * @return void 반환값 없음
     */
    private function allowInternalOutboundUrls(): void
    {
        Config::set('g7_settings.core.security.allow_internal_outbound_urls', true);
    }
}
