<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\PureTestCase;

/**
 * 현금영수증 식별번호 종류 Enum 테스트
 */
class CashReceiptIdentifierTypeTest extends PureTestCase
{
    /**
     * 체크섬이 맞는 10자리 사업자등록번호는 유효한 번호로 판정된다.
     *
     * @scenario identifier_type=business
     *
     * @effects business_number_checksum_validated
     *
     * @param  string  $number  체크섬이 유효한 사업자등록번호
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('validBusinessNumberProvider')]
    public function 유효한_사업자등록번호는_체크섬을_통과한다(string $number): void
    {
        $this->assertTrue(CashReceiptIdentifierType::isValidBusinessNumber($number));
    }

    /**
     * 체크섬 검증을 통과해야 하는 실제 사업자등록번호 사례를 제공한다.
     *
     * @return array<string, array{string}> 사례 이름별 [사업자등록번호]
     */
    public static function validBusinessNumberProvider(): array
    {
        return [
            '체크섬 유효 #1' => ['1248100998'],
            '체크섬 유효 #2' => ['1018116293'],
        ];
    }

    /**
     * 체크digit 불일치·000 세무서 코드·자릿수 오류·숫자 외 문자가 섞인 번호는 사업자등록번호로 인정되지 않는다.
     *
     * @scenario identifier_type=business
     *
     * @effects business_number_checksum_validated
     *
     * @param  string  $number  거부되어야 하는 사업자등록번호 후보
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('invalidBusinessNumberProvider')]
    public function 무효한_사업자등록번호는_거부된다(string $number): void
    {
        $this->assertFalse(CashReceiptIdentifierType::isValidBusinessNumber($number));
    }

    /**
     * 체크섬·자릿수·형식 중 하나라도 어긋나 거부되어야 하는 번호 사례를 제공한다.
     *
     * @return array<string, array{string}> 사례 이름별 [무효 번호]
     */
    public static function invalidBusinessNumberProvider(): array
    {
        return [
            '체크digit 불일치' => ['1248100997'],
            '임의 순번' => ['1234567890'],
            // 앞 9자리가 0 이면 체크섬을 수학적으로 항상 만족하지만 발급될 수 없는 번호다.
            '전부 0 (체크섬은 통과하나 세무서 코드 000)' => ['0000000000'],
            '자릿수 부족' => ['124810099'],
            '자릿수 초과' => ['12481009980'],
            '숫자 아님' => ['124-81-0099'],
        ];
    }

    /**
     * 휴대폰(010 등 접두 10~11자리)·카드(13~19자리)·사업자번호(체크섬) 종류별 형식 규칙대로 식별번호 일치 여부를 판정한다.
     *
     * @scenario identifier_type=phone
     *
     * @effects business_number_checksum_validated, identifier_format_validated_per_type
     *
     * @param  CashReceiptIdentifierType  $type  검증할 식별번호 종류
     * @param  string  $identifier  검사 대상 식별번호 문자열
     * @param  bool  $expected  형식 일치 기대값
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('identifierFormatProvider')]
    public function 식별번호_형식을_종류별로_검증한다(
        CashReceiptIdentifierType $type,
        string $identifier,
        bool $expected,
    ): void {
        $this->assertSame($expected, $type->matches($identifier));
    }

    /**
     * 식별번호 종류별 경계 자릿수·접두어·하이픈 포함 사례와 기대 판정을 제공한다.
     *
     * @return array<string, array{CashReceiptIdentifierType, string, bool}> 사례 이름별 [종류, 식별번호, 기대값]
     */
    public static function identifierFormatProvider(): array
    {
        return [
            '휴대폰 10자리' => [CashReceiptIdentifierType::PHONE, '0101234567', true],
            '휴대폰 11자리' => [CashReceiptIdentifierType::PHONE, '01012345678', true],
            '휴대폰 접두어 오류' => [CashReceiptIdentifierType::PHONE, '02012345678', false],
            '휴대폰 하이픈 포함' => [CashReceiptIdentifierType::PHONE, '010-1234-5678', false],
            '카드 13자리' => [CashReceiptIdentifierType::CARD, '1234567890123', true],
            '카드 19자리' => [CashReceiptIdentifierType::CARD, '1234567890123456789', true],
            '카드 12자리(부족)' => [CashReceiptIdentifierType::CARD, '123456789012', false],
            '카드 20자리(초과)' => [CashReceiptIdentifierType::CARD, '12345678901234567890', false],
            '사업자번호 유효' => [CashReceiptIdentifierType::BUSINESS, '1248100998', true],
            '사업자번호 무효' => [CashReceiptIdentifierType::BUSINESS, '1248100997', false],
        ];
    }

    /**
     * 국세청 자진발급 지정번호(010-000-1234)는 자진발급으로 식별하고 일반 휴대폰번호는 그렇게 보지 않는다.
     *
     * @scenario identifier_type=phone
     *
     * @effects self_issue_number_recognized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 자진발급_지정번호를_식별한다(): void
    {
        $this->assertTrue(CashReceiptIdentifierType::isSelfIssueNumber('0100001234'));
        $this->assertFalse(CashReceiptIdentifierType::isSelfIssueNumber('01012345678'));
    }

    /**
     * 소득공제용 발급은 휴대폰·카드번호만 식별번호로 허용하고 사업자등록번호는 허용하지 않는다.
     *
     * @scenario identifier_type=card, receipt_type=income
     *
     * @effects phone_and_card_allowed_for_both_types, business_number_only_for_expense
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 소득공제용은_휴대폰과_카드번호만_허용한다(): void
    {
        $allowed = CashReceiptType::INCOME->allowedIdentifierTypes();

        $this->assertContains(CashReceiptIdentifierType::PHONE, $allowed);
        $this->assertContains(CashReceiptIdentifierType::CARD, $allowed);
        $this->assertNotContains(CashReceiptIdentifierType::BUSINESS, $allowed);
    }

    /**
     * 지출증빙용 발급은 사업자등록번호뿐 아니라 휴대폰·카드번호도 식별번호로 허용한다.
     *
     * @scenario identifier_type=business, receipt_type=expense
     *
     * @effects phone_and_card_allowed_for_both_types, business_number_only_for_expense
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 지출증빙용은_휴대폰번호도_허용한다(): void
    {
        // 팝빌 발행유형 가이드가 소득공제/지출증빙 양쪽에 휴대폰번호를 명시한다.
        $allowed = CashReceiptType::EXPENSE->allowedIdentifierTypes();

        $this->assertContains(CashReceiptIdentifierType::BUSINESS, $allowed);
        $this->assertContains(CashReceiptIdentifierType::PHONE, $allowed);
        $this->assertContains(CashReceiptIdentifierType::CARD, $allowed);
    }

    /**
     * KG 레거시 발급용도 값은 정규 용도로, 정규 값은 그대로, null·빈 문자열·해석 불가 값은 null 로 정규화한다.
     *
     * @scenario legacy_receipt_type=income_deduction
     *
     * @effects legacy_receipt_type_normalized
     *
     * @param  string|null  $legacy  저장돼 있던 발급용도 원시값
     * @param  CashReceiptType|null  $expected  정규화 기대 결과
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('legacyTypeProvider')]
    public function 레거시_발급용도_값을_정규화한다(?string $legacy, ?CashReceiptType $expected): void
    {
        $this->assertSame($expected, CashReceiptType::fromLegacy($legacy));
    }

    /**
     * 레거시·정규·누락·해석 불가 발급용도 값과 정규화 기대 결과를 제공한다.
     *
     * @return array<string, array{?string, ?CashReceiptType}> 사례 이름별 [원시값, 기대 용도]
     */
    public static function legacyTypeProvider(): array
    {
        return [
            'KG 레거시 소득공제' => ['income_deduction', CashReceiptType::INCOME],
            'KG 레거시 지출증빙' => ['expenditure_proof', CashReceiptType::EXPENSE],
            '정규 소득공제' => ['income', CashReceiptType::INCOME],
            '정규 지출증빙' => ['expense', CashReceiptType::EXPENSE],
            'null' => [null, null],
            '빈 문자열' => ['', null],
            '해석 불가' => ['unknown', null],
        ];
    }
}
