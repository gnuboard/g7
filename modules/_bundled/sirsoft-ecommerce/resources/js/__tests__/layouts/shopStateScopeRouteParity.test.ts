// e2e:allow 편집기 상태 스코프 ↔ 라우트 토큰 정합만 검사하는 정적 계약 테스트 — 런타임 동작 무변경, 해당 화면 E2E 는 기존 spec 으로 커버됨
/**
 * 상점 상태 그룹 scope ↔ routes.json 라우트 패리티 계약 테스트
 *
 * 편집기는 템플릿 routes.json 의 동적 path 표현식을 평가해 `selectedRoute.path` 를
 * 만들고, 그 값을 editor-spec 의 `scope.match` 와 대조해 상태 토글을 띄운다. 따라서
 * scope 는 **routes.json 이 실제로 만들어 내는 path 문자열**과 일치해야 한다.
 *
 * 이 테스트가 막는 회귀는 두 축이다:
 *
 *  1. 세그먼트 축 — 상점 주소는 운영자 설정이라 첫 세그먼트가 사이트마다 다르다
 *     (기본 `shop` / `route_path` 로 바꾼 값 / `no_route` 면 세그먼트 없음).
 *     `/*` 로 쓰면 no_route 상점에서만 매칭이 깨진다 (공개 #85, engine-v1.58.0 `/*?`).
 *
 *  2. 파라미터 토큰 축 — `matchRoutePattern` 은 `:param` 을 리터럴로 취급한다.
 *     scope 에 `:id` 라 적었는데 routes.json 이 `:product_code` 를 쓰면 세그먼트
 *     토큰을 아무리 고쳐도 **세 축 모두에서** 영영 매칭되지 않는다. 예외도 경고도
 *     없이 그 화면의 상태 토글만 사라지므로 정적 대조가 유일한 발견 경로다.
 *
 * scope 목록을 손으로 열거하지 않고 editor-spec 과 routes.json 양쪽에서 도출한다 —
 * 나중에 상점 화면이 늘어도 이 테스트가 그 화면까지 자동으로 검사한다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

import { matchStateItems } from '../../../../../../../resources/js/core/template-engine/layout-editor/utils/matchStateScope';

function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);

const spec = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'), 'utf-8'),
);
const routesJson = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'templates/_bundled/sirsoft-basic/routes.json'), 'utf-8'),
);

/** routes.json 의 상점 주소 prefix 표현식 (`no_route ? '' : route_path ?? 'shop'`) */
const SHOP_PREFIX_EXPR = /^\/\{\{[^}]*?no_route[\s\S]*?\}\}/;

/** 편집기가 상점 주소 설정을 평가했을 때 나올 수 있는 세 가지 첫 세그먼트 */
const AXES: ReadonlyArray<[label: string, base: string]> = [
  ['기본 상점 주소(shop)', '/shop'],
  ['운영자가 바꾼 주소(store)', '/store'],
  ['주소 없이 운영하는 상점(no_route)', ''],
];

/** 상점 주소에 종속된 라우트를 축별로 평가한 path 목록 */
function shopRoutePaths(base: string): string[] {
  return (routesJson.routes ?? [])
    .map((r: { path?: string }) => r.path)
    .filter((p: unknown): p is string => typeof p === 'string' && SHOP_PREFIX_EXPR.test(p))
    .map((p: string) => p.replace(SHOP_PREFIX_EXPR, base));
}

/**
 * editor-spec 의 상점 route scope 목록 (그룹의 첫 상태 id 를 식별자로 함께 들고 간다).
 * 관리자 라우트(`*​/admin/...`)는 상점 주소와 무관하므로 제외한다.
 */
const shopScopes: Array<{ match: string; probeItemId: string }> = (spec.states?.groups ?? [])
  .filter(
    (g: { scope?: { kind?: string; match?: unknown }; items?: unknown[] }) =>
      g.scope?.kind === 'route' &&
      typeof g.scope?.match === 'string' &&
      !g.scope.match.includes('/admin/') &&
      Array.isArray(g.items) &&
      g.items.length > 0,
  )
  .map((g: { scope: { match: string }; items: Array<{ id: string }> }) => ({
    match: g.scope.match,
    probeItemId: g.items[0].id,
  }));

describe('상점 상태 그룹 scope ↔ routes.json 라우트 패리티', () => {
  it('검사 모집단이 비어 있지 않다 (빈 모집단으로 통과하는 것을 막는다)', () => {
    expect(shopScopes.length).toBeGreaterThan(0);
    expect(shopRoutePaths('/shop').length).toBeGreaterThan(0);
  });

  describe.each(AXES)('%s', (_label, base) => {
    it.each(shopScopes.map((s) => [s.match, s.probeItemId]))(
      'scope %s 의 상태가 실제 라우트에서 노출된다',
      (scopeMatch: string, probeItemId: string) => {
        const candidates = shopRoutePaths(base);

        // 실제 소비자(matchStateItems)로 판정해 정규식 의미까지 함께 고정한다.
        const hit = candidates.find((routePath) =>
          matchStateItems(spec.states.groups, { kind: 'route', match: routePath }).some(
            (i) => i.id === probeItemId,
          ),
        );

        expect(
          hit,
          `scope "${scopeMatch}" 가 이 축의 어떤 라우트와도 매칭되지 않는다.\n` +
            `  후보 라우트: ${candidates.join(', ')}`,
        ).toBeDefined();
      },
    );
  });
});
