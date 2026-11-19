<?php

namespace App\Services;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;
use App\Fields\FieldContext;
use App\Fields\FieldFormSchema;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Helpers\TimezoneHelper;
use App\Models\FieldDefinition;
use App\Models\User;

/**
 * 회원 폼 화면(가입, 내 정보, 추가 정보, 관리자 회원 폼)이 받는 항목 계약 유스케이스.
 *
 * @since 7.2.0
 */
class MemberFieldSchemaService
{
    /**
     * @param  FieldFormSchema  $schema  항목 계약 생성기
     * @param  FieldRegistry  $registry  항목 등록소 (정의 버전)
     * @param  FieldValueService  $values  항목 값 Service (현재 값 읽기)
     * @param  UserLockedFieldsService  $lockedFields  회원 칸 수정 잠금
     * @param  NicknamePolicyService  $nicknamePolicy  닉네임 정책 (다음 변경 가능 시각)
     * @param  RequiredFieldsChecker  $requiredFields  비어 있는 필수 항목 판정
     * @param  DisplayNameChoiceService  $displayNameChoice  표시 이름 종류 선택 칸의 묶음
     * @param  DisplayNameResolver  $displayNames  표시 이름 계산기 (관리자 화면의 회원 상태)
     */
    public function __construct(
        protected FieldFormSchema $schema,
        protected FieldRegistry $registry,
        protected FieldValueService $values,
        protected UserLockedFieldsService $lockedFields,
        protected NicknamePolicyService $nicknamePolicy,
        protected RequiredFieldsChecker $requiredFields,
        protected DisplayNameChoiceService $displayNameChoice,
        protected DisplayNameResolver $displayNames,
    ) {}

    /**
     * 가입 화면이 그릴 항목 계약과 정의 버전을 반환합니다.
     *
     * 인자(`verification_token`, `pending_token`, `pending_source`)는 문맥의 부가 값으로 필터
     * `core.field.filter_definitions` 에 넘어간다 — 본인인증·소셜·SSO 확장이 초기값과 잠금을 채울 때 읽는다.
     *
     * 본인인증을 마치고 온 가입(`verification_token`)이면 인증 값으로 초기값(`default`)을 채우고, 「인증 값으로 채우고 잠금」을 켠 항목은
     * 읽기 전용(`readonly`, `locked_reason: identity`)으로 싣는다. 그 결과를 `verified_claims` 로 알려 준다 —
     * 토큰이 만료됐으면 잠금 없이 `status: expired` 가 온다.
     *
     * @param  array<string, mixed>  $params  검증된 조회 인자
     * @return array{fields: array<int, array<string, mixed>>, fields_version: string, display_name: array<string, mixed>, verified_claims: array{status: string, filled: array<int, string>, locked: array<int, string>}|null} 항목 계약 목록, 정의 버전, 표시 이름 종류 선택 묶음, 본인인증 채움 결과(토큰이 없거나 가입 전 본인인증 정책이 꺼져 있으면 null)
     */
    public function forSignup(array $params = []): array
    {
        $context = new FieldContext(
            surface: FieldContext::SURFACE_SIGNUP,
            // 미리보기 입력값은 표시 이름 묶음만 쓴다. 항목 문맥(확장 필터로 넘어간다)에는 싣지 않는다.
            extra: array_filter(
                array_diff_key($params, DisplayNameChoiceService::PREVIEW_PARAMS),
                fn ($value) => $value !== null && $value !== '',
            ),
        );

        $fields = $this->schema->forSurface(FieldSet::CORE_USER, $context);
        $verifiedClaims = $this->applyVerifiedClaims($fields, $params['verification_token'] ?? null, $context);

        return [
            'fields' => $fields,
            'fields_version' => $this->registry->version(FieldSet::CORE_USER),
            // 표시 이름 종류 선택 칸 — 고를 수 있는지, 고를 수 있는 종류와 종류별 미리보기
            'display_name' => $this->displayNameChoice->bundle(null, $params),
            // 본인인증 채움 결과 — 토큰 없이 부르거나 가입 전 본인인증 정책이 꺼져 있으면 null
            'verified_claims' => $verifiedClaims,
        ];
    }

    /**
     * 가입 화면 항목에 본인인증 값을 채우고 잠금 항목을 읽기 전용으로 바꿉니다.
     *
     * @param  array<int, array<string, mixed>>  $fields  항목 계약 목록 (제자리에서 고친다)
     * @param  mixed  $token  조회 인자의 본인인증 토큰
     * @param  FieldContext  $context  가입 표면 문맥
     * @return array{status: string, filled: array<int, string>, locked: array<int, string>}|null 채움 결과 (토큰이 없거나 가입 전 본인인증 정책이 꺼져 있으면 null)
     */
    protected function applyVerifiedClaims(array &$fields, mixed $token, FieldContext $context): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $result = app(IdentityClaimsService::class)->describeForToken($token, 'signup', null, $context);

        // 가입 전 본인인증 정책이 꺼진 사이트 — 가입 인증 값을 쓰지 않으므로 토큰이 없는 것과 같게 응답한다
        if ($result['status'] === IdentityClaimsService::STATUS_DISABLED) {
            return null;
        }

        foreach ($fields as $index => $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key) || ! array_key_exists($key, $result['fields']) || ($field['deferred'] ?? false)) {
                continue;
            }

            $fields[$index]['default'] = $result['fields'][$key];
            if (in_array($key, $result['locked'], true)) {
                $fields[$index]['readonly'] = true;
                $fields[$index]['locked_reason'] = IdentityClaimsService::LOCK_REASON;
            }
        }

        return [
            'status' => $result['status'],
            'filled' => array_keys($result['fields']),
            'locked' => $result['locked'],
        ];
    }

    /**
     * 추가 정보 입력 화면이 그릴 항목 계약과 정의 버전을 반환합니다.
     *
     * 그 회원에게 비어 있는 필수 항목만 준다. 미입력이 없으면 `fields` 가 빈 배열이다 — 화면은 입력을 받지 않고 원래 가려던 곳으로 보낸다.
     * 파일, 이미지 항목은 폼 저장이 아니라 파일 올리기 주소로 채운다(`files`, `file_rules` 가 함께 온다).
     *
     * @param  User  $user  본인
     * @return array{fields: array<int, array<string, mixed>>, fields_version: string, pending_required_fields: array<int, string>} 항목 계약 목록, 정의 버전, 비어 있는 필수 항목 키
     */
    public function forRequiredInfo(User $user): array
    {
        $pending = $this->requiredFields->pendingKeys($user, fresh: true);
        $context = new FieldContext(FieldContext::SURFACE_REQUIRED_INFO, owner: $user, actor: $user, onlyKeys: $pending);

        $fields = [];
        if ($pending !== []) {
            $definitions = $this->registry->forSurface(FieldSet::CORE_USER, $context);
            $current = $this->values->read($user, FieldSet::CORE_USER, $definitions);

            foreach ($definitions as $definition) {
                $type = $this->registry->typeOf($definition);
                if ($type !== null) {
                    $fields[] = $this->schema->describe($definition, $type, $context, $current[$definition->key] ?? null);
                }
            }
        }

        return [
            'fields' => $fields,
            'fields_version' => $this->registry->version(FieldSet::CORE_USER),
            'pending_required_fields' => $pending,
        ];
    }

    /**
     * 내 정보 수정·보기 화면이 그릴 항목 계약과 정의 버전을 반환합니다.
     *
     * 본인이 고칠 수 있는 항목(본인 수정 범위)과, 볼 수만 있는 항목(관리자만 수정 범위)을 함께 준다. 관리자만 열람 항목은 주지 않는다.
     * 잠긴 칸에는 `readonly` 와 사유(`locked_reason`)를 싣는다.
     *
     * | 사유 | 뜻 |
     * | --- | --- |
     * | admin_only | 관리자만 수정할 수 있는 항목 |
     * | sso, identity, locked 등 | 확장이 잠근 칸 (`UserLockedFieldsService::reasonsFor()`) |
     * | nickname_interval | 닉네임 변경 주기가 아직 지나지 않음 (`locked_until` 에 다시 바꿀 수 있는 시각) |
     *
     * @param  User  $user  본인
     * @param  array<string, mixed>  $params  검증된 조회 인자 (표시 이름 미리보기 입력값 `preview_name`, `preview_nickname`, `preview_email`)
     * @return array{fields: array<int, array<string, mixed>>, fields_version: string, display_name: array<string, mixed>} 항목 계약 목록, 정의 버전, 표시 이름 종류 선택 묶음
     */
    public function forProfile(User $user, array $params = []): array
    {
        $context = FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $user, $user);

        $editable = $this->registry->forSurface(FieldSet::CORE_USER, $context);
        $viewOnly = $this->registry->active(FieldSet::CORE_USER, null, $context)
            ->filter(fn (FieldDefinition $definition) => $definition->edit_scope === FieldDefinition::SCOPE_ADMIN_EDIT);
        $definitions = $editable->concat($viewOnly)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();

        $current = $this->values->read($user, FieldSet::CORE_USER, $definitions);
        $locks = $this->lockedFields->reasonsFor($user, UserLockedFieldsService::SURFACE_PROFILE);
        $nicknameNext = $this->nicknamePolicy->nextChangeableAt($user);
        // 본인인증 값으로 채워져 잠긴 항목 — 기본 칸은 위 잠금(`$locks`)에 이미 들어 있고, 여기서는 운영자 추가 항목까지 본다
        $identityLocked = app(IdentityClaimsService::class)->lockedKeysFor($user);

        $fields = [];
        foreach ($definitions as $definition) {
            $type = $this->registry->typeOf($definition);
            if ($type === null) {
                continue;
            }

            $field = $this->schema->describe($definition, $type, $context, $current[$definition->key] ?? null);
            $field['locked_until'] = null;

            if ($definition->edit_scope === FieldDefinition::SCOPE_ADMIN_EDIT) {
                $field = [...$field, 'readonly' => true, 'required' => false, 'locked_reason' => 'admin_only'];
            } elseif ($definition->storage === FieldDefinition::STORAGE_COLUMN && isset($locks[$definition->key])) {
                $field = [...$field, 'readonly' => true, 'locked_reason' => $locks[$definition->key]];
            } elseif (in_array($definition->key, $identityLocked, true)) {
                $field = [...$field, 'readonly' => true, 'locked_reason' => IdentityClaimsService::LOCK_REASON];
            } elseif ($definition->key === 'nickname' && $definition->storage === FieldDefinition::STORAGE_COLUMN && $nicknameNext !== null) {
                $field = [
                    ...$field,
                    'readonly' => true,
                    'locked_reason' => 'nickname_interval',
                    'locked_until' => $nicknameNext->copy()->setTimezone(TimezoneHelper::getUserTimezone())->format('Y-m-d H:i'),
                ];
            }

            $fields[] = $field;
        }

        return [
            'fields' => $fields,
            'fields_version' => $this->registry->version(FieldSet::CORE_USER),
            // 표시 이름 종류 선택 칸 — 고른 종류, 고를 수 있는 종류와 미리보기, 고른 종류가 허용에서 빠졌는지
            'display_name' => $this->displayNameChoice->bundle($user, $params),
        ];
    }

    /**
     * 관리자 회원 폼(추가, 수정)과 회원 상세가 그릴 항목 계약과 정의 버전을 반환합니다.
     *
     * 사용 중인 항목 전부를 준다 — 관리자만 수정, 관리자만 열람 항목 포함. 항목마다 범위(`edit_scope`)를 실어 화면이 배지로 보인다.
     * 회원을 주면(수정, 상세) 현재 값의 보기용 글자와 올라간 파일, 외부가 관리해 관리자도 못 바꾸는 칸의 잠금,
     * 그리고 그 회원의 항목 상태(`member`)가 함께 온다. 회원이 없으면(추가) 파일 항목은 `deferred` 로 싣는다 — 저장한 뒤에 올린다.
     *
     * @param  User|null  $target  대상 회원 (추가 화면이면 null)
     * @param  User|null  $actor  요청한 관리자
     * @return array{fields: array<int, array<string, mixed>>, fields_version: string, member: array<string, mixed>|null} 항목 계약, 정의 버전, 회원의 항목 상태
     */
    public function forAdmin(?User $target, ?User $actor = null): array
    {
        $context = FieldContext::for(
            $target !== null ? FieldContext::SURFACE_ADMIN_UPDATE : FieldContext::SURFACE_ADMIN_CREATE,
            $target,
            $actor,
        );

        $definitions = $this->registry->forSurface(FieldSet::CORE_USER, $context);
        $current = $target !== null ? $this->values->read($target, FieldSet::CORE_USER, $definitions) : [];
        $locks = $target !== null ? $this->lockedFields->reasonsFor($target, UserLockedFieldsService::SURFACE_ADMIN) : [];

        $fields = [];
        foreach ($definitions as $definition) {
            $type = $this->registry->typeOf($definition);
            if ($type === null) {
                continue;
            }

            $field = $this->schema->describe($definition, $type, $context, $current[$definition->key] ?? null);
            $field['edit_scope'] = $definition->edit_scope;

            if ($target === null && $this->values->isFileField($definition)) {
                $field = [...$field, 'deferred' => true, 'required' => false];
            } elseif ($definition->storage === FieldDefinition::STORAGE_COLUMN && isset($locks[$definition->key])) {
                $field = [...$field, 'readonly' => true, 'locked_reason' => $locks[$definition->key]];
            }

            $fields[] = $field;
        }

        return [
            'fields' => $fields,
            'fields_version' => $this->registry->version(FieldSet::CORE_USER),
            'member' => $target !== null ? $this->memberStatus($target) : null,
        ];
    }

    /**
     * 관리자 화면이 읽기 전용으로 보이는 회원의 항목 상태를 반환합니다.
     *
     * @param  User  $user  대상 회원
     * @return array{nickname_changed_at: string|null, nickname_next_changeable_at: string|null, required_fields_deferred_at: string|null, pending_required_fields: array<int, array{key: string, label: string}>} 닉네임 마지막 변경 시각, 본인이 다시 바꿀 수 있는 시각, 필수 항목을 건너뛰고 만들어진 시각, 비어 있는 필수 항목
     */
    protected function memberStatus(User $user): array
    {
        $timezone = TimezoneHelper::getUserTimezone();
        $format = fn ($time) => $time?->copy()->setTimezone($timezone)->format('Y-m-d H:i');

        return [
            'nickname_changed_at' => $format($user->nickname_changed_at),
            'nickname_next_changeable_at' => $format($this->nicknamePolicy->nextChangeableAt($user)),
            'required_fields_deferred_at' => $format($user->required_fields_deferred_at),
            'pending_required_fields' => $this->requiredFields->describe($this->requiredFields->pendingKeys($user)),
            // 표시 이름(읽기 전용) — 회원이 고른 종류, 지금 적용되는 종류, 사이트 기본 영역과 관리자 영역에서 보이는 이름
            ...$this->displayNameStatus($user),
        ];
    }

    /**
     * 관리자 화면이 읽기 전용으로 보이는 회원의 표시 이름 상태를 반환합니다 (관리자는 회원의 종류를 바꾸지 못한다).
     *
     * @param  User  $user  대상 회원
     * @return array{display_name_kind: string|null, display_name_kind_label: string|null, display_name_kind_unavailable: bool, display_name_effective_kind: string, display_name_effective_kind_label: string, display_name: string|null, display_name_admin: string|null} 고른 종류와 이름, 허용에서 빠졌는지, 적용되는 종류와 이름, 사이트 기본 영역과 관리자 영역에서 보이는 이름
     */
    protected function displayNameStatus(User $user): array
    {
        $choice = $this->displayNameChoice->bundle($user);
        $chosen = $choice['value'] ?? $choice['unavailable_kind']['value'] ?? null;

        return [
            'display_name_kind' => $chosen,
            'display_name_kind_label' => $chosen !== null ? $this->displayNameChoice->kindLabel($chosen) : null,
            'display_name_kind_unavailable' => (bool) $choice['value_unavailable'],
            'display_name_effective_kind' => $choice['effective_kind'],
            'display_name_effective_kind_label' => $this->displayNameChoice->kindLabel($choice['effective_kind']),
            'display_name' => $choice['current'],
            'display_name_admin' => $this->displayNames->resolve($user, DisplayNameAreaRegistry::AREA_ADMIN),
        ];
    }
}
