<?php

namespace Plugins\Sirsoft\HeroSection\Support\Positions;

use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;

/**
 * 위치 정의 한 건 — 필터 `sirsoft-hero_section.position.registered_positions` 가 모은 배열을 검증해 만든다.
 *
 * 이름은 문구 키(프론트 다국어 키) 또는 언어별 객체다. 문구 키는 화면이 풀고, 언어별 객체는 서버가 현재 언어로 고른다.
 */
final class PositionDefinition
{
    /**
     * 위치 키 형식 — 확장점 이름이자 DB `position string(50)` 값
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,49}$/';

    /**
     * PositionDefinition 생성자
     *
     * @param  string  $key  위치 키
     * @param  string|array<string, string>  $label  이름(문구 키 또는 언어별 객체)
     * @param  int  $order  탭 정렬 순서
     * @param  string|null  $targetType  대상 종류 (정적 위치면 null)
     * @param  string|array<string, string>|null  $targetLabel  대상 이름
     * @param  class-string<TargetResolverInterface>|null  $resolver  대상 해석기 클래스
     */
    public function __construct(
        public readonly string $key,
        public readonly string|array $label,
        public readonly int $order = 1000,
        public readonly ?string $targetType = null,
        public readonly string|array|null $targetLabel = null,
        public readonly ?string $resolver = null,
    ) {}

    /**
     * 동적 위치인지 반환합니다.
     *
     * @return bool 대상이 있으면 true
     */
    public function isDynamic(): bool
    {
        return $this->targetType !== null;
    }

    /**
     * 이름의 문구 키를 반환합니다.
     *
     * @return string|null 문구 키 (언어별 객체로 받았으면 null)
     */
    public function labelKey(): ?string
    {
        return is_string($this->label) ? $this->label : null;
    }

    /**
     * 언어별 객체로 받은 이름을 현재 언어로 고릅니다.
     *
     * @param  string|null  $locale  언어 (기본 현재 언어)
     * @return string|null 이름 (문구 키로 받았으면 null)
     */
    public function label(?string $locale = null): ?string
    {
        return is_array($this->label) ? self::pick($this->label, $locale) : null;
    }

    /**
     * 대상 이름의 문구 키를 반환합니다.
     *
     * @return string|null 문구 키 (언어별 객체거나 정적 위치면 null)
     */
    public function targetLabelKey(): ?string
    {
        return is_string($this->targetLabel) ? $this->targetLabel : null;
    }

    /**
     * 언어별 객체로 받은 대상 이름을 현재 언어로 고릅니다.
     *
     * @param  string|null  $locale  언어 (기본 현재 언어)
     * @return string|null 대상 이름 (문구 키거나 정적 위치면 null)
     */
    public function targetLabel(?string $locale = null): ?string
    {
        return is_array($this->targetLabel) ? self::pick($this->targetLabel, $locale) : null;
    }

    /**
     * 언어별 객체에서 현재 언어 → 사이트 기본 언어 → en → 첫 값 순으로 고릅니다.
     *
     * @param  array<string, string>  $map  언어별 문구
     * @param  string|null  $locale  언어
     * @return string 문구
     */
    private static function pick(array $map, ?string $locale): string
    {
        foreach ([$locale ?? app()->getLocale(), config('app.site_locale', config('app.locale')), 'en'] as $candidate) {
            if (is_string($candidate) && is_string($map[$candidate] ?? null) && $map[$candidate] !== '') {
                return $map[$candidate];
            }
        }

        return (string) (array_values(array_filter($map, 'is_string'))[0] ?? '');
    }
}
