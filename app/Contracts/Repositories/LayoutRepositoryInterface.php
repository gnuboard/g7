<?php

namespace App\Contracts\Repositories;

use App\Enums\LayoutSourceType;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use Illuminate\Database\Eloquent\Collection;

interface LayoutRepositoryInterface
{
    /**
     * 특정 템플릿의 모든 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection 레이아웃 컬렉션
     */
    public function getByTemplateId(int $templateId): Collection;

    /**
     * 특정 레이아웃 조회 (템플릿 ID와 이름으로)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findByName(int $templateId, string $name): ?TemplateLayout;

    /**
     * ID로 레이아웃 조회
     *
     * @param  int  $id  레이아웃 ID
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findById(int $id): ?TemplateLayout;

    /**
     * 레이아웃이 존재하는지 확인
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return bool 존재 여부
     */
    public function exists(int $templateId, string $name): bool;

    /**
     * extends를 가진 자식 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extendsName  extends 이름
     * @return Collection 자식 레이아웃 컬렉션
     */
    public function getChildrenByExtends(int $templateId, string $extendsName): Collection;

    /**
     * 레이아웃 업데이트
     *
     * @param  int  $id  레이아웃 ID
     * @param  array  $data  업데이트할 데이터
     * @return TemplateLayout 업데이트된 레이아웃 모델
     */
    public function update(int $id, array $data): TemplateLayout;

    /**
     * 특정 레이아웃의 모든 버전 조회
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return Collection 버전 컬렉션
     */
    public function getVersionsByLayoutId(int $layoutId): Collection;

    /**
     * 특정 버전 조회
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  int  $version  버전 번호
     * @return TemplateLayoutVersion|null 찾은 버전 모델 또는 null
     */
    public function findVersionByNumber(int $layoutId, int $version): ?TemplateLayoutVersion;

    /**
     * 템플릿 오버라이드 레이아웃 찾기
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findTemplateOverride(int $templateId, string $layoutName): ?TemplateLayout;

    /**
     * 모듈 기본 레이아웃 찾기
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findModuleLayout(int $templateId, string $layoutName): ?TemplateLayout;

    /**
     * 특정 템플릿의 모든 레이아웃 이름 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return \Illuminate\Support\Collection<int, string> 레이아웃 이름 컬렉션
     */
    public function getLayoutNamesByTemplateId(int $templateId): \Illuminate\Support\Collection;

    /**
     * 특정 모듈의 모든 레이아웃 조회
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection 레이아웃 컬렉션
     */
    public function getLayoutsByModule(string $moduleIdentifier): Collection;

    /**
     * 특정 템플릿에서 오버라이드된 모든 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection 오버라이드 레이아웃 컬렉션
     */
    public function getOverriddenLayouts(int $templateId): Collection;

    /**
     * 특정 모듈의 레이아웃 중 템플릿에서 오버라이드된 것들 조회
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @param  int  $templateId  템플릿 ID
     * @return Collection 오버라이드 레이아웃 컬렉션
     */
    public function getModuleLayoutOverrides(string $moduleIdentifier, int $templateId): Collection;

    /**
     * 우선순위에 따라 레이아웃 조회 (오버라이드 우선)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findByNameWithOverride(int $templateId, string $name): ?TemplateLayout;

    /**
     * 특정 템플릿의 모든 모듈 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $moduleIdentifier  특정 모듈만 조회 (선택)
     * @return Collection 모듈 레이아웃 컬렉션
     */
    public function findModuleLayouts(int $templateId, ?string $moduleIdentifier = null): Collection;

    /**
     * 특정 템플릿의 모든 레이아웃 조회 (source_type 필터 옵션 포함)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $sourceType  소스 타입 필터
     * @param  string|null  $sourceIdentifier  소스 식별자 필터
     * @return Collection 레이아웃 컬렉션
     */
    public function getByTemplateIdWithFilter(
        int $templateId,
        ?string $sourceType = null,
        ?string $sourceIdentifier = null
    ): Collection;

    /**
     * 특정 플러그인의 모든 레이아웃 조회
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return Collection 레이아웃 컬렉션
     */
    public function getLayoutsByPlugin(string $pluginIdentifier): Collection;

    /**
     * 특정 템플릿의 모든 플러그인 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $pluginIdentifier  특정 플러그인만 조회 (선택)
     * @return Collection 플러그인 레이아웃 컬렉션
     */
    public function findPluginLayouts(int $templateId, ?string $pluginIdentifier = null): Collection;

    /**
     * 레이아웃을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return TemplateLayout 생성 또는 업데이트된 레이아웃 모델
     */
    public function updateOrCreate(array $attributes, array $values): TemplateLayout;

    /**
     * 특정 모듈의 soft delete된 레이아웃을 조회합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection soft delete된 레이아웃 컬렉션
     */
    public function getTrashedByModule(string $moduleIdentifier): Collection;

    /**
     * 특정 모듈의 레이아웃을 soft delete합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int soft delete된 레코드 수
     */
    public function softDeleteByModule(string $moduleIdentifier): int;

    /**
     * 특정 모듈의 레이아웃을 영구 삭제합니다 (soft delete 포함).
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 삭제된 레코드 수
     */
    public function forceDeleteByModule(string $moduleIdentifier): int;

    /**
     * 특정 모듈의 레이아웃 개수를 반환합니다 (soft delete 포함).
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 레이아웃 개수
     */
    public function countByModule(string $moduleIdentifier): int;

    /**
     * 특정 모듈의 soft delete된 레이아웃을 복원합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 복원된 레코드 수
     */
    public function restoreByModule(string $moduleIdentifier): int;

    /**
     * 특정 모듈의 레이아웃들을 조회합니다 (soft delete 제외).
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection 레이아웃 컬렉션
     */
    public function getByModuleIdentifier(string $moduleIdentifier): Collection;

    /**
     * 특정 확장(모듈 또는 플러그인)의 레이아웃들을 소스 타입과 함께 조회합니다.
     *
     * @param  string  $sourceIdentifier  확장 식별자
     * @param  LayoutSourceType  $sourceType  소스 타입 (Module 또는 Plugin)
     * @return Collection 레이아웃 컬렉션
     */
    public function getBySourceIdentifier(string $sourceIdentifier, LayoutSourceType $sourceType): Collection;

    /**
     * 특정 템플릿의 모든 레이아웃을 삭제합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByTemplateId(int $templateId): int;

    /**
     * 특정 템플릿의 레이아웃 개수를 조회합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 레이아웃 개수
     */
    public function countByTemplateId(int $templateId): int;

    /**
     * 특정 템플릿의 오버라이드 레이아웃들을 조회합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection 오버라이드 레이아웃 컬렉션
     */
    public function getOverridesByTemplateId(int $templateId): Collection;

    /**
     * 특정 소스 식별자의 레이아웃을 모두 삭제합니다.
     *
     * @param  string  $sourceIdentifier  소스 식별자 (모듈/템플릿 식별자)
     * @return int 삭제된 레코드 수
     */
    public function deleteBySourceIdentifier(string $sourceIdentifier): int;
}
