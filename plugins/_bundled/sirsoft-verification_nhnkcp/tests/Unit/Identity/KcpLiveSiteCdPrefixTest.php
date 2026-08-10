<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Unit\Identity;

use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 운영 사이트코드 프리픽스 부착 + 운영 가능 여부 판정 검증.
 *
 * 운영자는 사이트코드에서 SM 을 뺀 값을 입력하고 런타임이 부착하지만, SM 을 포함해 입력해도
 * 중복 부착되면 안 된다 (그 상태로 저장되면 모든 인증 요청이 실패한다).
 *
 * @since 1.0.0
 */
class KcpLiveSiteCdPrefixTest extends PluginTestCase
{
    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_prefix_is_added_when_missing(): void
    {
        $this->assertSame('SMA1B2C', $this->provider()->buildLiveSiteCd('A1B2C'));
    }

    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_prefix_is_not_duplicated_when_already_present(): void
    {
        $this->assertSame('SMA1B2C', $this->provider()->buildLiveSiteCd('SMA1B2C'));
    }

    /**
     * 운영자가 프리픽스를 소문자로 입력했을 때도 중복 부착되지 않아야 한다.
     *
     * 사이트코드 자체는 대문자로 발급되지만 프리픽스를 손으로 옮겨 적는 과정에서 소문자가
     * 섞일 수 있다. 이때 프리픽스 판정이 대소문자를 구분하면 `SMsmA1B2C` 가 만들어져 모든
     * 인증 요청이 잘못된 사이트코드로 나간다.
     *
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_prefix_is_not_duplicated_when_present_in_lowercase(): void
    {
        $this->assertSame('SMA1B2C', $this->provider()->buildLiveSiteCd('smA1B2C'));
        $this->assertSame('SMA1B2C', $this->provider()->buildLiveSiteCd('SmA1B2C'));
        $this->assertSame('SMA1B2C', $this->provider()->buildLiveSiteCd('sMA1B2C'));
    }

    /**
     * 소문자 프리픽스가 이미 저장된 기설치본도 런타임에서 올바른 사이트코드를 내야 한다.
     *
     * @scenario mode=live,live_credentials=complete
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_stored_lowercase_prefix_resolves_to_canonical_site_cd(): void
    {
        $provider = $this->provider([
            'is_test_mode' => false,
            'live_site_cd' => 'smA1B2C',
            'live_enc_key' => 'live-key',
        ]);

        $this->assertTrue($provider->isAvailable());
        $this->assertSame('SMA1B2C', $provider->resolveSiteCd());
    }

    /**
     * @scenario mode=live,live_credentials=missing_site_cd
     *
     * @effects sm_prefix_is_added_once
     */
    public function test_blank_input_stays_blank(): void
    {
        $this->assertSame('', $this->provider()->buildLiveSiteCd(''));
        $this->assertSame('', $this->provider()->buildLiveSiteCd('   '));
    }

    /**
     * @scenario mode=test,live_credentials=complete
     *
     * @effects availability_reflects_mode_and_credentials
     */
    public function test_test_mode_is_available_with_test_credentials(): void
    {
        $provider = $this->provider([
            'is_test_mode' => true,
            'test_site_cd' => 'AO7F3',
            'test_enc_key' => 'test-key',
        ]);

        $this->assertTrue($provider->isAvailable());
    }

    /**
     * @scenario mode=test,live_credentials=complete
     *
     * @effects availability_reflects_mode_and_credentials
     */
    public function test_test_mode_is_unavailable_without_test_credentials(): void
    {
        $provider = $this->provider([
            'is_test_mode' => true,
            'test_site_cd' => 'AO7F3',
            'test_enc_key' => '',
        ]);

        $this->assertFalse($provider->isAvailable());
    }

    /**
     * @scenario mode=live,live_credentials=complete
     *
     * @effects availability_reflects_mode_and_credentials
     */
    public function test_live_mode_is_available_with_live_credentials(): void
    {
        $provider = $this->provider([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'live-key',
        ]);

        $this->assertTrue($provider->isAvailable());
        $this->assertSame('SMA1B2C', $provider->resolveSiteCd());
        $this->assertSame('live-key', $provider->resolveEncKey());
    }

    /**
     * @scenario mode=live,live_credentials=missing_enc_key
     *
     * @effects availability_reflects_mode_and_credentials
     */
    public function test_live_mode_is_unavailable_without_enc_key(): void
    {
        $provider = $this->provider([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => '',
        ]);

        $this->assertFalse($provider->isAvailable());
    }

    /**
     * @scenario mode=live,live_credentials=missing_site_cd
     *
     * @effects availability_reflects_mode_and_credentials
     */
    public function test_live_mode_is_unavailable_without_site_cd(): void
    {
        $provider = $this->provider([
            'is_test_mode' => false,
            'live_site_cd' => '',
            'live_enc_key' => 'live-key',
        ]);

        $this->assertFalse($provider->isAvailable());
    }

    /**
     * 설정이 주입된 provider 인스턴스.
     *
     * @param  array<string, mixed>  $config
     */
    private function provider(array $config = []): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, ['config' => $config]);
    }
}
