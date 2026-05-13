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
     * @param int $templateId 템플릿 ID
     * @param string $extensionPointName 확장점 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getByExtensionPoint(int $templateId, string $extensionPointName): Collection;

    /**
     * 특정 레이아웃을 타겟으로 하는 오버레이 목록 조회
     *
     * @param int $templateId 템플릿 ID
     * @param string $layoutName 레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getOverlaysByLayout(int $templateId, string $layoutName): Collection;

    /**
     * 확장 등록
     *
     * @param array $data 확장 데이터
     * @return LayoutExtension
     */
    public function create(array $data): LayoutExtension;

    /**
     * 확장 등록 또는 업데이트 (upsert)
     *
     * 동일한 조건의 확장이 존재하면 업데이트하고, 없으면 생성합니다.
     *
     * @param array $attributes 조회 조건 (template_id, extension_type, target_name, source_type, source_identifier)
     * @param array $values 생성/업데이트할 값
     * @return LayoutExtension
     */
    public function updateOrCreate(array $attributes, array $values): LayoutExtension;

    /**
     * 출처별 확장 삭제 (soft delete)
     *
     * @param LayoutSourceType $sourceType 출처 타입
     * @param string $identifier 출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function softDeleteBySource(LayoutSourceType $sourceType, string $identifier): int;

    /**
     * 출처별 확장 복원
     *
     * @param LayoutSourceType $sourceType 출처 타입
     * @param string $identifier 출처 식별자
     * @return int 복원된 레코드 수
     */
    public function restoreBySource(LayoutSourceType $sourceType, string $identifier): int;

    /**
     * 출처별 확장 영구 삭제
     *
     * @param LayoutSourceType $sourceType 출처 타입
     * @param string $identifier 출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function forceDeleteBySource(LayoutSourceType $sourceType, string $identifier): int;

    /**
     * 템플릿 오버라이드 확인 (Extension Point용)
     *
     * 특정 extension_point에 대해 템플릿이 오버라이드를 정의했는지 확인합니다.
     *
     * @param int $templateId 템플릿 ID
     * @param string $extensionPointName 확장점 이름
     * @param string $moduleIdentifier 모듈/플러그인 식별자
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
     * @param int $templateId 템플릿 ID
     * @param string $layoutName 레이아웃 이름
     * @param string $moduleIdentifier 모듈/플러그인 식별자
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
     * @param int $templateId 템플릿 ID
     * @param string $extensionPointName 확장점 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedExtensionPoints(int $templateId, string $extensionPointName): Collection;

    /**
     * 오버라이드를 고려한 Overlay 조회
     *
     * 템플릿 오버라이드가 있는 모듈 확장은 제외하고,
     * 오버라이드된 버전과 원본 모듈 확장을 함께 반환합니다.
     *
     * @param int $templateId 템플릿 ID
     * @param string $layoutName 레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedOverlays(int $templateId, string $layoutName): Collection;

    /**
     * 특정 템플릿의 모든 확장 조회
     *
     * @param int $templateId 템플릿 ID
     * @return Collection<int, LayoutExtension>
     */
    public function getByTemplateId(int $templateId): Collection;

    /**
     * 특정 템플릿의 모든 확장 삭제
     *
     * @param int $templateId 템플릿 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByTemplateId(int $templateId): int;
}
