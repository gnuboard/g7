<?php

namespace App\Extension\Concerns;

use App\Services\Extension\ExtensionIconResolver;

/**
 * 확장 목록 행에 아이콘 필드를 얹는 공통 조각
 *
 * 매니저 셋(Module·Plugin·Template)이 같은 자리에 같은 두 필드를 붙이므로 한
 * 정의만 둔다. 해석기는 메서드 안에서 app() 으로 받는다 — 매니저 생성자 시그니처를
 * 바꾸면 이들을 직접 new 하는 호출처가 전부 깨지기 때문이다.
 *
 * 해석기는 요청 단위 메모를 가지므로 한 목록 응답 안에서 확장당 SVG 파싱은 1회다.
 */
trait ResolvesExtensionIcons
{
    /** @var ExtensionIconResolver|null 요청 단위로 재사용하는 해석기 */
    private ?ExtensionIconResolver $extensionIconResolver = null;

    /**
     * 목록 행에 병합할 아이콘 필드를 만듭니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $version  캐시 무효화에 쓸 버전
     * @return array{icon_url: ?string, icon_source: string} 행에 펼쳐 넣을 필드
     */
    protected function resolveIconFields(string $identifier, ?string $version = null): array
    {
        $this->extensionIconResolver ??= app(ExtensionIconResolver::class);

        return $this->extensionIconResolver->describe($this->extensionIconType(), $identifier, $version);
    }

    /**
     * 이 매니저가 다루는 확장 유형을 반환합니다.
     *
     * @return string module|plugin|template 중 하나
     */
    abstract protected function extensionIconType(): string;
}
