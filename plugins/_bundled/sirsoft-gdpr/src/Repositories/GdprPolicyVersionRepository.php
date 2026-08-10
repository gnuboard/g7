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
            // 전순서 보장 — version 이 유니크하더라도 정렬 계약을 키로 닫아 둔다
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 특정 version 정수에 해당하는 정책 버전 1건을 반환합니다.
     *
     * createdBy 관계를 eager load 하여 admin snapshot 모달의 발행자 표시에 활용.
     *
     * @param  int  $version  조회할 정책 버전 정수
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
     * @param  int  $perPage  페이지당 행 수 (1~100 사이로 clamp)
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return GdprPolicyVersion::query()
            ->with('createdBy')
            ->orderByDesc('version')
            // 전순서 보장 — version 이 유니크하더라도 정렬 계약을 키로 닫아 둔다
            ->orderByDesc('id')
            // audit:allow repository-paginate-column-pruning reason: 발행 이력은 정책 변경 시에만
            // 늘어나는 정의성 테이블이라 깊은 OFFSET 이 성립하지 않는다. 넓은 컬럼(snapshot JSON)은
            // 아래 컬럼 목록에서 제외했고, 본문은 상세 조회(getByVersion)에서만 읽는다.
            ->paginate(
                max(1, min(100, $perPage)),
                ['id', 'version', 'change_type', 'memo', 'created_by', 'created_at'],
            );
    }

    /**
     * 새 정책 버전 행을 생성합니다.
     *
     * @param  array  $data  ['version', 'change_type', 'memo', 'snapshot', 'created_by']
     * @return GdprPolicyVersion
     */
    public function create(array $data): GdprPolicyVersion
    {
        return GdprPolicyVersion::create($data);
    }
}
