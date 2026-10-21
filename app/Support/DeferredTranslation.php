<?php

namespace App\Support;

/**
 * 나중에 번역할 문구(번역 키 + 치환값).
 *
 * 서비스 공급자 부팅 중에는 활성 언어팩(ja 등) 번역이 아직 등록되지 않아 그 자리에서 `__()` 로 만든 문구가 폴백(ko)이
 * 된다. `BootDeferredLog` 의 맥락 값으로 이 객체를 넘기면 기록을 남기는 시점(언어팩 등록 뒤)에 번역한다.
 */
final class DeferredTranslation
{
    /**
     * 번역 키와 치환값을 담습니다.
     *
     * @param  string  $key  번역 키
     * @param  array<string, mixed>  $replace  문구 자리표시자 값
     */
    public function __construct(
        public readonly string $key,
        public readonly array $replace = [],
    ) {}

    /**
     * 지금의 번역기로 문구를 만듭니다.
     *
     * @return string 번역 문구
     */
    public function resolve(): string
    {
        return (string) __($this->key, $this->replace);
    }
}
