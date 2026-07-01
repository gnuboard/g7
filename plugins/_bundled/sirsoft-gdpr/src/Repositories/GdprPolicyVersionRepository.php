<?php

namespace Plugins\Sirsoft\Gdpr\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPolicyVersionRepositoryInterface;

/**
 * GDPR 정책 버전 Repository 구현체 (immutable append-only)
 */
class GdprPolicyVersionRepository implements GdprPolicyVersionRepositoryInterface
{
    /**
     * 최신 (현재) 정책 버전 1건을 반환합니다.
     *
     * @return GdprPolicyVersion|null
     */
    public function getCurrent(): ?GdprPolicyVersion
    {
        return GdprPolicyVersion::query()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * 특정 version 정수에 해당하는 정책 버전 1건을 반환합니다.
     *
     * createdBy 관계를 eager load 하여 admin snapshot 모달의 발행자 표시에 활용.
     *
     * @param int $version 조회할 정책 버전 정수
     * @return GdprPolicyVersion|null 해당 버전 row 가 없으면 null
     */
    public function getByVersion(int $version): ?GdprPolicyVersion
    {
        return GdprPolicyVersion::query()
            ->with('createdBy')
            ->where('version', $version)
            ->first();
    }

    /**
     * 현재 정책 버전 정수 값을 반환합니다.
     *
     * @return int 발행된 버전이 하나도 없으면 0
     */
    public function getCurrentVersion(): int
    {
        $current = $this->getCurrent();

        return $current?->version ?? 0;
    }

    /**
     * 관리자 화면용 페이지네이션 조회 (version DESC).
     *
     * @param int $perPage 페이지당 행 수 (1~100 사이로 clamp)
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return GdprPolicyVersion::query()
            ->with('createdBy')
            ->orderByDesc('version')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * 새 정책 버전 행을 생성합니다.
     *
     * @param array $data ['version', 'change_type', 'memo', 'snapshot', 'created_by']
     * @return GdprPolicyVersion
     */
    public function create(array $data): GdprPolicyVersion
    {
        return GdprPolicyVersion::create($data);
    }
}
