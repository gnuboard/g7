<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 관리자 마일리지 수동 차감 DTO
 */
class MileageAdminDeductDto
{
    /**
     * @param  int  $amount  차감 금액 (양수 입력 — 내부에서 음수 거래로 기록)
     * @param  string  $currency  통화 코드
     * @param  int  $grantedBy  부여(차감) 관리자 user.id
     * @param  string|null  $memo  관리자 메모
     * @param  string|null  $description  거래 설명
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public int $grantedBy,
        public ?string $memo = null,
        public ?string $description = null,
    ) {}
}
