<?php

namespace App\Fields;

/**
 * 7.1.x 템플릿 화면이 보내던 코어 회원 칸 (옛 화면 호환의 기준).
 *
 * 회원 항목을 모르는 옛 화면(7.1.x 템플릿)에서 온 요청은 그 화면에 입력칸이 없는 항목의 필수 규칙을 「값을 보냈을 때만」으로 늦춘다
 * ({@see FieldRuleFactory::rules()}). 운영자 추가 항목과 확장 선언 항목은 늘 늦추고, 코어 항목은 아래 목록에 없는 것만 늦춘다.
 * 목록에 있는 코어 칸은 옛 화면에도 입력칸이 있었으므로 규칙을 그대로 건다.
 *
 * 근거는 7.1.6 태그의 번들 레이아웃이다(입력칸 `name` 을 코어 항목의 저장 컬럼으로 맞춘 것. 주소는 zipcode, address, address_detail).
 *
 * | 표면 | 7.1.6 레이아웃 | 코어 칸 |
 * | --- | --- | --- |
 * | 가입 | `templates/_bundled/sirsoft-basic/layouts/partials/auth/_register_form.json` | name, nickname, mobile, phone, language |
 * | 내 정보 수정 | `templates/_bundled/sirsoft-basic/layouts/partials/mypage/profile/_edit.json` | 코어 11칸 전부 |
 * | 관리자 회원 추가, 수정 | `templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.json` | 코어 11칸 전부 (국가는 `field_country` 복합 입력이 `form.country` 에 쓴다) |
 *
 * 시험(`tests/Unit/Fields/LegacyScreenFieldsTest.php`)이 7.1.6 태그와 저장소의 7.1.3 서빙 고정물에서 칸을 다시 뽑아 이 목록과 대조한다.
 * 7.2.0 뒤에 생긴 코어 항목은 어느 목록에도 없으므로 옛 화면에서 늘 늦춰진다.
 *
 * @since 7.2.0
 */
final class LegacyScreenFields
{
    /** 7.1.x 의 코어 회원 칸 전부 (내 정보 수정, 관리자 폼이 모두 그렸다) */
    private const ALL_7_1 = ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'address', 'signature', 'bio'];

    /** 표면 => 7.1.x 화면이 보내던 코어 항목 키 */
    public const CORE_KEYS = [
        FieldContext::SURFACE_SIGNUP => ['name', 'nickname', 'mobile', 'phone', 'language'],
        FieldContext::SURFACE_SELF_UPDATE => self::ALL_7_1,
        FieldContext::SURFACE_ADMIN_CREATE => self::ALL_7_1,
        FieldContext::SURFACE_ADMIN_UPDATE => self::ALL_7_1,
    ];

    /**
     * 그 표면의 7.1.x 화면이 보내던 코어 항목 키를 반환합니다.
     *
     * @param  string  $surface  표면
     * @return array<int, string>|null 코어 항목 키 목록 (7.1.x 에 없던 표면이면 null — 코어 항목을 늦추지 않는다)
     */
    public static function coreKeysFor(string $surface): ?array
    {
        return self::CORE_KEYS[$surface] ?? null;
    }
}
