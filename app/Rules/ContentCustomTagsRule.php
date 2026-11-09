<?php

namespace App\Rules;

use App\Support\Content\ContentHtmlPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 본문 허용 태그 「운영자 자유 추가」 검증 (#883 5단계, D19)
 *
 * 형식 `^[a-z][a-z0-9-]{0,31}$`. 스크립트·외부 문서 삽입·폼처럼 절대 허용하지 않는 태그(`ContentHtmlPolicy::REJECTED_CUSTOM_TAGS`)는
 * 저장을 거절하고 사유를 알린다. 그 밖(svg·math·details·canvas 등)은 허용하되 화면이 「보안 위험」 경고를 늘 보인다. 속성 정화
 * (`on*`·`javascript:`)는 추가 태그에도 항상 한다.
 *
 * @since 7.1.5
 */
class ContentCustomTagsRule implements ValidationRule
{
    /** 최대 개수 */
    public const MAX_TAGS = 50;

    /**
     * 목록을 검증합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value) || count($value) > self::MAX_TAGS) {
            $fail(__('validation.settings.content_custom_tag_list', ['max' => self::MAX_TAGS]));

            return;
        }

        foreach ($value as $tag) {
            $tag = is_string($tag) ? strtolower(trim($tag)) : '';
            if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $tag) !== 1) {
                $fail(__('validation.settings.content_custom_tag_format', ['tag' => is_string($tag) ? $tag : '']));

                continue;
            }
            $reason = ContentHtmlPolicy::REJECTED_CUSTOM_TAGS[$tag] ?? null;
            if ($reason !== null) {
                $fail(__('validation.settings.content_custom_tag_rejected', [
                    'tag' => $tag,
                    'reason' => __('validation.settings.content_custom_tag_reasons.'.$reason),
                ]));
            }
        }
    }
}
