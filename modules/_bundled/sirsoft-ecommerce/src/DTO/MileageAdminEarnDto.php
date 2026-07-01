<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 관리자 마일리지 수동 지급 DTO
 */
class MileageAdminEarnDto
{
    /**
     * @param  int  $amount  지급 금액 (양수)
     * @param  string  $currency  통화 코드
     * @param  int  $grantedBy  부여 관리자 user.id
     * @param  string|null  $memo  관리자 메모
     * @param  string|null  $description  거래 설명
     * @param  string|null  $expiresAt  직접 지정 만료일 (null 시 정책 기본 또는 무기한)
     * @param  bool  $useDefaultExpiry  정책 기본 유효기간 적용 여부 (false + expiresAt null = 무기한)
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public int $grantedBy,
        public ?string $memo = null,
        public ?string $description = null,
        public ?string $expiresAt = null,
        public bool $useDefaultExpiry = true,
    ) {}
}
