<?php

namespace App\DisplayName;

use App\Fields\FieldSet;
use App\Models\FieldDefinition;
use Illuminate\Support\Facades\Log;

/**
 * 표시 이름 설정이 닉네임 항목에 거는 요구.
 *
 * | 수준 | 조건 | 닉네임 항목 |
 * | --- | --- | --- |
 * | all | 어떤 영역(또는 영역의 인스턴스)이 닉네임이 필요한 종류로 **고정** | 사용, 필수로 잠근다 (전원 필수) |
 * | chosen | 「회원이 고른 값」 방식을 쓰는 영역이 있고, 회원이 고를 수 있는 종류나 회원 기본 종류에 닉네임이 필요한 종류가 있다 | 사용만 잠근다. 그 종류로 보이는 회원만 요청 검증에서 필수 |
 * | none | 그 밖 | 건드리지 않는다 |
 *
 * 항목 등록소가 항목을 내줄 때 이 요구를 적용하므로(`FieldRegistry`), 검증 규칙, 화면 항목 계약, 운영자 항목 표가 같은 값을 본다.
 * 저장된 항목 행은 바꾸지 않는다. 표시 이름 설정을 되돌리면 운영자가 정해 둔 사용, 필수 값이 그대로 돌아온다.
 *
 * 추가 항목 종류(`field:{키}`)는 닉네임을 요구하지 않으므로 판정에 항목 등록소를 읽지 않는다
 * (항목 등록소가 이 클래스를 부르므로 서로를 부르면 안 된다).
 *
 * @since 7.2.0
 */
class NicknameRequirement
{
    /** 수준: 전원 필수 */
    public const LEVEL_ALL = 'all';

    /** 수준: 닉네임이 필요한 종류로 보이는 회원만 필수 */
    public const LEVEL_CHOSEN = 'chosen';

    /** 수준: 요구 없음 */
    public const LEVEL_NONE = 'none';

    /** 잠금 사유 코드 (화면이 사유별 문구를 고른다) */
    public const LOCK_REASON = 'nickname_display';

    /** 잠금 사유 문구의 번역 키 */
    public const LOCK_MESSAGE_KEY = 'display_name.locks.nickname_display';

    /** 닉네임 항목 키 */
    public const NICKNAME_FIELD = 'nickname';

    /**
     * 판정해 둔 수준 (null 이면 아직 판정하지 않음).
     */
    protected ?string $level = null;

    /**
     * 수준을 판정한 때의 설정 값 지문 (설정이 바뀌면 다시 판정한다).
     */
    protected ?string $signature = null;

    /**
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameAreaRegistry  $areas  영역 등록소
     * @param  DisplayNameSettings  $settings  표시 이름 설정
     */
    public function __construct(
        protected DisplayNameKindRegistry $kinds,
        protected DisplayNameAreaRegistry $areas,
        protected DisplayNameSettings $settings,
    ) {}

    /**
     * 지금 설정이 닉네임에 거는 요구 수준을 반환합니다.
     *
     * @return string LEVEL_ALL | LEVEL_CHOSEN | LEVEL_NONE
     */
    public function level(): string
    {
        $signature = md5(serialize([
            $this->settings->storedAreas(),
            $this->settings->rawSelectableKinds(),
            $this->settings->rawMemberDefaultKind(),
        ]));
        if ($this->level === null || $this->signature !== $signature) {
            $this->signature = $signature;
            try {
                $this->level = $this->determine();
            } catch (\Throwable $e) {
                // 설치 전, 마이그레이션 전 구간에서는 영역의 인스턴스 설정을 읽지 못할 수 있다. 요구 없음으로 본다.
                Log::debug('닉네임 요구 판정: 표시 이름 설정을 읽지 못했습니다', ['error' => $e->getMessage()]);
                $this->level = self::LEVEL_NONE;
            }
        }

        return $this->level;
    }

    /**
     * 종류가 닉네임을 필요로 하는지 반환합니다.
     *
     * @param  string|null  $kind  종류 키
     * @return bool 닉네임이 필요하면 true (지금 없는 종류와 추가 항목 종류는 false)
     */
    public function kindRequiresNickname(?string $kind): bool
    {
        if ($kind === null || $kind === '') {
            return false;
        }

        return ($this->kinds->staticKinds()[$kind] ?? null)?->requiresNickname() === true;
    }

    /**
     * 항목이 이 요구로 잠기는 속성과 사유 문구 키를 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<string, string> 속성(enabled, required) => 사유 문구의 번역 키 (잠기지 않으면 빈 배열)
     */
    public function locksFor(FieldDefinition $definition): array
    {
        if (! $this->isNicknameField($definition)) {
            return [];
        }

        return match ($this->level()) {
            self::LEVEL_ALL => ['enabled' => self::LOCK_MESSAGE_KEY, 'required' => self::LOCK_MESSAGE_KEY],
            self::LEVEL_CHOSEN => ['enabled' => self::LOCK_MESSAGE_KEY],
            default => [],
        };
    }

    /**
     * 항목에 이 요구를 적용합니다 (닉네임 항목의 사용, 필수를 고정하고 잠금을 싣는다).
     *
     * @param  FieldDefinition  $definition  항목 정의 (호출자가 넘긴 사본을 고친다)
     * @return FieldDefinition 요구를 적용한 항목
     */
    public function apply(FieldDefinition $definition): FieldDefinition
    {
        $locks = $this->locksFor($definition);
        if ($locks === []) {
            return $definition;
        }

        $definition->enabled = true;
        if (isset($locks['required'])) {
            $definition->required = true;
        }
        $definition->locks = [...($definition->locks ?? []), ...$locks];

        return $definition;
    }

    /**
     * 판정해 둔 수준을 버립니다 (설정을 저장한 뒤와 테스트용).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->level = null;
        $this->signature = null;
    }

    /**
     * 회원 집합의 닉네임 컬럼 항목인지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return bool 닉네임 항목이면 true
     */
    protected function isNicknameField(FieldDefinition $definition): bool
    {
        return $definition->field_set === FieldSet::CORE_USER
            && $definition->key === self::NICKNAME_FIELD
            && $definition->storage === FieldDefinition::STORAGE_COLUMN;
    }

    /**
     * 설정을 읽어 수준을 판정합니다.
     *
     * @return string 수준
     */
    protected function determine(): string
    {
        $stored = $this->settings->storedAreas();
        $memberModeUsed = false;

        foreach ($this->areas->all() as $area) {
            $setting = is_array($stored[$area->key] ?? null) ? $stored[$area->key] : $area->defaultSetting();
            foreach ([$setting, ...$area->instanceUsages()] as $candidate) {
                $mode = $candidate['mode'] ?? null;
                if ($mode === DisplayNameArea::MODE_FIXED && $this->kindRequiresNickname($candidate['kind'] ?? null)) {
                    return self::LEVEL_ALL;
                }
                $memberModeUsed = $memberModeUsed || $mode === DisplayNameArea::MODE_MEMBER;
            }
        }

        // 「회원이 고른 값」 방식을 쓰는 영역이 하나도 없으면 고를 수 있는 종류는 어디에도 보이지 않는다.
        if (! $memberModeUsed) {
            return self::LEVEL_NONE;
        }

        foreach ([...$this->settings->rawSelectableKinds(), $this->settings->rawMemberDefaultKind()] as $kind) {
            if ($this->kindRequiresNickname($kind)) {
                return self::LEVEL_CHOSEN;
            }
        }

        return self::LEVEL_NONE;
    }
}
