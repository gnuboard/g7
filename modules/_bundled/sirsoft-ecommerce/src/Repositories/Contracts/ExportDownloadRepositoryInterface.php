<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;

/**
 * 내보내기 다운로드 이력 Repository 인터페이스
 */
interface ExportDownloadRepositoryInterface
{
    /**
     * 다운로드 이력 1건을 남깁니다.
     *
     * @param  array<string, mixed>  $data  이력 속성
     * @return ExportDownload 생성된 이력
     */
    public function create(array $data): ExportDownload;

    /**
     * 작업 하나의 다운로드 이력을 최신순으로 조회합니다.
     *
     * @param  int  $exportId  작업 ID
     * @param  int  $perPage  쪽당 개수
     * @return LengthAwarePaginator 이력 목록
     */
    public function paginateForExport(int $exportId, int $perPage = 20): LengthAwarePaginator;
}
