<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 현금영수증 식별번호 종류 Enum 테스트
 */
class CashReceiptIdentifierTypeTest extends ModuleTestCase
{
    #[Test]
    #[DataProvider('validBusinessNumberProvider')]
    public function 유효한_사업자등록번호는_체크섬을_통과한다(string $number): void
    {
        $this->assertTrue(CashReceiptIdentifierType::isValidBusinessNumber($number));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validBusinessNumberProvider(): array
    {
        return [
            '체크섬 유효 #1' => ['1248100998'],
            '체크섬 유효 #2' => ['1018116293'],
        ];
    }

    #[Test]
    #[DataProvider('invalidBusinessNumberProvider')]
    public function 무효한_사업자등록번호는_거부된다(string $number): void
    {
        $this->assertFalse(CashReceiptIdentifierType::isValidBusinessNumber($number));
    }

    /**
     * @return array<string, array{string}>
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
     * @return array<string, array{CashReceiptIdentifierType, string, bool}>
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

    #[Test]
    public function 자진발급_지정번호를_식별한다(): void
    {
        $this->assertTrue(CashReceiptIdentifierType::isSelfIssueNumber('0100001234'));
        $this->assertFalse(CashReceiptIdentifierType::isSelfIssueNumber('01012345678'));
    }

    #[Test]
    public function 소득공제용은_휴대폰과_카드번호만_허용한다(): void
    {
        $allowed = CashReceiptType::INCOME->allowedIdentifierTypes();

        $this->assertContains(CashReceiptIdentifierType::PHONE, $allowed);
        $this->assertContains(CashReceiptIdentifierType::CARD, $allowed);
        $this->assertNotContains(CashReceiptIdentifierType::BUSINESS, $allowed);
    }

    #[Test]
    public function 지출증빙용은_휴대폰번호도_허용한다(): void
    {
        // 팝빌 발행유형 가이드가 소득공제/지출증빙 양쪽에 휴대폰번호를 명시한다.
        $allowed = CashReceiptType::EXPENSE->allowedIdentifierTypes();

        $this->assertContains(CashReceiptIdentifierType::BUSINESS, $allowed);
        $this->assertContains(CashReceiptIdentifierType::PHONE, $allowed);
        $this->assertContains(CashReceiptIdentifierType::CARD, $allowed);
    }

    #[Test]
    #[DataProvider('legacyTypeProvider')]
    public function 레거시_발급용도_값을_정규화한다(?string $legacy, ?CashReceiptType $expected): void
    {
        $this->assertSame($expected, CashReceiptType::fromLegacy($legacy));
    }

    /**
     * @return array<string, array{?string, ?CashReceiptType}>
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
