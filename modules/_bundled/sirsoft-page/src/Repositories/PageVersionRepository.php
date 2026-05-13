<?php

namespace Modules\Sirsoft\Page\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Repositories\Contracts\PageVersionRepositoryInterface;

/**
 * 페이지 버전 Repository
 *
 * 페이지 버전 데이터 접근 계층을 담당합니다.
 */
class PageVersionRepository implements PageVersionRepositoryInterface
{
    /**
     * 페이지 버전을 생성합니다.
     *
     * @param  array  $data  버전 생성 데이터
     * @return PageVersion 생성된 버전 모델
     */
    public function create(array $data): PageVersion
    {
        return PageVersion::create($data);
    }

    /**
     * 페이지의 버전 이력을 조회합니다.
     *
     * @param  Page  $page  페이지 모델
     * @return Collection 버전 목록 (최신순)
     */
    public function getVersionsByPage(Page $page): Collection
    {
        return $page->versions()
            ->with('creator')
            ->orderByDesc('version')
            ->get();
    }

    /**
     * 특정 버전 번호로 페이지 버전을 조회합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  int  $version  버전 번호
     * @return PageVersion|null 버전 모델 또는 null
     */
    public function findByPageAndVersion(Page $page, int $version): ?PageVersion
    {
        return $page->versions()
            ->where('version', $version)
            ->first();
    }

    /**
     * ID로 페이지 버전을 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  버전 ID
     * @return PageVersion 버전 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): PageVersion
    {
        return PageVersion::findOrFail($id);
    }
}
