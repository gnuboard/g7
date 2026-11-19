<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExportDownloadRepositoryInterface;

/**
 * 내보내기 다운로드 이력 Repository
 */
class ExportDownloadRepository implements ExportDownloadRepositoryInterface
{
    /**
     * @param  ExportDownload  $model  다운로드 이력 모델
     */
    public function __construct(
        protected ExportDownload $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ExportDownload
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForExport(int $exportId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->with('user')
            ->where('export_id', $exportId)
            ->orderByDesc('downloaded_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'export_id', 'user_id', 'user_name_snapshot', 'ip_address', 'downloaded_at']);
    }
}
