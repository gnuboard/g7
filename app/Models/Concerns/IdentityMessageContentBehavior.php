<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * IDV(본인인증) 메시지 템플릿 컨텐츠 Trait.
 *
 * IdentityMessageTemplate 모델에서 사용하는 casts, 스코프, 다국어 헬퍼,
 * 변수 치환 메서드를 제공합니다. 알림 시스템(NotificationContentBehavior)과
 * 완전히 분리된 IDV 전용 트레이트입니다.
 */
trait IdentityMessageContentBehavior
{
    /**
     * 공통 casts 초기화.
     *
     * @return void
     */
    public function initializeIdentityMessageContentBehavior(): void
    {
        $this->mergeCasts([
            'subject' => 'array',
            'body' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);
    }

    /**
     * 활성 템플릿만 조회합니다.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 특정 채널의 템플릿을 조회합니다.
     *
     * @param  Builder  $query
     * @param  string  $channel
     * @return Builder
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * 현재 로케일의 제목을 반환합니다.
     *
     * @param  string|null  $locale
     * @return string
     */
    public function getLocalizedSubject(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $subject = $this->subject ?? [];

        return $subject[$locale] ?? $subject['ko'] ?? $subject['en'] ?? '';
    }

    /**
     * 현재 로케일의 본문을 반환합니다.
     *
     * @param  string|null  $locale
     * @return string
     */
    public function getLocalizedBody(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $body = $this->body ?? [];

        return $body[$locale] ?? $body['ko'] ?? $body['en'] ?? '';
    }

    /**
     * 변수를 치환하여 제목과 본문을 반환합니다.
     *
     * {key} 형식의 변수를 실제 값으로 치환합니다.
     *
     * @param  array  $data  key => value 변수 맵
     * @param  string|null  $locale
     * @return array{subject: string, body: string}
     */
    public function replaceVariables(array $data, ?string $locale = null): array
    {
        $subject = $this->getLocalizedSubject($locale);
        $body = $this->getLocalizedBody($locale);

        $replacements = [];
        foreach ($data as $key => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return [
            'subject' => strtr($subject, $replacements),
            'body' => strtr($body, $replacements),
        ];
    }

    /**
     * 단일 문자열의 변수를 치환합니다.
     *
     * @param  string  $template
     * @param  array  $data
     * @return string
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
