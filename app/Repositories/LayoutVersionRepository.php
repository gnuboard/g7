<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class LayoutVersionRepository implements LayoutVersionRepositoryInterface
{
    public function __construct(
        private TemplateLayoutVersion $model
    ) {}

    /**
     * 버전 저장 (자동 증가)
     *
     * @param int $layoutId 레이아웃 ID
     * @param array $oldContent 이전 콘텐츠
     * @param array|null $newContent 새 콘텐츠 (null이면 현재 레이아웃에서 가져옴)
     * @return TemplateLayoutVersion 생성된 버전
     */
    public function saveVersion(int $layoutId, array $oldContent, ?array $newContent = null): TemplateLayoutVersion
    {
        $nextVersion = $this->getNextVersion($layoutId);

        // newContent가 제공되지 않으면 현재 레이아웃 content를 사용
        if ($newContent === null) {
            $layout = TemplateLayout::findOrFail($layoutId);
            $newContent = $layout->content;
        }

        // changes_summary 계산
        $changesSummary = $this->calculateChanges($oldContent, $newContent);

        return $this->model->create([
            'layout_id' => $layoutId,
            'version' => $nextVersion,
            'content' => $oldContent,
            'changes_summary' => $changesSummary,
        ]);
    }

    /**
     * 특정 레이아웃의 모든 버전 조회 (최신순)
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
     */
    public function getVersion(int $versionId): ?TemplateLayoutVersion
    {
        return $this->model->find($versionId);
    }

    /**
     * 다음 버전 번호 계산
     */
    public function getNextVersion(int $layoutId): int
    {
        $maxVersion = $this->model
            ->where('layout_id', $layoutId)
            ->max('version');

        return $maxVersion ? $maxVersion + 1 : 1;
    }

    /**
     * JSON content 변경사항 계산
     */
    public function calculateChanges(array $oldContent, array $newContent): array
    {
        $added = [];
        $removed = [];
        $modified = [];

        // 재귀적으로 비교
        $this->diffRecursive($oldContent, $newContent, '', $added, $removed, $modified);

        // 문자 수 변화 계산
        $oldChars = strlen(json_encode($oldContent, JSON_UNESCAPED_UNICODE));
        $newChars = strlen(json_encode($newContent, JSON_UNESCAPED_UNICODE));
        $charDiff = $newChars - $oldChars;

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'char_diff' => $charDiff,
        ];
    }

    /**
     * 재귀적 JSON diff 알고리즘
     */
    private function diffRecursive(
        array $old,
        array $new,
        string $prefix,
        array &$added,
        array &$removed,
        array &$modified
    ): void {
        // 삭제된 키 찾기
        foreach ($old as $key => $value) {
            $path = $prefix ? "{$prefix}.{$key}" : $key;

            if (!array_key_exists($key, $new)) {
                $removed[] = $path;
                continue;
            }

            // 값 비교
            $oldValue = $old[$key];
            $newValue = $new[$key];

            // 둘 다 배열이면 재귀 호출
            if (is_array($oldValue) && is_array($newValue)) {
                $this->diffRecursive($oldValue, $newValue, $path, $added, $removed, $modified);
            } elseif ($oldValue !== $newValue) {
                // 값이 변경됨
                $modified[] = $path;
            }
        }

        // 추가된 키 찾기
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old)) {
                $path = $prefix ? "{$prefix}.{$key}" : $key;
                $added[] = $path;

                // 추가된 항목의 하위 구조도 added로 포함
                if (is_array($value)) {
                    $this->collectAddedPaths($value, $path, $added);
                }
            }
        }
    }

    /**
     * 추가된 경로의 모든 하위 경로 수집
     */
    private function collectAddedPaths(array $data, string $prefix, array &$added): void
    {
        foreach ($data as $key => $value) {
            $path = "{$prefix}.{$key}";
            $added[] = $path;

            if (is_array($value)) {
                $this->collectAddedPaths($value, $path, $added);
            }
        }
    }

    /**
     * 버전 복원
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

            // 2. 레이아웃 모델 조회 및 현재 content 저장
            $layout = TemplateLayout::findOrFail($layoutId);
            $currentContent = $layout->content;

            // 3. 레이아웃을 복원할 content로 업데이트
            $layout->update([
                'content' => $versionToRestore->content,
            ]);

            // 4. 복원 전 content를 새 버전으로 저장 (이력 보존)
            return $this->saveVersion($layoutId, $currentContent, $versionToRestore->content);
        });
    }
}
