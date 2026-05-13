<?php

namespace App\Contracts\Repositories;

use App\Models\TemplateLayoutVersion;
use Illuminate\Database\Eloquent\Collection;

interface LayoutVersionRepositoryInterface
{
    /**
     * 버전 저장 (자동 증가)
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  array  $oldContent  이전 콘텐츠
     * @param  array|null  $newContent  새 콘텐츠 (null이면 현재 레이아웃 content 사용)
     * @return TemplateLayoutVersion 생성된 버전 모델
     */
    public function saveVersion(int $layoutId, array $oldContent, ?array $newContent = null): TemplateLayoutVersion;

    /**
     * 특정 레이아웃의 모든 버전 조회 (최신순)
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return Collection 버전 컬렉션
     */
    public function getVersions(int $layoutId): Collection;

    /**
     * 특정 버전 조회
     *
     * @param  int  $versionId  버전 ID
     * @return TemplateLayoutVersion|null 찾은 버전 모델 또는 null
     */
    public function getVersion(int $versionId): ?TemplateLayoutVersion;

    /**
     * 다음 버전 번호 계산
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return int 다음 버전 번호
     */
    public function getNextVersion(int $layoutId): int;

    /**
     * JSON content 변경사항 계산
     *
     * @param  array  $oldContent  이전 콘텐츠
     * @param  array  $newContent  새 콘텐츠
     * @return array 변경사항 (added, removed, modified)
     */
    public function calculateChanges(array $oldContent, array $newContent): array;

    /**
     * 버전 복원
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return TemplateLayoutVersion 복원 후 생성된 새 버전 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 버전을 찾을 수 없는 경우
     */
    public function restoreVersion(int $layoutId, int $versionId): TemplateLayoutVersion;
}