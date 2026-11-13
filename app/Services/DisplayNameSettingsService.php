<?php

namespace App\Services;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameKindRegistry;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\DisplayNameSettings;
use App\DisplayName\NicknameRequirement;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 환경설정 > 회원 > 표시 이름 구획이 쓰는 유스케이스.
 *
 * 화면이 고를 종류와 영역 목록, 닉네임이 비어 있는 회원 수 안내, 저장에 딸린 처리(영역 묶음 보존, 닉네임 입력 요구 켜기,
 * 이름이 구워진 캐시 비우기), 추가 항목의 표시 이름 사용처를 맡는다.
 *
 * @since 7.2.0
 */
class DisplayNameSettingsService
{
    /** 저장하지 않는 지시 값의 키 — 닉네임 항목의 「기존 회원에게도 입력 요구」를 함께 켠다 */
    public const NICKNAME_REQUIRE_KEY = 'nickname_require_existing_members';

    /** 미리보기에 쓰는 표본 회원의 아이디 */
    public const SAMPLE_ID = 'gildong';

    /**
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameAreaRegistry  $areas  영역 등록소
     * @param  DisplayNameSettings  $settings  표시 이름 설정
     * @param  DisplayNameResolver  $resolver  표시 이름 계산기 (미리보기)
     * @param  NicknameRequirement  $nicknameRequirement  닉네임 요구 판정
     * @param  FieldRegistry  $fields  항목 등록소
     * @param  FieldDefinitionRepositoryInterface  $definitions  항목 정의 저장소
     * @param  FieldDefinitionService  $fieldDefinitions  항목 관리 Service (닉네임 입력 요구 켜기)
     * @param  UserRepositoryInterface  $users  회원 저장소
     */
    public function __construct(
        protected DisplayNameKindRegistry $kinds,
        protected DisplayNameAreaRegistry $areas,
        protected DisplayNameSettings $settings,
        protected DisplayNameResolver $resolver,
        protected NicknameRequirement $nicknameRequirement,
        protected FieldRegistry $fields,
        protected FieldDefinitionRepositoryInterface $definitions,
        protected FieldDefinitionService $fieldDefinitions,
        protected UserRepositoryInterface $users,
    ) {}

    /**
     * 표시 이름 구획이 그릴 종류와 영역 목록, 지금 설정을 반환합니다.
     *
     * 종류의 `preview` 는 표본 회원(이름, 닉네임, 아이디)을 그 종류로 계산한 글자다. 가림 규칙이 서버와 같게 보이도록 서버가 만든다.
     *
     * @return array{kinds: array<int, array<string, mixed>>, areas: array<int, array<string, mixed>>, selectable_kinds: array<int, string>, member_default_kind: string, sample: array{name: string, nickname: string, id: string}, nickname_requirement: string} 화면 데이터
     */
    public function options(): array
    {
        $sample = $this->sampleMember();

        $kinds = [];
        foreach ($this->kinds->all() as $key => $kind) {
            [$ownerType, $owner] = $this->kinds->ownerOf($key);
            $kinds[] = [
                'key' => $key,
                'label' => $kind->label(),
                'owner_type' => $ownerType,
                'owner' => $owner,
                'requires_nickname' => $kind->requiresNickname(),
                'searchable' => $kind->searchColumns() !== [] || DisplayNameKindRegistry::fieldKeyOf($key) !== null,
                'fallback' => $kind->fallback(),
                // 추가 항목 종류는 표본 값이 없어 항목 이름을 그대로 보인다.
                'preview' => $this->resolver->computeAs($sample, $key, ['field_values' => $this->sampleFieldValues()]),
            ];
        }

        $areas = [];
        foreach ($this->areas->tree() as ['area' => $area, 'depth' => $depth]) {
            $setting = $this->settings->areaSetting($area);
            $rule = $this->resolver->areaRule($area->key);
            $areas[] = [
                'key' => $area->key,
                'label' => $area->label(),
                'parent' => $area->key === DisplayNameAreaRegistry::AREA_SITE ? null : ($area->parent ?? DisplayNameAreaRegistry::AREA_SITE),
                'depth' => $depth,
                'audience' => $area->audience,
                'owner_type' => $area->ownerType,
                'owner' => $area->ownerIdentifier,
                // 사이트 기본 영역은 따를 부모가 없다 — 화면이 「따름」 선택지를 감춘다.
                'can_inherit' => $area->key !== DisplayNameAreaRegistry::AREA_SITE,
                'mode' => $setting['mode'],
                'kind' => $setting['kind'],
                'default' => $area->defaultSetting(),
                // 상속을 다 따라간 결과 — 「따름」인 영역이 지금 무엇으로 보이는지 화면이 곁들여 보인다.
                'effective' => ['mode' => $rule['mode'], 'kind' => $rule['kind'], 'source' => $rule['source']],
            ];
        }

        return [
            'kinds' => $kinds,
            'areas' => $areas,
            'selectable_kinds' => $this->settings->selectableKinds(),
            'member_default_kind' => $this->settings->memberDefaultKind(),
            'sample' => [
                'name' => (string) $sample->name,
                'nickname' => (string) $sample->nickname,
                // 표본 회원의 아이디(이메일의 @ 앞부분) — 실제 회원의 값이 아니다
                'id' => self::SAMPLE_ID,
            ],
            'nickname_requirement' => $this->nicknameRequirement->level(),
        ];
    }

    /**
     * 닉네임이 비어 있는 회원 수와 닉네임 항목의 지금 상태를 반환합니다 (닉네임 종류를 고르기 전 안내).
     *
     * @return array{nickname_empty_count: int, nickname: array{enabled: bool, required: bool, require_existing_members: bool}, nickname_requirement: string} 안내 값
     */
    public function nicknameEmptyCount(): array
    {
        $stored = $this->definitions->findByKey(FieldSet::CORE_USER, NicknameRequirement::NICKNAME_FIELD);
        $presented = $this->fields->find(FieldSet::CORE_USER, NicknameRequirement::NICKNAME_FIELD);

        return [
            'nickname_empty_count' => $this->users->countMembersWithEmptyColumns([NicknameRequirement::NICKNAME_FIELD]),
            'nickname' => [
                // 운영자가 저장해 둔 값(표시 이름 설정이 거는 고정을 적용하기 전)
                'enabled' => (bool) ($stored?->enabled ?? $presented?->enabled ?? false),
                'required' => (bool) ($stored?->required ?? $presented?->required ?? false),
                'require_existing_members' => (bool) ($stored?->require_existing_members ?? $presented?->require_existing_members ?? false),
            ],
            'nickname_requirement' => $this->nicknameRequirement->level(),
        ];
    }

    /**
     * 회원 탭 저장 데이터를 저장할 모양으로 맞춥니다.
     *
     * - 저장하지 않는 지시 값(`nickname_require_existing_members`)을 떼어 낸다.
     * - 영역 묶음은 저장 파일에서 통째로 바뀌므로, 화면이 보내지 않은 영역(꺼진 확장의 영역)의 기존 값을 보존해 합친다.
     *
     * @param  array<string, mixed>  $member  회원 탭 저장 데이터
     * @return array{0: array<string, mixed>, 1: bool} [저장할 데이터, 닉네임 입력 요구를 함께 켤지]
     */
    public function prepareForSave(array $member): array
    {
        $requireNickname = filter_var($member[self::NICKNAME_REQUIRE_KEY] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($member[self::NICKNAME_REQUIRE_KEY]);

        if (is_array($member['display_name_areas'] ?? null)) {
            $submitted = [];
            foreach ($member['display_name_areas'] as $key => $setting) {
                if (! is_string($key) || ! is_array($setting)) {
                    continue;
                }
                $mode = in_array($setting['mode'] ?? null, DisplayNameArea::MODES, true) ? $setting['mode'] : DisplayNameArea::MODE_INHERIT;
                $submitted[$key] = $mode === DisplayNameArea::MODE_FIXED
                    ? ['mode' => $mode, 'kind' => (string) ($setting['kind'] ?? '')]
                    : ['mode' => $mode];
            }

            $preserved = array_filter(
                $this->settings->storedAreas(),
                fn (string $key) => ! $this->areas->has($key) && ! isset($submitted[$key]),
                ARRAY_FILTER_USE_KEY,
            );
            $member['display_name_areas'] = $preserved + $submitted;
        }

        return [$member, $requireNickname];
    }

    /**
     * 회원 탭 저장 뒤의 처리를 합니다.
     *
     * - 이름이 구워진 검색 엔진용 미리 그린 화면을 전부 비운다(표시 이름 설정이 바뀌면 모든 화면의 작성자 이름이 달라질 수 있다).
     * - 닉네임 입력 요구를 함께 켜라는 지시가 있었으면 닉네임 항목의 「기존 회원에게도 입력 요구」를 켠다.
     *
     * @param  bool  $requireNickname  닉네임 입력 요구를 함께 켤지
     * @return void 반환값 없음
     */
    public function afterSave(bool $requireNickname): void
    {
        $this->nicknameRequirement->flush();

        try {
            app(SeoCacheManagerInterface::class)->clearAll();
        } catch (\Throwable $e) {
            // 캐시 삭제 실패가 설정 저장을 되돌리지는 않는다 — 유효 시간이 지나거나 수동 삭제로 회복된다.
            Log::warning('회원 설정 저장 후 SEO 캐시 삭제 실패 — seo:clear 로 수동 삭제 필요', ['error' => $e->getMessage()]);
        }

        if ($requireNickname) {
            $this->requireNicknameFromExistingMembers();
        }
    }

    /**
     * 닉네임 항목의 「기존 회원에게도 입력 요구」를 켭니다.
     *
     * @return bool 켰으면(또는 이미 켜져 있으면) true, 저장된 닉네임 항목이 없으면 false
     */
    public function requireNicknameFromExistingMembers(): bool
    {
        $nickname = $this->definitions->findByKey(FieldSet::CORE_USER, NicknameRequirement::NICKNAME_FIELD);
        if ($nickname === null) {
            return false;
        }

        if (! $nickname->require_existing_members) {
            $this->fieldDefinitions->update($nickname, ['require_existing_members' => true]);
        }

        return true;
    }

    /**
     * 추가 항목이 표시 이름 종류로 쓰이는 곳을 반환합니다 (항목 삭제를 막고 어디서 쓰이는지 알릴 때).
     *
     * | type | 뜻 |
     * | --- | --- |
     * | selectable_kind | 회원이 고를 수 있는 종류에 들어 있다 |
     * | member_default_kind | 회원 기본 종류다 |
     * | area | 영역이 이 종류로 고정돼 있다 (`area` 에 영역 키) |
     * | area_instance | 영역의 인스턴스(게시판 등)가 이 종류로 고정돼 있다 |
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, array{type: string, label: string, area: string|null}> 사용처 목록 (쓰이지 않으면 빈 배열)
     */
    public function usagesOf(FieldDefinition $definition): array
    {
        if ($definition->field_set !== FieldSet::CORE_USER) {
            return [];
        }

        $kind = DisplayNameKindRegistry::fieldKindKey($definition->key);
        $usages = [];

        if (in_array($kind, $this->settings->rawSelectableKinds(), true)) {
            $usages[] = ['type' => 'selectable_kind', 'label' => __('display_name.usages.selectable_kind'), 'area' => null];
        }
        if ($this->settings->rawMemberDefaultKind() === $kind) {
            $usages[] = ['type' => 'member_default_kind', 'label' => __('display_name.usages.member_default_kind'), 'area' => null];
        }

        $stored = $this->settings->storedAreas();
        foreach ($this->areas->all() as $area) {
            $setting = is_array($stored[$area->key] ?? null) ? $stored[$area->key] : $area->defaultSetting();
            if (($setting['mode'] ?? null) === DisplayNameArea::MODE_FIXED && ($setting['kind'] ?? null) === $kind) {
                $usages[] = ['type' => 'area', 'label' => __('display_name.usages.area', ['area' => $area->label()]), 'area' => $area->key];
            }
            foreach ($area->instanceUsages() as $instance) {
                if ($instance['mode'] === DisplayNameArea::MODE_FIXED && $instance['kind'] === $kind) {
                    $usages[] = [
                        'type' => 'area_instance',
                        'label' => __('display_name.usages.area_instance', ['area' => $area->label(), 'instance' => $instance['label'] ?? '']),
                        'area' => $area->key,
                    ];
                }
            }
        }

        return $usages;
    }

    /**
     * 미리보기에 쓰는 표본 회원을 만듭니다 (저장하지 않는다).
     *
     * @return User 표본 회원
     */
    public function sampleMember(): User
    {
        return (new User)->forceFill([
            'name' => __('display_name.sample.name'),
            'nickname' => __('display_name.sample.nickname'),
            'email' => self::SAMPLE_ID.'@example.com', // audit:allow resource-member-email-ungated 미리보기용 표본 회원의 가짜 주소(실제 회원 아님, 응답에는 아이디만 싣는다)
        ]);
    }

    /**
     * 미리보기에 쓰는 추가 항목 표본 값을 만듭니다 (항목 이름을 값 자리에 보인다).
     *
     * @return array<string, string> 항목 키 => 표본 값
     */
    protected function sampleFieldValues(): array
    {
        $values = [];
        foreach ($this->kinds->fieldKinds() as $kind) {
            $values[$kind->definition()->key] = $kind->label();
        }

        return $values;
    }
}
