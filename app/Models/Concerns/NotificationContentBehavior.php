<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * 알림 템플릿 컨텐츠 Trait.
 *
 * NotificationTemplate 모델에서 공통으로 사용하는 casts, 스코프, 다국어 헬퍼,
 * 변수 치환 메서드를 제공합니다.
 */
trait NotificationContentBehavior
{
    /**
     * 공통 casts를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    public function initializeNotificationContentBehavior(): void
    {
        $this->mergeCasts([
            'subject' => 'array',
            'body' => 'array',
            'variables' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);
    }

    /**
     * 활성 템플릿만 조회합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터링된 쿼리
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 유형의 템플릿을 조회합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $type  템플릿 유형
     * @return Builder 필터링된 쿼리
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * 현재 로케일의 제목을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (null이면 현재 앱 로케일)
     * @return string 로케일에 맞는 제목
     */
    public function getLocalizedSubject(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $subject = $this->subject ?? [];

        return $subject[$locale] ?? $subject[config('app.fallback_locale', 'ko')] ?? '';
    }

    /**
     * 현재 로케일의 본문을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (null이면 현재 앱 로케일)
     * @return string 로케일에 맞는 본문
     */
    public function getLocalizedBody(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $body = $this->body ?? [];

        return $body[$locale] ?? $body[config('app.fallback_locale', 'ko')] ?? '';
    }

    /**
     * 변수를 치환하여 제목과 본문을 반환합니다.
     *
     * {key} 형식의 변수를 실제 값으로 치환합니다.
     *
     * @param  array  $data  key => value 변수 맵
     * @param  string|null  $locale  로케일 (null이면 현재 앱 로케일)
     * @return array{subject: string, body: string} 치환된 제목과 본문
     */
    public function replaceVariables(array $data, ?string $locale = null): array
    {
        $subject = $this->getLocalizedSubject($locale);
        $body = $this->getLocalizedBody($locale);

        $replacements = [];
        foreach ($data as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($this->unwrapBlockPlaceholderParagraphs($body, $replacements), $replacements),
        ];
    }

    /**
     * 블록 HTML 을 값으로 받는 자리표시자만 든 문단의 `<p>` 를 걷습니다.
     *
     * 메일 본문 편집기는 최상위에 놓인 글자를 문단으로 감싸므로, 기본 본문의 `{notice_sections}{privacy_policy_link}`
     * 같은 블록 자리표시자가 저장 뒤 `<p>{…}{…}</p>` 가 된다. 값이 `<h3>`·`<p>` 같은 블록 HTML 이면 치환 결과가
     * 문단 안에 블록이 든 잘못된 HTML(`<p><h3>`)이 되어 메일 프로그램마다 다르게 그려졌다(7.1.0 실측 M1-O8).
     * 자리표시자와 공백만 든 문단이고 치환 값에 블록 태그가 있을 때만 걷는다 — 글자가 함께 든 문단, 인라인 값은 그대로다.
     *
     * @param  string  $body  치환 전 본문
     * @param  array<string, string>  $replacements  `{key}` => 값
     * @return string 블록 자리표시자 문단을 걷은 본문(아직 치환 전)
     */
    private function unwrapBlockPlaceholderParagraphs(string $body, array $replacements): string
    {
        if ($replacements === [] || stripos($body, '<p') === false) {
            return $body;
        }

        return preg_replace_callback(
            '/<p(?:\s[^>]*)?>((?:\s|&nbsp;|\{[A-Za-z0-9_.]+\})*)<\/p>/u',
            function (array $match) use ($replacements): string {
                $inner = $match[1];
                if (! preg_match('/\{[A-Za-z0-9_.]+\}/', $inner)) {
                    return $match[0];
                }

                $rendered = strtr($inner, $replacements);

                return preg_match('/<(?:p|div|h[1-6]|ul|ol|li|table|blockquote|pre|hr|section|article|figure)\b/i', $rendered)
                    ? $inner
                    : $match[0];
            },
            $body,
        ) ?? $body;
    }

    /**
     * 단일 문자열의 변수를 치환합니다.
     *
     * click_url 등 다국어가 아닌 단일 문자열에 {key} 패턴을 치환합니다.
     *
     * @param  string  $template  치환 대상 문자열 (예: /mypage/orders/{order_number})
     * @param  array  $data  변수 데이터
     * @return string 치환된 문자열
     */
    public function replaceVariablesInString(string $template, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($template, $replacements);
    }
}
