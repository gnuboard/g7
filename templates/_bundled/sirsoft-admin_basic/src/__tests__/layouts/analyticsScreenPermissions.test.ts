/**
 * 방문자 통계 화면의 **권한 교차 전수 가드** — 계획서 §4.2 「권한 3종」 · §8 M11 「권한 3단계 교차」.
 *
 * 라우트마다 권한을 적어 두는 것만으로는 교차가 성립하지 않는다. 화면은 자기 권한으로 열리고 나서
 * **다른 권한의 API** 를 부를 수 있고, 그러면 그 권한이 없는 운영자에게는 화면이 열린 채로 403 이 난다.
 * 실제로 개인정보 열람 전용·설정 전용 계정에서 상단 경고 소스와 저장소·알림 정의 소스가 그랬다
 * (#682 S3 라운드 2). E2E 는 언제나 조회 권한을 함께 발급해 이 조합을 한 번도 만들지 않았다.
 *
 * 그래서 두 모집단을 저장소에서 파생해 교차한다.
 *  - 화면 → 권한: `routes.json` 의 통계 라우트.
 *  - 엔드포인트 → 권한: `routes/api.php` 의 `permission:admin,…` 미들웨어.
 *
 * 규칙은 하나다. **자동으로 부르는 소스의 권한은 그 화면의 권한이어야 한다.** 예외는 화면 권한으로는
 * 얻을 수 없는 값(상단 경고·저장소 현황·알림 정의)뿐이고, 그것들은 403 을 삼키고 화면이 권한 안내로
 * 갈음한다는 것을 함께 단언한다 — 삼키지 않으면 화면을 열 때마다 전역 오류 토스트가 뜬다.
 *
 * @scenario screen=settings, state=forbidden, viewport=1440, locale=ko
 * @effects every_analytics_screen_only_calls_its_own_permission
 */
import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { TEMPLATE_DIR, collectObjects, analyticsScreens, expandedObjects, analyticsLayoutFiles, loadLayout, adminAuthUserFields } from './analyticsLayoutTestUtils';

const LAYOUT_DIR = path.join(TEMPLATE_DIR, 'layouts');
const REPO_ROOT = path.resolve(TEMPLATE_DIR, '../../..');
const API_ROUTES = path.join(REPO_ROOT, 'routes', 'api.php');

/**
 * 화면 권한으로는 얻을 수 없지만 화면이 그래도 부르는 소스.
 *
 * 값을 못 받는 것은 설계상 받아들이되, 403 을 삼키고(토스트 금지) 화면이 그 자리를 권한 안내나
 * 빈 자리로 갈음해야 한다. 키는 `데이터소스 id` 다.
 */
const CROSS_PERMISSION_SOURCES = new Set([
  'analytics_status',
  'analytics_storage',
  'analytics_notification_definitions',
]);

/** 화면 → 권한. `routes.json` 의 통계 라우트에서 파생한다. */
function screenPermissions(): Map<string, string> {
  const routes = JSON.parse(fs.readFileSync(path.join(TEMPLATE_DIR, 'routes.json'), 'utf8'));
  const list: any[] = Array.isArray(routes) ? routes : (routes.routes ?? []);
  const map = new Map<string, string>();
  for (const route of list) {
    const layout = route?.layout;
    const permission = route?.meta?.permission ?? route?.permission;
    if (typeof layout === 'string' && layout.startsWith('admin_analytics') && typeof permission === 'string') {
      map.set(`${layout}.json`, permission);
    }
  }
  return map;
}

/**
 * 엔드포인트 → 권한. `routes/api.php` 를 읽어 `prefix` 중첩을 따라가며 경로를 복원한다.
 *
 * @returns `{ pattern, permission }` 목록 — pattern 은 `/api/admin/analytics/reports/{report}` 꼴
 */
function apiPermissions(): { pattern: string; permission: string; controller?: string; method?: string }[] {
  const source = fs.readFileSync(API_ROUTES, 'utf8');
  const lines = source.split('\n');
  const entries: { pattern: string; permission: string; controller?: string; method?: string }[] = [];
  // `use …\ReportController as AdminAnalyticsReportController;` — 라우트는 별칭으로 적으므로 클래스 이름으로 푼다.
  const aliases = new Map<string, string>(
    [...source.matchAll(/^use\s+[\w\\]*\\(\w+)\s+as\s+(\w+);/gm)].map((m) => [m[2], m[1]]),
  );
  const stack: { prefix: string; depth: number }[] = [];
  let depth = 0;

  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    const prefixMatch = line.match(/Route::(?:[\w:]+\([^)]*\)->)*prefix\('([^']+)'\)/);

    // 한 줄에 열고 닫는 괄호가 섞이므로, prefix 등록은 그 줄의 깊이 증가를 기준으로 삼는다.
    const opens = (line.match(/\{/g) ?? []).length;
    const closes = (line.match(/\}/g) ?? []).length;

    const routeMatch = line.match(/Route::(get|post|put|patch|delete)\(\s*'([^']*)'/);
    if (routeMatch) {
      // 미들웨어는 같은 문장 안(같은 줄 또는 다음 몇 줄)에 있다 — 세미콜론까지 모아서 찾는다.
      let statement = line;
      for (let j = i + 1; j < lines.length && !statement.includes(';'); j += 1) {
        statement += `\n${lines[j]}`;
      }
      const permissionMatch = statement.match(/permission:admin,([A-Za-z0-9_.]+)/);
      if (permissionMatch) {
        const uri = routeMatch[2].replace(/^\/+|\/+$/g, '');
        const prefix = stack.map((entry) => entry.prefix).join('/');
        const pattern = `/${['api', prefix, uri].filter(Boolean).join('/')}`.replace(/\/+/g, '/');
        const action = statement.match(/\[\s*(\w+)::class\s*,\s*'(\w+)'\s*\]/);
        const controller = action ? (aliases.get(action[1]) ?? action[1]) : undefined;
        entries.push({ pattern, permission: permissionMatch[1], controller, method: action?.[2] });
      }
    }

    if (prefixMatch && opens > closes) {
      stack.push({ prefix: prefixMatch[1], depth });
    }
    depth += opens - closes;
    while (stack.length > 0 && depth <= stack[stack.length - 1].depth) {
      stack.pop();
    }
  }

  return entries;
}

/**
 * 레이아웃 엔드포인트를 경로 칸으로 자른다 — 표현식(`{{…}}`)은 한 칸짜리 빈칸(`:p`)으로 본다.
 *
 * 표현식 안에 중괄호가 또 들어 있어(`{{({a:'b'})[query.tab]}}`) 정규식으로는 끝을 못 찾는다. 여는
 * `{{` 를 만나면 짝이 맞는 `}}` 까지 직접 센다.
 */
function segments(endpoint: string): string[] {
  let out = '';
  for (let i = 0; i < endpoint.length; i += 1) {
    if (endpoint.startsWith('{{', i)) {
      const end = endpoint.indexOf('}}', i + 2);
      out += ':p';
      i = (end === -1 ? endpoint.length : end + 1);
      continue;
    }
    out += endpoint[i];
  }
  return out
    .replace(/\?.*$/, '')
    .replace(/\/+$/, '')
    .split('/')
    .map((part) => (/^\{[^}]*\}$/.test(part) || part.includes(':p') ? ':p' : part));
}

/** 라우트 패턴이 이 엔드포인트를 받는가 — 파라미터 칸(`{report}`·표현식)은 아무 값이나 받는다. */
function matches(routePattern: string, endpoint: string): boolean {
  const a = segments(routePattern);
  const b = segments(endpoint);
  if (a.length !== b.length) return false;
  return a.every((part, index) => part === ':p' || b[index] === ':p' || part === b[index]);
}

/** 라우트 경로 → 권한. 통계 밖 화면(`/admin/settings` 등)까지 포함한다. */
function routePermissions(): { pattern: string; permission: string }[] {
  const routes = JSON.parse(fs.readFileSync(path.join(TEMPLATE_DIR, 'routes.json'), 'utf8'));
  const list: any[] = Array.isArray(routes) ? routes : (routes.routes ?? []);
  const entries: { pattern: string; permission: string }[] = [];
  for (const route of list) {
    const raw = route?.path ?? route?.url;
    const permission = route?.meta?.permission ?? route?.permission;
    if (typeof raw !== 'string' || typeof permission !== 'string') continue;
    entries.push({ pattern: raw.replace(/^\*/, '').replace(/\/+$/, ''), permission });
  }
  return entries;
}

/** 레이아웃 노드가 직접 거는 `navigate` 의 목적지 경로를 모은다(`sequence` 안쪽까지). */
function navigateTargets(node: Record<string, any>): string[] {
  const found: string[] = [];
  const walk = (value: any): void => {
    if (Array.isArray(value)) {
      value.forEach(walk);
      return;
    }
    if (!value || typeof value !== 'object') return;
    if (value.handler === 'navigate' && typeof value?.params?.path === 'string') {
      found.push(value.params.path);
    }
    Object.entries(value).forEach(([key, child]) => {
      // 자식 노드(children)로는 내려가지 않는다 — 그 버튼은 그 노드 차례에 따로 검사한다.
      if (key === 'children') return;
      walk(child);
    });
  };
  walk(node.actions);
  return found;
}

/** 화면이 선언한 `computed` 식 — 권한 게이트가 `_computed.canX` 를 거쳐 갈 수 있다. */
function computedExpressions(relative: string, seen = new Set<string>()): Record<string, string> {
  if (seen.has(relative)) return {};
  seen.add(relative);
  const file = path.join(LAYOUT_DIR, relative);
  if (!fs.existsSync(file)) return {};
  const tree = JSON.parse(fs.readFileSync(file, 'utf8'));
  const out: Record<string, string> = {};
  const walk = (value: any): void => {
    if (Array.isArray(value)) {
      value.forEach(walk);
      return;
    }
    if (!value || typeof value !== 'object') return;
    if (value.computed && typeof value.computed === 'object' && !Array.isArray(value.computed)) {
      for (const [name, expression] of Object.entries(value.computed as Record<string, unknown>)) {
        if (typeof expression === 'string') out[name] = expression;
      }
    }
    if (typeof value.partial === 'string') Object.assign(out, computedExpressions(value.partial, seen));
    Object.values(value).forEach(walk);
  };
  walk(tree);
  return out;
}

/** `_computed.canX` 를 그 식으로 펼친다 — 권한 문자열이 어디에 적혔든 찾아내기 위해서다. */
function resolveGuard(guard: string, computeds: Record<string, string>): string {
  let out = guard;
  for (let pass = 0; pass < 3; pass += 1) {
    const next = out.replace(/_computed\.([A-Za-z0-9_]+)/g, (whole, name: string) => computeds[name] ?? whole);
    if (next === out) break;
    out = next;
  }
  return out;
}

/** `{{…}}` 한 겹을 벗긴다 */
function unwrap(expression: string): string {
  const t = expression.trim();
  return t.startsWith('{{') && t.endsWith('}}') ? t.slice(2, -2) : t;
}

/**
 * 가드 식을 계산값까지 풀어 **실제로 평가**한다 — 권한 문자열이 식 어딘가에 적혀 있는지(부분문자열)가
 * 아니라, 그 권한을 가진 계정과 못 가진 계정에서 참·거짓이 갈리는지를 본다. 부분문자열로 재면
 * `!…some(p => … === 'x')` 처럼 뒤집힌 가드도 통과한다(#682 S3 R5-D8).
 *
 * @returns 평가값, 식이 평가기에서 실패하면 `'error'`
 */
function evaluateGuard(guard: string, computeds: Record<string, string>, permissions: string[]): unknown {
  let expression = unwrap(guard);
  for (let pass = 0; pass < 4; pass += 1) {
    const next = expression.replace(/_computed\.([A-Za-z0-9_]+)/g, (whole, name: string) => (computeds[name] !== undefined ? `(${unwrap(computeds[name])})` : whole));
    if (next === expression) break;
    expression = next;
  }
  try {
    return evaluateSafeExpression(expression, {
      current_user: { data: { permissions: permissions.map((identifier) => ({ identifier })) } },
      _computed: {},
      _global: {},
      _local: {},
      query: {},
      route: {},
    });
  } catch {
    return 'error';
  }
}

/** 라우트 패턴이 이 목적지를 받는가 — `:key` 칸은 아무 값이나 받는다. */
function routeMatches(pattern: string, target: string): boolean {
  const a = pattern.split('/');
  const b = target.replace(/\?.*$/, '').replace(/\/+$/, '').split('/');
  if (a.length !== b.length) return false;
  return a.every((part, index) => part.startsWith(':') || part === b[index]);
}

describe('방문자 통계 화면 — 권한 교차 전수', () => {
  const screens = analyticsScreens();
  const permissions = screenPermissions();
  const api = apiPermissions();

  // @scenario screen=settings, state=forbidden, viewport=1440, locale=ko
  // @effects every_analytics_screen_only_calls_its_own_permission
  it('두 모집단이 비어 있지 않다 — 비면 아래 단언이 조용히 통과한다', () => {
    expect(screens.length).toBeGreaterThanOrEqual(14);
    expect(permissions.size).toBeGreaterThanOrEqual(14);
    expect(api.filter((entry) => entry.pattern.includes('/analytics')).length).toBeGreaterThanOrEqual(20);
    // 화면 목록과 라우트 목록이 서로를 덮는지 — 한쪽만 늘어도 red 다. 종전에는 「라우트 ⊆ 화면」 한 방향만
    // 재서, 라우트 없는 새 화면은 아래 세 케이스가 `permissions.get()` 이 비어 조용히 건너뛰었다(#682 S3 R15-D1).
    expect([...permissions.keys()].filter((screen) => !screens.includes(screen)), '레이아웃 없는 라우트').toEqual([]);
    expect(screens.filter((screen) => !permissions.has(screen)), '라우트(권한)가 없는 통계 화면').toEqual([]);
  });

  // @scenario screen=visitors, state=forbidden, viewport=1440, locale=ko
  // @effects every_analytics_screen_only_calls_its_own_permission
  it('자동으로 부르는 소스의 권한이 화면 권한과 같다 — 다르면 합의된 교차 소스여야 한다', () => {
    const unexpected: string[] = [];
    const unresolved: string[] = [];

    for (const screen of screens) {
      const screenPermission = permissions.get(screen);
      if (!screenPermission) continue;

      for (const node of expandedObjects(screen)) {
        if (node.type !== 'api' || typeof node.endpoint !== 'string' || node.auto_fetch !== true) continue;

        // 고정 경로가 파라미터 경로(`reports/{report}`)보다 먼저 맞아야 한다 — 빈칸이 적은 쪽을 고른다.
        const candidates = api
          .filter((entry) => matches(entry.pattern, node.endpoint))
          .sort((x, y) => segments(x.pattern).filter((p) => p === ':p').length - segments(y.pattern).filter((p) => p === ':p').length);
        const matched = candidates[0];
        if (!matched) {
          unresolved.push(`${screen}:${node.id} → ${node.endpoint}`);
          continue;
        }
        if (matched.permission === screenPermission) continue;
        if (CROSS_PERMISSION_SOURCES.has(node.id)) continue;

        unexpected.push(`${screen}:${node.id} 는 ${matched.permission} 인데 화면은 ${screenPermission}`);
      }
    }

    // 매칭 실패는 그 자체로 결함이다 — 패턴이 바뀌었는데 가드가 눈감는 것을 막는다.
    expect(unresolved).toEqual([]);
    expect(unexpected).toEqual([]);
  });

  // @scenario screen=settings, state=forbidden, viewport=1440, locale=ko
  // @effects every_analytics_screen_only_calls_its_own_permission
  it('교차 소스는 403 을 삼킨다 — 화면을 열 때마다 오류 토스트가 뜨지 않는다', () => {
    const missing: string[] = [];
    let seen = 0;

    for (const screen of screens) {
      for (const node of expandedObjects(screen)) {
        if (node.type !== 'api' || !CROSS_PERMISSION_SOURCES.has(node.id)) continue;
        seen += 1;
        const handled = node.errorHandling?.['403'] ?? node.error_handling?.['403'];
        const action = Array.isArray(handled) ? handled[0] : handled;
        const kind = typeof action === 'string' ? action : (action?.handler ?? action?.type);
        if (kind !== 'suppress') missing.push(`${screen}:${node.id}`);
      }
    }

    expect(seen).toBeGreaterThanOrEqual(13);
    expect(missing).toEqual([]);
  });
  // @scenario screen=overview, state=forbidden, viewport=1440, locale=ko
  // @effects every_analytics_screen_only_calls_its_own_permission
  it('안내 버튼은 그 계정이 갈 수 있는 화면만 가리킨다 — 못 가는 곳이면 그리지 않는다', () => {
    const routes = routePermissions();
    const offenders: string[] = [];
    let crossings = 0;

    expect(routes.length).toBeGreaterThanOrEqual(14);

    for (const screen of screens) {
      const screenPermission = permissions.get(screen);
      if (!screenPermission) continue;
      const computeds = computedExpressions(screen);

      for (const node of expandedObjects(screen)) {
        for (const target of navigateTargets(node)) {
          const route = routes.find((entry) => routeMatches(entry.pattern, target));
          // 라우트를 못 찾으면 이 템플릿 밖(외부 링크·동적 경로)이라 판정 대상이 아니다.
          if (!route || route.permission === screenPermission) continue;

          crossings += 1;
          const guard = typeof node.if === 'string' ? node.if : '';
          // 화면 권한만 가진 계정 → 숨어야 하고, 목적지 권한까지 가진 계정 → 보여야 한다. 둘 다 재야
          // 「늘 거짓」(버튼이 영영 안 뜸)과 「뒤집힘」(못 가는 계정에게만 뜸)을 함께 잡는다.
          const without = evaluateGuard(guard, computeds, [screenPermission]);
          const withTarget = evaluateGuard(guard, computeds, [screenPermission, route.permission]);
          if (guard === '' || without === 'error' || withTarget === 'error') {
            offenders.push(`${screen}: ${target} 는 ${route.permission} 인데 버튼 가드를 평가하지 못했다 — ${resolveGuard(guard, computeds).slice(0, 80)}`);
          } else if (without) {
            offenders.push(`${screen}: ${target} 는 ${route.permission} 인데 그 권한이 없는 계정에게도 버튼이 뜬다`);
          } else if (!withTarget) {
            offenders.push(`${screen}: ${target} 는 ${route.permission} 인데 그 권한이 있는 계정에게도 버튼이 안 뜬다`);
          }
        }
      }
    }

    // 교차가 하나도 안 잡히면 위 단언은 빈 배열을 비교할 뿐이다 — 모집단 자체를 먼저 잰다.
    expect(crossings).toBeGreaterThanOrEqual(4);
    expect(offenders).toEqual([]);
  });

  // @scenario screen=settings, state=forbidden, viewport=1440, locale=ko
  // @effects cross_permission_fallback_never_reads_as_a_fact
  it('교차 소스의 값을 글자로 그리는 자리는 권한을 확인한 가지 안에만 있다 — 삼킨 403 의 fallback 을 사실처럼 그리지 않는다', () => {
    // 403 을 삼킨 소스에는 fallback(0·빈 배열)이 남는다. 그 값을 그대로 글자로 그리면 권한이 없는 운영자에게
    // 「0행」이 사실로 읽힌다 — 되돌릴 수 없는 전체 삭제 창이 그랬다(#682 S3 라운드 15, 라운드 2 저장소 표와 같은 부류).
    // 창·조각까지 조상 사슬을 따라가며, 그 글자 자신이나 조상의 조건이 소스의 권한을 확인하는지 본다.
    // 조건이 소스 값 자체를 보면(예: `backlogged === true`) fallback 이 그 가지를 닫으므로 통과로 친다.
    const offenders: string[] = [];
    let checked = 0;
    let screensWithCrossing = 0;

    for (const screen of screens) {
      const screenPermission = permissions.get(screen);
      if (!screenPermission) continue;

      const objects = expandedObjects(screen);
      const crossing = new Map<string, string>();
      for (const node of objects) {
        if (node.type !== 'api' || !CROSS_PERMISSION_SOURCES.has(node.id) || typeof node.endpoint !== 'string') continue;
        const matched = api.find((entry) => matches(entry.pattern, node.endpoint));
        if (matched && matched.permission !== screenPermission) crossing.set(node.id, matched.permission);
      }
      if (crossing.size === 0) continue;
      screensWithCrossing += 1;

      const resolve = expressionResolver(screen, objects);
      walkRendered(screen, (node, ancestors) => {
        for (const text of renderedStrings(node)) {
          for (const [source, permission] of crossing) {
            if (!new RegExp(`\\b${source}\\??\\.data`).test(text)) continue;
            checked += 1;
            if (resolve(text).includes(permission)) continue;
            const guarded = [...ancestors, node].some((owner) => {
              const condition = typeof owner.if === 'string' ? owner.if : '';
              return resolve(condition).includes(permission) || new RegExp(`\\b${source}\\??\\.data`).test(condition);
            });
            if (!guarded) offenders.push(`${screen}: ${source}(${permission}) 를 가드 없이 그린다 — ${text.slice(0, 80)}`);
          }
        }
      });
    }

    expect(screensWithCrossing).toBeGreaterThanOrEqual(4);
    expect(checked).toBeGreaterThan(0);
    expect(offenders).toEqual([]);
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects report_page_size_follows_the_setting
  it('설정이 한 쪽 행 수를 정하는 엔드포인트를 부르는 소스는 per_page 에 숫자를 박지 않는다 — 박으면 설정이 화면에 안 닿는다', () => {
    // 서버는 `per_page` 가 없을 때 설정 「상세 TOP N」을 쓴다. 화면이 `query.per_page || 25` 를 늘 보내
    // 설정을 50 으로 바꿔도 리포트 7소스가 25행이었다 — 기본값이 25 로 같아 증상이 가려졌다(#682 S3 라운드 15).
    // 그 엔드포인트는 컨트롤러에서 파생한다: 설정값을 기본으로 읽는 메서드와, 그 메서드에 위임하는 메서드.
    const controller = fs.readFileSync(
      path.join(REPO_ROOT, 'app/Http/Controllers/Api/Admin/Analytics/ReportController.php'),
      'utf8',
    );
    const heads = [...controller.matchAll(/public function (\w+)\(/g)];
    const bodies = new Map<string, string>(
      heads.map((head, index) => [head[1], controller.slice(head.index, heads[index + 1]?.index ?? controller.length)]),
    );
    const direct = [...bodies].filter(([, body]) => body.includes('topNDetail')).map(([name]) => name);
    const settingMethods = new Set(
      [...bodies].filter(([name, body]) => direct.includes(name) || direct.some((d) => body.includes(`$this->${d}(`))).map(([name]) => name),
    );
    expect(settingMethods.size).toBeGreaterThanOrEqual(3);

    const endpoints = api.filter((entry) => entry.controller === 'ReportController' && settingMethods.has(entry.method ?? ''));
    expect(endpoints.length).toBeGreaterThanOrEqual(3);

    const offenders: string[] = [];
    let sources = 0;
    for (const screen of screens) {
      for (const node of expandedObjects(screen)) {
        if (node.type !== 'api' || typeof node.endpoint !== 'string') continue;
        if (!endpoints.some((entry) => matches(entry.pattern, node.endpoint))) continue;
        sources += 1;
        const perPage = node.params?.per_page;
        if (typeof perPage === 'number' || (typeof perPage === 'string' && /(\|\||\?\?)\s*\d/.test(perPage))) {
          offenders.push(`${screen}:${node.id} per_page=${String(perPage)}`);
        }
      }
    }

    expect(sources).toBeGreaterThanOrEqual(7);
    expect(offenders).toEqual([]);
  });

  // @scenario screen=diagnostics, state=forbidden, viewport=1440, locale=ko
  // @effects every_analytics_source_handles_403
  it('모든 화면의 모든 api 소스가 403 을 처리한다 — 화면마다 권한 오류 화면은 하나 이상, 나머지는 삼킨다', () => {
    // 개별 화면 테스트가 403 을 재는 화면은 8/14 였고, 주 소스만 봤다 — 보조 소스 4개(개요 실시간·진단
    // 이력·실패 로그·설정 opt-out)는 처리 없이 전역 토스트로 떨어졌다(#682 S3 R3-D7·R5-D6·R9-D2·R13-D1).
    // 모집단을 화면 트리(조각 포함)에서 파생해, 소스마다 403 이 권한 오류 화면(주 소스) 또는 삼킴(보조
    // 소스)인지 본다. 권한 오류 화면이 하나도 없는 화면은 권한 없는 계정에게 빈 화면만 남긴다.
    const unhandled: string[] = [];
    const withoutErrorPage: string[] = [];
    let sources = 0;
    for (const screen of screens) {
      let errorPages = 0;
      for (const node of expandedObjects(screen)) {
        if (node.type !== 'api' || typeof node.endpoint !== 'string' || typeof node.id !== 'string') continue;
        sources += 1;
        const handled = node.errorHandling?.['403'] ?? node.error_handling?.['403'];
        const action = Array.isArray(handled) ? handled[0] : handled;
        const kind = typeof action === 'string' ? action : (action?.handler ?? action?.type);
        if (kind === 'showErrorPage') errorPages += 1;
        else if (kind !== 'suppress') unhandled.push(`${screen}:${node.id} (${String(kind)})`);
      }
      if (errorPages === 0) withoutErrorPage.push(screen);
    }
    expect(sources, '훑은 api 소스').toBeGreaterThanOrEqual(screens.length * 2);
    expect(unhandled, '403 을 처리하지 않는 소스').toEqual([]);
    expect(withoutErrorPage, '권한 오류 화면을 띄우는 소스가 없는 화면').toEqual([]);
  });

  // @scenario screen=dashboard_widget, state=forbidden, viewport=1440, locale=ko
  // @effects permission_guards_read_only_fields_the_auth_response_sends
  it('권한 가드가 읽는 current_user 필드는 관리자 인증 응답이 실제로 싣는 필드다 — 없는 필드의 분기는 죽은 가지다', () => {
    // 진단·대시보드는 `is_super_admin`, 나머지는 `is_super` 를 봤는데 `/api/admin/auth/user`
    // (`UserResource::toArray`)는 둘 다 싣지 않는다 — 두 철자 모두 늘 undefined 인 가지였다(#682 S3 R2-E2·R3-E1·R12-D6).
    // 모집단은 레이아웃 전수와 응답 리소스에서 판다 — 서버가 필드를 더하면 그 필드는 자동으로 허용된다.
    const fields = adminAuthUserFields();
    expect(fields.length, '관리자 인증 응답의 필드').toBeGreaterThan(10);
    expect(fields, '권한 배열은 응답에 있어야 한다').toContain('permissions');

    const reads = new Map<string, string>();
    for (const file of analyticsLayoutFiles()) {
      for (const m of JSON.stringify(loadLayout(file)).matchAll(/current_user\?\.data\?\.([A-Za-z_]+)/g)) {
        if (!reads.has(m[1])) reads.set(m[1], file);
      }
    }
    expect(reads.size, '레이아웃이 읽는 current_user 필드').toBeGreaterThan(0);
    const absent = [...reads].filter(([field]) => !fields.includes(field)).map(([field, file]) => `${field} (${file})`);
    expect(absent, '응답에 없는 필드를 읽는 자리').toEqual([]);
  });
});

/**
 * 화면 트리를 조각·창까지 펼치며 조상 사슬과 함께 돈다.
 *
 * 데이터소스·계산값 정의와 액션은 그리는 노드가 아니므로 내려가지 않는다.
 */
function walkRendered(
  relative: string,
  visit: (node: Record<string, any>, ancestors: Record<string, any>[]) => void,
  ancestors: Record<string, any>[] = [],
  seen = new Set<string>(),
): void {
  if (seen.has(relative)) return;
  const file = path.join(LAYOUT_DIR, relative);
  if (!fs.existsSync(file)) return;
  const trail = new Set(seen).add(relative);
  const walk = (value: any, chain: Record<string, any>[]): void => {
    if (Array.isArray(value)) {
      value.forEach((child) => walk(child, chain));
      return;
    }
    if (!value || typeof value !== 'object') return;
    visit(value, chain);
    const next = [...chain, value];
    if (typeof value.partial === 'string') walkRendered(value.partial, visit, next, trail);
    for (const [key, child] of Object.entries(value)) {
      if (key === 'actions' || key === 'data_sources' || key === 'computed' || key === 'init_actions') continue;
      walk(child, next);
    }
  };
  walk(JSON.parse(fs.readFileSync(file, 'utf8')), ancestors);
}

/** 노드가 화면에 그리는 문자열 — 글자와 속성값(조건 `if` 는 뺀다). */
function renderedStrings(node: Record<string, any>): string[] {
  const out: string[] = [];
  const collect = (value: any): void => {
    if (typeof value === 'string') out.push(value);
    else if (Array.isArray(value)) value.forEach(collect);
    else if (value && typeof value === 'object') Object.values(value).forEach(collect);
  };
  if (typeof node.text === 'string') out.push(node.text);
  if (node.props) collect(node.props);
  return out;
}

/**
 * `_computed.X` 와 `_global.X` 를 그 식으로 끝까지 푼다.
 *
 * 창(modal)은 화면 계산값을 못 읽어서 여는 버튼이 판정값을 `_global` 에 실어 보낸다 — 그 전역 키를
 * 채우는 `setState` 까지 따라가야 권한 확인이 어디서 왔는지 보인다.
 */
function expressionResolver(screen: string, objects: Record<string, any>[]): (expression: string) => string {
  const computeds = computedExpressions(screen);
  const globals: Record<string, string> = {};
  for (const node of objects) {
    if (node.handler !== 'setState' || node.params?.target !== 'global') continue;
    for (const [key, value] of Object.entries(node.params as Record<string, unknown>)) {
      if (key !== 'target' && typeof value === 'string') globals[key] = `${globals[key] ?? ''} ${value}`;
    }
  }
  return (expression: string) => {
    let out = expression;
    for (let pass = 0; pass < 4; pass += 1) {
      const next = out
        .replace(/_computed\.([A-Za-z0-9_]+)/g, (whole, name: string) => computeds[name] ?? whole)
        .replace(/_global\.([A-Za-z0-9_]+)/g, (whole, name: string) => globals[name] ?? whole);
      if (next === out) break;
      out = next;
    }
    return out;
  };
}
