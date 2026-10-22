<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\Enums\EmailTrust;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Services\IdTokenVerifier;
use Plugins\Sirsoft\SocialLogin\Services\StateStore;
use Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers\DriverTestCase;

/**
 * id_token 검증기, state 저장소, 프로필 정규화 도우미 시험.
 *
 * @since 1.0.0
 */
class VerifierAndStateTest extends DriverTestCase
{
    /** 시험 JWKS 주소 */
    private const JWKS = 'https://idp.example.test/jwks';

    /**
     * 서명된 id_token 을 만듭니다.
     *
     * @param  string  $kid  키 ID
     * @return string 서명된 JWT 문자열
     */
    private function idToken(string $kid = 'k1'): string
    {
        return self::signJwt(['iss' => 'https://idp.example.test', 'aud' => 'client-123', 'sub' => 'u1', 'exp' => time() + 300, 'nonce' => 'n1'], ['kid' => $kid]);
    }

    /**
     * JWKS 를 캐시해 한 번만 받는지 확인한다.
     *
     * @scenario verifier=jwks_cache
     * @effects jwks_cached_one_fetch
     * @return void 반환값 없음
     */
    public function test_jwks_is_cached(): void
    {
        Http::fake([self::JWKS => Http::response(['keys' => [self::jwk()]])]);
        $verifier = app(IdTokenVerifier::class);

        $verifier->verify($this->idToken(), self::JWKS, 'https://idp.example.test', 'client-123', 'n1');
        $claims = $verifier->verify($this->idToken(), self::JWKS, 'https://idp.example.test', 'client-123', 'n1');

        $this->assertSame('u1', $claims['sub']);
        Http::assertSentCount(1);
    }

    /**
     * 제공자가 키를 돌렸으면(캐시에 없는 kid) 한 번만 다시 받는다.
     *
     * @scenario verifier=kid_rotation
     * @effects unknown_kid_refetched_once
     * @return void 반환값 없음
     */
    public function test_unknown_kid_refetches_once(): void
    {
        Http::fakeSequence(self::JWKS)
            ->push(['keys' => [self::jwk('old')]])
            ->push(['keys' => [self::jwk('k1')]]);
        $verifier = app(IdTokenVerifier::class);

        $claims = $verifier->verify($this->idToken('k1'), self::JWKS, 'https://idp.example.test', 'client-123', 'n1');

        $this->assertSame('u1', $claims['sub']);
        Http::assertSentCount(2);
    }

    /**
     * 다시 받아도 없는 키 ID 면 거절하는지 확인한다.
     *
     * @scenario verifier=kid_unknown
     * @effects unknown_kid_rejected_after_one_refetch
     * @return void 반환값 없음
     */
    public function test_still_unknown_kid_is_rejected_after_one_refetch(): void
    {
        Http::fake([self::JWKS => Http::response(['keys' => [self::jwk('other')]])]);

        try {
            app(IdTokenVerifier::class)->verify($this->idToken('k1'), self::JWKS, 'https://idp.example.test', 'client-123', 'n1');
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('id_token_kid_unknown', $e->errorCode);
        }

        Http::assertSentCount(2);
    }

    /**
     * 공개키(JWKS) 서버에 연결하지 못하면 제공자 오류로 바꿔 콜백이 실패 안내로 끝나게 하는지 확인한다.
     *
     * @scenario verifier=jwks_unreachable
     * @effects jwks_connection_failure_becomes_provider_error
     * @return void 반환값 없음
     */
    public function test_jwks_connection_failure_becomes_provider_error(): void
    {
        Http::fake([self::JWKS => fn () => throw new ConnectionException('Connection refused')]);

        try {
            app(IdTokenVerifier::class)->verify($this->idToken('k1'), self::JWKS, 'https://idp.example.test', 'client-123', 'n1');
            $this->fail('예외가 나야 한다');
        } catch (ProviderException $e) {
            $this->assertSame('jwks_fetch_failed', $e->errorCode);
            $this->assertSame('connection_failed', $e->getMessage());
        }
    }

    /**
     * state 를 한 번만 꺼낼 수 있는지 확인한다.
     *
     * @scenario state=one_time
     * @effects state_pulled_once
     * @return void 반환값 없음
     */
    public function test_state_can_be_pulled_only_once(): void
    {
        $store = app(StateStore::class);
        $created = $store->create('kakao', 'login', '/mypage', 'https://site.example.test/cb', null, true, true);

        $pulled = $store->pull($created->state);

        $this->assertNotNull($pulled);
        $this->assertSame('/mypage', $pulled->redirect);
        $this->assertSame($created->codeVerifier, $pulled->codeVerifier);
        $this->assertSame($created->nonce, $pulled->nonce);
        $this->assertNull($store->pull($created->state), '두 번째는 없다(인가 코드 재사용 차단)');
        $this->assertNull($store->pull('forged-state'));
    }

    /**
     * 복귀 주소 표.
     *
     * @return array<string, array{0: string|null, 1: string}> 사례 이름 => 인자 목록
     */
    public static function redirects(): array
    {
        return [
            '상대 경로' => ['/mypage/profile?tab=1', '/mypage/profile?tab=1'],
            '루트' => ['/', '/'],
            '프로토콜 상대' => ['//evil.example.test/x', '/'],
            '절대 주소' => ['https://evil.example.test/', '/'],
            '스킴' => ['javascript:alert(1)', '/'],
            '역슬래시' => ['/\\evil.example.test', '/'],
            '제어문자' => ["/a\nb", '/'],
            '경로 앞 스킴 흉내' => ['/javascript:alert(1)', '/'],
            '빈 값' => [null, '/'],
        ];
    }

    /**
     * 복귀 주소가 같은 출처 상대 경로로만 제한되는지 확인한다.
     *
     * @scenario state=redirect_whitelist
     * @effects redirect_limited_to_same_origin_path
     * @param  string|null  $input  데이터 제공자가 준 값
     * @param  string  $expected  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('redirects')]
    public function test_redirect_whitelist(?string $input, string $expected): void
    {
        $this->assertSame($expected, StateStore::safeRedirect($input));
    }

    /**
     * 이메일 검증 표시 3값 × 신뢰 정책 3종 표.
     *
     * @return array<string, array{0: bool|null, 1: string, 2: bool}> 사례 이름 => 인자 목록
     */
    public static function trustMatrix(): array
    {
        return [
            'flag × 검증됨' => [true, 'flag', true],
            'flag × 미검증' => [false, 'flag', false],
            'flag × 알 수 없음' => [null, 'flag', false],
            'trust × 검증됨' => [true, 'trust', true],
            'trust × 미검증' => [false, 'trust', true],
            'trust × 알 수 없음' => [null, 'trust', true],
            'verify × 검증됨' => [true, 'verify', false],
            'verify × 미검증' => [false, 'verify', false],
            'verify × 알 수 없음' => [null, 'verify', false],
        ];
    }

    /**
     * 제공자의 이메일 검증 표시와 사이트의 신뢰 정책 9조합 판정을 확인한다.
     *
     * @scenario profile=email_trust
     * @effects email_trust_matrix_resolved
     * @param  bool|null  $verified  제공자 검증 표시
     * @param  string  $trust  신뢰 정책 값
     * @param  bool  $expected  기대 판정
     * @return void 반환값 없음
     */
    #[DataProvider('trustMatrix')]
    public function test_email_trust_matrix(?bool $verified, string $trust, bool $expected): void
    {
        $profile = new NormalizedProfile(providerUserId: 'u1', email: 'a@example.test', emailVerified: $verified);

        $this->assertSame($expected, $profile->isEmailTrusted(EmailTrust::from($trust)));
        $this->assertFalse((new NormalizedProfile(providerUserId: 'u1'))->isEmailTrusted(EmailTrust::from($trust)), '이메일이 없으면 어느 정책이든 거짓');
    }

    /**
     * 마스킹 이메일과 문자열 불리언 처리 도우미를 확인한다.
     *
     * @scenario profile=normalize_helpers
     * @effects masked_email_and_string_bool_handled
     * @return void 반환값 없음
     */
    public function test_normalized_profile_helpers(): void
    {
        $this->assertNull(NormalizedProfile::email('ka***@kakao.com'));
        $this->assertNull(NormalizedProfile::email('not-an-email'));
        $this->assertSame('a@example.test', NormalizedProfile::email(' a@example.test '));
        $this->assertTrue(NormalizedProfile::bool('true'));
        $this->assertFalse(NormalizedProfile::bool('FALSE'));
        $this->assertNull(NormalizedProfile::bool('yes'));
        $this->assertNull(NormalizedProfile::str(''));
    }
}
