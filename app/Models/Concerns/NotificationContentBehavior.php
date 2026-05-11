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
     * @param Builder $query 쿼리 빌더
     * @return Builder 필터링된 쿼리
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 유형의 템플릿을 조회합니다.
     *
     * @param Builder $query 쿼리 빌더
     * @param string $type 템플릿 유형
     * @return Builder 필터링된 쿼리
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * 현재 로케일의 제목을 반환합니다.
     *
     * @param string|null $locale 로케일 (null이면 현재 앱 로케일)
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
     * @param string|null $locale 로케일 (null이면 현재 앱 로케일)
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
     * @param array $data key => value 변수 맵
     * @param string|null $locale 로케일 (null이면 현재 앱 로케일)
     * @return array{subject: string, body: string} 치환된 제목과 본문
     */
    public function replaceVariables(array $data, ?string $locale = null): array
    {
        $subject = $this->getLocalizedSubject($locale);
        $body = $this->getLocalizedBody($locale);

        $replacements = [];
        foreach ($data as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($body, $replacements),
        ];
    }

    /**
     * 단일 문자열의 변수를 치환합니다.
     *
     * click_url 등 다국어가 아닌 단일 문자열에 {key} 패턴을 치환합니다.
     *
     * @param string $template 치환 대상 문자열 (예: /mypage/orders/{order_number})
     * @param array $data 변수 데이터
     * @return string 치환된 문자열
     */
    public function replaceVariablesInString(string $template, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($template, $replacements);
    }
}
