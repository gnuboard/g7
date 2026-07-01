<?php

namespace Plugins\Sirsoft\Gdpr\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;

/**
 * GDPR 정책 버전 Repository 인터페이스 (immutable append-only)
 */
interface GdprPolicyVersionRepositoryInterface
{
    /**
     * 최신 (현재) 정책 버전 1건을 반환합니다.
     *
     * @return GdprPolicyVersion|null 발행된 버전이 하나도 없으면 null
     */
    public function getCurrent(): ?GdprPolicyVersion;

    /**
     * 특정 version 정수에 해당하는 정책 버전 1건을 반환합니다.
     *
     * admin 동의 이력 / 정책 버전 이력 화면에서 행 클릭 시 그 시점 snapshot 조회용.
     *
     * @param int $version 조회할 정책 버전 정수
     * @return GdprPolicyVersion|null 해당 버전 row 가 없으면 null
     */
    public function getByVersion(int $version): ?GdprPolicyVersion;

    /**
     * 현재 정책 버전 정수 값을 반환합니다.
     *
     * 신규 설치 직후 (발행 row 없음) 에는 0 을 반환합니다.
     *
     * @return int
     */
    public function getCurrentVersion(): int;

    /**
     * 관리자 화면용 페이지네이션 조회 (이력 모달).
     *
     * 정렬: version DESC (최신 버전이 상단).
     *
     * @param int $perPage 페이지당 행 수 (1~100 사이로 clamp)
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage): LengthAwarePaginator;

    /**
     * 새 정책 버전 행을 생성합니다.
     *
     * version 컬럼은 호출자가 미리 계산하여 전달해야 합니다 (Service 에서 단조 증가 보장).
     *
     * @param array $data ['version', 'change_type', 'memo', 'snapshot', 'created_by']
     * @return GdprPolicyVersion
     */
    public function create(array $data): GdprPolicyVersion;
}
