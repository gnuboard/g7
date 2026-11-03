<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\SnapshotProduct;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PureTestCase;

/**
 * 스냅샷 상품의 과세 종류 해석 — 손상·미지 값은 과세로 본다 (#612 W12)
 *
 * 클레임 재계산은 주문 시점 상품 스냅샷으로 돈다. 스냅샷의 `tax_status` 가 손상됐거나(대소문자·공백·숫자·배열)
 * 이 버전이 모르는 값이면 `tryFrom` 이 null 을 돌려주는데, 그 null 을 그대로 두면 과세 판정(=== TAXABLE)이 거짓이
 * 되어 손상된 주문이 조용히 면세로 재계산된다. 「없으면 과세」 규약으로 떨어지는지 고정한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/order-vat-amount.yaml`
 */
class SnapshotProductTaxStatusTest extends PureTestCase
{
    /**
     * 손상·미지 과세 종류는 과세로 해석되는지 확인합니다.
     *
     * @effects snapshot_unknown_tax_status_falls_back_to_taxable
     *
     * @param  mixed  $value  스냅샷에 남은 tax_status 값
     * @return void 반환값 없음
     */
    #[DataProvider('corruptedTaxStatusProvider')]
    public function test_corrupted_or_unknown_tax_status_resolves_to_taxable(mixed $value): void
    {
        $product = new SnapshotProduct(['id' => 1, 'name' => '손상 스냅샷', 'tax_status' => $value, 'tax_rate' => 10.0]);

        $this->assertSame(ProductTaxStatus::TAXABLE, $product->tax_status);
    }

    /**
     * 손상·미지 과세 종류 값.
     *
     * @return array<string, array{mixed}> 케이스 (스냅샷 tax_status 값)
     */
    public static function corruptedTaxStatusProvider(): array
    {
        return [
            '미지 문자열' => ['bogus'],
            '빈 문자열' => [''],
            '대문자' => ['ZERO_RATED'],
            '앞뒤 공백' => [' tax_free '],
            '구 표기(비과세)' => ['non_taxable'],
            '정수' => [1],
            '배열' => [['value' => 'zero_rated']],
            'null' => [null],
        ];
    }

    /**
     * 키 자체가 없는 스냅샷도 과세로 해석되는지 확인합니다.
     *
     * @effects snapshot_unknown_tax_status_falls_back_to_taxable
     *
     * @return void 반환값 없음
     */
    public function test_missing_tax_status_key_resolves_to_taxable(): void
    {
        $product = new SnapshotProduct(['id' => 1, 'name' => '구 스냅샷']);

        $this->assertSame(ProductTaxStatus::TAXABLE, $product->tax_status);
    }

    /**
     * 올바른 값은 그대로 해석되는지 확인합니다 — 폴백이 정상 값까지 과세로 덮지 않아야 한다.
     *
     * @effects snapshot_unknown_tax_status_falls_back_to_taxable
     *
     * @return void 반환값 없음
     */
    public function test_valid_tax_status_is_preserved(): void
    {
        foreach (ProductTaxStatus::cases() as $status) {
            $product = new SnapshotProduct(['id' => 1, 'name' => '정상', 'tax_status' => $status->value]);

            $this->assertSame($status, $product->tax_status, $status->value);
        }
    }
}
