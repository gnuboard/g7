<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Drivers\FacebookDriver;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 페이스북 드라이버 시험 — 설정의 Graph 버전, GET 토큰 교환, appsecret_proof, 프로필 변종 3(부록 B), 오류, 권한 회수.
 *
 * @since 1.0.0
 */
class FacebookDriverTest extends DriverTestCase
{
    /**
     * Graph API 버전을 설정에서 읽고, 형식이 틀리면 기본값을 쓰는지 확인한다.
     *
     * @scenario provider=facebook
     * @effects graph_version_from_settings
     * @return void 반환값 없음
     */
    public function test_graph_version_comes_from_settings(): void
    {
        $url = $this->driver(FacebookDriver::class, ['graph_api_version' => 'v27.0'])->authorizeUrl($this->request());
        $this->assertStringStartsWith('https://www.facebook.com/v27.0/dialog/oauth?', $url);

        $fallback = $this->driver(FacebookDriver::class, ['graph_api_version' => 'latest'])->authorizeUrl($this->request());
        $this->assertStringStartsWith('https://www.facebook.com/'.FacebookDriver::DEFAULT_GRAPH_VERSION.'/dialog/oauth?', $fallback);
        $this->assertArrayNotHasKey('code_challenge', $this->query($fallback));
    }

    /**
     * 페이스북 토큰 교환이 GET 으로 가는지 확인한다.
     *
     * @scenario provider=facebook
     * @effects token_exchange_is_get
     * @return void 반환값 없음
     */
    public function test_token_exchange_uses_get(): void
    {
        Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'at', 'token_type' => 'bearer', 'expires_in' => 5183944])]);

        $tokens = $this->driver(FacebookDriver::class)->exchangeCode('c', $this->request());

        $this->assertSame('at', $tokens->accessToken);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'code=c'));
    }

    /**
     * 부록 B 페이스북 3행.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string|null}> 사례 이름 => 인자 목록
     */
    public static function profiles(): array
    {
        return [
            '완비' => ['facebook_full', 'fb.user@example.test', 'https://example.test/avatar.png'],
            '이메일 없음' => ['facebook_no_email', null, 'https://example.test/avatar.png'],
            '이름만' => ['facebook_name_only', null, null],
        ];
    }

    /**
     * 페이스북 프로필 변종 정규화와 appsecret_proof 첨부를 확인한다.
     *
     * @scenario provider=facebook, variant=appendix_b
     * @effects profile_variants_normalized
     * @param  string  $fixture  데이터 제공자가 준 값
     * @param  string|null  $email  데이터 제공자가 준 값
     * @param  string|null  $avatar  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('profiles')]
    public function test_profile_variants_and_appsecret_proof(string $fixture, ?string $email, ?string $avatar): void
    {
        $body = $this->fixture($fixture);
        Http::fake(['graph.facebook.com/*/me*' => Http::response($body)]);

        $profile = $this->driver(FacebookDriver::class)->fetchProfile($this->tokens(), $this->request());

        $this->assertSame($body['id'], $profile->providerUserId);
        $this->assertSame($email, $profile->email);
        $this->assertNull($profile->emailVerified);
        $this->assertSame($body['name'], $profile->name);
        $this->assertSame($avatar, $profile->avatarUrl);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'access-1', 'secret-456')));
    }

    /**
     * Graph API 오류 본문이 facebook_{code} 로 바뀌는지 확인한다.
     *
     * @scenario provider=facebook, error=190
     * @effects provider_error_code_mapped
     * @return void 반환값 없음
     */
    public function test_graph_error_maps_code(): void
    {
        Http::fake(['graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        try {
            $this->driver(FacebookDriver::class)->fetchProfile($this->tokens(), $this->request());
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('facebook_190', $e->errorCode);
        }
    }

    /**
     * 권한 회수가 DELETE 요청으로 가는지 확인한다.
     *
     * @scenario provider=facebook
     * @effects revoke_deletes_permissions
     * @return void 반환값 없음
     */
    public function test_revoke_deletes_permissions(): void
    {
        Http::fake(['graph.facebook.com/*/me/permissions*' => Http::response(['success' => true])]);

        $this->assertTrue($this->driver(FacebookDriver::class)->revoke($this->tokens(), new SocialAccount));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
    }
}
