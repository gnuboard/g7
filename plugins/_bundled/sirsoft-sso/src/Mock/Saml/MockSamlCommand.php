<?php

namespace Plugins\Sirsoft\Sso\Mock\Saml;

use App\Support\SiteUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;

/**
 * 모의 SAML IdP 행을 심고, 모의 IdP 이름으로 IdP 발 로그아웃(LogoutRequest)을 SP 에 보냅니다.
 *
 * 게이트를 켜고 끄는 일은 `sirsoft-sso:mock --enable|--disable` 이 한다. 운영 환경(`APP_ENV=production`)에서는 거절한다.
 *
 * @since 1.0.0
 */
class MockSamlCommand extends Command
{
    /** 모의 SAML IdP 기본 키 */
    public const MOCK_SAML_KEY = 'mock_saml_a';

    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:mock-saml
        {--seed-idp : 모의 SAML IdP 행을 만들거나 맞춥니다}
        {--key=mock_saml_a : 모의 SAML IdP 키 (접두 mock_)}
        {--domain=corp798.test : --seed-idp 의 소유 도메인}
        {--metadata-variant=ok : --seed-idp 가 쓸 메타데이터 변종 (ok, cert_expiring_7d, cert_rotated)}
        {--idp-initiated : --seed-idp 때 IdP-initiated 를 켭니다}
        {--logout : 모의 IdP 이름으로 서명한 LogoutRequest 를 SP 의 SLS 로 보냅니다}
        {--name-id= : --logout 대상 NameID}
        {--session-index= : --logout 대상 SessionIndex}';

    /** @var string 명령 설명 */
    protected $description = 'SSO 모의 SAML IdP 행 시딩, IdP 발 로그아웃 발신 (개발·검수 전용)';

    /**
     * 명령을 실행합니다.
     *
     * @param  MockGate  $gate  게이트
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  MockSamlKeys  $keys  모의 서명 키
     * @return int 종료 코드
     */
    public function handle(MockGate $gate, IdentityProviderRepositoryInterface $idps, MockSamlKeys $keys): int
    {
        if (app()->environment('production')) {
            $this->error(__('sirsoft-sso::messages.mock.production_refused'));

            return self::FAILURE;
        }

        $key = (string) $this->option('key');
        if (! str_starts_with($key, 'mock_') || preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key) !== 1) {
            $this->error(__('sirsoft-sso::saml.mock.key_invalid'));

            return self::FAILURE;
        }

        if ($this->option('seed-idp')) {
            $this->seed($idps, $key);
        }

        if ($this->option('logout')) {
            return $this->sendLogout($gate, $idps, $keys, $key);
        }

        return self::SUCCESS;
    }

    /**
     * 모의 SAML IdP 행을 만들거나 맞춥니다 (자동 생성, 동의 받음, 소유 도메인, MFA 인증 수준).
     *
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  string  $key  키
     * @return void 반환값 없음
     */
    private function seed(IdentityProviderRepositoryInterface $idps, string $key): void
    {
        $variant = in_array($this->option('metadata-variant'), MockSamlResponses::METADATA_VARIANTS, true) ? (string) $this->option('metadata-variant') : 'ok';
        $attributes = [
            'protocol' => SamlDriver::KEY,
            'enabled' => true,
            'label' => ['ko' => '모의 SAML 계정', 'en' => 'Mock SAML account'],
            'config' => [
                'idp_metadata_url' => MockSamlController::metadataUrl($variant),
                'name_id_format' => 'persistent',
                'attr_email' => 'email',
                'attr_name' => 'displayName',
                'attr_groups' => 'groups',
                'mfa_authn_contexts' => [MockSamlResponses::MFA_CONTEXT],
            ],
            'owned_domains' => [mb_strtolower((string) $this->option('domain'))],
            'provisioning_mode' => 'jit',
            'require_consent' => true,
            'idp_initiated' => (bool) $this->option('idp-initiated'),
        ];

        $existing = $idps->findByKey($key);
        if ($existing !== null) {
            $existing->forceFill($attributes)->save();
        } else {
            $idps->create(['key' => $key] + $attributes);
        }

        $this->info(__('sirsoft-sso::saml.mock.idp_seeded', ['key' => $key]));
    }

    /**
     * 서명한 LogoutRequest 주소를 SP 의 SLS 로 보냅니다(서버 간 GET — 브라우저 대신).
     *
     * @param  MockGate  $gate  게이트
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  MockSamlKeys  $keys  서명 키
     * @param  string  $key  IdP 키
     * @return int 종료 코드
     */
    private function sendLogout(MockGate $gate, IdentityProviderRepositoryInterface $idps, MockSamlKeys $keys, string $key): int
    {
        $idp = $idps->findByKey($key);
        $nameId = (string) $this->option('name-id');
        if ($idp === null || ! $gate->enabled() || $nameId === '') {
            $this->error(__('sirsoft-sso::saml.mock.gate_off'));

            return self::FAILURE;
        }

        $sessionIndex = $this->option('session-index');
        $url = MockSamlResponses::logoutRequestUrl(
            SiteUrl::absolute(SamlDriver::slsPath($idp)),
            MockSamlController::entityId(),
            $nameId,
            is_string($sessionIndex) && $sessionIndex !== '' ? $sessionIndex : null,
            $keys->pair(),
        );

        $status = Http::withoutVerifying()->withoutRedirecting()->timeout(10)->get($url)->status();
        $this->line(__('sirsoft-sso::saml.mock.logout_sent', ['key' => $key, 'status' => $status]));

        return $status >= 200 && $status < 400 ? self::SUCCESS : self::FAILURE;
    }
}
