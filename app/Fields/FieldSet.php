<?php

namespace App\Fields;

use App\Enums\FieldSourceType;
use App\Models\FieldDefinition;

/**
 * 항목 집합 한 건 — 항목들이 모이는 자리(회원, 게시판 비회원 작성자, 주문서 등)의 선언.
 *
 * @since 7.2.0
 */
final class FieldSet
{
    /** 코어 회원 항목 집합 */
    public const CORE_USER = 'core.user';

    /** 운영자 추가 항목의 요청 묶음 키 (회원 집합) */
    public const CUSTOM_BUNDLE = 'custom_fields';

    /**
     * @param  string  $key  집합 식별자 (예: core.user, sirsoft-board.guest_author)
     * @param  string  $ownerType  집합을 가진 쪽의 종류 (core|module|plugin)
     * @param  string  $ownerIdentifier  집합을 가진 쪽의 식별자 (core 또는 확장 식별자)
     * @param  string  $label  운영자 화면에 보일 이름 (번역 키 또는 문자열)
     * @param  array<int, string>  $ownerTypes  값 소유자 종류 (field_values.owner_type 으로 쓰는 값)
     * @param  array<int, string>  $surfaces  이 집합이 쓰는 표면 (signup, self_update 등)
     * @param  array<int, string>|null  $allowedTypes  허용 유형 키 (null 이면 전부)
     * @param  bool  $acceptsContributions  다른 확장의 항목 기여를 받는지
     * @param  bool  $allowsCustom  운영자가 항목을 추가할 수 있는지
     * @param  string  $readPermission  항목 표 조회 권한
     * @param  string  $managePermission  항목 표 수정 권한
     * @param  string|null  $bundleKey  컬럼이 아닌 모든 항목이 들어가는 요청 묶음 키 (예: guest_fields). null 이면 선언 항목은 최상위, 운영자 추가 항목은 custom_fields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $ownerType,
        public readonly string $ownerIdentifier,
        public readonly string $label,
        public readonly array $ownerTypes = [],
        public readonly array $surfaces = [],
        public readonly ?array $allowedTypes = null,
        public readonly bool $acceptsContributions = true,
        public readonly bool $allowsCustom = true,
        public readonly string $readPermission = 'core.settings.read',
        public readonly string $managePermission = 'core.settings.update',
        public readonly ?string $bundleKey = null,
    ) {}

    /**
     * 선언 배열로 집합을 만듭니다.
     *
     * @param  array<string, mixed>  $data  선언 (key 필수)
     * @param  string  $ownerType  선언한 쪽의 종류
     * @param  string  $ownerIdentifier  선언한 쪽의 식별자
     * @return self 집합
     */
    public static function fromArray(array $data, string $ownerType, string $ownerIdentifier): self
    {
        return new self(
            key: (string) $data['key'],
            ownerType: $ownerType,
            ownerIdentifier: $ownerIdentifier,
            label: (string) ($data['label'] ?? $data['key']),
            ownerTypes: array_values((array) ($data['owner_types'] ?? [])),
            surfaces: array_values((array) ($data['surfaces'] ?? [])),
            allowedTypes: isset($data['allowed_types']) ? array_values((array) $data['allowed_types']) : null,
            acceptsContributions: (bool) ($data['accepts_contributions'] ?? true),
            allowsCustom: (bool) ($data['allows_custom'] ?? true),
            readPermission: (string) ($data['read_permission'] ?? 'core.settings.read'),
            managePermission: (string) ($data['manage_permission'] ?? 'core.settings.update'),
            bundleKey: isset($data['bundle_key']) ? (string) $data['bundle_key'] : null,
        );
    }

    /**
     * 이 집합이 유형을 받는지 반환합니다.
     *
     * @param  string  $type  유형 키
     * @return bool 받으면 true
     */
    public function allowsType(string $type): bool
    {
        return $this->allowedTypes === null || in_array($type, $this->allowedTypes, true);
    }

    /**
     * 항목의 요청 속성 경로를 반환합니다.
     *
     * - 컬럼 저장 항목: 키 그대로(최상위, 기존 요청 키 유지)
     * - 묶음 키가 있는 집합: `{묶음 키}.{키}`
     * - 그 밖: 선언 항목은 최상위, 운영자 추가 항목은 `custom_fields.{키}`
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return string 요청 속성 경로 (오류 키와 폼 경로도 같다)
     */
    public function attributeFor(FieldDefinition $definition): string
    {
        if ($definition->storage === FieldDefinition::STORAGE_COLUMN) {
            return $definition->key;
        }
        if ($this->bundleKey !== null) {
            return $this->bundleKey.'.'.$definition->key;
        }

        return $definition->source_type === FieldSourceType::Custom->value
            ? self::CUSTOM_BUNDLE.'.'.$definition->key
            : $definition->key;
    }

    /**
     * 번역한 집합 이름을 반환합니다.
     *
     * @return string 집합 이름
     */
    public function localizedLabel(): string
    {
        $translated = __($this->label);

        return is_string($translated) ? $translated : $this->label;
    }
}
