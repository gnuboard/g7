<?php

namespace App\Models;

use App\Enums\FieldEditScope;
use App\Enums\FieldSourceType;
use App\Enums\FieldStorageType;
use App\Models\Concerns\HasUserOverrides;
use App\Support\LocalizedValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 항목 집합에 속한 입력 항목 한 건.
 *
 * 코어·확장이 선언한 항목(source_type = core|module|plugin)과 운영자가 만든 항목(custom)을 같은 표에 담는다.
 * 운영자가 고친 속성은 user_overrides 에 기록되어 선언 재동기화에서 보존된다.
 *
 * @since 7.2.0
 *
 * @property int $id
 * @property string $field_set
 * @property string $key
 * @property string $source_type
 * @property string $source_identifier
 * @property string $type
 * @property string $storage
 * @property string|null $storage_target
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property array<string, string>|null $placeholder
 * @property array<int, array<string, mixed>>|null $options
 * @property array<string, mixed>|null $constraints
 * @property bool $enabled
 * @property bool $required
 * @property bool $show_on_signup
 * @property string $edit_scope
 * @property bool $require_existing_members
 * @property bool $public_profile
 * @property bool $admin_list_column
 * @property bool $admin_searchable
 * @property bool $display_name_usable
 * @property string|null $idv_claim
 * @property bool $idv_lock
 * @property array<string, string>|null $locks
 * @property array<int, string>|null $legacy_keys
 * @property array<string, mixed>|null $ui
 * @property int $sort_order
 * @property array<int, string>|null $user_overrides
 */
class FieldDefinition extends Model
{
    use HasUserOverrides;

    /** 출처: 코어 선언 */
    public const SOURCE_CORE = FieldSourceType::Core->value;

    /** 출처: 모듈 선언 */
    public const SOURCE_MODULE = FieldSourceType::Module->value;

    /** 출처: 플러그인 선언 */
    public const SOURCE_PLUGIN = FieldSourceType::Plugin->value;

    /** 출처: 운영자가 만든 항목 */
    public const SOURCE_CUSTOM = FieldSourceType::Custom->value;

    /** 저장 방식: 소유 테이블의 컬럼 */
    public const STORAGE_COLUMN = FieldStorageType::Column->value;

    /** 저장 방식: 확장 핸들러 */
    public const STORAGE_HANDLER = FieldStorageType::Handler->value;

    /** 저장 방식: 공용 값 테이블 */
    public const STORAGE_VALUE = FieldStorageType::Value->value;

    /** 범위: 본인 수정 */
    public const SCOPE_SELF = FieldEditScope::Self_->value;

    /** 범위: 관리자만 수정(본인은 열람) */
    public const SCOPE_ADMIN_EDIT = FieldEditScope::AdminEdit->value;

    /** 범위: 관리자만 열람 */
    public const SCOPE_ADMIN_ONLY = FieldEditScope::AdminOnly->value;

    /** 항목 키 형식 — 운영자 추가 항목과 확장 선언 항목 공통 */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    protected $table = 'field_definitions';

    protected $fillable = [
        'field_set', 'key', 'source_type', 'source_identifier', 'type', 'storage', 'storage_target',
        'name', 'description', 'placeholder', 'options', 'constraints',
        'enabled', 'required', 'show_on_signup', 'edit_scope', 'require_existing_members',
        'public_profile', 'admin_list_column', 'admin_searchable', 'display_name_usable',
        'idv_claim', 'idv_lock', 'locks', 'legacy_keys', 'ui', 'sort_order', 'user_overrides',
    ];

    /**
     * ActivityLog 추적 필드.
     *
     * @var array<int, string>
     */
    protected array $activityLogFields = [
        'key', 'type', 'enabled', 'required', 'show_on_signup', 'edit_scope', 'require_existing_members',
        'public_profile', 'admin_list_column', 'admin_searchable', 'display_name_usable', 'sort_order',
    ];

    /**
     * 운영자가 고칠 수 있는 속성 — 선언 재동기화에서 보존한다.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = [
        'name', 'description', 'placeholder', 'options', 'constraints',
        'enabled', 'required', 'show_on_signup', 'edit_scope', 'require_existing_members',
        'public_profile', 'admin_list_column', 'admin_searchable', 'display_name_usable',
        'idv_claim', 'idv_lock', 'sort_order',
    ];

    /**
     * 다국어 JSON 속성 — 로케일 단위로 보존한다.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name', 'description', 'placeholder'];

    /**
     * 속성 캐스팅을 정의합니다.
     *
     * @return array<string, string> 속성 => 캐스트 타입
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'placeholder' => 'array',
            'options' => 'array',
            'constraints' => 'array',
            'enabled' => 'boolean',
            'required' => 'boolean',
            'show_on_signup' => 'boolean',
            'require_existing_members' => 'boolean',
            'public_profile' => 'boolean',
            'admin_list_column' => 'boolean',
            'admin_searchable' => 'boolean',
            'display_name_usable' => 'boolean',
            'idv_lock' => 'boolean',
            'locks' => 'array',
            'legacy_keys' => 'array',
            'ui' => 'array',
            'sort_order' => 'integer',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 이 항목의 값 행들.
     *
     * @return HasMany<FieldValue, $this> 값 관계
     */
    public function values(): HasMany
    {
        return $this->hasMany(FieldValue::class, 'field_definition_id');
    }

    /**
     * 운영자가 만든 항목인지 반환합니다.
     *
     * @return bool 운영자 추가 항목이면 true
     */
    public function isCustom(): bool
    {
        return $this->source_type === self::SOURCE_CUSTOM;
    }

    /**
     * 제한 값 하나를 반환합니다.
     *
     * @param  string  $key  제한 키 (예: max_length)
     * @param  mixed  $default  없을 때 기본값
     * @return mixed 제한 값
     */
    public function constraint(string $key, mixed $default = null): mixed
    {
        return ($this->constraints ?? [])[$key] ?? $default;
    }

    /**
     * 선언한 쪽이 운영자 변경을 막은 속성인지 반환합니다.
     *
     * @param  string  $attribute  속성명 (enabled, required 등)
     * @return bool 잠겨 있으면 true
     */
    public function isLocked(string $attribute): bool
    {
        return array_key_exists($attribute, $this->locks ?? []);
    }

    /**
     * 요청 로케일로 푼 다국어 속성 값을 반환합니다.
     *
     * 요청 로케일 → 기본 로케일 → 첫 값 순으로 찾는다.
     *
     * @param  string  $attribute  다국어 속성명 (name|description|placeholder)
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string|null 풀린 문자열
     */
    public function localized(string $attribute, ?string $locale = null): ?string
    {
        return self::localizeValue($this->getAttribute($attribute), $locale);
    }

    /**
     * 다국어 배열에서 로케일에 맞는 문자열을 고릅니다.
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string|null 풀린 문자열
     */
    public static function localizeValue(mixed $value, ?string $locale = null): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        if (! is_array($value) || $value === []) {
            return null;
        }

        // 요청 언어 → 사이트 기본 언어 → 영어 → 아무 언어 (공개 #148 D7 — 값 표시 대체 순서의 단일 기준)
        return LocalizedValue::pick($value, $locale);
    }
}
