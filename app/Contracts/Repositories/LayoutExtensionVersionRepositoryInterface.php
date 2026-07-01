<?php

namespace App\Contracts\Repositories;

use App\Models\TemplateLayoutExtensionVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface LayoutExtensionVersionRepositoryInterface
{
    /**
     * 버전 저장 (자동 증가)
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  array  $oldContent  이전 콘텐츠
     * @param  array|null  $newContent  새 콘텐츠 (null이면 현재 확장 content 사용)
     * @return TemplateLayoutExtensionVersion 생성된 버전 모델
     */
    public function saveVersion(int $extensionId, array $oldContent, ?array $newContent = null): TemplateLayoutExtensionVersion;

    /**
     * 특정 확장의 모든 버전 조회 (최신순)
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @return Collection 버전 컬렉션
     */
    public function getVersions(int $extensionId): Collection;

    /**
     * 확장 ID 목록의 현재(최신) 버전 번호 맵 조회
     *
     * 레이아웃 편집기 라우트 트리의 확장 노드 버전 배지 데이터
     * 소스로, 버전 이력이 1건 이상인 확장만 포함된다(미저장 확장 = 원본 → 맵 제외).
     *
     * @param  array<int>  $extensionIds  확장 ID 목록
     * @return array<int, int> 확장 ID → 최신 버전 번호
     */
    public function getCurrentVersionsByExtensionIds(array $extensionIds): array;

    /**
     * 특정 버전 조회
     *
     * @param  int  $versionId  버전 ID
     * @return TemplateLayoutExtensionVersion|null 찾은 버전 모델 또는 null
     */
    public function getVersion(int $versionId): ?TemplateLayoutExtensionVersion;

    /**
     * 다음 버전 번호 계산
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @return int 다음 버전 번호
     */
    public function getNextVersion(int $extensionId): int;

    /**
     * JSON content 변경사항 카운트 계산 (라인 단위)
     *
     * @param  array  $oldContent  이전 콘텐츠
     * @param  array  $newContent  새 콘텐츠
     * @return array{added: int, removed: int, char_diff: int} 추가/삭제 라인 수 + 문자 수 변화
     */
    public function calculateChanges(array $oldContent, array $newContent): array;

    /**
     * 버전 복원
     *
     * @param  int  $extensionId  레이아웃 확장 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return TemplateLayoutExtensionVersion 복원 후 생성된 새 버전 모델
     *
     * @throws ModelNotFoundException 버전을 찾을 수 없는 경우
     */
    public function restoreVersion(int $extensionId, int $versionId): TemplateLayoutExtensionVersion;
}
