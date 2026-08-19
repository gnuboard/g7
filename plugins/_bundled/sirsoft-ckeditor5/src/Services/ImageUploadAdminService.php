<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;

/**
 * CKEditor5 업로드 이미지 관리 화면 서비스
 *
 * 참조 여부는 컬럼에 저장하지 않고 조회할 때마다 판정합니다. 참조 관계는 콘텐츠가
 * 편집될 때마다 바뀌므로 스냅샷을 저장하면 "저장 당시엔 미참조였던" 이미지를 이후에
 * 오판 삭제할 수 있기 때문입니다. 대신 판정 비용에 상한을 둡니다.
 *
 *   - 기본 목록: 현재 페이지(≤100행)만 판정 — 페이지 크기가 곧 비용 상한
 *   - 참조 상태 필터: 최신순 스캔 윈도우(SCAN_WINDOW) 안에서만 판정하고, 상한에 걸린
 *     사실을 응답 메타(`scan_limited`)로 알려 화면이 "최근 N건 기준" 임을 표시하게 한다
 */
class ImageUploadAdminService
{
    /**
     * 참조 상태 필터를 적용할 때 훑는 최신 업로드 건수 상한
     */
    public const SCAN_WINDOW = 500;

    /**
     * @param  ImageUploadRepositoryInterface  $repository  업로드 리포지토리
     * @param  ImageReferenceScanService  $scanner  참조 판정 서비스
     * @param  ImageCleanupService  $cleanupService  삭제 서비스 (커맨드와 동일 경로 공유)
     */
    public function __construct(
        protected ImageUploadRepositoryInterface $repository,
        protected ImageReferenceScanService $scanner,
        protected ImageCleanupService $cleanupService,
    ) {}

    /**
     * 관리 화면 목록을 조회합니다.
     *
     * @param  array  $filters  필터 (search / referenced / date_from / date_to / sort_by / sort_order)
     * @param  int  $perPage  페이지 크기
     * @param  int  $page  페이지 번호
     * @return array{items: Collection<int, Ckeditor5ImageUpload>, pagination: array<string, mixed>, scan_limited: bool}
     */
    public function paginate(array $filters, int $perPage, int $page = 1): array
    {
        $referencedFilter = (string) ($filters['referenced'] ?? 'all');

        if ($referencedFilter === 'referenced' || $referencedFilter === 'unreferenced') {
            return $this->paginateByReferenceState($filters, $perPage, $page, $referencedFilter === 'referenced');
        }

        $paginator = $this->repository->paginateForAdmin($filters, $perPage);

        /** @var Collection<int, Ckeditor5ImageUpload> $items */
        $items = collect($paginator->items());
        $this->attachReferenceState($items);

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'scan_limited' => false,
            'reference_sources_incomplete' => false,
        ];
    }

    /**
     * 업로드 이미지 1건을 삭제합니다.
     *
     * @param  Ckeditor5ImageUpload  $upload  업로드 기록
     * @return bool 삭제 성공 여부 (파일 삭제 실패 시 false)
     */
    public function delete(Ckeditor5ImageUpload $upload): bool
    {
        return $this->cleanupService->deleteUpload($upload);
    }

    /**
     * 업로드 이미지를 일괄 삭제합니다.
     *
     * @param  array<int, int>  $ids  업로드 ID 목록
     * @return array{deleted: int, failed: int} 처리 결과
     */
    public function bulkDelete(array $ids): array
    {
        $uploads = $this->repository->findManyByIds($ids);

        $deleted = 0;
        $failed = 0;

        foreach ($uploads as $upload) {
            $this->cleanupService->deleteUpload($upload) ? $deleted++ : $failed++;
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * ID 로 업로드를 조회합니다.
     *
     * @param  int  $id  업로드 ID
     * @return Ckeditor5ImageUpload|null
     */
    public function find(int $id): ?Ckeditor5ImageUpload
    {
        return $this->repository->findById($id);
    }

    /**
     * 참조 상태 필터가 걸린 목록을 스캔 윈도우 안에서 페이지네이션합니다.
     *
     * @param  array  $filters  필터 배열
     * @param  int  $perPage  페이지 크기
     * @param  int  $page  페이지 번호
     * @param  bool  $wantReferenced  true 면 참조됨, false 면 미참조만
     * @return array{items: Collection<int, Ckeditor5ImageUpload>, pagination: array<string, mixed>, scan_limited: bool}
     */
    private function paginateByReferenceState(array $filters, int $perPage, int $page, bool $wantReferenced): array
    {
        $window = $this->repository->findScanWindow($filters, self::SCAN_WINDOW);
        $scanLimited = $window->count() >= self::SCAN_WINDOW;

        $this->attachReferenceState($window);

        $matched = $window
            ->filter(fn (Ckeditor5ImageUpload $upload) => (bool) $upload->referenced === $wantReferenced)
            ->values();

        // 스캔 윈도우는 최신순 고정으로 확보하지만, 화면이 요청한 정렬(검증 완료 값)은
        // 윈도우 안에서 그대로 적용한다 — 무시하면 "파일 크기순 + 미참조" 조합이
        // 오류 없이 최신순으로만 나와 정렬이 동작하는 것처럼 보인다.
        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDesc = strtolower((string) ($filters['sort_order'] ?? 'desc')) !== 'asc';
        $matched = $matched
            ->sortBy([[$sortBy, $sortDesc ? 'desc' : 'asc'], ['id', 'desc']])
            ->values();

        $total = $matched->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $currentPage = max(1, min($page, $lastPage));
        $items = $matched->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $items->isEmpty() ? null : ($currentPage - 1) * $perPage + 1,
                'to' => $items->isEmpty() ? null : ($currentPage - 1) * $perPage + $items->count(),
            ],
            'scan_limited' => $scanLimited,
            // 비활성 모듈이 있으면 그 모듈의 콘텐츠가 판정에서 빠져 "미참조" 가
            // 신뢰 불가다 — 화면이 경고를 표시할 수 있도록 메타로 알린다.
            'reference_sources_incomplete' => $this->scanner->hasPotentiallyMissingSources(),
        ];
    }

    /**
     * 업로드 목록에 참조 여부를 임시 속성으로 부착합니다.
     *
     * 저장 컬럼이 아니라 응답 전용 값이라 모델에 setAttribute 로만 실어 보냅니다
     * (저장 호출 없음).
     *
     * @param  Collection<int, Ckeditor5ImageUpload>  $uploads  업로드 목록
     */
    private function attachReferenceState(Collection $uploads): void
    {
        $map = $this->scanner->mapReferenced($uploads);

        foreach ($uploads as $upload) {
            $upload->setAttribute('referenced', $map[(int) $upload->id] ?? true);
        }
    }
}
