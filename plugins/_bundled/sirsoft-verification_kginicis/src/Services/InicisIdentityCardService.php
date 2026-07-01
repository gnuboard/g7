<?php

namespace Plugins\Sirsoft\VerificationKginicis\Services;

use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;

/**
 * 마이페이지 본인인증 카드 데이터 조회 Service.
 *
 * Controller 가 Repository 를 직접 주입하지 않도록 (service-repository 규정) 본 Service 가
 * 사용자 ID → 본인확인 record 조회의 단일 책임을 캡슐화한다.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityCardService
{
    /**
     * @param  InicisIdentityRecordRepositoryInterface  $recordRepository  본 plugin record Repository
     */
    public function __construct(
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
    ) {}

    /**
     * 사용자의 이니시스 본인확인 record 를 조회한다 (없으면 null).
     *
     * @param  int  $userId  조회 대상 사용자 ID (보통 Auth::id())
     * @return InicisIdentityRecord|null
     */
    public function findForUser(int $userId): ?InicisIdentityRecord
    {
        return $this->recordRepository->findByUserId($userId);
    }
}
