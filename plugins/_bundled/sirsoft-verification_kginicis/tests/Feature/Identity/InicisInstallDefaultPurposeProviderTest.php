<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Identity;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\VerificationKginicis\Plugin;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * Plugin::install() 의 성인인증 목적별 프로바이더 초기값 세팅 검증.
 *
 * 배경: 운영자가 환경설정에서 직접 매핑하지 않으면 성인인증(inicis.adult_verification)
 * 목적이 코어 default_provider 로 폴백된다. 설치 시 1회 이니시스를 초기값으로 세팅하여
 * 설치 직후부터 성인인증이 이니시스로 분기되도록 한다.
 *
 * 검증 매트릭스:
 *  - 매핑 미설정 상태에서 install() → inicis 로 세팅
 *  - 운영자가 이미 다른 값을 지정한 상태에서 install() → 덮어쓰지 않음(초기값 성격)
 *  - install() 은 항상 true 반환
 *  - install() 본문이 src/ 클래스(InicisIdentityProvider)를 정적 참조하지 않음 (오토로드 미등록 회귀 가드)
 *
 * Storage::fake('settings') 로 격리 — 실제 dev 설정 파일을 오염시키지 않는다.
 *
 * 회귀: install() 이 InicisIdentityProvider::ADULT_PURPOSE / ::PROVIDER_ID 상수를 참조하면
 * 실제 설치 경로(코어 PluginManager->install)에서 "Class not found" 가 발생한다. install() 은
 * 코어가 플러그인 PSR-4 오토로드를 등록하기 전에 호출되기 때문. 리터럴 문자열로 대체해야 한다.
 */
class InicisInstallDefaultPurposeProviderTest extends PluginTestCase
{
    /** getIdentityPurposes() 선언값과 동일한 리터럴 (provider 상수 참조 금지 — 위 회귀 참고) */
    private const PURPOSE_KEY = 'identity.purpose_providers.inicis.adult_verification';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('settings');
    }

    /**
     * @scenario existing_mapping=unset
     * @effects install_sets_inicis_when_mapping_unset, install_always_returns_true
     */
    public function test_install_sets_adult_verification_provider_to_inicis_when_unset(): void
    {
        $settings = app(SettingsService::class);

        // 사전 조건: 매핑 미설정
        $this->assertEmpty($settings->getSetting(self::PURPOSE_KEY));

        $result = (new Plugin)->install();

        $this->assertTrue($result);
        $this->assertSame('inicis', $settings->getSetting(self::PURPOSE_KEY));
    }

    /**
     * @scenario existing_mapping=set_other
     * @effects install_preserves_existing_operator_choice, install_always_returns_true
     */
    public function test_install_preserves_operator_choice_when_already_set(): void
    {
        $settings = app(SettingsService::class);

        // 운영자가 다른 프로바이더로 이미 지정한 상태
        $settings->setSetting(self::PURPOSE_KEY, 'g7:core.mail');

        $result = (new Plugin)->install();

        $this->assertTrue($result);
        $this->assertSame(
            'g7:core.mail',
            $settings->getSetting(self::PURPOSE_KEY),
            '운영자가 이미 지정한 매핑은 install() 이 덮어쓰지 않아야 한다',
        );
    }

    /**
     * install() 은 코어 PluginManager 가 플러그인 PSR-4 오토로드를 등록하기 전에 호출되므로,
     * 본문이 src/ 클래스를 정적 참조하면 실제 설치 시 "Class not found" 로 깨진다.
     * install() 메서드 소스에 InicisIdentityProvider 참조가 없음을 정적으로 보장한다.
     */
    public function test_install_does_not_statically_reference_src_provider_class(): void
    {
        $reflection = new \ReflectionMethod(Plugin::class, 'install');
        $file = file($reflection->getFileName());
        $body = implode('', array_slice(
            $file,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $this->assertStringNotContainsString(
            'InicisIdentityProvider',
            $body,
            'install() 은 PSR-4 오토로드 등록 전에 호출되므로 src/ 클래스를 참조하면 안 된다 (리터럴 사용)',
        );
    }
}
