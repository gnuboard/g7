<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Repositories\Concerns\CalculatesJsonContentDiff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class LayoutVersionRepository implements LayoutVersionRepositoryInterface
{
    use CalculatesJsonContentDiff;

    public function __construct(
        private TemplateLayoutVersion $model
    ) {}

    /**
     * 버전 저장 (자동 증가)
     *
     * 버전 1건은 저장 시점의 content 스냅샷(`$content`)을 담고, changes_summary 는
     * 직전 버전(`$previousContent`) 대비 그 스냅샷이 무엇이 바뀌었는지(추가/삭제/변경/문자수)를
     * 기록한다. 따라서 목록의 각 버전은 "이 버전이 직전 대비 어떻게 바뀌었나"를 정확히 표현한다.
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  array  $content  저장할 content 스냅샷 (이 버전이 담는 내용)
     * @param  array|null  $previousContent  직전 버전 content (변경 요약 기준). null 이면 변경 요약 0
     * @return TemplateLayoutVersion 생성된 버전
     */
    public function saveVersion(int $layoutId, array $content, ?array $previousContent = null): TemplateLayoutVersion
    {
        $nextVersion = $this->getNextVersion($layoutId);

        // changes_summary 계산 — 직전 버전 대비 이 스냅샷의 변경. 직전이 없으면(최초) 빈 요약.
        $changesSummary = $previousContent !== null
            ? $this->calculateChanges($previousContent, $content)
            : ['added' => 0, 'removed' => 0, 'char_diff' => 0];

        return $this->model->create([
            'layout_id' => $layoutId,
            'version' => $nextVersion,
            'content' => $content,
            'changes_summary' => $changesSummary,
        ]);
    }

    /**
     * 특정 레이아웃의 모든 버전 조회 (최신순)
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return Collection 버전 컬렉션
     */
    public function getVersions(int $layoutId): Collection
    {
        return $this->model
            ->where('layout_id', $layoutId)
            ->orderBy('version', 'desc')
            ->get();
    }

    /**
     * 특정 버전 조회
     *
     * @param  int  $versionId  버전 ID
     * @return TemplateLayoutVersion|null 찾은 버전 모델 또는 null
     */
    public function getVersion(int $versionId): ?TemplateLayoutVersion
    {
        return $this->model->find($versionId);
    }

    /**
     * 다음 버전 번호 계산
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return int 다음 버전 번호
     */
    public function getNextVersion(int $layoutId): int
    {
        $maxVersion = $this->model
            ->where('layout_id', $layoutId)
            ->max('version');

        return $maxVersion ? $maxVersion + 1 : 1;
    }

    /**
     * 버전 복원
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return TemplateLayoutVersion 복원 후 생성된 새 버전 모델
     *
     * @throws ModelNotFoundException 버전을 찾을 수 없는 경우
     */
    public function restoreVersion(int $layoutId, int $versionId): TemplateLayoutVersion
    {
        return DB::transaction(function () use ($layoutId, $versionId) {
            // 1. 복원할 버전 조회
            $versionToRestore = $this->model
                ->where('id', $versionId)
                ->where('layout_id', $layoutId)
                ->firstOrFail();

            // 2. 레이아웃 모델 조회 및 복원 직전 content 보관 (변경 요약 기준)
            $layout = TemplateLayout::findOrFail($layoutId);
            $currentContent = $layout->content;

            // 3. 레이아웃을 복원할 content로 업데이트
            $layout->update([
                'content' => $versionToRestore->content,
            ]);

            // 4. 복원 결과를 새 버전으로 저장 — content 는 복원된 내용(versionToRestore),
            //    changes_summary 는 복원 직전(currentContent) 대비 변경이다. 따라서 복원으로
            //    내용이 줄면 "삭제", 늘면 "추가"로 정확히 기록된다(종전엔 content 로 복원 직전
            //    상태를 저장하고 방향도 거꾸로라, 복원인데 "추가"로 표기되던 결함 수정).
            return $this->saveVersion($layoutId, $versionToRestore->content, $currentContent);
        });
    }

    /**
     * 템플릿의 레이아웃별 현재(최신) 버전 번호 맵 조회
     *
     * 레이아웃 편집기 라우트 트리의 버전 배지용 — 버전 이력이 있는
     * 레이아웃만 포함된다(미저장 레이아웃 = 원본 → 맵 제외 → 배지 미표시).
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @return array<string, int> 레이아웃 이름 → 최신 버전 번호
     */
    public function getCurrentVersionsByTemplateId(int $templateId): array
    {
        // 테이블 프리픽스 환경에서도 안전하도록 join/테이블 한정자 없이 2단계 조회 —
        // (1) 템플릿의 레이아웃 id→name 맵, (2) layout_id 별 MAX(version).
        $layoutNames = TemplateLayout::query()
            ->where('template_id', $templateId)
            ->pluck('name', 'id');

        if ($layoutNames->isEmpty()) {
            return [];
        }

        $maxVersions = $this->model->newQuery()
            ->whereIn('layout_id', $layoutNames->keys())
            ->groupBy('layout_id')
            ->selectRaw('layout_id, MAX(version) as current_version')
            ->pluck('current_version', 'layout_id');

        $result = [];
        foreach ($maxVersions as $layoutId => $version) {
            $name = $layoutNames[$layoutId] ?? null;
            if ($name !== null) {
                $result[$name] = (int) $version;
            }
        }

        return $result;
    }

    /**
     * 모든 버전의 changes_summary 를 현재 알고리즘으로 재계산하여 갱신합니다.
     *
     * @return int 갱신된 버전 수
     */
    public function recalculateAllChangeSummaries(): int
    {
        $updated = 0;

        // 레이아웃별로 버전을 오름차순 정렬해 인접 쌍으로 재계산.
        $layoutIds = $this->model->query()->distinct()->pluck('layout_id');

        foreach ($layoutIds as $layoutId) {
            $versions = $this->model
                ->where('layout_id', $layoutId)
                ->orderBy('version', 'asc')
                ->get();

            $previousContent = null;
            foreach ($versions as $version) {
                $summary = $previousContent !== null
                    ? $this->calculateChanges($previousContent, $version->content ?? [])
                    : ['added' => 0, 'removed' => 0, 'char_diff' => 0];

                $version->update(['changes_summary' => $summary]);
                $previousContent = $version->content ?? [];
                $updated++;
            }
        }

        return $updated;
    }
}
