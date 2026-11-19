<?php

namespace App\Extension\Integrity;

/**
 * GitHub 저장소에 받을 태그 릴리스가 없을 때의 예외.
 *
 * 7.2.0 부터 브랜치(`main`·`master`)로 물러서지 않는다 — 호출부는 이 예외를 자기 문맥 문장(확장 업데이트는
 * 「릴리스를 찾지 못함」)으로 바꿔 알린다.
 *
 * @since 7.2.0
 */
class PackageReleaseNotFoundException extends \RuntimeException
{
    /**
     * 예외를 만듭니다.
     *
     * @param  string  $repository  `owner/repo`
     * @param  string|null  $version  찾던 버전 (최신 릴리스면 null)
     */
    public function __construct(
        public readonly string $repository,
        public readonly ?string $version = null,
    ) {
        parent::__construct((string) __('common.errors.github_release_not_found', ['repository' => $repository]));
    }
}
