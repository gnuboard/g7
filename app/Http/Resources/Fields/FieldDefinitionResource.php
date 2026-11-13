<?php

namespace App\Http\Resources\Fields;

use App\DisplayName\NicknameRequirement;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldRegistry;
use App\Fields\FieldTypeRegistry;
use App\Http\Resources\BaseApiResource;
use App\Models\FieldDefinition;
use Illuminate\Http\Request;

/**
 * 운영자 항목 표·모달용 항목 정의 Resource.
 *
 * 다국어 값은 편집용 원본(로케일 배열)과 표에 바로 쓸 풀린 문자열을 함께 싣는다.
 *
 * @mixin FieldDefinition
 */
class FieldDefinitionResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 직렬화된 항목 정의
     */
    public function toArray(Request $request): array
    {
        $types = app(FieldTypeRegistry::class);
        $type = $types->get($this->type);
        [$typeOwnerType, $typeOwner] = $types->ownerOf($this->type);

        return [
            'id' => $this->id,
            'field_set' => $this->field_set,
            'key' => $this->key,
            'attribute' => app(FieldRegistry::class)->attributeFor($this->resource),
            'source_type' => $this->source_type,
            'source_identifier' => $this->source_identifier,
            // 선언한 확장의 이름 — 표의 「출처」 열이 보인다(코어 항목과 운영자 추가 항목은 null)
            'source_label' => app(FieldRegistry::class)->sourceLabel($this->resource),
            'is_custom' => $this->isCustom(),
            'type' => $this->type,
            'type_label' => $type?->label() ?? $this->type,
            'type_owner' => $type === null ? null : $typeOwner,
            'type_owner_type' => $type === null ? null : $typeOwnerType,
            // 유형을 제공한 확장이 꺼져 있으면 false — 표에 「사용 불가」로 남긴다.
            'available' => $type !== null,
            'storage' => $this->storage,
            'name' => $this->name ?? [],
            'label' => $this->localized('name') ?? $this->key,
            'description' => $this->description ?? [],
            'placeholder' => $this->placeholder ?? [],
            'options' => $this->options ?? [],
            'constraints' => (object) ($this->constraints ?? []),
            'enabled' => (bool) $this->enabled,
            'required' => (bool) $this->required,
            'show_on_signup' => (bool) $this->show_on_signup,
            'edit_scope' => $this->edit_scope,
            'require_existing_members' => (bool) $this->require_existing_members,
            'public_profile' => (bool) $this->public_profile,
            'admin_list_column' => (bool) $this->admin_list_column,
            'admin_searchable' => (bool) $this->admin_searchable,
            'display_name_usable' => (bool) $this->display_name_usable,
            'idv_claim' => $this->idv_claim,
            'idv_lock' => (bool) $this->idv_lock,
            // 선언한 쪽이 운영자 변경을 막은 속성 => 사유 문구
            'locks' => (object) $this->localizedLocks(),
            // 잠긴 속성 => 사유 코드. 표시 이름 설정이 건 잠금은 `nickname_display`, 선언한 쪽이 건 잠금은 `declared`
            'lock_reasons' => (object) $this->lockReasons(),
            'ui' => (object) ($this->ui ?? []),
            'sort_order' => (int) $this->sort_order,
            'supports' => [
                'signup' => (bool) $type?->supports(FieldTypeInterface::FEATURE_SIGNUP),
                'unique' => (bool) $type?->supports(FieldTypeInterface::FEATURE_UNIQUE) && $this->storage === FieldDefinition::STORAGE_VALUE,
                'search' => (bool) $type?->supports(FieldTypeInterface::FEATURE_SEARCH),
                'list_column' => (bool) $type?->supports(FieldTypeInterface::FEATURE_LIST_COLUMN),
                'display_name' => (bool) $type?->supports(FieldTypeInterface::FEATURE_DISPLAY_NAME),
                'options' => (bool) $type?->supports(FieldTypeInterface::FEATURE_OPTIONS),
            ],
            'user_overrides' => $this->user_overrides ?? [],
            ...$this->formatTimestamps(),
        ];
    }

    /**
     * 잠긴 속성마다의 사유 코드를 반환합니다.
     *
     * @return array<string, string> 속성 => 사유 코드 (nickname_display|declared)
     */
    protected function lockReasons(): array
    {
        $reasons = [];
        foreach ($this->locks ?? [] as $attribute => $reason) {
            $reasons[(string) $attribute] = $reason === NicknameRequirement::LOCK_MESSAGE_KEY
                ? NicknameRequirement::LOCK_REASON
                : 'declared';
        }

        return $reasons;
    }

    /**
     * 잠금 사유를 요청 로케일 문구로 풉니다.
     *
     * @return array<string, string> 속성 => 사유 문구
     */
    protected function localizedLocks(): array
    {
        $locks = [];
        foreach ($this->locks ?? [] as $attribute => $reason) {
            $translated = is_string($reason) && $reason !== '' ? __($reason) : __('fields.locks.default');
            $locks[(string) $attribute] = is_string($translated) ? $translated : (string) $reason;
        }

        return $locks;
    }
}
