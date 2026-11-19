<?php

namespace App\Services;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameChoicePolicy;
use App\DisplayName\DisplayNameKindRegistry;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\DisplayNameSettings;
use App\DisplayName\NicknameRequirement;
use App\Fields\FieldSet;
use App\Models\User;

/**
 * 가입 화면과 내 정보 수정의 「표시 이름 종류 선택」 칸이 받는 묶음을 만드는 유스케이스.
 *
 * 항목 API(`GET /api/auth/register/fields`, `GET /api/me/fields`)의 `display_name` 키로 나간다.
 *
 * @since 7.2.0
 */
class DisplayNameChoiceService
{
    /** 미리보기에 쓸 입력값 키 (조회 인자) => 회원 속성 */
    public const PREVIEW_PARAMS = ['preview_name' => 'name', 'preview_nickname' => 'nickname', 'preview_email' => 'email'];

    /**
     * @param  DisplayNameChoicePolicy  $policy  종류 선택 판정
     * @param  DisplayNameSettings  $settings  표시 이름 설정
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기
     * @param  NicknameRequirement  $nicknameRequirement  닉네임 요구 판정
     * @param  DisplayNameSettingsService  $settingsService  표본 회원
     * @param  FieldDefinitionRepositoryInterface  $definitions  항목 정의 저장소 (지금은 종류가 아닌 추가 항목의 이름)
     */
    public function __construct(
        protected DisplayNameChoicePolicy $policy,
        protected DisplayNameSettings $settings,
        protected DisplayNameKindRegistry $kinds,
        protected DisplayNameResolver $resolver,
        protected NicknameRequirement $nicknameRequirement,
        protected DisplayNameSettingsService $settingsService,
        protected FieldDefinitionRepositoryInterface $definitions,
    ) {}

    /**
     * 종류 선택 칸의 묶음을 반환합니다.
     *
     * | 키 | 뜻 |
     * | --- | --- |
     * | can_choose | 이 사람이 종류를 고를 수 있는지 (권한이 있고 고를 종류가 둘 이상) |
     * | value | 회원이 고른 종류 키. 고르지 않았거나 고른 종류가 허용에서 빠졌으면 null |
     * | value_unavailable | 전에 고른 종류가 허용에서 빠졌는지 (화면이 「종류를 다시 골라 주세요」를 보인다) |
     * | unavailable_kind | 그때의 종류 `{value, label}` (아니면 null) |
     * | default_kind, default_kind_label | 고르지 않았을 때 적용되는 회원 기본 종류 |
     * | effective_kind | 지금 이 회원에게 적용되는 종류 |
     * | current | 지금 다른 회원에게 보이는 이름 (사이트 기본 영역. 가입 화면은 null) |
     * | options | 고를 수 있는 종류 `[{value, label, preview, requires_nickname, is_default}]`. 고를 수 없으면 빈 배열 |
     * | preview_is_sample | 미리보기를 표본 회원(홍길동)으로 계산했는지 |
     *
     * `options[].preview` 는 그 종류를 고르면 보일 글자다. 조회 인자 `preview_name`, `preview_nickname`, `preview_email` 을 주면
     * 입력 중인 값으로 계산하고, 가입 화면에서 아무것도 주지 않으면 표본 회원으로 계산한다. 가림 규칙은 서버 한 곳에 있다.
     *
     * @param  User|null  $user  회원 (가입 화면이면 null)
     * @param  array<string, mixed>  $params  조회 인자 (미리보기 입력값)
     * @return array<string, mixed> 종류 선택 묶음
     */
    public function bundle(?User $user, array $params = []): array
    {
        $canChoose = $user !== null ? $this->policy->allowedFor($user) : $this->policy->allowedForSignup();
        $selectable = $this->settings->selectableKinds();
        $default = $this->settings->memberDefaultKind();

        $chosen = $user?->getAttribute('display_name_kind');
        $chosen = is_string($chosen) && $chosen !== '' ? $chosen : null;
        $unavailable = $chosen !== null && ! in_array($chosen, $selectable, true);

        [$subject, $isSample] = $this->previewSubject($user, $params);

        $options = [];
        foreach ($canChoose ? $selectable : [] as $key) {
            $kind = $this->kinds->get($key);
            if ($kind === null) {
                continue;
            }
            $options[] = [
                'value' => $key,
                'label' => $kind->label(),
                'preview' => $this->resolver->computeAs($subject, $key),
                'requires_nickname' => $kind->requiresNickname(),
                'is_default' => $key === $default,
            ];
        }

        return [
            'can_choose' => $canChoose,
            'value' => $unavailable ? null : $chosen,
            'value_unavailable' => $unavailable,
            'unavailable_kind' => $unavailable ? ['value' => $chosen, 'label' => $this->kindLabel($chosen)] : null,
            'default_kind' => $default,
            'default_kind_label' => $this->kindLabel($default),
            'effective_kind' => $this->resolver->memberKind($user),
            'current' => $user !== null ? $this->resolver->resolve($user, DisplayNameAreaRegistry::AREA_SITE) : null,
            'options' => $options,
            'preview_is_sample' => $isSample,
            'nickname_requirement' => $this->nicknameRequirement->level(),
        ];
    }

    /**
     * 종류 이름을 반환합니다 (지금은 등록되지 않은 종류도 가능한 한 사람이 읽는 이름으로).
     *
     * @param  string  $key  종류 키
     * @return string 종류 이름 (알 수 없으면 키 그대로)
     */
    public function kindLabel(string $key): string
    {
        $kind = $this->kinds->get($key);
        if ($kind !== null) {
            return $kind->label();
        }

        // 「표시 이름으로 사용 가능」을 끈 추가 항목 — 항목이 남아 있으면 그 이름을 보인다.
        $fieldKey = DisplayNameKindRegistry::fieldKeyOf($key);
        if ($fieldKey !== null) {
            return $this->definitions->findByKey(FieldSet::CORE_USER, $fieldKey)?->localized('name') ?? $fieldKey;
        }

        $translationKey = 'display_name.kinds.'.$key;
        $label = __($translationKey);

        return is_string($label) && $label !== $translationKey ? $label : $key;
    }

    /**
     * 미리보기를 계산할 대상을 만듭니다 (저장하지 않는 사본).
     *
     * @param  User|null  $user  회원
     * @param  array<string, mixed>  $params  조회 인자
     * @return array{0: User, 1: bool} [대상, 표본 회원으로 계산하는지]
     */
    protected function previewSubject(?User $user, array $params): array
    {
        $given = [];
        foreach (self::PREVIEW_PARAMS as $param => $attribute) {
            // 빈 입력은 null 로 들어온다. 「지금 비어 있다」로 계산한다.
            if (array_key_exists($param, $params)) {
                $given[$attribute] = is_string($params[$param]) ? $params[$param] : '';
            }
        }

        if ($user === null && $given === []) {
            return [$this->settingsService->sampleMember(), true];
        }

        $subject = $user !== null ? clone $user : new User;
        if ($given !== []) {
            $subject->forceFill($given);
        }

        return [$subject, false];
    }
}
