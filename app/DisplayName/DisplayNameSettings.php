<?php

namespace App\DisplayName;

use App\DisplayName\Kinds\NameKind;

/**
 * 표시 이름 설정(환경설정 > 회원 > 표시 이름)을 읽는 한 곳.
 *
 * 설정 범주 `member` 의 세 키를 읽는다.
 *
 * | 키 | 모양 |
 * | --- | --- |
 * | display_name_selectable_kinds | 회원이 고를 수 있는 종류 키 목록 |
 * | display_name_member_default_kind | 회원이 고르지 않았을 때의 종류 키 |
 * | display_name_areas | `{영역 키: {mode, kind}}` |
 *
 * 저장 파일은 기본값과 범주 단위로 얕게 합쳐진다. 그래서 `display_name_areas` 를 한 번이라도 저장한 사이트는
 * 그 뒤 생긴 영역의 기본값이 묶음 안에 없다. 여기서 없는 영역을 영역의 선언 기본값으로 채워 읽고,
 * 지금은 없는 종류(꺼진 확장, 지워진 추가 항목)를 가리키는 값은 「따름」으로 읽는다.
 *
 * @since 7.2.0
 */
class DisplayNameSettings
{
    /** 설정 키: 회원이 고를 수 있는 종류 */
    public const KEY_SELECTABLE = 'member.display_name_selectable_kinds';

    /** 설정 키: 회원 기본 종류 */
    public const KEY_MEMBER_DEFAULT = 'member.display_name_member_default_kind';

    /** 설정 키: 영역별 방식 */
    public const KEY_AREAS = 'member.display_name_areas';

    /** 어떤 설정도 쓸 수 없을 때의 종류 */
    public const FALLBACK_KIND = NameKind::KEY;

    /**
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  DisplayNameAreaRegistry  $areas  영역 등록소
     */
    public function __construct(
        protected DisplayNameKindRegistry $kinds,
        protected DisplayNameAreaRegistry $areas,
    ) {}

    /**
     * 회원이 고를 수 있는 종류 키를 반환합니다 (지금 등록된 종류만).
     *
     * @return array<int, string> 종류 키 목록 (설정이 비었거나 전부 사라졌으면 회원 기본 종류 하나)
     */
    public function selectableKinds(): array
    {
        $kinds = array_values(array_unique(array_filter(
            $this->rawSelectableKinds(),
            fn (string $key) => $this->kinds->has($key),
        )));

        return $kinds !== [] ? $kinds : [$this->memberDefaultKind()];
    }

    /**
     * 설정에 저장된 「회원이 고를 수 있는 종류」를 그대로 반환합니다 (지금 없는 종류 포함).
     *
     * @return array<int, string> 종류 키 목록
     */
    public function rawSelectableKinds(): array
    {
        $stored = g7_core_settings(self::KEY_SELECTABLE, [self::FALLBACK_KIND]);

        return is_array($stored)
            ? array_values(array_filter($stored, fn ($key) => is_string($key) && $key !== ''))
            : [self::FALLBACK_KIND];
    }

    /**
     * 회원이 고르지 않았을 때의 종류 키를 반환합니다.
     *
     * @return string 종류 키 (설정 값이 지금 없는 종류면 「이름」)
     */
    public function memberDefaultKind(): string
    {
        $stored = $this->rawMemberDefaultKind();

        return $this->kinds->has($stored) ? $stored : self::FALLBACK_KIND;
    }

    /**
     * 설정에 저장된 회원 기본 종류를 그대로 반환합니다.
     *
     * @return string 종류 키
     */
    public function rawMemberDefaultKind(): string
    {
        $stored = g7_core_settings(self::KEY_MEMBER_DEFAULT, self::FALLBACK_KIND);

        return is_string($stored) && $stored !== '' ? $stored : self::FALLBACK_KIND;
    }

    /**
     * 설정에 저장된 영역 묶음을 그대로 반환합니다 (지금 없는 영역 포함).
     *
     * @return array<string, array<string, mixed>> 영역 키 => 저장 값
     */
    public function storedAreas(): array
    {
        $stored = g7_core_settings(self::KEY_AREAS, []);

        return is_array($stored) ? array_filter($stored, 'is_array') : [];
    }

    /**
     * 영역의 설정을 반환합니다.
     *
     * 저장 값이 없으면 영역의 선언 기본값을 쓴다. 고정인데 종류가 지금 없으면 「따름」으로 읽고,
     * 뿌리 영역(`core:site`)은 따를 부모가 없으므로 「이름」 고정으로 읽는다.
     *
     * @param  DisplayNameArea  $area  영역
     * @return array{mode: string, kind: string|null} 방식과 종류
     */
    public function areaSetting(DisplayNameArea $area): array
    {
        $stored = $this->storedAreas()[$area->key] ?? null;
        $setting = $this->normalize(is_array($stored) ? $stored : $area->defaultSetting());

        if ($setting['mode'] === DisplayNameArea::MODE_INHERIT && $area->key === DisplayNameAreaRegistry::AREA_SITE) {
            return ['mode' => DisplayNameArea::MODE_FIXED, 'kind' => self::FALLBACK_KIND];
        }

        return $setting;
    }

    /**
     * 등록된 모든 영역의 설정을 반환합니다 (운영자 화면과 사용처 판정용).
     *
     * @return array<string, array{mode: string, kind: string|null}> 영역 키 => 방식과 종류
     */
    public function allAreaSettings(): array
    {
        $settings = [];
        foreach ($this->areas->all() as $area) {
            $settings[$area->key] = $this->areaSetting($area);
        }

        return $settings;
    }

    /**
     * 설정 값을 쓸 수 있는 모양으로 맞춥니다.
     *
     * @param  array<string, mixed>  $setting  저장 값 또는 인스턴스 설정
     * @return array{mode: string, kind: string|null} 방식과 종류 (쓸 수 없는 값은 따름)
     */
    public function normalize(array $setting): array
    {
        $mode = $setting['mode'] ?? null;

        if ($mode === DisplayNameArea::MODE_MEMBER) {
            return ['mode' => DisplayNameArea::MODE_MEMBER, 'kind' => null];
        }

        if ($mode === DisplayNameArea::MODE_FIXED && is_string($setting['kind'] ?? null) && $this->kinds->has($setting['kind'])) {
            return ['mode' => DisplayNameArea::MODE_FIXED, 'kind' => $setting['kind']];
        }

        return ['mode' => DisplayNameArea::MODE_INHERIT, 'kind' => null];
    }
}
