<?php

namespace Plugins\Sirsoft\HeroSection\Contracts;

/**
 * 대상 사이트 주소 일괄 조회 — 대상 해석기의 선택 계약.
 *
 * 관리자 대상 목록은 한 쪽(최대 20개)의 사이트 주소를 함께 보인다. 해석기가 이 계약을 구현하면 한 번에 묻고, 구현하지 않으면 그 쪽 안의 키만
 * `TargetResolverInterface::siteUrl()` 로 하나씩 묻는다.
 */
interface TargetSiteUrlsInterface
{
    /**
     * 저장 키들의 사이트 주소를 한 번에 반환합니다.
     *
     * @param  array<int, string>  $keys  저장 키 목록
     * @return array<string, string> 저장 키 => 사이트 경로 (없는 대상은 빠진다)
     */
    public function siteUrls(array $keys): array;
}
