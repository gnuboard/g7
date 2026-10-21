<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Services\LayoutExtensionService;
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
     * 출처의 지워진(soft delete) 확장 행을 조회합니다 — 재활성화 복원 후보.
     *
     * 어느 행을 되살릴지는 서비스가 정한다(활성 템플릿 + 붙을 자리 — {@see LayoutExtensionService::restoreBySource()}).
     * 종전에는 여기서 후보 전부를 한 번에 되살려, 일부러 숨긴 오등록 행과 꺼진 템플릿의 행까지 살아났다(#725).
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  array<int, string>|null  $fragmentKeys  이 조각 키의 행만 (null 이면 전부)
     * @return Collection<int, LayoutExtension> 지워진 행
     */
    public function getTrashedBySource(LayoutSourceType $sourceType, string $identifier, ?array $fragmentKeys = null): Collection
    {
        return LayoutExtension::onlyTrashed()
            ->bySource($sourceType, $identifier)
            ->when($fragmentKeys !== null, fn ($query) => $query->whereIn('fragment_key', $fragmentKeys))
            ->orderBy('id')
            ->get();
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
     * 확장 행의 조각 키를 바꿔 저장합니다 (soft delete 된 행 포함).
     *
     * `update()` 는 `findOrFail` 로 삭제 행을 찾지 못하므로, 이미 조회한 모델을 받아 그대로 저장합니다.
     *
     * @param  LayoutExtension  $extension  대상 확장 모델 (삭제 행일 수 있음)
     * @param  string  $fragmentKey  새 조각 키
     * @return LayoutExtension 저장된 확장 모델
     */
    public function saveFragmentKey(LayoutExtension $extension, string $fragmentKey): LayoutExtension
    {
        $extension->fragment_key = $fragmentKey;
        $extension->save();

        return $extension;
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

    /**
     * 지워진(soft delete) 행까지 포함해 조회 조건에 일치하는 확장 1건을 찾습니다.
     *
     * @param  array<string, mixed>  $attributes  조회 조건 (템플릿·대상·출처·조각 식별자)
     * @return LayoutExtension|null 없으면 null (지워진 행이면 `trashed()` 가 true)
     */
    public function findWithTrashedByAttributes(array $attributes): ?LayoutExtension
    {
        return LayoutExtension::withTrashed()->where($attributes)->first();
    }

    /**
     * 지워진 확장 행을 되살립니다.
     *
     * @param  LayoutExtension  $extension  지워진 행
     * @return LayoutExtension 되살린 행 (같은 인스턴스)
     */
    public function restoreTrashed(LayoutExtension $extension): LayoutExtension
    {
        $extension->restore();

        return $extension;
    }

    /**
     * 이미 읽어 둔 확장 행에 값을 채워 저장합니다 — 다시 조회하지 않고 같은 인스턴스를 갱신한다.
     *
     * @param  LayoutExtension  $extension  저장할 행
     * @param  array<string, mixed>  $attributes  저장할 컬럼 값
     * @return LayoutExtension 저장한 행 (같은 인스턴스)
     */
    public function saveModelAttributes(LayoutExtension $extension, array $attributes): LayoutExtension
    {
        $extension->forceFill($attributes)->save();

        return $extension;
    }

    /**
     * 확장 행 하나를 soft delete 합니다.
     *
     * @param  LayoutExtension  $extension  지울 행
     * @return void 반환값 없음
     */
    public function softDelete(LayoutExtension $extension): void
    {
        $extension->delete();
    }

    /**
     * 출처(모듈/플러그인)의 확장 중 원본 본문(`original_content`)이 비어 있는 행을 지워진 행까지 포함해 조회합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return Collection<int, LayoutExtension> 원본 본문이 없는 행 목록
     */
    public function getWithoutOriginalContentBySource(LayoutSourceType $sourceType, string $identifier): Collection
    {
        return LayoutExtension::withTrashed()
            ->where('source_type', $sourceType)
            ->where('source_identifier', $identifier)
            ->whereNull('original_content')
            ->get();
    }
}
