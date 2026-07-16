<?php

namespace App\Contracts\Extension;

/**
 * 확장 선언 미들웨어의 라우트 매칭 인덱스 계약.
 *
 * 활성 확장(모듈/플러그인)이 `getMiddleware()` 로 선언한 미들웨어를 수집해,
 * 요청 시점에 라우트 이름·URI 를 각 선언의 `targets` 술어와 대조하여 매칭되는
 * 미들웨어 FQCN 목록을 반환합니다. 코어 self-gate 게이트(`ExtensionMiddlewareGate`)가
 * 이 계약을 사용해 그룹/timing 별로 매칭 미들웨어를 동적 실행합니다.
 *
 * 부팅 시점 라우트 순회로 확장 간 미들웨어를 부착할 수 없는 제약(확장 라우트는
 * booted 지연 등록 + 순서 미보장)을 요청 시점 매칭으로 원천 회피합니다.
 * IDV `IdentityPolicyRepository::getRouteScopeIndex()` 미러.
 *
 * @since 7.0.5
 */
interface ExtensionMiddlewareRegistryInterface
{
    /**
     * 현재 요청에 매칭되는 확장 미들웨어 FQCN 목록을 반환합니다.
     *
     * 게이트 래퍼가 자기 그룹·timing 을 전달하면, 그 축의 엔트리 중 라우트명 또는
     * URI 술어가 매칭되는 것을 확장 로드 순서대로 반환합니다.
     *
     * @param  string  $routeName  현재 요청 라우트 이름 (무명 라우트면 빈 문자열)
     * @param  string  $path  현재 요청 URI path (선행 슬래시 없음, 무명 라우트 타게팅용)
     * @param  string  $group  요청이 속한 그룹 ('web'|'api')
     * @param  string  $timing  실행 시점 ('before_core'|'after_core')
     * @return array<int, class-string> 매칭된 확장 미들웨어 FQCN (선언 순서 = 확장 로드 순서)
     */
    public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array;
}
