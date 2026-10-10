<?php

namespace App\Enums;

/**
 * 컴포넌트 매니페스트(`components.json`)를 소유한 주체의 종류.
 *
 * 템플릿과 확장(모듈/플러그인)은 같은 `components.json` 스키마를 쓰지만 소유자를 적는
 * 필드 이름이 다르다 — 템플릿은 `templateId`, 확장은 `identifier`. 두 표기를 그대로 둔 채
 * 컴파일러가 하나의 "소유자" 개념으로 정규화하기 위한 분류다(런타임 검증이 템플릿
 * 매니페스트의 `templateId` 를 필수로 보므로 필드명 자체는 통일하지 않는다 —
 * ComponentRegistry.ts:297).
 *
 * 소유자 종류에 따라 매니페스트가 선언할 수 있는 것이 갈린다:
 *  - 오버라이드(`overrides`) 선언 권한은 **템플릿 전용**이다. 레이아웃 오버라이드가
 *    `source_type=template` 일 때만 성립하는 것과 같은 규약(migration `:22`).
 *  - 템플릿 전용 판본(`for`) 선언은 **확장 전용**이다. 템플릿이 자기 컴포넌트의
 *    "다른 템플릿용 판본" 을 내는 것은 뜻이 성립하지 않는다.
 */
enum ComponentOwnerKind: string
{
    /**
     * 템플릿이 소유 — 매니페스트가 `templateId` 로 자신을 밝힌다.
     */
    case Template = 'template';

    /**
     * 모듈·플러그인이 소유 — 매니페스트가 `identifier` 로 자신을 밝힌다.
     */
    case Extension = 'extension';

    /**
     * 이 소유자가 자신을 밝히는 매니페스트 필드명을 반환합니다.
     *
     * @return string 매니페스트 필드명 (`templateId` 또는 `identifier`)
     */
    public function ownerField(): string
    {
        return match ($this) {
            self::Template => 'templateId',
            self::Extension => 'identifier',
        };
    }

    /**
     * 이 소유자가 컴포넌트 오버라이드를 선언할 수 있는지 판정합니다.
     *
     * @return bool 선언 권한이 있으면 true (템플릿만)
     */
    public function mayDeclareOverrides(): bool
    {
        return $this === self::Template;
    }

    /**
     * 이 소유자가 템플릿 전용 판본을 선언할 수 있는지 판정합니다.
     *
     * @return bool 선언 권한이 있으면 true (확장만)
     */
    public function mayDeclareForVariants(): bool
    {
        return $this === self::Extension;
    }
}
