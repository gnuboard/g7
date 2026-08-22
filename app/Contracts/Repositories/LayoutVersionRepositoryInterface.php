<?php

namespace App\Contracts\Repositories;

use App\Models\TemplateLayoutVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
     * @param  int  $layoutId  레이아웃 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return TemplateLayoutVersion 복원 후 생성된 새 버전 모델
     *
     * @throws ModelNotFoundException 버전을 찾을 수 없는 경우
     */
    public function restoreVersion(int $layoutId, int $versionId): TemplateLayoutVersion;

    /**
     * 템플릿의 레이아웃별 현재(최신) 버전 번호 맵 조회
     *
     * 레이아웃 편집기 좌측 라우트 트리의 버전 배지 데이터 소스로,
     * 해당 템플릿에 속한 레이아웃 중 버전 이력이 1건 이상인 레이아웃만 포함된다
     * (한 번도 저장된 적 없는 레이아웃 = 원본 상태 → 맵에 없음 → 배지 미표시).
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @return array<string, int> 레이아웃 이름 → 최신 버전 번호
     */
    public function getCurrentVersionsByTemplateId(int $templateId): array;

    /**
     * 모든 버전의 changes_summary 를 현재 알고리즘으로 재계산하여 갱신합니다.
     *
     * 측정 알고리즘 변경(키 경로 단위 → 라인 단위) 이전에 저장된 버전들은 옛 기준의
     * changes_summary 를 담고 있다. 각 레이아웃의 버전을 버전 순으로 정렬해 인접 쌍으로
     * 재계산하여 갱신한다(첫 버전은 baseline 으로 빈 요약).
     *
     * @return int 갱신된 버전 수
     */
    public function recalculateAllChangeSummaries(): int;
}
