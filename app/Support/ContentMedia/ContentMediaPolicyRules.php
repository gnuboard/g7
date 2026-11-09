<?php

namespace App\Support\ContentMedia;

use App\Enums\ContentMediaKind;
use Illuminate\Validation\Rule;

/**
 * 본문 미디어 정책 설정값의 검증 규칙 — 확장 설정 FormRequest 가 함께 쓴다 (#883 4단계)
 *
 * 게시판(게시판별 `content_media_policy`)·페이지·이커머스(모듈 설정 `content_media`)가 같은 형식을 검증하게 한다.
 * 확장자는 종류별 후보 안에서만 고른다. 정책 전체를 비우면(null) 확장의 첨부 정책을 따른다.
 *
 * @since 7.1.5
 */
final class ContentMediaPolicyRules
{
    /**
     * 검증 규칙.
     *
     * @param  string  $prefix  정책 필드 경로 (예: `content_media_policy`)
     * @return array<string, array<int, mixed>> 규칙
     */
    public static function settingsRules(string $prefix): array
    {
        $rules = [
            $prefix => ['nullable', 'array'],
            "{$prefix}.max_per_document" => ['nullable', 'integer', 'min:1', 'max:'.ContentMediaPolicy::MAX_PER_DOCUMENT_LIMIT],
        ];

        foreach (ContentMediaKind::cases() as $kind) {
            $key = "{$prefix}.{$kind->value}";
            $rules[$key] = ['nullable', 'array'];
            $rules["{$key}.enabled"] = ['nullable', 'boolean'];
            $rules["{$key}.max_size_mb"] = ['nullable', 'integer', 'min:1', 'max:'.ContentMediaPolicy::MAX_SIZE_MB_LIMIT];
            $rules["{$key}.extensions"] = ['nullable', 'array'];
            $rules["{$key}.extensions.*"] = ['string', Rule::in($kind->candidateExtensions())];
        }

        return $rules;
    }

    /**
     * 설정 화면이 보낸 정책 값을 검증 전에 정리합니다.
     *
     * 화면 조각(`_content_media_policy.g7.html`)은 「직접 정하기」 스위치를 `use_custom` 으로 보낸다. 꺼져 있거나 값이 없으면 null
     * (첨부 정책을 따름)로 바꾸고, 스위치가 문자열(`"on"`·`"off"`)로 와도 불리언으로 맞춘다. 확장자 목록이 쉼표 문자열이면 배열로 바꾼다.
     *
     * @param  mixed  $value  요청 값
     * @return array<string, mixed>|null 정리한 값 (null: 첨부 정책을 따름)
     */
    public static function normalizeInput(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        if (array_key_exists('use_custom', $value)) {
            if (! filter_var($value['use_custom'], FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }
            // 켜짐 표지는 저장값에 남긴다 — 설정 응답을 그대로 폼에 싣는 화면(모듈 설정)이 다시 열 때 스위치가 켜진 채 보이게.
            // 정책 해석(`ContentMediaPolicy::fromArray`)은 모르는 키를 무시한다
            $value['use_custom'] = true;
        }

        foreach (ContentMediaKind::cases() as $kind) {
            if (! is_array($value[$kind->value] ?? null)) {
                continue;
            }
            $entry = $value[$kind->value];
            if (array_key_exists('enabled', $entry) && ! is_bool($entry['enabled'])) {
                $entry['enabled'] = filter_var($entry['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($entry['extensions']) && is_string($entry['extensions'])) {
                $entry['extensions'] = array_values(array_filter(array_map('trim', explode(',', $entry['extensions'])), fn ($e) => $e !== ''));
            }
            if (isset($entry['extensions']) && is_array($entry['extensions'])) {
                $entry['extensions'] = array_values(array_map(fn ($e) => strtolower(ltrim(trim((string) $e), '.')), $entry['extensions']));
            }
            $value[$kind->value] = $entry;
        }

        return $value;
    }

    /**
     * 저장된 정책을 설정 화면용으로 바꿉니다 — 값이 있으면 `use_custom: true` 를 붙이고, 없으면 null.
     *
     * @param  array<string, mixed>|null  $stored  저장 값
     * @return array<string, mixed>|null 화면 값
     */
    public static function toFormValue(?array $stored): ?array
    {
        return $stored === null || $stored === [] ? null : ['use_custom' => true] + $stored;
    }

    /**
     * 검증 문구.
     *
     * @param  string  $prefix  정책 필드 경로
     * @return array<string, string> 문구
     */
    public static function messages(string $prefix): array
    {
        $messages = [
            "{$prefix}.max_per_document.min" => __('attachment.content_media.validation.max_per_document_range', ['max' => ContentMediaPolicy::MAX_PER_DOCUMENT_LIMIT]),
            "{$prefix}.max_per_document.max" => __('attachment.content_media.validation.max_per_document_range', ['max' => ContentMediaPolicy::MAX_PER_DOCUMENT_LIMIT]),
        ];

        foreach (ContentMediaKind::cases() as $kind) {
            $label = __("attachment.content_media.kind.{$kind->value}");
            $messages["{$prefix}.{$kind->value}.max_size_mb.min"] = __('attachment.content_media.validation.max_size_range', ['kind' => $label, 'max' => ContentMediaPolicy::MAX_SIZE_MB_LIMIT]);
            $messages["{$prefix}.{$kind->value}.max_size_mb.max"] = __('attachment.content_media.validation.max_size_range', ['kind' => $label, 'max' => ContentMediaPolicy::MAX_SIZE_MB_LIMIT]);
            $messages["{$prefix}.{$kind->value}.extensions.*.in"] = __('attachment.content_media.validation.extension_not_candidate', [
                'kind' => $label,
                'extensions' => implode(', ', $kind->candidateExtensions()),
            ]);
        }

        return $messages;
    }
}
