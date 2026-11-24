/**
 * 전 화면 DOM id 유일성 크롤 (이슈 #408 5.2절, 결정 D6)
 *
 * HTML `id` 는 한 문서에 하나여야 한다. 같은 id 가 둘 이상이면 `getElementById`·`<label for>`·`aria-*`
 * 연결과 확장 주입(target_id)이 첫 요소에만 걸린다. 이 spec 은 실행 중인 서버가 내주는 **활성 확장 병합
 * 라우트 전부**를 1440px 와 390px 두 폭으로 열어 실제 DOM 에서 다음 두 가지를 센다.
 *   1. 같은 id 를 가진 요소가 2개 이상인 id (중복)
 *   2. `{{` 가 남은 id (보간 잔재)
 * 판정은 모든 화면에서 둘 다 0건이다. 결과 전체는 `test-results/id-crawl-report.json`(환경 변수 `G7_E2E_ID_CRAWL_REPORT` 로 바꿀 수 있다)에 남긴다.
 *
 * 실행 조건: `G7_E2E_ID_CRAWL=1` 일 때만 돈다(기본 Playwright 실행에는 끼지 않는다).
 *   PowerShell: `$env:G7_E2E_ID_CRAWL='1'; npx playwright test tests/Playwright/specs/id-duplicate/all-routes-dom-id-unique.spec.ts`
 *
 * 라우트 출처: `GET /api/templates/{identifier}/routes.json` (sirsoft-admin_basic, sirsoft-basic).
 *   응답 형태(2026-11-23 실측):
 *     `{ success: true, message: string, data: { version: string, routes: RouteEntry[] } }`
 *     RouteEntry = `{ path, layout?, redirect?, auth_required?, guest_only?, params?, permission?, permissions?,
 *                    requires?, meta, source: { kind: 'template'|'module'|'plugin'|'core', identifier } }`
 *   - 관리자 path 는 `/admin/...` 앞에 별표(`*`) 한 글자가 붙어 온다. 사용자 상점 경로는
 *     `/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?...}}/products` 처럼 상점 주소 표현식으로 시작한다.
 *   - 같은 path 가 두 번 나올 수 있다(코어 방문자 통계와 glitter-analytics 의 `/admin/analytics`). 화면은 path
 *     하나에 하나만 매칭되므로 path 기준으로 한 번만 연다.
 *   - nginx 가 `.json` 경로를 정적 파일로 먼저 찾기 때문에 **HTTP 상태는 404 로 오고 본문은 정상**이다.
 *     그래서 상태 코드가 아니라 본문(`success`·`data.routes` 배열)으로 판정한다.
 *
 * 동적 파라미터: `tests/Playwright/fixtures/id-crawl-params.php` 의 읽기 전용 DB 조회 표본을 쓴다.
 *   `PARAM_RULES` 에 경로 패턴마다 채울 값을 적는다. 값을 못 구했거나 열 수 없는 화면은 사유와 함께
 *   `skipped` 에 올린다. 규칙이 없는 동적 경로(새로 생긴 라우트)는 `unresolved` 로 모아 **실패**한다 —
 *   사유 없는 건너뜀을 허용하지 않는다.
 *
 * 인증: `issueToken()`(admin 역할) 토큰 하나로 관리자와 사용자 화면을 모두 연다. 토큰 계정은 실행마다
 *   새로 만든 시험 회원이라 「내 주문」 같은 소유 데이터가 없다 — 그런 상세 화면은 사유를 적고 건너뛴다.
 *
 * 측정: `page.goto(url, { waitUntil: 'networkidle' })` → 관리자는 `#main_content`, 사용자는 앱 루트(`#app`)
 *   아래 첫 요소 마운트를 기다리고, id 개수가 1초 간격으로 연속 3번 같아질 때까지(최대 20초) 본 뒤
 *   2500ms 를 더 둔다 → `document.querySelectorAll('[id]')`.
 *   networkidle·마운트·안정 대기가 시간 안에 끝나지 않아도 그 시점 DOM 을 재고 보고서에 표시한다.
 *
 * 한계: 모달·BottomSheet·드롭다운처럼 **사용자 조작으로 열리는 영역은 열지 않는다**(닫힌 상태에서는
 *   마운트되지 않는다). 그 부분의 중복(#408 3.1절 B2 무통장 일괄 매칭 모달, B3 구매 시트, C3 주소형 항목 2개
 *   이상인 회원 폼 등)은 계획서 8절 Chrome MCP 시나리오가 맡는다. 데이터 조건(표본 행의 값)에 따라 그려지지
 *   않는 분기도 이 크롤로는 확인되지 않는다.
 *
 * 부수 효과: 화면을 GET 으로 열기만 한다. 화면이 열릴 때 서버가 스스로 남기는 기록(조회수, 방문 통계,
 *   활동 로그)은 생길 수 있다.
 *
 * @scenario dom-id-uniqueness-all-routes
 */
import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { APIRequestContext, Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.skip(process.env.G7_E2E_ID_CRAWL !== '1', 'G7_E2E_ID_CRAWL=1 일 때만 실행하는 전 화면 크롤이다 (#408 D6)');

// ESM 환경(package.json "type": "module")에서는 __dirname 이 없으므로 import.meta.url 로 만든다.
const specDir = dirname(fileURLToPath(import.meta.url));

/** 코어 루트 (tests/Playwright/specs/id-duplicate 기준 네 단계 위) */
const G7_ROOT = process.env.G7_ROOT || resolve(specDir, '../../../../');

/** 결과 보고서 경로 */
const REPORT_PATH = process.env.G7_E2E_ID_CRAWL_REPORT || resolve(G7_ROOT, 'test-results/id-crawl-report.json');

/** 라우트를 읽을 템플릿 (관리자, 사용자) */
const ROUTE_SOURCES = ['sirsoft-admin_basic', 'sirsoft-basic'] as const;

/** 측정 폭 (responsive 분기 차이) */
const WIDTHS = [
  { width: 1440, height: 900 },
  { width: 390, height: 844 },
] as const;

/**
 * goto networkidle 한도.
 *
 * 개발 서버(디버그 모드)는 API 하나가 2~16초 걸린다. `/admin/users/create` 실측(2026-11-23): `#main_content`
 * 가 약 20초에 생기고(id 94개) 데이터 소스가 끝난 약 36초에야 다 그려졌다(id 196개). 30초 한도에서는
 * 데이터가 들어오기 전 DOM 을 재 중복을 놓쳤다.
 */
const NAV_TIMEOUT_MS = 90_000;

/** 마운트 대기 한도 */
const MOUNT_TIMEOUT_MS = 30_000;

/** id 개수 안정 판정 — 이 간격으로 재서 연속 3번 같으면 멈춘다 */
const STABLE_POLL_MS = 1_000;

/** id 개수 안정 대기 한도 */
const STABLE_TIMEOUT_MS = 20_000;

/** 마운트 뒤 추가 대기 (지연 데이터·확장 주입) */
const SETTLE_MS = 2_500;

/**
 * 화면별 탭 변종 — `?tab=` 으로 하나씩 연다.
 *
 * 출처(2026-11-23 develop d5c07422b9):
 *   ① 계획서 명령 `grep -rnoE "query\??\.tab \?\? [^)]*|query\??\.tab === '[a-z_]+'" --include=*.g7.html modules/_bundled templates/_bundled`
 *      — 6줄만 잡힌다(템플릿 설정 4, 방문자 통계 유입 keywords, 상품 상세 info). 설정 화면들은
 *      `(query.tab || _global.activeXTab || 'general') === '…'` 꼴이라 이 패턴에 걸리지 않는다.
 *   ② 그래서 같은 취지로 탭 링크를 경로별로 모았다:
 *      `grep -rhoE "[\"'\`](/[a-zA-Z0-9_/{}.-]*)\?tab=[a-z_]+" --include=*.g7.html modules/_bundled templates/_bundled plugins/_bundled`
 *   ③ 링크가 없는 탭(social_login policy·providers, sso_logs attempts·scim)은 `query.tab === '…'` 비교 줄에서 더했다.
 * 템플릿 설정(`query.tab ?? null`)은 탭 목록이 설정 스키마에서 동적으로 와서 기본 화면만 연다.
 * 알림 정의 탭은 `channel=alimtalk` 변종을 더 연다 — 비즈뿌리오 행 표시 조각이 `activeChannel === 'alimtalk'` 일 때만 그려져(호스트가 `query.channel ?? 'mail'` 을 넘김) 기본 진입으로는 3.1절 C2 가 보이지 않는다 (before 크롤 실측).
 * 상품 상세 `query.tab ?? 'info'` 는 상품 상세 안 정보 탭으로, 상세 화면 기본 진입에서 이미 그려진다.
 */
const TAB_VARIANTS: Record<string, readonly string[]> = {
  '/admin/settings': [
    'general', 'mail', 'upload', 'content', 'seo', 'security', 'member', 'drivers', 'advanced',
    'notification_definitions', 'notification_definitions&channel=alimtalk', 'language_packs', 'identity', 'privacy', 'system', 'update', 'info',
  ],
  '/admin/boards/settings': [
    'general', 'basic_defaults', 'report_policy', 'spam_security', 'seo', 'notification_definitions', 'notification_definitions&channel=alimtalk',
    'identity_policies', 'privacy_retention', 'schedules',
  ],
  '/admin/ecommerce/settings': [
    'basic_info', 'language_currency', 'product_display', 'order_settings', 'shipping', 'claim', 'mileage',
    'review_settings', 'seo', 'notification_definitions', 'notification_definitions&channel=alimtalk', 'identity_policies', 'privacy_retention', 'schedules',
  ],
  '/admin/analytics/actions': ['search', 'events', 'downloads', 'outlinks', 'goals', 'dimensions'],
  '/admin/analytics/bots': ['bots', 'pages'],
  '/admin/analytics/environment': ['browsers', 'devices', 'os', 'resolutions', 'languages', 'locales'],
  '/admin/analytics/pages': ['popular', 'entry', 'exit', 'titles', 'depths', 'durations'],
  '/admin/analytics/referrers': ['types', 'sites', 'keywords', 'mediums', 'campaigns'],
  '/admin/analytics/regions': ['countries', 'regions', 'cities'],
  '/admin/analytics/settings': ['collection', 'privacy', 'retention', 'bots', 'reports'],
  '/admin/plugins/sirsoft-koreaexim/settings': ['settings', 'history'],
  '/admin/plugins/sirsoft-message_bizppurio/settings': ['connection', 'templates'],
  '/admin/plugins/sirsoft-social_login/settings': ['providers', 'policy', 'notifications'],
  '/admin/plugins/sirsoft-sso/logs': ['attempts', 'scim'],
};

/** fixture 가 돌려주는 표본 키 */
type ParamKey =
  | 'board_slug' | 'board_post_id' | 'board_report_id' | 'user_uuid' | 'role_id' | 'product_code'
  | 'category_slug' | 'order_number' | 'claim_number' | 'shipping_policy_id' | 'coupon_id' | 'shop_base'
  | 'page_id' | 'page_slug' | 'popup_id' | 'hero_banner_id' | 'memo_id' | 'visitor_key' | 'form_uuid'
  | 'form_slug' | 'form_submission_uuid' | 'notification_hub_plan_id' | 'notification_hub_attempt_id'
  | 'notification_hub_dead_letter_id' | 'module_identifier' | 'plugin_identifier' | 'user_template_identifier';

/** fixture 출력 */
interface CrawlParams {
  params: Partial<Record<ParamKey, string | null>> & Record<string, unknown>;
  reasons: Partial<Record<ParamKey, string>>;
}

/** 경로 규칙 — 채울 표본 키 목록(고정 값은 `{ fixed }`) 또는 건너뜀 사유 */
type ParamRule =
  | { keys: Record<string, ParamKey | { fixed: string; note: string }> }
  | { expand: Record<string, readonly string[]> }
  | { skip: string };

const OWNER_ONLY = '토큰 계정은 실행마다 새로 만든 시험 회원이라 소유한 주문·클레임이 없다 — 남의 번호로 열면 본인 확인 오류 화면만 그려진다';
const GUEST_ONLY = '비회원 주문 조회 인증(X-Guest-Order-Token, 주문 번호 + 조회 비밀번호)이 있어야 열린다 — 크롤은 비회원 조회 폼을 거치지 않는다';
const PREVIEW_TOKEN = '레이아웃 편집기 미리보기 저장 때만 발급되는 일회성 토큰 경로다';

/**
 * 동적 경로 규칙. 키는 정규화한 경로(앞 `*` 제거, 상점 주소 표현식은 `{shop}`)다.
 * 여기 없는 동적 경로는 `unresolved` 로 실패한다.
 */
const PARAM_RULES: Record<string, ParamRule> = {
  // 관리자 — 코어
  '/admin/users/:id': { keys: { id: 'user_uuid' } },
  '/admin/users/:id/edit': { keys: { id: 'user_uuid' } },
  '/admin/roles/:id/edit': { keys: { id: 'role_id' } },
  '/admin/modules/:identifier/language-packs': { keys: { identifier: 'module_identifier' } },
  '/admin/plugins/:identifier/language-packs': { keys: { identifier: 'plugin_identifier' } },
  '/admin/templates/:identifier/language-packs': { keys: { identifier: 'user_template_identifier' } },
  '/admin/templates/:identifier/edit': { keys: { identifier: 'user_template_identifier' } },
  '/admin/templates/:type': { expand: { type: ['admin', 'user'] } },
  '/admin/analytics/visitors/:key': { keys: { key: 'visitor_key' } },
  '/admin/preview/:token': { skip: PREVIEW_TOKEN },
  // 관리자 — 모듈
  '/admin/form-builder/forms/:id/edit': { keys: { id: 'form_uuid' } },
  '/admin/form-builder/submissions/:id': { keys: { id: 'form_submission_uuid' } },
  '/admin/modules/glitter-notification_hub/plans/:id': { keys: { id: 'notification_hub_plan_id' } },
  '/admin/modules/glitter-notification_hub/attempts/:id': { keys: { id: 'notification_hub_attempt_id' } },
  '/admin/modules/glitter-notification_hub/dead-letters/:id': { keys: { id: 'notification_hub_dead_letter_id' } },
  '/admin/memos/:id/edit': { keys: { id: 'memo_id' } },
  '/admin/boards/:slug/edit': { keys: { slug: 'board_slug' } },
  '/admin/board/:slug/create': { keys: { slug: 'board_slug' } },
  '/admin/board/:slug/:id/edit': { keys: { slug: 'board_slug', id: 'board_post_id' } },
  '/admin/board/:slug/post/:id': { keys: { slug: 'board_slug', id: 'board_post_id' } },
  '/admin/board/:slug': { keys: { slug: 'board_slug' } },
  '/admin/boards/reports/:id': { keys: { id: 'board_report_id' } },
  '/admin/ecommerce/products/:itemCode/edit': { keys: { itemCode: 'product_code' } },
  '/admin/ecommerce/personal-payment/:id': {
    keys: { id: { fixed: '1', note: '개인결제 상세는 아직 데이터소스가 없는 골격 화면이라 route 값을 읽지 않는다' } },
  },
  '/admin/ecommerce/promotion-coupons/:id/edit': { keys: { id: 'coupon_id' } },
  '/admin/ecommerce/orders/:orderNumber': { keys: { orderNumber: 'order_number' } },
  '/admin/ecommerce/claims/:claimNumber': { keys: { claimNumber: 'claim_number' } },
  '/admin/ecommerce/shipping-policies/:id/edit': { keys: { id: 'shipping_policy_id' } },
  '/admin/pages/:id/edit': { keys: { id: 'page_id' } },
  '/admin/pages/:id': { keys: { id: 'page_id' } },
  // 관리자 — 플러그인
  '/admin/plugins/sirsoft-hero_section/banners/:id/edit': { keys: { id: 'hero_banner_id' } },
  '/admin/plugins/sirsoft-popup/popups/:id/edit': { keys: { id: 'popup_id' } },
  // 사용자
  '/board/:slug': { keys: { slug: 'board_slug' } },
  '/board/:slug/write': { keys: { slug: 'board_slug' } },
  '/board/:slug/:id': { keys: { slug: 'board_slug', id: 'board_post_id' } },
  '/board/:slug/:id/edit': { keys: { slug: 'board_slug', id: 'board_post_id' } },
  '{shop}/category/:slug': { keys: { slug: 'category_slug' } },
  '{shop}/products/:product_code': { keys: { product_code: 'product_code' } },
  '{shop}/orders/:id/complete': { skip: OWNER_ONLY },
  '{shop}/reorder/:id': { skip: OWNER_ONLY },
  '{shop}/guest/orders/:order_number': { skip: GUEST_ONLY },
  '{shop}/guest/orders/:order_number/claims/apply/:type': { skip: GUEST_ONLY },
  '{shop}/guest/claims/:claim_number/edit': { skip: GUEST_ONLY },
  '{shop}/guest/claims/:claim_number': { skip: GUEST_ONLY },
  '/mypage/orders/:order_number': { skip: OWNER_ONLY },
  '/mypage/orders/:order_number/claims/apply/:type': { skip: OWNER_ONLY },
  '/mypage/claims/:claim_number/edit': { skip: OWNER_ONLY },
  '/mypage/claims/:claim_number': { skip: OWNER_ONLY },
  '/users/:userId': { keys: { userId: 'user_uuid' } },
  '/users/:userId/posts': { keys: { userId: 'user_uuid' } },
  '/page/:slug': { keys: { slug: 'page_slug' } },
  '/forms/:slug': { keys: { slug: 'form_slug' } },
  '/preview/:token': { skip: PREVIEW_TOKEN },
};

/** routes.json 항목 (이 spec 이 읽는 키만) */
interface RouteEntry {
  path: string;
  layout?: string;
  redirect?: string;
}

/** 열 화면 하나 */
interface Screen {
  kind: 'admin' | 'user';
  routePath: string;
  layout: string;
  url: string;
  tab?: string;
  note?: string;
}

/** 건너뛴 라우트 */
interface Skipped {
  kind: 'admin' | 'user';
  routePath: string;
  layout: string;
  reason: string;
}

/** 중복 id 한 건 */
interface DuplicateId {
  id: string;
  count: number;
  /** 요소 표본(최대 3개) — `tag.class앞부분` */
  samples: string[];
}

/** 화면 하나 × 폭 하나 측정 결과 */
interface Measurement {
  width: number;
  kind: 'admin' | 'user';
  layout: string;
  url: string;
  finalPath: string;
  idCount: number;
  duplicates: DuplicateId[];
  literals: string[];
  networkidleTimeout: boolean;
  mountTimeout: boolean;
  /** id 개수가 안정 대기 한도 안에 멈추지 않았다 */
  unstable: boolean;
  error?: string;
  elapsedMs: number;
}

/**
 * 라우트 path 를 규칙 키로 정규화한다.
 *
 * @param rawPath routes.json 의 path
 * @returns 앞 `*` 를 떼고 상점 주소 표현식을 `{shop}` 으로 바꾼 경로
 */
function normalizePath(rawPath: string): string {
  return rawPath.replace(/^\*/, '').replace(/^\/\{\{[\s\S]*?\}\}/, '{shop}');
}

/**
 * 템플릿 하나의 병합 라우트를 읽는다.
 *
 * @param request API 요청 컨텍스트
 * @param identifier 템플릿 식별자
 * @returns 라우트 목록
 */
async function fetchRoutes(request: APIRequestContext, identifier: string): Promise<RouteEntry[]> {
  const res = await request.get(`/api/templates/${identifier}/routes.json`, { headers: { Accept: 'application/json' } });
  const text = await res.text();
  let body: { success?: boolean; data?: { routes?: unknown } };
  try {
    body = JSON.parse(text);
  } catch {
    throw new Error(`${identifier} routes.json 본문이 JSON 이 아니다 (HTTP ${res.status()}): ${text.slice(0, 200)}`);
  }
  if (body.success !== true || !Array.isArray(body.data?.routes)) {
    throw new Error(`${identifier} routes.json 형태가 예상과 다르다 (HTTP ${res.status()}): ${text.slice(0, 200)}`);
  }
  return (body.data!.routes as RouteEntry[]).filter((r) => typeof r?.path === 'string');
}

/**
 * fixture php 로 동적 파라미터 표본을 읽는다.
 *
 * @returns 표본과 사유
 */
function loadParams(): CrawlParams {
  const stdout = execSync('php tests/Playwright/fixtures/id-crawl-params.php', { cwd: G7_ROOT, encoding: 'utf-8' });
  const start = stdout.indexOf('{');
  return JSON.parse(stdout.slice(start)) as CrawlParams;
}

/**
 * 라우트 목록을 열 화면과 건너뜀으로 나눈다.
 *
 * @param kind 관리자/사용자
 * @param routes 라우트 목록
 * @param crawl fixture 표본
 * @param seen 이미 다룬 경로 (관리자·사용자 공통, path 중복 제거)
 * @returns 화면·건너뜀·규칙 없는 동적 경로
 */
function planScreens(
  kind: 'admin' | 'user',
  routes: RouteEntry[],
  crawl: CrawlParams,
  seen: Map<string, string>,
): { screens: Screen[]; skipped: Skipped[]; unresolved: string[] } {
  const screens: Screen[] = [];
  const skipped: Skipped[] = [];
  const unresolved: string[] = [];

  for (const route of routes) {
    const routePath = normalizePath(route.path);
    const layout = route.layout ?? '';
    if (seen.has(routePath)) {
      skipped.push({
        kind,
        routePath,
        layout,
        reason: `같은 path 를 ${seen.get(routePath)} 가 먼저 선언했다 — 화면은 path 하나에 하나만 매칭되므로 그 측정으로 갈음한다`,
      });
      continue;
    }
    seen.set(routePath, layout || '(redirect)');

    if (route.redirect) {
      skipped.push({ kind, routePath, layout, reason: `redirect 라우트 (→ ${route.redirect}) — 대상 화면을 따로 연다` });
      continue;
    }

    let base = routePath;
    if (base.startsWith('{shop}')) {
      const shop = crawl.params.shop_base;
      if (typeof shop !== 'string') {
        skipped.push({ kind, routePath, layout, reason: crawl.reasons.shop_base ?? '상점 경로를 구하지 못했다' });
        continue;
      }
      base = shop + base.slice('{shop}'.length);
    }

    const paramNames = [...base.matchAll(/:([A-Za-z_]+)/g)].map((m) => m[1]);
    if (paramNames.length === 0) {
      screens.push({ kind, routePath, layout, url: base || '/' });
      continue;
    }

    const rule = PARAM_RULES[routePath];
    if (!rule) {
      unresolved.push(`${kind} ${routePath} (${layout}) — PARAM_RULES 에 규칙이 없다`);
      continue;
    }
    if ('skip' in rule) {
      skipped.push({ kind, routePath, layout, reason: rule.skip });
      continue;
    }
    if ('expand' in rule) {
      const [name, values] = Object.entries(rule.expand)[0];
      for (const value of values) {
        screens.push({ kind, routePath, layout, url: base.replace(`:${name}`, value), note: `${name}=${value}` });
      }
      continue;
    }

    let url = base;
    const notes: string[] = [];
    let missing: string | null = null;
    for (const name of paramNames) {
      const source = rule.keys[name];
      if (source === undefined) {
        missing = `PARAM_RULES 규칙에 :${name} 값이 없다`;
        break;
      }
      if (typeof source === 'object') {
        url = url.replace(`:${name}`, source.fixed);
        notes.push(`${name}=${source.fixed} (${source.note})`);
        continue;
      }
      const value = crawl.params[source];
      if (typeof value !== 'string' || value === '') {
        missing = `${source}: ${crawl.reasons[source] ?? '표본 값이 없다'}`;
        break;
      }
      url = url.replace(`:${name}`, encodeURIComponent(value));
    }
    if (missing !== null) {
      if (missing.startsWith('PARAM_RULES')) unresolved.push(`${kind} ${routePath} (${layout}) — ${missing}`);
      else skipped.push({ kind, routePath, layout, reason: missing });
      continue;
    }
    screens.push({ kind, routePath, layout, url, note: notes.length > 0 ? notes.join(', ') : undefined });
  }

  return { screens, skipped, unresolved };
}

/**
 * 탭 변종을 더한다.
 *
 * @param screens 기본 화면 목록
 * @returns 기본 화면 + `?tab=` 변종
 */
function withTabVariants(screens: Screen[]): Screen[] {
  const out: Screen[] = [];
  for (const screen of screens) {
    out.push(screen);
    for (const tab of TAB_VARIANTS[screen.url] ?? []) {
      out.push({ ...screen, url: `${screen.url}?tab=${tab}`, tab });
    }
  }
  return out;
}

/**
 * 화면 하나를 열어 id 를 잰다.
 *
 * @param page Playwright 페이지
 * @param screen 열 화면
 * @param width 폭
 * @returns 측정 결과
 */
async function measure(page: Page, screen: Screen, width: number): Promise<Measurement> {
  const started = Date.now();
  const result: Measurement = {
    width,
    kind: screen.kind,
    layout: screen.layout,
    url: screen.url,
    finalPath: '',
    idCount: 0,
    duplicates: [],
    literals: [],
    networkidleTimeout: false,
    mountTimeout: false,
    unstable: false,
    elapsedMs: 0,
  };

  try {
    try {
      await page.goto(screen.url, { waitUntil: 'networkidle', timeout: NAV_TIMEOUT_MS });
    } catch (error) {
      if (!(error instanceof Error) || !/Timeout/i.test(error.name + error.message)) throw error;
      // 폴링·스트리밍 화면은 networkidle 에 닿지 않는다 — 그 시점 DOM 을 잰다.
      result.networkidleTimeout = true;
    }

    try {
      if (screen.kind === 'admin') {
        await page.waitForSelector('#main_content', { state: 'attached', timeout: MOUNT_TIMEOUT_MS });
      } else {
        await page.waitForFunction(() => (document.getElementById('app')?.childElementCount ?? 0) > 0, undefined, {
          timeout: MOUNT_TIMEOUT_MS,
        });
      }
    } catch {
      result.mountTimeout = true;
    }
    // networkidle 에 닿지 못한 화면도 그려지는 중이면 기다린다 — id 개수가 연속 3번 같을 때까지.
    const stableDeadline = Date.now() + STABLE_TIMEOUT_MS;
    let last = -1;
    let same = 0;
    while (Date.now() < stableDeadline && same < 2) {
      const n = await page.evaluate(() => document.querySelectorAll('[id]').length);
      same = n === last ? same + 1 : 0;
      last = n;
      if (same < 2) await page.waitForTimeout(STABLE_POLL_MS);
    }
    result.unstable = same < 2;
    await page.waitForTimeout(SETTLE_MS);

    const collected = await page.evaluate(() => {
      const counts = new Map<string, Element[]>();
      document.querySelectorAll('[id]').forEach((el) => {
        if (!el.id) return;
        const list = counts.get(el.id) ?? [];
        list.push(el);
        counts.set(el.id, list);
      });
      const describe = (el: Element): string => {
        const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
        return cls ? `${el.tagName.toLowerCase()}.${cls}` : el.tagName.toLowerCase();
      };
      const duplicates = [...counts.entries()]
        .filter(([, els]) => els.length > 1)
        .map(([id, els]) => ({ id, count: els.length, samples: els.slice(0, 3).map(describe) }))
        .sort((a, b) => a.id.localeCompare(b.id));
      const literals = [...counts.keys()].filter((id) => id.includes('{{') || id.includes('}}')).sort();
      return { idCount: counts.size, duplicates, literals, path: location.pathname + location.search };
    });
    result.idCount = collected.idCount;
    result.duplicates = collected.duplicates;
    result.literals = collected.literals;
    result.finalPath = collected.path;
  } catch (error) {
    result.error = error instanceof Error ? error.message.split('\n')[0] : String(error);
  }

  result.elapsedMs = Date.now() - started;
  return result;
}

/**
 * 보고서를 쓴다 (화면마다 덮어써 중간에 끊겨도 남는다).
 *
 * @param report 보고서 객체
 */
function writeReport(report: unknown): void {
  mkdirSync(dirname(REPORT_PATH), { recursive: true });
  writeFileSync(REPORT_PATH, `${JSON.stringify(report, null, 2)}\n`, 'utf-8');
}

// 증거는 보고서 JSON 이다. 실패 시 스크린샷은 마지막 화면 한 장뿐이라 쓸모가 없고, 느린 서버에서는
// 두 페이지 촬영이 실패 후 처리 한도(30초)를 넘겨 「Test timeout」 이 덧붙는다(2026-11-23 실측).
// 몇 시간짜리 trace 도 남기지 않는다. (describe 안의 use 는 새 worker 를 강제해 금지되므로 파일 최상위에 둔다)
test.use({ screenshot: 'off', trace: 'off' });

test.describe('@id-duplicate 전 화면 DOM id 유일성 크롤', () => {
  // @scenario width=desktop_1440, route_kind=admin
  // @scenario width=desktop_1440, route_kind=user
  // @scenario width=mobile_390, route_kind=admin
  // @scenario width=mobile_390, route_kind=user
  // @effects dom_id_unique_all_routes, no_uninterpolated_id_literal, skipped_routes_have_reason
  test('관리자·사용자 전 화면을 1440px·390px 로 열면 중복 id 0건, 보간 잔재 0건이다', async ({ page, request }) => {
    test.setTimeout(8 * 60 * 60 * 1000);
    const runStarted = Date.now();

    const token = issueToken();
    await authenticatePage(page, token);

    const crawl = loadParams();
    const seen = new Map<string, string>();
    const routeCounts: Record<string, number> = {};
    const screens: Screen[] = [];
    const skipped: Skipped[] = [];
    const unresolved: string[] = [];
    for (const identifier of ROUTE_SOURCES) {
      const kind = identifier === 'sirsoft-admin_basic' ? 'admin' : 'user';
      const routes = await fetchRoutes(request, identifier);
      routeCounts[identifier] = routes.length;
      const planned = planScreens(kind, routes, crawl, seen);
      screens.push(...withTabVariants(planned.screens));
      skipped.push(...planned.skipped);
      unresolved.push(...planned.unresolved);
    }
    // 일부 화면만 다시 잴 때(중단·네트워크 끊김 뒤 재측정) — 경로 정규식. 비우면 전 화면이다
    const only = process.env.G7_E2E_ID_CRAWL_ONLY ? new RegExp(process.env.G7_E2E_ID_CRAWL_ONLY) : null;
    if (only) screens.splice(0, screens.length, ...screens.filter((s) => only.test(s.url)));

    const results: Measurement[] = [];
    const report = {
      issue: '#408',
      generatedAt: new Date().toISOString(),
      finishedAt: null as string | null,
      elapsedMs: 0,
      routeCounts,
      params: crawl.params,
      paramReasons: crawl.reasons,
      widths: WIDTHS.map((w) => w.width),
      screenCount: screens.length,
      screens,
      skipped,
      unresolved,
      results,
      summary: {} as Record<string, unknown>,
    };
    writeReport(report);

    // 폭마다 페이지 하나씩 두고 동시에 돈다. 개발 서버 응답이 요청당 약 2초라(2026-11-23 실측) 화면 하나가
    // 25~40초 걸리고, 한 페이지로 두 폭을 차례로 돌면 516회 측정이 4시간을 넘는다. 두 페이지는 같은 토큰·
    // 같은 컨텍스트를 쓰고 서로의 상태를 바꾸지 않는다(GET 화면 열기만 한다).
    const pages: Page[] = [page];
    for (let i = 1; i < WIDTHS.length; i += 1) {
      const extra = await page.context().newPage();
      await authenticatePage(extra, token);
      pages.push(extra);
    }
    await Promise.all(
      WIDTHS.map(async (viewport, i) => {
        const p = pages[i];
        await p.setViewportSize(viewport);
        for (const screen of screens) {
          results.push(await measure(p, screen, viewport.width));
          writeReport(report);
        }
      }),
    );
    results.sort((a, b) => b.width - a.width);

    // 요약 — id 별로 어느 화면·폭에서 몇 개였는지
    const byId: Record<string, { maxCount: number; screens: string[] }> = {};
    for (const r of results) {
      for (const d of r.duplicates) {
        const entry = (byId[d.id] ??= { maxCount: 0, screens: [] });
        entry.maxCount = Math.max(entry.maxCount, d.count);
        entry.screens.push(`${r.width} ${r.url} ×${d.count}`);
      }
    }
    report.summary = {
      measuredByWidth: Object.fromEntries(WIDTHS.map((w) => [w.width, results.filter((r) => r.width === w.width && !r.error).length])),
      screensWithDuplicatesByWidth: Object.fromEntries(
        WIDTHS.map((w) => [w.width, results.filter((r) => r.width === w.width && r.duplicates.length > 0).length]),
      ),
      duplicateIdCount: Object.keys(byId).length,
      duplicateIds: byId,
      literalScreens: results.filter((r) => r.literals.length > 0).map((r) => `${r.width} ${r.url}: ${r.literals.join(', ')}`),
      errors: results.filter((r) => r.error).map((r) => `${r.width} ${r.url}: ${r.error}`),
      networkidleTimeouts: results.filter((r) => r.networkidleTimeout).map((r) => `${r.width} ${r.url}`),
      mountTimeouts: results.filter((r) => r.mountTimeout).map((r) => `${r.width} ${r.url}`),
      unstable: results.filter((r) => r.unstable).map((r) => `${r.width} ${r.url}`),
    };
    report.finishedAt = new Date().toISOString();
    report.elapsedMs = Date.now() - runStarted;
    writeReport(report);

    expect.soft(unresolved, '규칙이 없는 동적 경로 (사유 없는 건너뜀)').toEqual([]);
    expect.soft(skipped.filter((s) => !s.reason.trim()), '사유가 빈 건너뜀').toEqual([]);
    expect.soft(report.summary.errors, '화면을 열거나 재는 중 오류').toEqual([]);
    expect.soft(report.summary.literalScreens, '`{{` 가 남은 id').toEqual([]);
    expect(
      results.filter((r) => r.duplicates.length > 0).map((r) => `${r.width} ${r.url}: ${r.duplicates.map((d) => `${d.id}×${d.count}`).join(', ')}`),
      '같은 id 가 2개 이상인 화면',
    ).toEqual([]);
  });
});
