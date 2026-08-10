<?php

namespace App\Support;

/**
 * 레이아웃 설명(`meta.description`) 표시 해석의 SSoT.
 *
 * 레이아웃의 설명은 그 레이아웃을 **소유한 템플릿**의 사전 키를 쓴다 — 유저 템플릿
 * 레이아웃이면 `$t:user.base_layout_description` 처럼. 그런데 코드 편집 화면은 관리자
 * 템플릿(`sirsoft-admin_basic`) 사전으로 렌더하므로 `user.*` 네임스페이스를 알지 못한다.
 * 값을 그대로 내보내면 파일 목록과 선택 파일 헤더에 번역 토큰이 원문으로 노출된다
 * (실측: 저장소 레이아웃 519개 중 83개가 `$t:` 토큰 또는 표현식 형태).
 *
 * 그래서 서버가 소유 템플릿 사전으로 해석하고, 해석할 수 없으면 레이아웃 이름으로
 * 폴백한다 — 사람이 읽을 수 없는 내부 표기를 보여주느니 파일명이 낫다.
 *
 * 목록(`LayoutListResource`)과 상세(`LayoutResource`)가 같은 규칙을 써야 하므로 이 클래스
 * 하나로 모은다. 한쪽만 고치면 목록에서는 번역되고 헤더에서는 토큰이 보이는 불일치가 된다.
 *
 * @since 7.0.6
 */
class LayoutDescription
{
    /** 프론트엔드 다국어 토큰 접두사 (`$t:key`) */
    private const TRANSLATION_PREFIX = '$t:';

    /**
     * 표시용 설명을 해석합니다.
     *
     * @param  mixed  $description  원본 설명 (평문 · `$t:` 토큰 · `{{...}}` 표현식 · null)
     * @param  string  $name  레이아웃 이름 (해석 불가 시 폴백)
     * @param  array<string, mixed>  $translations  소유 템플릿의 프론트엔드 다국어 데이터
     * @return string 표시용 설명
     */
    public static function resolve(mixed $description, string $name, array $translations = []): string
    {
        if (! is_string($description) || $description === '') {
            return $name;
        }

        // 런타임 컨텍스트(`route` 등)가 필요한 식은 이 시점에 해석할 수 없다.
        // 예: `{{route.id ? '$t:board.edit' : '$t:board.new'}}`
        if (str_contains($description, '{{')) {
            return $name;
        }

        if (! str_starts_with($description, self::TRANSLATION_PREFIX)) {
            return $description;
        }

        $key = substr($description, strlen(self::TRANSLATION_PREFIX));
        $resolved = data_get($translations, $key);

        return is_string($resolved) && $resolved !== '' ? $resolved : $name;
    }
}
