<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class MenuResource extends BaseApiResource
{
    /**
     * 메뉴 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 메뉴 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'name' => $this->getValue('name'),  // 다국어 객체 그대로 반환 (프론트엔드에서 $localized() 사용)
            'slug' => $this->getValue('slug'),
            'url' => $this->getValue('url'),
            'icon' => $this->getValue('icon'),
            'order' => $this->getValue('order'),
            'is_active' => $this->getValue('is_active', true),

            // 관계형 데이터
            'parent_id' => $this->getValue('parent_id'),  // parent_id 추가 (프론트엔드에서 필요)
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            'parent' => $this->whenLoaded('parent', function () {
                return $this->parent ? [
                    'id' => $this->parent->id,
                    'name' => $this->parent->name,  // 다국어 객체 그대로 반환
                    'url' => $this->parent->url,
                    'icon' => $this->parent->icon,
                ] : null;
            }),

            'children' => $this->whenLoaded('children', function () {
                return $this->children->map(function ($child) {
                    return [
                        'id' => $child->id,
                        'name' => $child->name,  // 다국어 객체 그대로 반환
                        'slug' => $child->slug,
                        'url' => $child->url,
                        'icon' => $child->icon,
                        'order' => $child->order,
                        'is_active' => $child->is_active,
                        'parent_id' => $child->parent_id,
                        'extension_type' => $child->extension_type?->value,
                        'extension_identifier' => $child->extension_identifier,
                        'roles' => $child->relationLoaded('roles') ? $child->roles->map(function ($role) {
                            return [
                                'id' => $role->id,
                                'name' => $role->name,
                                'permission_type' => $role->pivot->permission_type ?? null,
                            ];
                        }) : [],
                    ];
                })->sortBy('order')->values();
            }),

            'creator' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                ] : null;
            }),

            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'permission_type' => $role->pivot->permission_type ?? null,
                    ];
                });
            }),

            // 통계 정보
            'children_count' => $this->when(
                isset($this->children_count),
                $this->getValue('children_count')
            ),
            'depth_level' => $this->when(
                isset($this->depth_level),
                $this->getValue('depth_level')
            ),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'created_by';
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.menus.create',
            'can_update' => 'core.menus.update',
            'can_delete' => 'core.menus.delete',
        ];
    }

    /**
     * 네비게이션용 단순한 형태의 배열을 반환합니다 (계층 구조 포함).
     *
     * @return array<string, mixed> 네비게이션용 메뉴 정보
     */
    public function toNavigationArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,  // 다국어 객체 그대로 반환
            'slug' => $this->slug,
            'url' => $this->url,
            'icon' => $this->icon,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'children' => $this->whenLoaded('activeChildren', function () {
                return $this->activeChildren
                    ->sortBy('order')
                    ->map(function ($child) {
                        return [
                            'id' => $child->id,
                            'name' => $child->name,  // 다국어 객체 그대로 반환
                            'slug' => $child->slug,
                            'url' => $child->url,
                            'icon' => $child->icon,
                            'order' => $child->order,
                            'is_active' => $child->is_active,
                            'parent_id' => $child->parent_id,
                            'extension_type' => $child->extension_type?->value,
                            'extension_identifier' => $child->extension_identifier,
                        ];
                    })->values();
            }),
        ];
    }

    /**
     * 관리자용 상세 정보를 포함한 배열을 반환합니다.
     *
     * @return array<string, mixed> 관리자용 상세 메뉴 정보
     */
    public function withAdminInfo(): array
    {
        return array_merge($this->toArray(request()), [
            'admin_notes' => $this->admin_notes,
            'created_by' => $this->creator?->uuid,
            'last_accessed_at' => $this->when($this->last_accessed_at, fn () => $this->formatDateTimeForUser($this->last_accessed_at)),
            'access_count' => $this->access_count ?? 0,
        ]);
    }
}
