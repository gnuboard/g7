<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Drivers\NaverDriver;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 네이버 드라이버 시험 — PKCE 미지원, 문자열 expires_in, 200 본문 오류, 프로필 변종 5(부록 B), 연결 해제.
 *
 * @since 1.0.0
 */
class NaverDriverTest extends DriverTestCase
{
    /**
     * 네이버 인가 주소에 PKCE 가 없는지 확인한다.
     *
     * @scenario provider=naver
     * @effects authorize_url_without_pkce
     * @return void 반환값 없음
     */
    public function test_authorize_url_has_no_pkce(): void
    {
        $q = $this->query($this->driver(NaverDriver::class)->authorizeUrl($this->request()));

        $this->assertSame('state-abc', $q['state']);
        $this->assertArrayNotHasKey('code_challenge', $q);
    }

    /**
     * 네이버의 문자열 expires_in 과 갱신 토큰 부재를 처리하는지 확인한다.
     *
     * @scenario provider=naver
     * @effects token_normalized
     * @return void 반환값 없음
     */
    public function test_string_expires_in_and_missing_refresh(): void
    {
        Http::fake(['nid.naver.com/oauth2.0/token' => Http::response(['access_token' => 'at', 'token_type' => 'bearer', 'expires_in' => '3600'])]);

        $tokens = $this->driver(NaverDriver::class)->exchangeCode('c', $this->request());

        $this->assertNull($tokens->refreshToken, '갱신 토큰이 없으면 null(저장소가 기존 값을 유지)');
        $this->assertEqualsWithDelta(time() + 3600, $tokens->expiresAt?->getTimestamp(), 5);
        Http::assertSent(fn (Request $r) => ! isset($r['code_verifier']));
    }

    /**
     * 네이버 토큰 발급 요청은 시작 때 만든 state 를 함께 보낸다(네이버 발급 요청 필수 값).
     *
     * @scenario provider=naver
     * @effects naver_token_request_carries_state
     * @return void 반환값 없음
     */
    public function test_token_exchange_sends_state(): void
    {
        Http::fake(['nid.naver.com/oauth2.0/token' => Http::response(['access_token' => 'at', 'token_type' => 'bearer', 'expires_in' => '3600'])]);

        $this->driver(NaverDriver::class)->exchangeCode('c', $this->request());

        Http::assertSent(fn (Request $r) => ($r['grant_type'] ?? null) === 'authorization_code' && ($r['state'] ?? null) === 'state-abc');
    }

    /**
     * 네이버는 토큰 오류도 HTTP 200 본문으로 준다.
     *
     * @scenario provider=naver, error=invalid_request
     * @effects provider_error_code_mapped
     * @return void 반환값 없음
     */
    public function test_token_error_in_200_body(): void
    {
        Http::fake(['nid.naver.com/oauth2.0/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'no valid data in session'])]);

        try {
            $this->driver(NaverDriver::class)->exchangeCode('c', $this->request());
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('invalid_request', $e->errorCode);
        }
    }

    /**
     * 부록 B 네이버 5행 (024 오류 포함).
     *
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string|null, 4: string|null}> 사례 이름 => 인자 목록
     */
    public static function profiles(): array
    {
        return [
            '완비' => ['naver_full', 'AbCdEf123_xyz', 'naver.user@example.test', '네이버 사용자', null],
            '이메일 없음' => ['naver_no_email', 'NoMail999', null, '네이버 사용자', null],
            '이름 없음' => ['naver_no_name', 'NoName777', 'naver.noname@example.test', null, null],
            '024 오류' => ['naver_error_024', null, null, null, '024'],
            '옛 정수 id' => ['naver_legacy_int_id', '32742776', 'naver.legacy@example.test', null, null],
        ];
    }

    /**
     * 부록 B 프로필 변종이 정규화되는지 확인한다.
     *
     * @scenario provider=naver, variant=appendix_b
     * @effects profile_variants_normalized
     * @param  string  $fixture  데이터 제공자가 준 값
     * @param  string|null  $id  데이터 제공자가 준 값
     * @param  string|null  $email  데이터 제공자가 준 값
     * @param  string|null  $name  데이터 제공자가 준 값
     * @param  string|null  $errorCode  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('profiles')]
    public function test_profile_variants(string $fixture, ?string $id, ?string $email, ?string $name, ?string $errorCode): void
    {
        Http::fake(['openapi.naver.com/v1/nid/me' => Http::response($this->fixture($fixture))]);

        try {
            $profile = $this->driver(NaverDriver::class)->fetchProfile($this->tokens(), $this->request());
        } catch (ProviderException $e) {
            $this->assertSame($errorCode, $e->errorCode);

            return;
        }

        $this->assertNull($errorCode, '오류 변종인데 예외가 나지 않았다');
        $this->assertSame($id, $profile->providerUserId, '대소문자 보존');
        $this->assertSame($email, $profile->email);
        $this->assertNull($profile->emailVerified, '네이버는 검증 여부를 알려 주지 않는다');
        $this->assertSame($name, $profile->name);
    }

    /**
     * 네이버 연결 해제가 delete grant 로 가고 HTTP 상태로 판정하는지 확인한다.
     *
     * @scenario provider=naver
     * @effects revoke_judged_by_http_status
     * @return void 반환값 없음
     */
    public function test_revoke_posts_delete_grant_and_reads_status(): void
    {
        Http::fake(['nid.naver.com/oauth2.0/token' => Http::response(['access_token' => 'at', 'result' => 'success'])]);

        $this->assertTrue($this->driver(NaverDriver::class)->revoke($this->tokens(), new SocialAccount));
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'delete' && $r['access_token'] === 'access-1' && $r['service_provider'] === 'NAVER');
    }
}
