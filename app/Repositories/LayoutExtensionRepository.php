<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use Illuminate\Support\Collection;

/**
 * 레이아웃 확장 리포지토리 구현
 */
class LayoutExtensionRepository implements LayoutExtensionRepositoryInterface
{
    /**
     * 특정 확장점에 등록된 확장 목록 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getByExtensionPoint(int $templateId, string $extensionPointName): Collection
    {
        return LayoutExtension::query()
            ->where('template_id', $templateId)
            ->extensionPoints()
            ->where('target_name', $extensionPointName)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * 특정 레이아웃을 타겟으로 하는 오버레이 목록 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getOverlaysByLayout(int $templateId, string $layoutName): Collection
    {
        return LayoutExtension::query()
            ->where('template_id', $templateId)
            ->overlays()
            ->where('target_name', $layoutName)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * 확장 등록
     *
     * @param  array  $data  확장 데이터
     * @return LayoutExtension 생성된 확장 모델
     */
    public function create(array $data): LayoutExtension
    {
        return LayoutExtension::create($data);
    }

    /**
     * 확장 등록 또는 업데이트 (upsert)
     *
     * 동일한 조건의 확장이 존재하면 업데이트하고, 없으면 생성합니다.
     * soft delete된 레코드도 복원하여 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 값
     * @return LayoutExtension 생성 또는 업데이트된 확장 모델
     */
    public function updateOrCreate(array $attributes, array $values): LayoutExtension
    {
        // soft delete된 레코드도 포함하여 조회
        $existing = LayoutExtension::withTrashed()
            ->where($attributes)
            ->first();

        if ($existing) {
            // 기존 레코드가 있으면 복원 후 업데이트
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($values);

            return $existing->fresh();
        }

        // 없으면 새로 생성
        return LayoutExtension::create(array_merge($attributes, $values));
    }

    /**
     * 출처별 확장 삭제 (soft delete)
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function softDeleteBySource(LayoutSourceType $sourceType, string $identifier): int
    {
        return LayoutExtension::query()
            ->bySource($sourceType, $identifier)
            ->delete();
    }

    /**
     * 출처별 확장 복원
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 복원된 레코드 수
     */
    public function restoreBySource(LayoutSourceType $sourceType, string $identifier): int
    {
        return LayoutExtension::query()
            ->withTrashed()
            ->bySource($sourceType, $identifier)
            ->restore();
    }

    /**
     * 출처별 확장 영구 삭제
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function forceDeleteBySource(LayoutSourceType $sourceType, string $identifier): int
    {
        return LayoutExtension::query()
            ->withTrashed()
            ->bySource($sourceType, $identifier)
            ->forceDelete();
    }

    /**
     * 템플릿 오버라이드 확인 (Extension Point용)
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
    ): ?LayoutExtension {
        return LayoutExtension::query()
            ->where('template_id', $templateId)
            ->extensionPoints()
            ->where('target_name', $extensionPointName)
            ->templateOverrides()
            ->overridingTarget($moduleIdentifier)
            ->active()
            ->first();
    }

    /**
     * 템플릿 오버라이드 확인 (Overlay용)
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
    ): ?LayoutExtension {
        return LayoutExtension::query()
            ->where('template_id', $templateId)
            ->overlays()
            ->where('target_name', $layoutName)
            ->templateOverrides()
            ->overridingTarget($moduleIdentifier)
            ->active()
            ->first();
    }

    /**
     * 오버라이드를 고려한 Extension Point 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedExtensionPoints(int $templateId, string $extensionPointName): Collection
    {
        // 1. 모든 확장 조회 (모듈/플러그인/템플릿 오버라이드 포함)
        $allExtensions = LayoutExtension::query()
            ->where('template_id', $templateId)
            ->extensionPoints()
            ->where('target_name', $extensionPointName)
            ->active()
            ->ordered()
            ->get();

        // 2. 템플릿 오버라이드에 가려진 모듈/플러그인 확장 제거
        return $this->resolveOverrides($allExtensions);
    }

    /**
     * 오버라이드를 고려한 Overlay 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    public function getResolvedOverlays(int $templateId, string $layoutName): Collection
    {
        // 1. 모든 오버레이 조회
        $allOverlays = LayoutExtension::query()
            ->where('template_id', $templateId)
            ->overlays()
            ->where('target_name', $layoutName)
            ->active()
            ->ordered()
            ->get();

        // 2. 템플릿 오버라이드에 가려진 모듈/플러그인 확장 제거
        return $this->resolveOverrides($allOverlays);
    }

    /**
     * 템플릿 오버라이드 해석을 적용해 가려진 확장을 제거합니다.
     *
     * 동일한 (extension_type, target_name) 범위 안에서, 템플릿이 특정
     * 모듈/플러그인을 오버라이드하면 그 모듈/플러그인의 원본 확장은
     * 화면에 적용되지 않으므로 결과에서 제외합니다. 템플릿 오버라이드 행은
     * 항상 유지됩니다.
     *
     * 렌더링 경로(getResolvedExtensionPoints/getResolvedOverlays)와
     * 편집 화면 트리(getByTemplateId)가 동일한 가시성 기준을 공유하도록
     * 이 메서드를 단일 출처(SSoT)로 사용합니다.
     *
     * 호출 측은 동일한 (extension_type, target_name) 범위의 확장만
     * 전달해야 합니다. 혼합 컬렉션은 target_name 별로 그룹핑한 뒤
     * 그룹마다 호출하십시오.
     *
     * @param  Collection<int, LayoutExtension>  $extensions  동일 범위 확장 컬렉션
     * @return Collection<int, LayoutExtension> 오버라이드에 가려진 행을 제외한 컬렉션
     */
    public function resolveOverrides(Collection $extensions): Collection
    {
        // 템플릿 오버라이드가 가리키는 모듈/플러그인 식별자 추출
        $overriddenSources = $extensions
            ->where('source_type', LayoutSourceType::Template)
            ->pluck('override_target')
            ->filter()
            ->unique()
            ->toArray();

        return $extensions->filter(function ($extension) use ($overriddenSources) {
            // 템플릿 오버라이드는 항상 포함
            if ($extension->source_type === LayoutSourceType::Template) {
                return true;
            }

            // 오버라이드된 모듈/플러그인 확장은 제외
            return ! in_array($extension->source_identifier, $overriddenSources, true);
        })->values();
    }

    /**
     * 특정 템플릿의 모든 확장 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection<int, LayoutExtension>
     */
    public function getByTemplateId(int $templateId): Collection
    {
        return LayoutExtension::query()
            ->where('template_id', $templateId)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * 특정 템플릿의 확장을 오버라이드 해석을 적용해 조회합니다.
     *
     * getByTemplateId 가 모든 행을 반환하는 것과 달리, 템플릿 오버라이드에
     * 가려져 화면에 적용되지 않는 모듈/플러그인 확장을 제외합니다.
     * 레이아웃 편집 화면 좌측 트리가 "실제 화면에 반영되는 확장"만 보여주도록
     * 렌더링 경로와 동일한 가시성 기준(resolveOverrides)을 적용합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection<int, LayoutExtension> 오버라이드에 가려진 행을 제외한 컬렉션
     */
    public function getResolvedByTemplateId(int $templateId): Collection
    {
        $all = $this->getByTemplateId($templateId);

        // (extension_type, target_name) 별로 그룹핑한 뒤 그룹마다 오버라이드 해석
        return $all
            ->groupBy(fn ($extension) => $extension->extension_type->value.'|'.$extension->target_name)
            ->flatMap(fn (Collection $group) => $this->resolveOverrides($group))
            ->values();
    }

    /**
     * ID로 단일 확장 조회
     *
     * @param  int  $extensionId  확장 ID
     * @return LayoutExtension|null 확장 모델 또는 null
     */
    public function findById(int $extensionId): ?LayoutExtension
    {
        return LayoutExtension::find($extensionId);
    }

    /**
     * 특정 출처(모듈/플러그인)의 모든 확장 조회
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return Collection<int, LayoutExtension>
     */
    public function getBySource(LayoutSourceType $sourceType, string $identifier): Collection
    {
        return LayoutExtension::query()
            ->bySource($sourceType, $identifier)
            ->get();
    }

    /**
     * 조회 조건에 일치하는 확장을 조회합니다.
     *
     * @param  array  $attributes  조회 조건
     * @return LayoutExtension|null 일치하는 확장 또는 null
     */
    public function findByAttributes(array $attributes): ?LayoutExtension
    {
        return LayoutExtension::query()->where($attributes)->first();
    }

    /**
     * 조회 조건에 일치하는 모든 LIVE 확장을 템플릿 구분 없이 조회합니다.
     *
     * cross-template 판정에 사용 — 동일 확장(source/type/target)이 어느 템플릿들에
     * 등록돼 있는지 전수 확인하기 위함. template_id 는 조건에 넣지 않는다.
     *
     * @param  array  $attributes  조회 조건 (template_id 제외)
     * @return \Illuminate\Database\Eloquent\Collection<int, LayoutExtension>
     */
    public function getAllByAttributesAcrossTemplates(array $attributes): \Illuminate\Database\Eloquent\Collection
    {
        return LayoutExtension::query()->where($attributes)->get();
    }

    /**
     * 확장 업데이트
     *
     * @param  int  $extensionId  확장 ID
     * @param  array  $data  업데이트할 데이터
     * @return LayoutExtension 업데이트된 확장 모델
     */
    public function update(int $extensionId, array $data): LayoutExtension
    {
        $extension = LayoutExtension::findOrFail($extensionId);
        $extension->update($data);

        return $extension->fresh();
    }

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
    public function updateWithLock(int $extensionId, array $data, int $newLockVersion): LayoutExtension
    {
        $extension = LayoutExtension::findOrFail($extensionId);
        $extension->update([...$data, 'lock_version' => $newLockVersion]);

        return $extension->fresh();
    }

    /**
     * 특정 템플릿의 모든 확장 삭제
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByTemplateId(int $templateId): int
    {
        return LayoutExtension::query()
            ->withTrashed()
            ->where('template_id', $templateId)
            ->forceDelete();
    }
}
