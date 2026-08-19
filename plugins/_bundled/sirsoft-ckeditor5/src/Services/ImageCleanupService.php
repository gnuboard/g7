<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;

/**
 * CKEditor5 미참조 업로드 이미지 정리 서비스
 *
 * 관리 화면의 수동 삭제와 정리 커맨드가 같은 삭제 경로(deleteUpload)를 공유합니다.
 *
 * 삭제 순서는 "물리 파일 먼저, 레코드 나중" 입니다. 반대로 하면 레코드가 사라진 파일은
 * 어떤 경로로도 다시 찾을 수 없어 영구 고아가 됩니다. 파일이 이미 없는 행은 고아 레코드로
 * 보고 레코드만 지웁니다 — 그러지 않으면 매 회차 같은 행을 재시도하는 루프가 됩니다.
 */
class ImageCleanupService
{
    /**
     * @param  ImageUploadRepositoryInterface  $repository  업로드 리포지토리
     * @param  ImageReferenceScanService  $scanner  참조 판정 서비스
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버
     */
    public function __construct(
        protected ImageUploadRepositoryInterface $repository,
        protected ImageReferenceScanService $scanner,
        protected StorageInterface $storage,
    ) {}

    /**
     * 보존기간이 지난 미참조 이미지를 정리합니다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  한 회차에 스캔할 최대 후보 수
     * @param  bool  $dryRun  true 면 판정만 하고 삭제하지 않음
     * @return array{scanned: int, referenced: int, deleted: int, failed: int, items: array<int, array<string, mixed>>, skipped_reason?: string}
     */
    public function pruneUnused(int $days, int $limit, bool $dryRun = false): array
    {
        // 비활성 모듈이 있으면 그 모듈이 등록했을 참조 소스가 이번 판정에 빠져 있을 수
        // 있다 — "미참조" 판정을 신뢰할 수 없으므로 실삭제 회차를 통째로 건너뛴다.
        // (dry-run 은 삭제가 없으므로 판정 미리보기 용도로 계속 허용한다.)
        if (! $dryRun && $this->scanner->hasPotentiallyMissingSources()) {
            Log::warning('CKEditor5 미참조 정리 건너뜀 — 비활성 모듈이 있어 참조 소스가 불완전할 수 있습니다.');

            return [
                'scanned' => 0,
                'referenced' => 0,
                'deleted' => 0,
                'failed' => 0,
                'items' => [],
                'skipped_reason' => 'sources_incomplete',
            ];
        }

        $threshold = Carbon::now()->subDays($days);
        $candidates = $this->repository->findOlderThan($threshold, $limit);

        $result = [
            'scanned' => $candidates->count(),
            'referenced' => 0,
            'deleted' => 0,
            'failed' => 0,
            'items' => [],
        ];

        // 행별 isReferenced 반복 대신 일괄 판정 — 소스당 1회 순회로 비용을 고정한다.
        $referencedMap = $this->scanner->mapReferenced($candidates);

        foreach ($candidates as $upload) {
            // 판정 누락 행은 안전 방향(참조됨)으로 본다.
            if ($referencedMap[(int) $upload->id] ?? true) {
                $result['referenced']++;

                continue;
            }

            if ($dryRun) {
                $result['items'][] = $this->describe($upload, 'would_delete');

                continue;
            }

            if ($this->deleteUpload($upload)) {
                $result['deleted']++;
                $result['items'][] = $this->describe($upload, 'deleted');

                continue;
            }

            $result['failed']++;
            $result['items'][] = $this->describe($upload, 'failed');
        }

        return $result;
    }

    /**
     * 업로드 이미지 1건의 파일과 레코드를 삭제합니다.
     *
     * @param  Ckeditor5ImageUpload  $upload  업로드 기록
     * @return bool 레코드까지 삭제되면 true, 파일 삭제 실패로 보류되면 false
     */
    public function deleteUpload(Ckeditor5ImageUpload $upload): bool
    {
        [$category, $relativePath] = $this->splitFilePath((string) ($upload->file_path ?? ''));

        if ($category === null || $relativePath === null) {
            // 경로를 분해할 수 없는 행은 가리키는 파일이 없다 — 레코드만 정리한다.
            Log::warning('CKEditor5 업로드 경로를 분해할 수 없어 레코드만 삭제합니다.', [
                'id' => $upload->id,
                'file_path' => $upload->file_path,
            ]);

            return $this->repository->delete($upload);
        }

        // 행 disk 가 더 이상 등록돼 있지 않으면(공급 플러그인 비활성 등) withDisk 가
        // InvalidArgumentException 을 던져 삭제/일괄삭제/prune 루프 전체가 500 으로
        // 중단된다 — ImageServeService::serve 와 동일하게 존재 검증 후 폴백한다.
        $disk = (string) ($upload->storage_disk ?? '');
        $useRowDisk = $disk !== ''
            && $disk !== $this->storage->getDisk()
            && config("filesystems.disks.{$disk}") !== null;

        if ($disk !== '' && ! $useRowDisk && $disk !== $this->storage->getDisk()) {
            Log::warning('CKEditor5 업로드의 저장 disk 가 미등록 상태 — 기본 스토리지로 폴백해 정리를 시도합니다.', [
                'id' => $upload->id,
                'disk' => $disk,
            ]);
        }

        $storage = $useRowDisk ? $this->storage->withDisk($disk) : $this->storage;

        if (! $storage->exists($category, $relativePath)) {
            // 파일이 이미 없는 고아 레코드 — 재시도 루프를 만들지 않도록 레코드를 정리한다.
            return $this->repository->delete($upload);
        }

        if (! $storage->delete($category, $relativePath)) {
            Log::warning('CKEditor5 업로드 파일 삭제에 실패해 레코드를 보존합니다.', [
                'id' => $upload->id,
                'disk' => $disk,
                'file_path' => $upload->file_path,
            ]);

            return false;
        }

        return $this->repository->delete($upload);
    }

    /**
     * 파일 경로를 카테고리와 상대 경로로 분해합니다.
     *
     * `file_path` 는 카테고리 prefix 를 포함합니다(`images/Y/m/d/{uuid}.{ext}`) —
     * 모델의 `resolveDirectUrl()` 과 동일한 분해 규칙을 씁니다.
     *
     * @param  string  $filePath  저장 파일 경로
     * @return array{0: string|null, 1: string|null} [카테고리, 상대 경로]
     */
    private function splitFilePath(string $filePath): array
    {
        if ($filePath === '' || ! str_contains($filePath, '/')) {
            return [null, null];
        }

        [$category, $relativePath] = explode('/', $filePath, 2);

        if ($category === '' || $relativePath === '') {
            return [null, null];
        }

        return [$category, $relativePath];
    }

    /**
     * 커맨드 출력·로그용 항목 요약을 만듭니다.
     *
     * @param  Ckeditor5ImageUpload  $upload  업로드 기록
     * @param  string  $status  처리 상태 (would_delete|deleted|failed)
     * @return array<string, mixed> 요약 배열
     */
    private function describe(Ckeditor5ImageUpload $upload, string $status): array
    {
        return [
            'id' => (int) $upload->id,
            'hash' => (string) $upload->hash,
            'original_name' => (string) $upload->original_name,
            'file_size' => (int) $upload->file_size,
            'created_at' => $upload->created_at?->toDateTimeString(),
            'status' => $status,
        ];
    }
}
