<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Services;

use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityRecord;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;

/**
 * 마이페이지 본인확인 카드 데이터 조회 Service.
 *
 * Controller 가 Repository 를 직접 주입하지 않도록(service-repository 규정) 사용자 ID →
 * 본인확인 record 조회의 단일 책임을 캡슐화한다.
 *
 * @since 1.0.0
 */
class KcpIdentityCardService
{
    /**
     * @param  KcpIdentityRecordRepositoryInterface  $recordRepository  본 plugin record Repository
     */
    public function __construct(
        protected readonly KcpIdentityRecordRepositoryInterface $recordRepository,
    ) {}

    /**
     * 사용자의 KCP 본인확인 record 를 조회한다 (없으면 null).
     *
     * @param  int  $userId  조회 대상 사용자 ID (보통 Auth::id())
     * @return KcpIdentityRecord|null 본인확인 record 또는 null
     */
    public function findForUser(int $userId): ?KcpIdentityRecord
    {
        return $this->recordRepository->findByUserId($userId);
    }
}
