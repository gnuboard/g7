<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\Drivers\AbstractOAuth2Driver;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Exceptions\ProviderException;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;

/**
 * 제공자 연결 테스트 — 회원 동의 없이 자격증명·주소가 맞는지 두 단계로 봅니다.
 *
 * 1단계(설정): 클라이언트 ID·필수 비밀값이 있는지, 일반 OIDC·구글은 discovery 문서와 서명 키 목록(JWKS)을 받을 수 있는지.
 * 2단계(토큰 요청): 존재하지 않는 인가 코드로 토큰 주소를 한 번 불러 제공자가 무엇을 거절하는지 읽는다. 「코드가 틀렸다」는
 * 거절은 클라이언트 인증이 통과했다는 뜻이고(정상), 「클라이언트가 틀렸다」·「콜백 주소가 다르다」는 그대로 운영자 조치
 * 대상이다. 실제 로그인 왕복은 [브라우저로 로그인 테스트]가 맡는다.
 *
 * 결과 분류: `credentials_ok` · `invalid_client` · `redirect_mismatch` · `unreachable`(연결 실패·제공자 5xx) ·
 * `inconclusive`(틀린 자격증명과 없는 코드를 같은 본문으로 거절해 판별할 수 없음 — 네이버 `invalid_request`) — 그 밖은
 * `unexpected` 와 제공자 코드.
 *
 * @since 1.0.0
 */
class ProviderTestService
{
    /** 「코드가 틀렸다」 계열 — 클라이언트 인증은 통과 */
    private const CODE_REJECTED = ['invalid_grant', 'KOE320', 'facebook_100'];

    /**
     * 판별할 수 없는 거절 — 제공자가 클라이언트를 확인하기 전에 같은 본문으로 거절한다.
     * 네이버는 틀린 클라이언트 ID·비밀값과 없는 인가 코드 모두에 `invalid_request`(「no valid data in session」)를 준다.
     */
    private const AMBIGUOUS_REJECTED = ['invalid_request'];

    /** 클라이언트 인증 실패 계열 */
    private const CLIENT_REJECTED = ['invalid_client', 'unauthorized_client', 'KOE010', 'KOE101', 'facebook_101', 'facebook_1', 'invalid_client_secret'];

    /** 콜백 주소 불일치 계열 */
    private const REDIRECT_REJECTED = ['redirect_uri_mismatch', 'KOE006', 'facebook_191'];

    /**
     * @param  ProviderRegistry  $registry  제공자 레지스트리
     */
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * 연결 테스트를 실행합니다.
     *
     * @param  string  $key  제공자 키
     * @return array<string, mixed> `result`(분류) · `steps`([{name, ok, code}]) · `provider_code`
     *
     * @throws SocialFlowException 등록되지 않은 제공자(`provider_unknown`, 404)
     */
    public function run(string $key): array
    {
        $driver = $this->registry->get($key);
        if ($driver === null) {
            throw new SocialFlowException('provider_unknown', 404);
        }

        $steps = [];

        // 앱 키가 바뀌어 저장된 비밀값을 풀 수 없으면 「비어 있음」과 구분해 알린다 — 다시 입력하면 풀린다.
        if ($driver instanceof AbstractOAuth2Driver && ($unreadable = $driver->unreadableSecrets()) !== []) {
            $steps[] = ['name' => 'credentials', 'ok' => false, 'code' => 'credentials_unreadable', 'fields' => $unreadable];

            return $this->result('credentials_unreadable', $steps, null);
        }

        $missing = $this->missingCredentials($driver);
        $steps[] = ['name' => 'credentials', 'ok' => $missing === [], 'code' => $missing === [] ? null : 'credentials_missing', 'fields' => $missing];
        if ($missing !== []) {
            return $this->result('credentials_missing', $steps, null);
        }

        try {
            if ($driver instanceof AbstractOAuth2Driver && $driver->supports()['oidc']) {
                $jwks = (string) $driver->endpoint('jwks');
                $this->assertJwksReachable($driver, $jwks);
                $steps[] = ['name' => 'discovery', 'ok' => true, 'code' => null];
            }
        } catch (ProviderException $e) {
            $steps[] = ['name' => 'discovery', 'ok' => false, 'code' => $e->errorCode];

            return $this->result($e->errorCode, $steps, $e->errorCode);
        }

        [$classification, $providerCode] = $this->probeToken($driver, $key);
        $steps[] = ['name' => 'token', 'ok' => $classification === 'credentials_ok', 'code' => $providerCode];

        return $this->result($classification, $steps, $providerCode);
    }

    /**
     * 비어 있는 필수 자격증명 필드를 찾습니다.
     *
     * @param  ProviderDriverInterface  $driver  드라이버
     * @return array<int, string> 비어 있는 필드 키
     */
    private function missingCredentials(ProviderDriverInterface $driver): array
    {
        if (! $driver instanceof AbstractOAuth2Driver) {
            return [];
        }

        $missing = [];
        foreach ($driver->credentialSchema() as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }

            $value = $field['key'] === 'client_secret' ? $driver->clientSecret() : $driver->setting((string) $field['key']);
            if (blank($value)) {
                $missing[] = (string) $field['key'];
            }
        }

        return $missing;
    }

    /**
     * 서명 키 목록(JWKS)을 받을 수 있는지 확인합니다.
     *
     * @param  AbstractOAuth2Driver  $driver  드라이버
     * @param  string  $url  JWKS 주소
     * @return void 반환값 없음
     *
     * @throws ProviderException 받지 못했거나 키가 없을 때(`jwks_unreachable`)
     */
    private function assertJwksReachable(AbstractOAuth2Driver $driver, string $url): void
    {
        if ($url === '') {
            throw new ProviderException('jwks_unreachable');
        }

        try {
            $client = Http::timeout(10)->acceptJson();
            $response = ($driver->isMock() ? $client->withoutVerifying() : $client)->get($url);
        } catch (\Throwable) {
            throw new ProviderException('jwks_unreachable');
        }

        if (! $response->successful() || ! is_array($response->json('keys')) || $response->json('keys') === []) {
            throw new ProviderException('jwks_unreachable', '', $response->status());
        }
    }

    /**
     * 존재하지 않는 인가 코드로 토큰 주소를 불러 거절 사유를 분류합니다.
     *
     * @param  ProviderDriverInterface  $driver  드라이버
     * @param  string  $key  제공자 키
     * @return array{0: string, 1: string|null} [분류, 제공자 코드]
     */
    private function probeToken(ProviderDriverInterface $driver, string $key): array
    {
        $request = new AuthorizationRequest(
            providerKey: $key,
            intent: AttemptIntent::Test->value,
            redirect: '/',
            state: Str::random(40),
            callbackUrl: OAuthFlowService::callbackUrl($key),
            codeVerifier: Str::random(64),
            createdAt: time(),
        );

        try {
            $driver->exchangeCode('g7-connection-test-'.Str::random(16), $request);
        } catch (ProviderException $e) {
            return [$this->classify($e), $e->errorCode];
        } catch (\Throwable) {
            return ['unexpected', 'unexpected_error'];
        }

        // 가짜 코드로 토큰이 나왔다 — 제공자가 코드를 검사하지 않는 것(모의·시험 서버)으로 보고 정상 처리.
        return ['credentials_ok', null];
    }

    /**
     * 제공자 거절을 분류합니다.
     *
     * @param  ProviderException  $e  예외
     * @return string 분류
     */
    private function classify(ProviderException $e): string
    {
        if ($e->getMessage() === 'connection_failed' || ($e->httpStatus !== null && $e->httpStatus >= 500)) {
            return 'unreachable';
        }

        return match (true) {
            in_array($e->errorCode, self::REDIRECT_REJECTED, true) => 'redirect_mismatch',
            in_array($e->errorCode, self::CLIENT_REJECTED, true) => 'invalid_client',
            in_array($e->errorCode, self::CODE_REJECTED, true) => 'credentials_ok',
            in_array($e->errorCode, self::AMBIGUOUS_REJECTED, true) => 'inconclusive',
            default => 'unexpected',
        };
    }

    /**
     * 결과 배열을 만듭니다.
     *
     * @param  string  $classification  분류
     * @param  array<int, array<string, mixed>>  $steps  단계
     * @param  string|null  $providerCode  제공자 코드
     * @return array<string, mixed> 결과
     */
    private function result(string $classification, array $steps, ?string $providerCode): array
    {
        return [
            'result' => $classification,
            'ok' => $classification === 'credentials_ok',
            'steps' => $steps,
            'provider_code' => $providerCode,
        ];
    }
}
