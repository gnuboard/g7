<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\Contracts\ProviderDriverInterface;
use Plugins\Sirsoft\SocialLogin\Enums\ProviderDriver;

/**
 * 재동의 요청 시험 — 이메일 제공을 거절해 가입이 막힌 회원이 로그인 화면의 재동의 단추로 다시 시작하면, 드라이버가 제공자에
 * 동의 화면을 다시 띄워 달라는 파라미터를 싣는지 본다. 평소 로그인에는 싣지 않는다.
 *
 * 모집단은 드라이버 종류(`ProviderDriver`) 전체에서 파생한다 — 드라이버가 늘었는데 기대값이 없으면 실패한다.
 *
 * @since 1.0.0
 */
class ReconsentParamsTest extends DriverTestCase
{
    /** OIDC discovery 주소 */
    private const DISCOVERY = 'https://idp.example.test/.well-known/openid-configuration';

    /**
     * 드라이버별 재동의 파라미터 기대값 (키 => 값). 카카오는 이메일 동의 항목을 범위에 넣어 추가 항목 동의를 띄운다.
     *
     * @var array<string, array<string, string>>
     */
    private const EXPECTED = [
        'kakao' => ['scope' => 'account_email'],
        'naver' => ['auth_type' => 'reprompt'],
        'google' => ['prompt' => 'consent'],
        'facebook' => ['auth_type' => 'rerequest'],
        'oidc' => ['prompt' => 'consent'],
    ];

    /**
     * 드라이버 종류 전체를 돌려줍니다.
     *
     * @return array<string, array{0: string}> 드라이버 값
     */
    public static function drivers(): array
    {
        $rows = [];
        foreach (ProviderDriver::cases() as $case) {
            $rows[$case->value] = [$case->value];
        }

        return $rows;
    }

    /**
     * 기대값 표가 드라이버 종류 전체와 같은지 확인한다(빈 모집단이나 빠진 드라이버를 막는다).
     *
     * @scenario reconsent=population
     *
     * @effects reconsent_expectation_covers_every_driver
     *
     * @return void 반환값 없음
     */
    public function test_expectations_cover_every_driver(): void
    {
        $this->assertNotSame([], ProviderDriver::values());
        $this->assertEqualsCanonicalizing(ProviderDriver::values(), array_keys(self::EXPECTED));
    }

    /**
     * 재동의 시작이면 드라이버별 파라미터를 싣고, 평소 시작이면 싣지 않는지 확인한다.
     *
     * @scenario reconsent=on|off, provider=kakao|naver|google|facebook|oidc
     *
     * @effects reconsent_params_only_on_reconsent
     *
     * @param  string  $driver  드라이버 값
     * @return void 반환값 없음
     */
    #[DataProvider('drivers')]
    public function test_reconsent_params_are_added_only_when_requested(string $driver): void
    {
        Http::fake([self::DISCOVERY => Http::response([
            'issuer' => 'https://idp.example.test',
            'authorization_endpoint' => 'https://idp.example.test/authorize',
            'token_endpoint' => 'https://idp.example.test/token',
            'userinfo_endpoint' => 'https://idp.example.test/userinfo',
            'jwks_uri' => 'https://idp.example.test/jwks',
        ])]);

        $plain = $this->query($this->make($driver)->authorizeUrl($this->request(true, true)));
        $again = $this->query($this->make($driver)->authorizeUrl($this->request(true, true, reconsent: true)));

        foreach (self::EXPECTED[$driver] as $key => $value) {
            $this->assertSame($value, $again[$key] ?? null, "{$driver} 재동의 {$key}");
            $this->assertNotSame($value, $plain[$key] ?? null, "{$driver} 평소 로그인에는 {$key}={$value} 가 없다");
        }
        $this->assertSame($plain['state'], $again['state'], '나머지 파라미터는 같다');
    }

    /**
     * 카카오 범위를 운영자가 정해 두었으면 그 범위를 지우지 않고 이메일 동의 항목만 더하는지 확인한다.
     *
     * @scenario reconsent=on, provider=kakao, scopes=configured
     *
     * @effects kakao_reconsent_keeps_configured_scopes
     *
     * @return void 반환값 없음
     */
    public function test_kakao_reconsent_keeps_configured_scopes(): void
    {
        $withNickname = $this->query($this->make('kakao', ['scopes' => ['profile_nickname']])->authorizeUrl($this->request(reconsent: true)));
        $withEmail = $this->query($this->make('kakao', ['scopes' => ['profile_nickname', 'account_email']])->authorizeUrl($this->request(reconsent: true)));

        $this->assertSame('profile_nickname account_email', $withNickname['scope']);
        $this->assertSame('profile_nickname account_email', $withEmail['scope'], '이미 있으면 겹쳐 넣지 않는다');
    }

    /**
     * 드라이버 값으로 드라이버를 만듭니다.
     *
     * @param  string  $driver  드라이버 값
     * @param  array<string, mixed>  $config  설정
     * @return ProviderDriverInterface 드라이버
     */
    private function make(string $driver, array $config = []): ProviderDriverInterface
    {
        $class = 'Plugins\\Sirsoft\\SocialLogin\\Drivers\\'.Str::studly($driver).'Driver';
        if ($driver === 'oidc') {
            $config += ['discovery_url' => self::DISCOVERY];
        }

        return $this->driver($class, $config);
    }
}
