<?php

namespace Plugins\Sirsoft\Sso\Console\Commands;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcKeys;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;

/**
 * 모의 IdP 게이트를 켜고 끄고, 모의 OIDC IdP 행을 심고, back-channel 로그아웃을 보냅니다.
 *
 * 모의 IdP 는 이 명령으로만 켠다(화면, API, 설정으로 켜는 경로는 없다). 운영 환경(`APP_ENV=production`)에서는 거절한다.
 *
 * @since 1.0.0
 */
class MockCommand extends Command
{
    /** 모의 OIDC IdP 키 */
    public const MOCK_OIDC_KEY = 'mock_oidc';

    /** 모의 OIDC IdP client_id */
    public const MOCK_CLIENT_ID = 'g7-mock-client';

    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:mock
        {--enable : 모의 IdP 를 켭니다}
        {--minutes=60 : 켜 둘 시간(분, 최대 240)}
        {--disable : 모의 IdP 를 끕니다}
        {--status : 상태만 봅니다}
        {--seed-idp : 모의 OIDC IdP 행(mock_oidc)을 만들거나 맞춥니다}
        {--domain=example.test : --seed-idp 의 소유 도메인}
        {--backchannel= : back-channel 로그아웃을 보낼 IdP 키}
        {--sub= : back-channel 대상 subject}
        {--sid= : back-channel 대상 세션 ID}';

    /** @var string 명령 설명 */
    protected $description = 'SSO 모의 IdP 게이트 제어, 모의 IdP 행 시딩, back-channel 로그아웃 발신 (개발·검수 전용)';

    /**
     * 명령을 실행합니다.
     *
     * @param  MockGate  $gate  게이트
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  MockOidcKeys  $keys  모의 서명 키
     * @return int 종료 코드
     */
    public function handle(MockGate $gate, IdentityProviderRepositoryInterface $idps, MockOidcKeys $keys): int
    {
        if (app()->environment('production')) {
            $this->error(__('sirsoft-sso::messages.mock.production_refused'));

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            $gate->disable();
            $this->info(__('sirsoft-sso::messages.mock.disabled'));

            return self::SUCCESS;
        }

        if ($this->option('enable')) {
            $until = $gate->enable((int) $this->option('minutes'));
            $this->info(__('sirsoft-sso::messages.mock.enabled', ['until' => $this->siteTime($until)]));
        }

        if ($this->option('seed-idp')) {
            $this->seedIdp($idps, (string) $this->option('domain'));
        }

        if (is_string($this->option('backchannel')) && $this->option('backchannel') !== '') {
            return $this->sendBackchannel($gate, $idps, $keys, (string) $this->option('backchannel'));
        }

        $until = $gate->until();
        $this->line($until === null
            ? __('sirsoft-sso::messages.mock.status_off')
            : __('sirsoft-sso::messages.mock.status_on', ['until' => $this->siteTime($until)]));

        return self::SUCCESS;
    }

    /**
     * 만료 시각을 사이트 시간대 문자열로 바꿉니다 (운영자가 콘솔에서 읽는 값).
     *
     * @param  int  $until  만료 시각 (unix 초)
     * @return string 사이트 시간대 `Y-m-d H:i:s`
     */
    private function siteTime(int $until): string
    {
        return (string) TimezoneHelper::toSiteDateTimeString(Carbon::createFromTimestamp($until));
    }

    /**
     * 모의 OIDC IdP 행을 만들거나 맞춥니다 (자동 생성, 동의 받음, 소유 도메인).
     *
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  string  $domain  소유 도메인
     * @return void 반환값 없음
     */
    private function seedIdp(IdentityProviderRepositoryInterface $idps, string $domain): void
    {
        $attributes = [
            'protocol' => 'oidc',
            'enabled' => true,
            'label' => ['ko' => '모의 회사 계정', 'en' => 'Mock company account'],
            'config' => [
                'discovery_url' => MockOidcController::issuer().'/.well-known/openid-configuration',
                'client_id' => self::MOCK_CLIENT_ID,
                'scopes' => 'openid email profile',
                'subject_claim' => 'sub',
                'use_pkce' => true,
                'token_endpoint_auth' => 'client_secret_post',
            ],
            'secrets' => ['client_secret' => 'mock-secret'],
            'owned_domains' => [mb_strtolower($domain)],
            'provisioning_mode' => 'jit',
            'require_consent' => true,
        ];

        $existing = $idps->findByKey(self::MOCK_OIDC_KEY);
        if ($existing !== null) {
            $existing->forceFill($attributes)->save();
        } else {
            $idps->create(['key' => self::MOCK_OIDC_KEY] + $attributes);
        }

        $this->info(__('sirsoft-sso::messages.mock.idp_seeded', ['key' => self::MOCK_OIDC_KEY]));
    }

    /**
     * 모의 IdP 이름으로 서명한 logout_token 을 IdP 의 back-channel 주소로 보냅니다.
     *
     * @param  MockGate  $gate  게이트
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  MockOidcKeys  $keys  서명 키
     * @param  string  $idpKey  IdP 키
     * @return int 종료 코드
     */
    private function sendBackchannel(MockGate $gate, IdentityProviderRepositoryInterface $idps, MockOidcKeys $keys, string $idpKey): int
    {
        $idp = $idps->findByKey($idpKey);
        if ($idp === null || ! $gate->enabled()) {
            $this->error(__('sirsoft-sso::messages.mock.backchannel_unavailable'));

            return self::FAILURE;
        }

        $claims = [
            'iss' => MockOidcController::issuer(),
            'aud' => (string) $idp->configValue('client_id', ''),
            'iat' => time(),
            'jti' => Str::random(32),
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass],
        ];
        if (is_string($this->option('sid')) && $this->option('sid') !== '') {
            $claims['sid'] = (string) $this->option('sid');
        }
        if (is_string($this->option('sub')) && $this->option('sub') !== '') {
            $claims['sub'] = (string) $this->option('sub');
        }

        $url = $idp->driver()->serviceProviderInfo($idp);
        $endpoint = collect($url)->firstWhere('key', 'backchannel_logout_uri')['value'] ?? null;
        if (! is_string($endpoint)) {
            $this->error(__('sirsoft-sso::messages.mock.backchannel_unavailable'));

            return self::FAILURE;
        }

        // 모의 IdP 만 TLS 검증을 끈다(개발 사이트 자체 서명 인증서) — OidcMetadata::http 와 같은 범위.
        $client = Http::asForm()->timeout(10);
        $status = ($idp->isMock() ? $client->withoutVerifying() : $client)->post($endpoint, ['logout_token' => $keys->sign($claims)])->status();
        $this->line('backchannel '.$idpKey.' → HTTP '.$status);

        return $status >= 200 && $status < 300 ? self::SUCCESS : self::FAILURE;
    }
}
