<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

use App\Enums\ContentMediaKind;

/**
 * 본문 미디어 정책(`content_media_policy`) 하위 칸의 검증 표시명(:attribute)을 만듭니다 (#883).
 *
 * 정책 규칙은 코어 `ContentMediaPolicyRules::settingsRules()` 가 미디어 종류(이미지·동영상·오디오)마다
 * 칸을 펼쳐 만든다. 요청이 이름을 주지 않으면 기본 문구에 「override values.content media policy.max per
 * document 필드는…」처럼 필드 키가 그대로 보였다. 게시판 생성·수정·일괄 적용 요청이 같은 이름을 쓰도록
 * 접두(`content_media_policy`·`override_values.content_media_policy`)만 받아 한곳에서 만든다.
 * 종류 이름은 코어 문구(`attachment.content_media.kind.*`)를 그대로 써서 코어 범위 문구와 같은 낱말을 쓴다.
 */
trait NamesContentMediaPolicyFields
{
    /**
     * 본문 미디어 정책 칸 키 → 표시명.
     *
     * @param  string  $prefix  정책 필드 경로 (예: `content_media_policy`)
     * @return array<string, string> 칸 키 → 표시명
     */
    protected function contentMediaPolicyAttributes(string $prefix): array
    {
        $base = 'sirsoft-board::validation.attributes.content_media_policy';

        $attributes = [
            $prefix => __("{$base}.policy"),
            "{$prefix}.max_per_document" => __("{$base}.max_per_document"),
        ];

        foreach (ContentMediaKind::cases() as $kind) {
            $key = "{$prefix}.{$kind->value}";
            $replace = ['kind' => __("attachment.content_media.kind.{$kind->value}")];

            $attributes[$key] = __("{$base}.kind", $replace);
            $attributes["{$key}.enabled"] = __("{$base}.enabled", $replace);
            $attributes["{$key}.max_size_mb"] = __("{$base}.max_size_mb", $replace);
            $attributes["{$key}.extensions"] = __("{$base}.extensions", $replace);
            $attributes["{$key}.extensions.*"] = __("{$base}.extensions", $replace);
        }

        return $attributes;
    }
}
