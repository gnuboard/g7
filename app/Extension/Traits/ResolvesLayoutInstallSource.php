<?php

namespace App\Extension\Traits;

/**
 * 레이아웃 설치 소스 전략(`auto` | `json`)을 해석하는 공통 트레이트.
 *
 * 기본 `auto` 는 저작 SSoT 인 `.g7.html` 을 우선해 설치 시점에 메모리로 컴파일한다.
 * `json` 은 진단·복구용 탈출구로, 짝 `.json` 이 실재하면 마크업을 무시하고 원본 JSON 을 등록한다
 * (컴파일러 회귀가 의심될 때 원본 기준으로 되돌려 설치하기 위한 것).
 *
 * 3개 매니저의 8개 호출처에 플래그를 배선하면 시그니처가 오염되므로, 값은 config 를 SSoT 로 두고
 * 커맨드(`--layout-source=json`)가 실행 시점에 런타임 오버라이드한다.
 */
trait ResolvesLayoutInstallSource
{
    /**
     * 설치 소스가 JSON 강제(`--layout-source=json`)인지 판정합니다.
     *
     * @return bool JSON 강제면 true
     */
    protected function layoutInstallSourceIsJsonForced(): bool
    {
        return self::normalizeLayoutInstallSource(config('template.layout_markup.install_source')) === 'json';
    }

    /**
     * 설치 소스 값을 정규화합니다 — 알 수 없는 값은 기본값 `auto` 로 떨어뜨립니다.
     *
     * @param  mixed  $value  원본 설정/옵션 값
     * @return string `auto` 또는 `json`
     */
    protected static function normalizeLayoutInstallSource(mixed $value): string
    {
        return is_string($value) && strtolower(trim($value)) === 'json' ? 'json' : 'auto';
    }
}
