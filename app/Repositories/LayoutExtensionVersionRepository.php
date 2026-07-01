<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutExtensionVersionRepositoryInterface;
use App\Models\LayoutExtension;
use App\Models\TemplateLayoutExtensionVersion;
use App\Repositories\Concerns\CalculatesJsonContentDiff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class LayoutExtensionVersionRepository implements LayoutExtensionVersionRepositoryInterface
{
    use CalculatesJsonContentDiff;

    public function __construct(
        private TemplateLayoutExtensionVersion $model
    ) {}

    /**
     * 버전 저장 (자동 증가)
     *
     * 버전 1건은 저장 시점의 content 스냅샷(`$content`)을 담고, changes_summary 는 직전
     * 버전(`$previousContent`) 대비 변경(추가/삭제/변경/문자수)을 기록한다. (레이아웃 본체와
     * 동일 정책 — 종전 2건 저장 + 자기 비교로 최신 버전 changes_summary 가 0 이던 결함 수정.)
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  array  $content  저장할 content 스냅샷
     * @param  array|null  $previousContent  직전 버전 content (변경 요약 기준). null 이면 변경 요약 0
     * @return TemplateLayoutExtensionVersion 생성된 버전
     */
    public function saveVersion(int $extensionId, array $content, ?array $previousContent = null): TemplateLayoutExtensionVersion
    {
        $nextVersion = $this->getNextVersion($extensionId);

        // changes_summary 계산 — 직전 버전 대비 이 스냅샷의 변경. 직전이 없으면(최초) 빈 요약.
        $changesSummary = $previousContent !== null
            ? $this->calculateChanges($previousContent, $content)
            : ['added' => 0, 'removed' => 0, 'char_diff' => 0];

        return $this->model->create([
            'extension_id' => $extensionId,
            'version' => $nextVersion,
            'content' => $content,
            'changes_summary' => $changesSummary,
        ]);
    }

    /**
     * 특정 확장의 모든 버전 조회 (최신순)
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @return Collection 버전 컬렉션
     */
    public function getVersions(int $extensionId): Collection
    {
        // creator eager load — 버전 목록에 저장자 이름(created_by_name) 노출용 (N+1 회피,
        // 레이아웃 본체 getVersionsByLayoutId 와 동일 정책).
        return $this->model
            ->with('creator:id,name')
            ->where('extension_id', $extensionId)
            ->orderBy('version', 'desc')
            ->get();
    }

    /**
     * 확장 ID 목록의 현재(최신) 버전 번호 맵 조회
     *
     * 레이아웃 편집기 라우트 트리의 확장 노드 버전 배지용 —
     * 버전 이력이 있는 확장만 포함된다(미저장 확장 = 원본 → 맵 제외 → 배지 미표시).
     *
     * @param  array<int>  $extensionIds  확장 ID 목록
     * @return array<int, int> 확장 ID → 최신 버전 번호
     */
    public function getCurrentVersionsByExtensionIds(array $extensionIds): array
    {
        if ($extensionIds === []) {
            return [];
        }

        return $this->model->newQuery()
            ->whereIn('extension_id', $extensionIds)
            ->groupBy('extension_id')
            ->selectRaw('extension_id, MAX(version) as current_version')
            ->pluck('current_version', 'extension_id')
            ->map(fn ($version) => (int) $version)
            ->all();
    }

    /**
     * 특정 버전 조회
     *
     * @param  int  $versionId  버전 ID
     * @return TemplateLayoutExtensionVersion|null 찾은 버전 모델 또는 null
     */
    public function getVersion(int $versionId): ?TemplateLayoutExtensionVersion
    {
        return $this->model->find($versionId);
    }

    /**
     * 다음 버전 번호 계산
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @return int 다음 버전 번호
     */
    public function getNextVersion(int $extensionId): int
    {
        $maxVersion = $this->model
            ->where('extension_id', $extensionId)
            ->max('version');

        return $maxVersion ? $maxVersion + 1 : 1;
    }

    /**
     * 버전 복원
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return TemplateLayoutExtensionVersion 복원 후 생성된 새 버전
     *
     * @throws ModelNotFoundException 버전을 찾을 수 없는 경우
     */
    public function restoreVersion(int $extensionId, int $versionId): TemplateLayoutExtensionVersion
    {
        return DB::transaction(function () use ($extensionId, $versionId) {
            // 1. 복원할 버전 조회
            $versionToRestore = $this->model
                ->where('id', $versionId)
                ->where('extension_id', $extensionId)
                ->firstOrFail();

            // 2. 확장 모델 조회 및 복원 직전 content 보관 (변경 요약 기준)
            $extension = LayoutExtension::findOrFail($extensionId);
            $currentContent = $extension->content;

            // 3. 확장을 복원할 content로 업데이트
            $extension->update([
                'content' => $versionToRestore->content,
            ]);

            // 4. 복원 결과를 새 버전으로 저장 — content 는 복원된 내용, changes_summary 는 복원
            //    직전(currentContent) 대비 변경(레이아웃 본체와 동일 — 복원으로 줄면 삭제, 늘면 추가).
            return $this->saveVersion($extensionId, $versionToRestore->content, $currentContent);
        });
    }
}
