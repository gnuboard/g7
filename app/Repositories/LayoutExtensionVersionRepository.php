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
     * 특정 확장의 최근 버전 목록 조회 (최신순)
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  int  $limit  조회할 최대 버전 수 (버전 행은 정리되지 않고 쌓이므로 상한 필수)
     * @return Collection 버전 컬렉션
     */
    public function getVersions(int $extensionId, int $limit = 100): Collection
    {
        // creator eager load — 버전 목록에 저장자 이름(created_by_name) 노출용 (N+1 회피,
        // 레이아웃 본체 getVersionsByLayoutId 와 동일 정책).
        //
        // 버전 행은 저장할 때마다 쌓이고 정리되지 않으므로 조회 건수에 상한을 둔다. 목록이 쓰지
        // 않는 `content`(버전마다 확장 본문 사본)도 조회하지 않는다 — 본문은 버전 비교 diff
        // 전용이며 단건 조회가 공급한다. (레이아웃 본체와 동일 정책)
        return $this->model
            ->with('creator:id,name')
            ->where('extension_id', $extensionId)
            ->orderBy('version', 'desc')
            ->limit($limit)
            ->get(['id', 'extension_id', 'version', 'changes_summary', 'created_by', 'created_at']);
    }

    /**
     * 확장의 특정 버전 번호 조회 (본문 포함)
     *
     * 목록(getVersions)에서 뺀 `content` 의 대체 경로다. 목록 조회를 재사용하면 두 가지가
     * 동시에 깨진다 — ① 목록이 조회하지 않는 `content` 가 빈 값이 되어 버전 비교 diff 가
     * "전 항목 삭제"로 표시되고 ② 목록 상한(기본 100건) 밖의 오래된 버전이 404 가 된다.
     * (레이아웃 본체 LayoutRepository::findVersionByNumber 와 동일 정책)
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  int  $version  버전 번호
     * @return TemplateLayoutExtensionVersion|null 찾은 버전 모델 또는 null
     */
    public function findVersionByNumber(int $extensionId, int $version): ?TemplateLayoutExtensionVersion
    {
        // creator eager load — 단건 버전 조회도 저장자 이름을 목록과 일관 노출.
        return $this->model
            ->newQuery()
            ->with('creator:id,name')
            ->where('extension_id', $extensionId)
            ->where('version', $version)
            ->first();
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
