<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use Illuminate\Support\Facades\App;
use Plugins\Sirsoft\MessageBizppurio\Enums\ResultCategory;
use Plugins\Sirsoft\MessageBizppurio\Services\ResultCodeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * ResultCodeResolver — 결과코드 분류·사유·라벨 검증 (계획서 D11).
 *
 * @scenario result_category=failure
 */
class ResultCodeResolverTest extends PluginTestCase
{
    private ResultCodeResolver $resolver;

    /**
     * 사유 문구를 한국어로 고정하고 검증 대상 ResultCodeResolver 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        App::setLocale('ko');
        $this->resolver = new ResultCodeResolver;
    }

    /**
     * 성공 코드(발송 1000 / SMS 4100 / LMS 6600 / 알림톡 7000 / 카카오 200)는 Success.
     *
     * @scenario result_category=success
     *
     * @effects success_codes_categorized_as_success
     *
     * @return void 반환값 없음
     */
    public function test_success_codes_categorized_as_success(): void
    {
        foreach (['1000', '4100', '6600', '7000', '200'] as $code) {
            $this->assertSame(ResultCategory::Success, $this->resolver->categorize($code), "코드 {$code}");
            $this->assertTrue($this->resolver->isSuccess($code), "코드 {$code}");
        }
    }

    /**
     * 잔액부족(9070 문자 / 7436 알림톡 / 9071 후불 한도초과)은 BalanceLow + isBalanceLow true.
     *
     * @scenario result_category=balance_low
     *
     * @effects balance_low_codes_9070_7436_categorized_as_balance_low
     *
     * @return void 반환값 없음
     */
    public function test_balance_low_codes(): void
    {
        foreach (['9070', '7436', '9071'] as $code) {
            $this->assertSame(ResultCategory::BalanceLow, $this->resolver->categorize($code), "코드 {$code}");
            $this->assertTrue($this->resolver->isBalanceLow($code), "코드 {$code}");
            $this->assertFalse($this->resolver->isSuccess($code), "코드 {$code}");
        }
    }

    /**
     * 후불 한도초과(9071) 는 사유 문구가 lang 에 정의되어 코드만 노출되지 않는다.
     *
     * @scenario result_category=balance_low
     *
     * @effects postpaid_limit_code_9071_has_reason
     *
     * @return void 반환값 없음
     */
    public function test_postpaid_limit_code_has_reason(): void
    {
        $this->assertNotNull($this->resolver->reason('9071'));
        $this->assertStringContainsString('9071', $this->resolver->label('9071'));
    }

    /**
     * 최신 명세 정합화로 추가된 코드(IP 오류·수신거부·발신프로필 키 무효)에 사유가 정의돼
     * 코드만 노출되지 않는다.
     *
     * @effects newly_added_result_codes_have_reason
     *
     * @return void 반환값 없음
     */
    public function test_newly_added_codes_have_reason(): void
    {
        foreach (['3003', '3010', '4431', '7103'] as $code) {
            $this->assertNotNull($this->resolver->reason($code), "코드 {$code} 사유 누락");
        }
    }

    /**
     * 재시도 대상으로 분류된 코드는 전부 사유가 lang 에 정의돼 있어야 한다(코드값만 노출 금지).
     *
     * 회귀: RETRYABLE_CODES 에 5002 가 분류돼 있었으나 lang/{ko,en}/result_codes.php 와 ja
     * 번들에 5002 키가 누락되어 label('5002') 가 사유 없이 코드만 반환하던 결함.
     *
     * @scenario result_category=retry
     *
     * @effects retryable_codes_all_have_reason
     *
     * @return void 반환값 없음
     */
    public function test_all_retryable_codes_have_reason(): void
    {
        foreach (['5002', '5003', '5004', '5005', '9000', '3011', '3013', '7306', '7307', '7421', '7437'] as $code) {
            $this->assertNotNull($this->resolver->reason($code), "코드 {$code} 사유 누락");
        }
    }

    /**
     * 일시오류 코드는 Retry.
     *
     * 공통(5002 요청 과다·5003/5004/5005/9000/3011/3013)에 더해, 알림톡 일시오류(7306 카카오
     * 시스템오류·7307 처리지연·7421 타임아웃·7437 메시지 요청실패)도 재시도 대상이다.
     * 7305(성공 불확실)는 이미 발송됐을 수 있어 중복발송 위험이 있으므로 재시도 대상에서 제외한다.
     *
     * @scenario result_category=retry
     *
     * @effects retryable_codes_categorized_as_retry
     *
     * @return void 반환값 없음
     */
    public function test_retryable_codes_categorized_as_retry(): void
    {
        foreach (['5002', '5003', '5004', '5005', '9000', '3011', '3013', '7306', '7307', '7421', '7437'] as $code) {
            $this->assertSame(ResultCategory::Retry, $this->resolver->categorize($code), "코드 {$code}");
        }
    }

    /**
     * 7305(성공 불확실)는 중복발송 위험으로 재시도 대상이 아니다(영구실패로 분류).
     *
     * @scenario result_category=failure
     *
     * @effects success_uncertain_7305_not_retryable
     *
     * @return void 반환값 없음
     */
    public function test_success_uncertain_7305_is_not_retryable(): void
    {
        $this->assertNotSame(ResultCategory::Retry, $this->resolver->categorize('7305'));
    }

    /**
     * 위 분류에 없는 코드는 영구 실패.
     *
     * @scenario result_category=failure
     *
     * @effects unknown_codes_categorized_as_permanent_failure
     *
     * @return void 반환값 없음
     */
    public function test_unknown_and_failure_codes_are_permanent_failure(): void
    {
        foreach (['4400', '6606', '7204', '2000', '9999'] as $code) {
            $this->assertSame(ResultCategory::PermanentFailure, $this->resolver->categorize($code), "코드 {$code}");
        }
    }

    /**
     * reason 은 lang 정의 코드는 사유를, 미정의 코드는 null 을 반환.
     *
     * @effects reason_resolves_defined_codes_and_null_for_unknown
     *
     * @return void 반환값 없음
     */
    public function test_reason_resolves_defined_codes_and_null_for_unknown(): void
    {
        $this->assertSame('음영 지역', $this->resolver->reason('4400'));
        $this->assertNull($this->resolver->reason('9999'));
    }

    /**
     * label 은 정의 코드는 "사유 (코드)", 미정의 코드는 코드만 반환.
     *
     * @effects label_formats_reason_with_code
     *
     * @return void 반환값 없음
     */
    public function test_label_formats_reason_with_code(): void
    {
        $this->assertSame('음영 지역 (4400)', $this->resolver->label('4400'));
        $this->assertSame('9999', $this->resolver->label('9999'));
    }
}
