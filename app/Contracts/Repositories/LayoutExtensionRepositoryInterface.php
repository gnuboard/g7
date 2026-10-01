<?php

namespace App\Contracts\Repositories;

use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use Illuminate\Support\Collection;

/**
 * 레이아웃 확장 리포지토리 인터페이스
 */
interface LayoutExtensionRepositoryInterface
{
    /**
     * 특정 확장점에 등록된 확장 목록 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getByExtensionPoint(int $templateId, string $extensionPointName): Collection;

    /**
     * 특정 레이아웃을 타겟으로 하는 오버레이 목록 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getOverlaysByLayout(int $templateId, string $layoutName): Collection;

    /**
     * 확장 등록
     *
     * @param  array  $data  확장 데이터
     * @return LayoutExtension 생성된 확장 모델
     */
    public function create(array $data): LayoutExtension;

    /**
     * 확장 등록 또는 업데이트 (upsert)
     *
     * 동일한 조건의 확장이 존재하면 업데이트하고, 없으면 생성합니다.
     *
     * @param  array  $attributes  조회 조건 (template_id, extension_type, target_name, source_type, source_identifier)
     * @param  array  $values  생성/업데이트할 값
     * @return LayoutExtension 생성되거나 갱신된 확장 모델
     */
    public function updateOrCreate(array $attributes, array $values): LayoutExtension;

    /**
     * 출처별 확장 삭제 (soft delete)
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function softDeleteBySource(LayoutSourceType $sourceType, string $identifier): int;

    /**
     * 출처별 확장 복원
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  array<int, string>|null  $fragmentKeys  이 조각 키의 행만 복원 (null 이면 전부)
     * @return int 복원된 레코드 수
     */
    public function restoreBySource(LayoutSourceType $sourceType, string $identifier, ?array $fragmentKeys = null): int;

    /**
     * 출처별 확장 영구 삭제
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function forceDeleteBySource(LayoutSourceType $sourceType, string $identifier): int;

    /**
     * 템플릿 오버라이드 확인 (Extension Point용)
     *
     * 특정 extension_point에 대해 템플릿이 오버라이드를 정의했는지 확인합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @param  string  $moduleIdentifier  모듈/플러그인 식별자
     * @return LayoutExtension|null 템플릿 오버라이드 또는 null
     */
    public function findTemplateOverrideForExtensionPoint(
        int $templateId,
        string $extensionPointName,
        string $moduleIdentifier
    ): ?LayoutExtension;

    /**
     * 템플릿 오버라이드 확인 (Overlay용)
     *
     * 특정 target_layout에 대해 템플릿이 오버라이드를 정의했는지 확인합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @param  string  $moduleIdentifier  모듈/플러그인 식별자
     * @return LayoutExtension|null 템플릿 오버라이드 또는 null
     */
    public function findTemplateOverrideForOverlay(
        int $templateId,
        string $layoutName,
        string $moduleIdentifier
    ): ?LayoutExtension;

    /**
     * 오버라이드를 고려한 Extension Point 조회
     *
     * 템플릿 오버라이드가 있는 모듈 확장은 제외하고,
     * 오버라이드된 버전과 원본 모듈 확장을 함께 반환합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedExtensionPoints(int $templateId, string $extensionPointName): Collection;

    /**
     * 오버라이드를 고려한 Overlay 조회
     *
     * 템플릿 오버라이드가 있는 모듈 확장은 제외하고,
     * 오버라이드된 버전과 원본 모듈 확장을 함께 반환합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedOverlays(int $templateId, string $layoutName): Collection;

    /**
     * 특정 템플릿의 모든 확장 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection<int, LayoutExtension>
     */
    public function getByTemplateId(int $templateId): Collection;

    /**
     * 특정 템플릿의 확장을 오버라이드 해석을 적용해 조회
     *
     * 템플릿 오버라이드에 가려져 화면에 적용되지 않는 모듈/플러그인 확장을
     * 제외합니다. 레이아웃 편집 화면 트리가 실제 화면에 반영되는 확장만
     * 보여주도록 사용합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedByTemplateId(int $templateId): Collection;

    /**
     * 템플릿 오버라이드 해석을 적용해 가려진 확장을 제거
     *
     * 동일한 (extension_type, target_name) 범위의 확장만 전달해야 합니다.
     *
     * @param  Collection<int, LayoutExtension>  $extensions  동일 범위 확장 컬렉션
     * @return Collection<int, LayoutExtension>
     */
    public function resolveOverrides(Collection $extensions): Collection;

    /**
     * ID로 단일 확장 조회
     *
     * @param  int  $extensionId  확장 ID
     * @return LayoutExtension|null 확장 모델 또는 null
     */
    public function findById(int $extensionId): ?LayoutExtension;

    /**
     * 특정 출처(모듈/플러그인)의 모든 확장 조회
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return Collection<int, LayoutExtension>
     */
    public function getBySource(LayoutSourceType $sourceType, string $identifier): Collection;

    /**
     * 조회 조건에 일치하는 확장을 조회
     *
     * @param  array  $attributes  조회 조건
     * @return LayoutExtension|null 일치하는 확장 또는 null
     */
    public function findByAttributes(array $attributes): ?LayoutExtension;

    /**
     * 확장 행의 조각 키를 바꿔 저장 (soft delete 된 행 포함)
     *
     * @param  LayoutExtension  $extension  대상 확장 모델 (삭제 행일 수 있음)
     * @param  string  $fragmentKey  새 조각 키
     * @return LayoutExtension 저장된 확장 모델
     */
    public function saveFragmentKey(LayoutExtension $extension, string $fragmentKey): LayoutExtension;

    /**
     * 조회 조건에 일치하는 모든 LIVE 확장을 템플릿 구분 없이 조회 (cross-template 판정용)
     *
     * @param  array  $attributes  조회 조건 (template_id 제외)
     * @return \Illuminate\Database\Eloquent\Collection<int, LayoutExtension>
     */
    public function getAllByAttributesAcrossTemplates(array $attributes): \Illuminate\Database\Eloquent\Collection;

    /**
     * 확장 업데이트
     *
     * @param  int  $extensionId  확장 ID
     * @param  array  $data  업데이트할 데이터
     * @return LayoutExtension 업데이트된 확장 모델
     */
    public function update(int $extensionId, array $data): LayoutExtension;

    /**
     * 확장 content + lock_version 동시 갱신 (낙관적 잠금)
     *
     * Service 가 호출 직전에 expected_lock_version 검증을 마친 상태로,
     * 본 메서드는 update 데이터에 lock_version 증가를 함께 반영한다.
     *
     * @param  int  $extensionId  확장 ID
     * @param  array  $data  업데이트 데이터 (content, priority 등)
     * @param  int  $newLockVersion  새 lock_version 값 (currentVersion + 1)
     * @return LayoutExtension 업데이트된 확장 모델
     */
    public function updateWithLock(int $extensionId, array $data, int $newLockVersion): LayoutExtension;

    /**
     * 특정 템플릿의 모든 확장 삭제
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByTemplateId(int $templateId): int;

    /**
     * 지워진(soft delete) 행까지 포함해 조회 조건에 일치하는 확장 1건을 찾습니다.
     *
     * @param  array<string, mixed>  $attributes  조회 조건 (템플릿·대상·출처·조각 식별자)
     * @return LayoutExtension|null 없으면 null (지워진 행이면 `trashed()` 가 true)
     */
    public function findWithTrashedByAttributes(array $attributes): ?LayoutExtension;

    /**
     * 지워진 확장 행을 되살립니다.
     *
     * @param  LayoutExtension  $extension  지워진 행
     * @return LayoutExtension 되살린 행 (같은 인스턴스)
     */
    public function restoreTrashed(LayoutExtension $extension): LayoutExtension;

    /**
     * 이미 읽어 둔 확장 행에 값을 채워 저장합니다 — 다시 조회하지 않고 같은 인스턴스를 갱신한다.
     *
     * @param  LayoutExtension  $extension  저장할 행
     * @param  array<string, mixed>  $attributes  저장할 컬럼 값
     * @return LayoutExtension 저장한 행 (같은 인스턴스)
     */
    public function saveModelAttributes(LayoutExtension $extension, array $attributes): LayoutExtension;

    /**
     * 확장 행 하나를 soft delete 합니다.
     *
     * @param  LayoutExtension  $extension  지울 행
     * @return void 반환값 없음
     */
    public function softDelete(LayoutExtension $extension): void;

    /**
     * 출처(모듈/플러그인)의 확장 중 원본 본문(`original_content`)이 비어 있는 행을 지워진 행까지 포함해 조회합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return Collection<int, LayoutExtension> 원본 본문이 없는 행 목록
     */
    public function getWithoutOriginalContentBySource(LayoutSourceType $sourceType, string $identifier): Collection;
}
