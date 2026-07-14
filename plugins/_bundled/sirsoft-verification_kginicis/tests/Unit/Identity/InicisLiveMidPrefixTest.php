<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Identity;

use App\Contracts\Extension\CacheInterface;
use Mockery;
use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;

/**
 * 라이브 MID 프리픽스(LIVE_MID_PREFIX) 회귀 테스트.
 *
 * 배경 (#458):
 *   이니시스 정책 변경으로 라이브 가맹점 MID 프리픽스가 SRA → SRB 로 전환된다.
 *   프리픽스를 InicisIdentityProvider::LIVE_MID_PREFIX 클래스 상수 1곳으로 추출하여
 *   런타임 로직(buildLiveMid / isAvailable)이 상수를 참조하도록 했다. 본 테스트는
 *   상수값이 실제 프리픽스 부착·검증에 일관되게 적용되는지 고정한다 (다음 정책 변경 시
 *   상수만 바꾸면 이 스위트가 전 지점을 함께 검증).
 */
class InicisLiveMidPrefixTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 프리픽스 상수는 현재 정책값 SRB 여야 한다.
     *
     * @return void
     */
    public function test_live_mid_prefix_constant_is_srb(): void
    {
        $this->assertSame('SRB', InicisIdentityProvider::LIVE_MID_PREFIX);
    }

    /**
     * buildLiveMid 는 프리픽스 미포함 입력값 앞에 상수 프리픽스를 부착해야 한다.
     *
     * @return void
     */
    public function test_build_live_mid_prepends_prefix_when_absent(): void
    {
        $result = $this->invokeBuildLiveMid('1234567');

        $this->assertSame(InicisIdentityProvider::LIVE_MID_PREFIX.'1234567', $result);
        $this->assertSame('SRB1234567', $result);
    }

    /**
     * buildLiveMid 는 이미 프리픽스를 포함한 입력값에 중복 부착하지 않아야 한다.
     *
     * @return void
     */
    public function test_build_live_mid_does_not_double_prepend_prefix(): void
    {
        $result = $this->invokeBuildLiveMid('SRB1234567');

        $this->assertSame('SRB1234567', $result);
    }

    /**
     * buildLiveMid 는 빈 입력값(공백 포함)을 빈 문자열로 반환해야 한다.
     *
     * @return void
     */
    public function test_build_live_mid_returns_empty_for_blank_input(): void
    {
        $this->assertSame('', $this->invokeBuildLiveMid(''));
        $this->assertSame('', $this->invokeBuildLiveMid('   '));
    }

    /**
     * 라이브 모드에서 프리픽스가 부착된 라이브 MID + API 키가 있으면 운영 가능해야 한다.
     *
     * @return void
     */
    public function test_is_available_true_in_live_mode_with_prefixed_mid(): void
    {
        $provider = $this->makeProvider([
            'is_test_mode' => false,
            'live_mid' => '1234567',
            'live_api_key' => 'live-api-key',
        ]);

        $this->assertTrue($provider->isAvailable());
    }

    /**
     * 라이브 모드에서 API 키가 비어 있으면 운영 불가여야 한다.
     *
     * @return void
     */
    public function test_is_available_false_in_live_mode_without_api_key(): void
    {
        $provider = $this->makeProvider([
            'is_test_mode' => false,
            'live_mid' => '1234567',
            'live_api_key' => '',
        ]);

        $this->assertFalse($provider->isAvailable());
    }

    /**
     * 라이브 MID 를 리플렉션으로 protected buildLiveMid() 에 통과시킨다.
     *
     * @param  string  $value
     * @return string
     */
    protected function invokeBuildLiveMid(string $value): string
    {
        $provider = $this->makeProvider(['is_test_mode' => true, 'test_mid' => 'INIiasTest']);

        $method = new \ReflectionMethod($provider, 'buildLiveMid');
        $method->setAccessible(true);

        return $method->invoke($provider, $value);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return InicisIdentityProvider
     */
    protected function makeProvider(array $config): InicisIdentityProvider
    {
        return new InicisIdentityProvider(
            Mockery::mock(InicisGatewayInterface::class),
            Mockery::mock(InicisChallengeMappingRepositoryInterface::class),
            Mockery::mock(InicisIdentityRecordRepositoryInterface::class),
            Mockery::mock(CacheInterface::class),
            $config,
        );
    }
}
