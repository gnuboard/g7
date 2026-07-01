<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use Illuminate\Database\Eloquent\Collection;

class LayoutRepository implements LayoutRepositoryInterface
{
    /**
     * 특정 템플릿의 모든 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection 레이아웃 컬렉션
     */
    public function getByTemplateId(int $templateId): Collection
    {
        return TemplateLayout::where('template_id', $templateId)
            ->orderBy('name')
            ->get();
    }

    /**
     * 특정 레이아웃 조회 (템플릿 ID와 이름으로)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findByName(int $templateId, string $name): ?TemplateLayout
    {
        return TemplateLayout::where('template_id', $templateId)
            ->where('name', $name)
            ->first();
    }

    /**
     * ID로 레이아웃 조회
     *
     * @param  int  $id  레이아웃 ID
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findById(int $id): ?TemplateLayout
    {
        return TemplateLayout::find($id);
    }

    /**
     * 레이아웃이 존재하는지 확인
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return bool 존재 여부
     */
    public function exists(int $templateId, string $name): bool
    {
        return TemplateLayout::where('template_id', $templateId)
            ->where('name', $name)
            ->exists();
    }

    /**
     * 특정 템플릿의 레이아웃 중 지정한 확장점(extension_point)을 정의한 것이 있는지 확인
     *
     * 레이아웃 content JSON 트리를 재귀 순회하여 `type: extension_point` 노드의
     * `name` 이 일치하는지 검사합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @return bool 정의 존재 여부
     */
    public function hasExtensionPoint(int $templateId, string $extensionPointName): bool
    {
        // 1차 필터: content 에 extension_point 명이 포함된 레이아웃만 조회 (성능 최적화)
        $layouts = TemplateLayout::where('template_id', $templateId)
            ->where('content', 'like', '%'.$extensionPointName.'%')
            ->get(['content']);

        foreach ($layouts as $layout) {
            if ($this->containsExtensionPoint($layout->content, $extensionPointName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 지정 extension_point 를 포함하는 레이아웃 이름 목록을 반환합니다.
     *
     * 확장 편집 모드에서 extension_point 확장의 대표 호스트 레이아웃 선택(picker)에 쓴다.
     * 여러 레이아웃에 같은 확장점이 있으면 모두 반환하며, 클라이언트가 1개면 즉시 진입,
     * 복수면 선택 모달을 띄운다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPointName  확장점 이름
     * @return array<int, string> 호스트 레이아웃 이름 목록 (정렬됨)
     */
    public function findLayoutNamesWithExtensionPoint(int $templateId, string $extensionPointName): array
    {
        $layouts = TemplateLayout::where('template_id', $templateId)
            ->where('content', 'like', '%'.$extensionPointName.'%')
            ->get(['name', 'content']);

        $names = [];
        foreach ($layouts as $layout) {
            if ($this->containsExtensionPoint($layout->content, $extensionPointName)) {
                $names[] = $layout->name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * 레이아웃 content 트리에 지정 노드 id 가 존재하는지 확인합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @param  string  $nodeId  찾을 노드 id
     * @return bool 노드 id 존재 여부
     */
    public function layoutContainsNodeId(int $templateId, string $layoutName, string $nodeId): bool
    {
        $layout = $this->findByNameWithOverride($templateId, $layoutName)
            ?? $this->findByName($templateId, $layoutName);
        if ($layout === null) {
            return false;
        }

        $content = is_array($layout->content)
            ? $layout->content
            : json_decode((string) $layout->content, true);

        return is_array($content) && $this->containsNodeId($content, $nodeId);
    }

    /**
     * content 트리를 재귀 순회하여 노드 id(`id` 또는 `props.id`)가 일치하는 노드를 검색합니다.
     *
     * @param  mixed  $node  탐색 노드 (배열 또는 스칼라)
     * @param  string  $nodeId  찾을 노드 id
     * @return bool 발견 여부
     */
    private function containsNodeId(mixed $node, string $nodeId): bool
    {
        if (! is_array($node)) {
            return false;
        }

        $id = $node['id'] ?? ($node['props']['id'] ?? null);
        if ($id === $nodeId) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && $this->containsNodeId($value, $nodeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 레이아웃 content 트리를 재귀 순회하여 extension_point 노드를 검색합니다.
     *
     * @param  mixed  $node  탐색 노드 (배열 또는 스칼라)
     * @param  string  $extensionPointName  확장점 이름
     * @return bool 발견 여부
     */
    private function containsExtensionPoint(mixed $node, string $extensionPointName): bool
    {
        if (! is_array($node)) {
            return false;
        }

        // extension_point 노드 검출
        if (($node['type'] ?? null) === 'extension_point' && ($node['name'] ?? null) === $extensionPointName) {
            return true;
        }

        // 모든 하위 노드 재귀 탐색 (components / children / slots 등 구조 무관)
        foreach ($node as $value) {
            if (is_array($value) && $this->containsExtensionPoint($value, $extensionPointName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * extends를 가진 자식 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extendsName  부모 레이아웃 이름
     * @return Collection 자식 레이아웃 컬렉션
     */
    public function getChildrenByExtends(int $templateId, string $extendsName): Collection
    {
        return TemplateLayout::where('template_id', $templateId)
            ->where('extends', $extendsName)
            ->get();
    }

    /**
     * 레이아웃 업데이트
     *
     * 전체 content를 교체합니다. FormRequest에서 구조 검증이 완료된 데이터를 받습니다.
     *
     * @param  int  $id  레이아웃 ID
     * @param  array  $data  업데이트할 content 데이터 (전체 레이아웃 JSON)
     * @return TemplateLayout 업데이트된 레이아웃 모델
     */
    public function update(int $id, array $data): TemplateLayout
    {
        $layout = TemplateLayout::findOrFail($id);

        // 전체 content 교체 (FormRequest에서 검증 완료)
        $layout->content = $data;

        // extends 필드 동기화 (캐시 무효화 및 자식 레이아웃 탐색용)
        $layout->extends = $data['extends'] ?? null;

        $layout->save();

        return $layout->fresh();
    }

    /**
     * 레이아웃 content + lock_version 동시 갱신 (낙관적 잠금)
     *
     * Service 가 호출 직전에 expected_lock_version 검증을 마친 상태로,
     * 본 메서드는 content 교체와 lock_version 증가를 한 번의 UPDATE 로 수행한다.
     *
     * @param  int  $id  레이아웃 ID
     * @param  array  $content  전체 레이아웃 JSON content
     * @param  int  $newLockVersion  새 lock_version 값 (currentVersion + 1)
     * @return TemplateLayout 업데이트된 레이아웃 모델
     */
    public function updateContent(int $id, array $content, int $newLockVersion): TemplateLayout
    {
        $layout = TemplateLayout::findOrFail($id);

        $layout->content = $content;
        $layout->extends = $content['extends'] ?? null;
        $layout->lock_version = $newLockVersion;

        $layout->save();

        return $layout->fresh();
    }

    /**
     * 특정 레이아웃의 모든 버전 조회
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return Collection 버전 컬렉션
     */
    public function getVersionsByLayoutId(int $layoutId): Collection
    {
        // creator eager load — 버전 목록에 저장자 이름(created_by_name) 노출용 (N+1 회피).
        return TemplateLayoutVersion::with('creator:id,name')
            ->where('layout_id', $layoutId)
            ->latest()
            ->get();
    }

    /**
     * 특정 버전 조회
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  int  $version  버전 번호
     * @return TemplateLayoutVersion|null 찾은 버전 모델 또는 null
     */
    public function findVersionByNumber(int $layoutId, int $version): ?TemplateLayoutVersion
    {
        // creator eager load — 단건 버전 조회(showVersion)도 저장자 이름 일관 노출.
        return TemplateLayoutVersion::with('creator:id,name')
            ->where('layout_id', $layoutId)
            ->where('version', $version)
            ->first();
    }

    /**
     * 템플릿 오버라이드 레이아웃 찾기
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findTemplateOverride(int $templateId, string $layoutName): ?TemplateLayout
    {
        return TemplateLayout::where('template_id', $templateId)
            ->where('name', $layoutName)
            ->fromTemplates()
            ->whereNotNull('source_identifier')
            ->first();
    }

    /**
     * 모듈 기본 레이아웃 찾기
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findModuleLayout(int $templateId, string $layoutName): ?TemplateLayout
    {
        return TemplateLayout::where('template_id', $templateId)
            ->where('name', $layoutName)
            ->fromModules()
            ->first();
    }

    /**
     * 특정 템플릿의 모든 레이아웃 이름 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return \Illuminate\Support\Collection<int, string> 레이아웃 이름 컬렉션
     */
    public function getLayoutNamesByTemplateId(int $templateId): \Illuminate\Support\Collection
    {
        return TemplateLayout::where('template_id', $templateId)
            ->pluck('name')
            ->unique();
    }

    /**
     * 특정 모듈의 모든 레이아웃 조회
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection 레이아웃 컬렉션
     */
    public function getLayoutsByModule(string $moduleIdentifier): Collection
    {
        return TemplateLayout::fromModules()
            ->bySourceIdentifier($moduleIdentifier)
            ->get(['template_id', 'name']);
    }

    /**
     * 특정 템플릿에서 오버라이드된 모든 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection 오버라이드 레이아웃 컬렉션
     */
    public function getOverriddenLayouts(int $templateId): Collection
    {
        return TemplateLayout::where('template_id', $templateId)
            ->fromTemplates()
            ->whereNotNull('source_identifier')
            ->get();
    }

    /**
     * 특정 모듈의 레이아웃 중 템플릿에서 오버라이드된 것들 조회
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @param  int  $templateId  템플릿 ID
     * @return Collection 오버라이드 레이아웃 컬렉션
     */
    public function getModuleLayoutOverrides(string $moduleIdentifier, int $templateId): Collection
    {
        // 모듈의 레이아웃 이름 목록 조회
        $moduleLayoutNames = TemplateLayout::fromModules()
            ->bySourceIdentifier($moduleIdentifier)
            ->where('template_id', $templateId)
            ->pluck('name');

        // 해당 이름들에 대한 오버라이드 조회
        return TemplateLayout::where('template_id', $templateId)
            ->fromTemplates()
            ->whereNotNull('source_identifier')
            ->whereIn('name', $moduleLayoutNames)
            ->get();
    }

    /**
     * 우선순위에 따라 레이아웃 조회 (오버라이드 우선)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout|null 찾은 레이아웃 모델 또는 null
     */
    public function findByNameWithOverride(int $templateId, string $name): ?TemplateLayout
    {
        // 1. 템플릿 오버라이드 확인 (최우선)
        $override = $this->findTemplateOverride($templateId, $name);
        if ($override) {
            return $override;
        }

        // 2. 모듈 기본 레이아웃 확인
        $moduleLayout = $this->findModuleLayout($templateId, $name);
        if ($moduleLayout) {
            return $moduleLayout;
        }

        // 3. 일반 템플릿 레이아웃 (폴백)
        return $this->findByName($templateId, $name);
    }

    /**
     * 특정 템플릿의 모든 모듈 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $moduleIdentifier  특정 모듈만 조회 (선택)
     * @return Collection 모듈 레이아웃 컬렉션
     */
    public function findModuleLayouts(int $templateId, ?string $moduleIdentifier = null): Collection
    {
        $query = TemplateLayout::where('template_id', $templateId)
            ->fromModules();

        if ($moduleIdentifier !== null) {
            $query->bySourceIdentifier($moduleIdentifier);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * 특정 템플릿의 모든 레이아웃 조회 (source_type 필터 옵션 포함)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $sourceType  소스 타입 필터 ('template', 'module', 'plugin')
     * @param  string|null  $sourceIdentifier  소스 식별자 필터 (null이면 source_identifier가 null인 레코드만 조회)
     * @param  bool  $nullIdentifierOnly  true이면 source_identifier가 null인 레코드만 조회 (sourceIdentifier 파라미터가 null일 때만 적용)
     * @return Collection 필터링된 레이아웃 컬렉션
     */
    public function getByTemplateIdWithFilter(
        int $templateId,
        ?string $sourceType = null,
        ?string $sourceIdentifier = null,
        bool $nullIdentifierOnly = true
    ): Collection {
        $query = TemplateLayout::where('template_id', $templateId);

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        if ($sourceIdentifier !== null) {
            $query->bySourceIdentifier($sourceIdentifier);
        } elseif ($nullIdentifierOnly) {
            // sourceIdentifier가 null이고 nullIdentifierOnly가 true이면
            // source_identifier가 null인 레코드만 조회
            $query->whereNull('source_identifier');
        }

        return $query->orderBy('name')->get();
    }

    /**
     * 특정 플러그인의 모든 레이아웃 조회
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return Collection 레이아웃 컬렉션
     */
    public function getLayoutsByPlugin(string $pluginIdentifier): Collection
    {
        return TemplateLayout::fromPlugins()
            ->bySourceIdentifier($pluginIdentifier)
            ->get(['template_id', 'name']);
    }

    /**
     * 특정 템플릿의 모든 플러그인 레이아웃 조회
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $pluginIdentifier  특정 플러그인만 조회 (선택)
     * @return Collection 플러그인 레이아웃 컬렉션
     */
    public function findPluginLayouts(int $templateId, ?string $pluginIdentifier = null): Collection
    {
        $query = TemplateLayout::where('template_id', $templateId)
            ->fromPlugins();

        if ($pluginIdentifier !== null) {
            $query->bySourceIdentifier($pluginIdentifier);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * 레이아웃을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return TemplateLayout 생성 또는 업데이트된 레이아웃 모델
     */
    public function updateOrCreate(array $attributes, array $values): TemplateLayout
    {
        return TemplateLayout::updateOrCreate($attributes, $values);
    }

    /**
     * 특정 모듈의 soft delete된 레이아웃을 조회합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection soft delete된 레이아웃 컬렉션
     */
    public function getTrashedByModule(string $moduleIdentifier): Collection
    {
        return TemplateLayout::onlyTrashed()
            ->where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier)
            ->get();
    }

    /**
     * 특정 모듈의 레이아웃을 soft delete합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int soft delete된 레코드 수
     */
    public function softDeleteByModule(string $moduleIdentifier): int
    {
        $layouts = TemplateLayout::where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier)
            ->get();

        $deletedCount = 0;
        foreach ($layouts as $layout) {
            $layout->delete();
            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * 특정 모듈의 레이아웃을 영구 삭제합니다 (soft delete 포함).
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 삭제된 레코드 수
     */
    public function forceDeleteByModule(string $moduleIdentifier): int
    {
        return TemplateLayout::withTrashed()
            ->where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier)
            ->forceDelete();
    }

    /**
     * 특정 모듈의 레이아웃 개수를 반환합니다 (soft delete 포함).
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 레이아웃 개수
     */
    public function countByModule(string $moduleIdentifier): int
    {
        return TemplateLayout::withTrashed()
            ->where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier)
            ->count();
    }

    /**
     * 특정 모듈의 soft delete된 레이아웃을 복원합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return int 복원된 레코드 수
     */
    public function restoreByModule(string $moduleIdentifier): int
    {
        $layouts = $this->getTrashedByModule($moduleIdentifier);

        $restoredCount = 0;
        foreach ($layouts as $layout) {
            $layout->restore();
            $restoredCount++;
        }

        return $restoredCount;
    }

    /**
     * 특정 모듈의 레이아웃들을 조회합니다 (soft delete 제외).
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection 레이아웃 컬렉션
     */
    public function getByModuleIdentifier(string $moduleIdentifier): Collection
    {
        return TemplateLayout::where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier)
            ->get();
    }

    /**
     * 특정 확장(모듈 또는 플러그인)의 레이아웃들을 소스 타입과 함께 조회합니다.
     *
     * @param  string  $sourceIdentifier  확장 식별자
     * @param  LayoutSourceType  $sourceType  소스 타입 (Module 또는 Plugin)
     * @return Collection 레이아웃 컬렉션
     */
    public function getBySourceIdentifier(string $sourceIdentifier, LayoutSourceType $sourceType): Collection
    {
        return TemplateLayout::where('source_type', $sourceType)
            ->where('source_identifier', $sourceIdentifier)
            ->get();
    }

    /**
     * 특정 템플릿의 모든 레이아웃을 삭제합니다.
     * soft deleted된 레코드를 포함하여 영구 삭제합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByTemplateId(int $templateId): int
    {
        return TemplateLayout::withTrashed()
            ->where('template_id', $templateId)
            ->forceDelete();
    }

    /**
     * 특정 템플릿의 레이아웃 개수를 조회합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 레이아웃 개수
     */
    public function countByTemplateId(int $templateId): int
    {
        return TemplateLayout::where('template_id', $templateId)->count();
    }

    /**
     * 특정 템플릿의 오버라이드 레이아웃들을 조회합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return Collection 오버라이드 레이아웃 컬렉션
     */
    public function getOverridesByTemplateId(int $templateId): Collection
    {
        return TemplateLayout::where('template_id', $templateId)
            ->where('source_type', LayoutSourceType::Template)
            ->whereNotNull('source_identifier')
            ->get();
    }

    /**
     * 특정 소스 식별자의 레이아웃을 모두 삭제합니다.
     * soft deleted된 레코드를 포함하여 영구 삭제합니다.
     *
     * @param  string  $sourceIdentifier  소스 식별자 (모듈/템플릿 식별자)
     * @return int 삭제된 레코드 수
     */
    public function deleteBySourceIdentifier(string $sourceIdentifier): int
    {
        return TemplateLayout::withTrashed()
            ->where('source_identifier', $sourceIdentifier)
            ->forceDelete();
    }
}
