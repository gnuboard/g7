<?php

namespace App\Contracts\Repositories;

use App\Models\TemplateCustomTranslation;
use Illuminate\Database\Eloquent\Collection;

interface TemplateCustomTranslationRepositoryInterface
{
    /**
     * 특정 템플릿의 커스텀 다국어 키 목록 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $layoutName  레이아웃 이름 필터 (null 이면 전체)
     * @param  string|null  $status  상태 필터 (null 이면 전체)
     * @return Collection<int, TemplateCustomTranslation> 커스텀 키 컬렉션
     */
    public function getByTemplateId(int $templateId, ?string $layoutName = null, ?string $status = null): Collection;

    /**
     * 특정 템플릿의 활성 커스텀 다국어 키 조회 (런타임 병합용)
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection<int, TemplateCustomTranslation> 활성 커스텀 키 컬렉션
     */
    public function getActiveByTemplateId(int $templateId): Collection;

    /**
     * 특정 템플릿 + 레이아웃의 커스텀 키 조회 (좀비 감지용)
     *
     * `layout_name` 으로 격리해, 해당 레이아웃 저장 시 그 레이아웃 출처 키만
     * 좀비 후보로 검사합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름 (원본, 예: board/list)
     * @param  string|null  $status  상태 필터 (null 이면 전체)
     * @return Collection<int, TemplateCustomTranslation> 커스텀 키 컬렉션
     */
    public function getByTemplateIdAndLayout(int $templateId, string $layoutName, ?string $status = null): Collection;

    /**
     * 주어진 ID 목록의 상태를 일괄 변경합니다 (active ↔ orphaned 전이).
     *
     * @param  array<int, int>  $ids  대상 커스텀 키 ID 목록
     * @param  string  $status  새 상태 (active|orphaned)
     * @return int 변경된 행 수
     */
    public function updateStatus(array $ids, string $status): int;

    /**
     * ID로 커스텀 다국어 키 조회
     *
     * @param  int  $id  커스텀 키 ID
     * @return TemplateCustomTranslation|null 찾은 모델 또는 null
     */
    public function findById(int $id): ?TemplateCustomTranslation;

    /**
     * 템플릿 + 키로 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $translationKey  다국어 키
     * @return TemplateCustomTranslation|null 찾은 모델 또는 null
     */
    public function findByKey(int $templateId, string $translationKey): ?TemplateCustomTranslation;

    /**
     * 특정 템플릿 + 레이아웃의 최대 seq 조회
     *
     * `custom.{layout}.{seq}` 형식 키에서 seq 의 현재 최대값을 반환합니다.
     * 신규 키 생성 시 다음 seq 계산에 사용됩니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutKey  키 네임스페이스 (레이아웃명 정규화)
     * @return int 현재 최대 seq (없으면 0)
     */
    public function getMaxSeq(int $templateId, string $layoutKey): int;

    /**
     * 커스텀 다국어 키 생성
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return TemplateCustomTranslation 생성된 모델
     */
    public function create(array $data): TemplateCustomTranslation;

    /**
     * 커스텀 다국어 키 값 수정 (lock_version 갱신 포함)
     *
     * @param  int  $id  커스텀 키 ID
     * @param  array<string, mixed>  $data  수정 데이터
     * @param  int  $newLockVersion  새 잠금 버전
     * @return TemplateCustomTranslation 수정된 모델
     */
    public function update(int $id, array $data, int $newLockVersion): TemplateCustomTranslation;

    /**
     * 커스텀 다국어 키 삭제
     *
     * @param  int  $id  커스텀 키 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;
}
