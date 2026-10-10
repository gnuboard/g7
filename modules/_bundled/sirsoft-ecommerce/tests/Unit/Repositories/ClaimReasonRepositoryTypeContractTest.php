<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Repositories\ClaimReasonRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 사유 카탈로그 조회의 `type` 필수 계약 회귀 테스트
 *
 * 결함: `getAll()` 이 `$filters['type'] ?? 'refund'` 로 조용한 기본값을 갖고 있었다.
 *   카탈로그가 환불 한 종류였을 때는 무해했지만 교환·반품이 생긴 뒤로는,
 *   `type` 을 빠뜨린 호출부가 "교환·반품 사유가 하나도 없다" 는 결과를 받는다 —
 *   예외도 로그도 남지 않고 화면에서만 빈 목록으로 드러난다. 미지정 시 환불로 해석하는
 *   결정은 요청 경계(`IndexClaimReasonRequest::filters()`)에 이미 있으므로 한 곳에만 있어야 한다.
 *
 * 기본값을 되살리면 첫 케이스가 red 가 된다.
 *
 * @scenario reason_type=exchange
 *
 * @effects settings_claim_reason_catalog_reads_and_writes_own_type
 */
class ClaimReasonRepositoryTypeContractTest extends ModuleTestCase
{
    protected ClaimReasonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ClaimReasonRepository(new ClaimReason);
    }

    /**
     * 사유 한 건을 만듭니다.
     *
     * @param  string  $type  사유 유형
     * @param  string  $code  사유 코드
     * @return ClaimReason 생성된 사유
     */
    private function makeReason(string $type, string $code): ClaimReason
    {
        return ClaimReason::create([
            'type' => $type,
            'code' => $code,
            'name' => ['ko' => $code, 'en' => $code],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * `type` 없이 조회하면 계약 위반으로 예외가 난다.
     */
    #[Test]
    public function it_rejects_a_query_without_a_type_filter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 조용히 환불만 돌려주지 않는다 — 빠뜨린 호출부가 즉시 드러나야 한다.
        $this->repository->getAll(['is_active' => true]);
    }

    /**
     * `type` 을 주면 그 유형만 돌려준다.
     */
    #[Test]
    public function it_returns_only_the_requested_type(): void
    {
        $this->makeReason('exchange', 'exchange_only');
        $this->makeReason('return', 'return_only');
        $this->makeReason('refund', 'refund_only');

        $codes = $this->repository->getAll(['type' => 'exchange'])->pluck('code');

        $this->assertContains('exchange_only', $codes);
        $this->assertNotContains('return_only', $codes);
        $this->assertNotContains('refund_only', $codes);
    }
}
