<?php

namespace Tests\Unit\Services;

use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * IDV 인증코드 길이 경계 테스트 (C3)
 *
 * 스키마가 경계를 선언하지 않아 서비스가 자체 상수(4~10)로 조용히 클램프했습니다.
 * 관리자가 12를 설정하면 화면은 12로 보이는데 실제로는 10자리 코드가 발급됐습니다.
 */
class MailIdentityProviderCodeLengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 프로바이더 인스턴스를 반환합니다.
     *
     * @return MailIdentityProvider 프로바이더
     */
    private function provider(): MailIdentityProvider
    {
        return app(MailIdentityProvider::class);
    }

    /**
     * 스키마가 경계를 선언하는지 테스트합니다.
     *
     * 관리자 화면은 스키마를 그대로 노출하므로, 경계 선언이 곧 UI 계약이 된다.
     *
     * @scenario provider=mail, code_length=schema_declaration
     *
     * @effects schema_declares_min_max_boundaries
     */
    public function test_schema_declares_code_length_boundaries(): void
    {
        $schema = $this->provider()->getSettingsSchema();

        // 수정 전: min/max 키 자체가 없었다
        $this->assertArrayHasKey('min', $schema['code_length']);
        $this->assertArrayHasKey('max', $schema['code_length']);
        $this->assertSame(MailIdentityProvider::MIN_CODE_LENGTH, $schema['code_length']['min']);
        $this->assertSame(MailIdentityProvider::MAX_CODE_LENGTH, $schema['code_length']['max']);
        $this->assertSame(MailIdentityProvider::DEFAULT_CODE_LENGTH, $schema['code_length']['default']);
    }

    /**
     * 경계 내 길이는 그대로 적용되는지 테스트합니다.
     *
     * @scenario provider=mail, code_length=within_bounds
     *
     * @effects configured_length_applied_verbatim
     */
    public function test_code_length_within_bounds_is_applied(): void
    {
        $method = new ReflectionMethod(MailIdentityProvider::class, 'generateNumericCode');

        $this->assertSame(8, strlen($method->invoke($this->provider(), 8)));
        $this->assertSame(4, strlen($method->invoke($this->provider(), 4)));
        $this->assertSame(10, strlen($method->invoke($this->provider(), 10)));
    }

    /**
     * 경계를 벗어나면 조용한 클램프 대신 기본값 폴백 + 경고 로그를 남기는지 테스트합니다.
     *
     * @scenario provider=mail, code_length=above_max
     *
     * @effects out_of_range_falls_back_to_default_with_warning
     */
    public function test_out_of_range_falls_back_to_default_with_warning(): void
    {
        Log::spy();

        $method = new ReflectionMethod(MailIdentityProvider::class, 'generateNumericCode');

        // 수정 전: 12 → 10 으로 조용히 클램프
        $code = $method->invoke($this->provider(), 12);

        $this->assertSame(MailIdentityProvider::DEFAULT_CODE_LENGTH, strlen($code));
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 하한 미만도 동일하게 폴백하는지 테스트합니다.
     *
     * @scenario provider=mail, code_length=below_min
     *
     * @effects out_of_range_falls_back_to_default_with_warning
     */
    public function test_below_minimum_falls_back_to_default(): void
    {
        $method = new ReflectionMethod(MailIdentityProvider::class, 'generateNumericCode');

        $this->assertSame(
            MailIdentityProvider::DEFAULT_CODE_LENGTH,
            strlen($method->invoke($this->provider(), 2))
        );
    }

    /**
     * 생성된 코드가 숫자로만 구성되는지 테스트합니다.
     *
     * @scenario provider=mail, code_length=within_bounds
     *
     * @effects generated_code_is_numeric
     */
    public function test_generated_code_is_numeric(): void
    {
        $method = new ReflectionMethod(MailIdentityProvider::class, 'generateNumericCode');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $method->invoke($this->provider(), 6));
    }
}
