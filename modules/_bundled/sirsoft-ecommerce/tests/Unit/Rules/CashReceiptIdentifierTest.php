<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Rules;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Rules\CashReceiptIdentifier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 현금영수증 식별번호 Custom Rule 테스트 (A-8-1 / D10)
 *
 * 용도 × 식별번호 종류 조합 매트릭스와 형식(정규식·체크섬) 검증을 다룬다.
 * "지출증빙 + 휴대폰번호 = 허용" 이 핵심이다 — 이슈 본문의 1:1 매핑은 부정확했다.
 */
class CashReceiptIdentifierTest extends ModuleTestCase
{
    /**
     * Rule 을 단독 실행해 통과 여부를 반환합니다.
     */
    private function passes(?CashReceiptType $type, ?CashReceiptIdentifierType $identifierType, string $value): bool
    {
        $validator = Validator::make(
            ['identifier' => $value],
            ['identifier' => [new CashReceiptIdentifier($type, $identifierType)]],
        );

        return $validator->passes();
    }

    // ─────────────────────────────────────────────
    // 용도 × 식별번호 종류 조합 매트릭스
    // ─────────────────────────────────────────────

    /**
     * @return array<string, array{CashReceiptType, CashReceiptIdentifierType, string, bool}>
     */
    public static function combinationMatrix(): array
    {
        // 사업자번호 1078648269 는 체크섬 유효값 (아래 별도 테스트에서 고정)
        return [
            '소득공제 + 휴대폰' => [CashReceiptType::INCOME, CashReceiptIdentifierType::PHONE, '01012345678', true],
            '소득공제 + 현금영수증카드' => [CashReceiptType::INCOME, CashReceiptIdentifierType::CARD, '1234567890123', true],
            '소득공제 + 사업자번호(거부)' => [CashReceiptType::INCOME, CashReceiptIdentifierType::BUSINESS, '1078648269', false],
            '지출증빙 + 사업자번호' => [CashReceiptType::EXPENSE, CashReceiptIdentifierType::BUSINESS, '1078648269', true],
            // 팝빌 발행유형 가이드 — 지출증빙에도 휴대폰번호 사용 가능
            '지출증빙 + 휴대폰(허용)' => [CashReceiptType::EXPENSE, CashReceiptIdentifierType::PHONE, '01012345678', true],
            '지출증빙 + 현금영수증카드' => [CashReceiptType::EXPENSE, CashReceiptIdentifierType::CARD, '1234567890123', true],
        ];
    }

    #[Test]
    #[DataProvider('combinationMatrix')]
    public function 용도와_식별번호_종류_조합을_검증한다(
        CashReceiptType $type,
        CashReceiptIdentifierType $identifierType,
        string $value,
        bool $expected,
    ): void {
        $this->assertSame($expected, $this->passes($type, $identifierType, $value));
    }

    // ─────────────────────────────────────────────
    // 자진발급 지정번호 (소득공제 전용)
    // ─────────────────────────────────────────────

    #[Test]
    public function 자진발급_지정번호는_소득공제용으로_허용된다(): void
    {
        $this->assertTrue($this->passes(
            CashReceiptType::INCOME,
            CashReceiptIdentifierType::PHONE,
            CashReceiptIdentifierType::SELF_ISSUE_NUMBER,
        ));
    }

    #[Test]
    public function 자진발급_지정번호는_지출증빙용으로_거부된다(): void
    {
        // 지출증빙 자진발급은 제도상 불가하다.
        $this->assertFalse($this->passes(
            CashReceiptType::EXPENSE,
            CashReceiptIdentifierType::PHONE,
            CashReceiptIdentifierType::SELF_ISSUE_NUMBER,
        ));
    }

    // ─────────────────────────────────────────────
    // 사업자등록번호 체크섬
    // ─────────────────────────────────────────────

    /**
     * @return array<string, array{string, bool}>
     */
    public static function businessNumbers(): array
    {
        return [
            '유효 체크섬 1' => ['1078648269', true],
            '유효 체크섬 2' => ['2208162517', true],
            '체크섬 불일치 (마지막 자리 오류)' => ['1078648260', false],
            '자릿수 부족' => ['107864826', false],
            '자릿수 초과' => ['10786482690', false],
            '숫자 아님' => ['107864826a', false],
            // 세무서 코드 000 은 체크섬을 수학적으로 만족하지만 발급될 수 없는 번호다.
            '세무서 코드 000' => ['0000000000', false],
        ];
    }

    #[Test]
    #[DataProvider('businessNumbers')]
    public function 사업자등록번호_체크섬을_검증한다(string $number, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->passes(CashReceiptType::EXPENSE, CashReceiptIdentifierType::BUSINESS, $number),
        );
    }

    // ─────────────────────────────────────────────
    // 형식 검증 (휴대폰 / 카드번호)
    // ─────────────────────────────────────────────

    /**
     * @return array<string, array{string, bool}>
     */
    public static function phoneNumbers(): array
    {
        return [
            '010 11자리' => ['01012345678', true],
            '011 10자리' => ['0111234567', true],
            '016/017/018/019' => ['01612345678', true],
            '012 (미지원 국번)' => ['01212345678', false],
            '02 지역번호' => ['0212345678', false],
            '자릿수 부족' => ['010123456', false],
            '자릿수 초과' => ['010123456789', false],
        ];
    }

    #[Test]
    #[DataProvider('phoneNumbers')]
    public function 휴대폰번호_형식을_검증한다(string $number, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->passes(CashReceiptType::INCOME, CashReceiptIdentifierType::PHONE, $number),
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cardNumbers(): array
    {
        return [
            '13자리 (최소)' => ['1234567890123', true],
            '19자리 (최대)' => ['1234567890123456789', true],
            '12자리 (미달)' => ['123456789012', false],
            '20자리 (초과)' => ['12345678901234567890', false],
            '숫자 아님' => ['123456789012a', false],
        ];
    }

    #[Test]
    #[DataProvider('cardNumbers')]
    public function 현금영수증_카드번호_형식을_검증한다(string $number, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->passes(CashReceiptType::INCOME, CashReceiptIdentifierType::CARD, $number),
        );
    }

    // ─────────────────────────────────────────────
    // 정규화 / 방어
    // ─────────────────────────────────────────────

    #[Test]
    public function 하이픈과_공백은_제거된_뒤_검증된다(): void
    {
        $this->assertTrue($this->passes(CashReceiptType::INCOME, CashReceiptIdentifierType::PHONE, '010-1234-5678'));
        $this->assertTrue($this->passes(CashReceiptType::EXPENSE, CashReceiptIdentifierType::BUSINESS, '107-86-48269'));
        $this->assertTrue($this->passes(CashReceiptType::INCOME, CashReceiptIdentifierType::PHONE, '010 1234 5678'));
    }

    #[Test]
    public function normalize_는_하이픈과_공백만_제거한다(): void
    {
        $this->assertSame('01012345678', CashReceiptIdentifier::normalize('010-1234-5678'));
        $this->assertSame('1078648269', CashReceiptIdentifier::normalize('107-86-48269'));
        $this->assertSame('01012345678', CashReceiptIdentifier::normalize(' 010 1234 5678 '));
    }

    #[Test]
    public function 용도나_종류가_해석되지_않으면_형식검증을_건너뛴다(): void
    {
        // receipt_type / identifier_type 의 in: 규칙이 이미 오류를 낸 상황 — 중복 오류를 내지 않는다.
        $this->assertTrue($this->passes(null, CashReceiptIdentifierType::PHONE, '완전히잘못된값'));
        $this->assertTrue($this->passes(CashReceiptType::INCOME, null, '완전히잘못된값'));
    }
}
