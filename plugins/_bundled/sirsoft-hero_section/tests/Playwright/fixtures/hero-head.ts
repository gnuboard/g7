/**
 * 히어로섹션 E2E 보조 — 계정을 실행 환경변수로 받는 spec 의 공통 준비(토큰·쓰기 차단)와 수정 전 대조 스위치.
 *
 * 계정(토큰·비밀번호는 출력하지 않는다):
 *   - `G7_E2E_TOKEN` 이 있으면 그 토큰을 쓴다.
 *   - 없으면 코어 `tests/Playwright/fixtures/admin-login.ts` 의 `adminToken` — `G7_E2E_ADMIN_EMAIL` + `G7_E2E_ADMIN_PASSWORD_FILE`(비밀번호 파일 경로)
 *     로그인, 둘 다 없으면 `playwright:issue-token` 발급(저장소 기본 경로).
 *
 * 쓰기 차단: GET·HEAD·OPTIONS 가 아닌 `/api/`(하위 폴더 흉내의 `/g7/api/` 포함) 요청은 네트워크 단에서 끊고 기록한다(XHR·fetch 모두).
 *   개발 DB 를 바꾸지 않는다 — 배너 데이터는 응답을 route fulfill 로 만들어 화면을 잰다.
 *
 * 수정 전 대조(기본 꺼짐): `G7_E2E_HERO_HEAD_DIR=<폴더>` 를 주면 이 브라우저에서만 히어로섹션 화면을 그 폴더의 수정 전 파일로
 *   바꿔 끼운다(서버 파일 불변). 폴더에는 다음 네 파일을 둔다(`git show HEAD:<경로> > <폴더>/<이름>`).
 *     - `plugin.iife.js`                 ← plugins/_bundled/sirsoft-hero_section/dist/js/plugin.iife.js
 *     - `hero_section_banner_list.json`  ← plugins/_bundled/sirsoft-hero_section/resources/layouts/admin/hero_section_banner_list.json
 *     - `hero_section_banner_form.json`  ← plugins/_bundled/sirsoft-hero_section/resources/layouts/admin/hero_section_banner_form.json
 *     - `ko.json`                        ← plugins/_bundled/sirsoft-hero_section/resources/lang/ko.json
 *   바꿔 끼우는 방법: 플러그인 병합 번들(`/api/plugins/bundle/js`) 안의 현재 히어로 번들 구간을 수정 전 번들로 바꾸고, 레이아웃·언어
 *   응답에서는 현재 파일과 수정 전 파일이 다른 글자 값만 수정 전 값으로 되돌린다(수정 전에 없던 칸은 지운다). 바꿔 끼우지 못하면
 *   (현재 번들 구간을 응답에서 못 찾는 등) 조용히 넘어가지 않고 시험을 실패시킨다.
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { APIRequestContext, Page, Route } from '@playwright/test';
import { adminToken } from '../../../../../../tests/Playwright/fixtures/admin-login';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 코어 루트 — 서버가 실제로 서빙하는 활성 디렉토리 파일을 읽는다 */
const CORE_ROOT = process.env.G7_ROOT || resolve(__dirname, '../../../../../../');

/** 활성 플러그인 디렉토리(병합 번들·레이아웃·언어의 실제 출처) */
const ACTIVE = resolve(CORE_ROOT, 'plugins/sirsoft-hero_section');

/** 수정 전 대조 폴더 — 없으면 스위치 꺼짐 */
export const HEAD_DIR = process.env.G7_E2E_HERO_HEAD_DIR || '';

/** 발급 경로일 때 넘길 권한 — 목록·등록 화면 진입 */
const PERMISSIONS = ['sirsoft-hero_section.banners.read', 'sirsoft-hero_section.banners.update'];

/**
 * 관리자 토큰을 얻는다(출력하지 않는다).
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns Sanctum 토큰
 */
export async function resolveToken(request: APIRequestContext): Promise<string> {
  if (process.env.G7_E2E_TOKEN) return process.env.G7_E2E_TOKEN;

  return adminToken(request, ...PERMISSIONS);
}

/**
 * 쓰기 요청을 끊고 기록한다. 화면별 모의 응답은 이 뒤에 등록한다(Playwright 는 나중에 건 route 를 먼저 탄다).
 *
 * @param page 페이지
 * @returns 끊은 요청 기록(`METHOD 경로`)
 */
export async function guardWrites(page: Page): Promise<string[]> {
  const writes: string[] = [];
  await page.route(
    (url) => /^(\/g7)?\/api\//.test(url.pathname),
    (route) => {
      const req = route.request();
      if (['GET', 'HEAD', 'OPTIONS'].includes(req.method())) return route.fallback();
      writes.push(`${req.method()} ${new URL(req.url()).pathname}`);

      return route.abort();
    },
  );

  return writes;
}

type Pair = { key: string; cur: string; head: string | undefined };

/**
 * 현재 파일과 수정 전 파일에서 값이 다른 글자 칸을 모은다(수정 전에 없던 칸은 head=undefined).
 *
 * @param cur 현재 JSON
 * @param head 수정 전 JSON
 * @param out 누적
 * @returns 바뀐 칸 목록
 */
function diffPairs(cur: unknown, head: unknown, out: Pair[] = []): Pair[] {
  if (!cur || typeof cur !== 'object') return out;
  for (const key of Object.keys(cur as Record<string, unknown>)) {
    const c = (cur as Record<string, unknown>)[key];
    const h = head && typeof head === 'object' ? (head as Record<string, unknown>)[key] : undefined;
    if (typeof c === 'string') {
      if (h === undefined) out.push({ key, cur: c, head: undefined });
      else if (typeof h === 'string' && h !== c) out.push({ key, cur: c, head: h });
    } else if (c && typeof c === 'object') {
      diffPairs(c, h, out);
    }
  }

  return out;
}

/**
 * 응답 JSON 안에서 현재 값과 같은 칸을 수정 전 값으로 되돌린다.
 *
 * @param node 응답 JSON 노드
 * @param pairs 바뀐 칸
 * @param hits 칸별 적용 횟수
 */
function applyPairs(node: unknown, pairs: Pair[], hits: number[]): void {
  if (!node || typeof node !== 'object') return;
  const obj = node as Record<string, unknown>;
  for (const key of Object.keys(obj)) {
    const value = obj[key];
    if (typeof value === 'string') {
      pairs.forEach((pair, i) => {
        if (pair.key !== key || pair.cur !== value) return;
        if (pair.head === undefined) delete obj[key];
        else obj[key] = pair.head;
        hits[i] += 1;
      });
    } else {
      applyPairs(value, pairs, hits);
    }
  }
}

/** 바꿔 끼운 횟수 — 시험이 「수정 전 판이 실제로 쓰였다」를 단언하는 데 쓴다 */
export type HeadSwap = { bundle: number; layouts: Record<string, number>; lang: number; errors: string[] };

/**
 * 수정 전 대조 스위치가 켜져 있으면 번들·레이아웃·언어 응답을 수정 전 판으로 바꿔 끼운다.
 *
 * @param page 페이지
 * @returns 바꿔 끼운 기록(스위치가 꺼져 있으면 null)
 */
export async function useHeadHero(page: Page): Promise<HeadSwap | null> {
  if (!HEAD_DIR) return null;
  const file = (name: string) => {
    const path = resolve(HEAD_DIR, name);
    if (!existsSync(path)) throw new Error(`G7_E2E_HERO_HEAD_DIR 에 ${name} 이 없습니다`);

    return readFileSync(path, 'utf-8');
  };
  const swap: HeadSwap = { bundle: 0, layouts: {}, lang: 0, errors: [] };

  // 1. 병합 번들 안의 히어로 구간
  const headBundle = file('plugin.iife.js');
  const curBundle = readFileSync(resolve(ACTIVE, 'dist/js/plugin.iife.js'), 'utf-8');
  await page.route((url) => url.pathname.endsWith('/api/plugins/bundle/js'), async (route: Route) => {
    const response = await route.fetch();
    const body = await response.text();
    const parts = body.split(curBundle);
    if (parts.length !== 2) {
      swap.errors.push(`병합 번들에서 현재 히어로 번들 구간을 ${parts.length - 1}개 찾음(1개여야 함)`);

      return route.fulfill({ response, body });
    }
    swap.bundle += 1;

    return route.fulfill({ response, body: parts.join(headBundle), headers: { ...response.headers(), 'content-type': 'application/javascript' } });
  });

  // 2. 관리자 레이아웃 두 개
  for (const name of ['hero_section_banner_list', 'hero_section_banner_form']) {
    const pairs = diffPairs(
      JSON.parse(readFileSync(resolve(ACTIVE, `resources/layouts/admin/${name}.json`), 'utf-8')),
      JSON.parse(file(`${name}.json`)),
    );
    swap.layouts[name] = 0;
    await page.route((url) => url.pathname.endsWith(`/api/layouts/sirsoft-admin_basic/sirsoft-hero_section.${name}`), async (route: Route) => {
      const response = await route.fetch();
      const body = await response.json();
      const hits = pairs.map(() => 0);
      applyPairs(body?.data, pairs, hits);
      const missed = pairs.filter((_, i) => hits[i] === 0).map((p) => `${p.key}=${p.cur.slice(0, 40)}`);
      if (missed.length) swap.errors.push(`${name}: 되돌리지 못한 칸 ${missed.join(', ')}`);
      else swap.layouts[name] += 1;

      return route.fulfill({ response, json: body });
    });
  }

  // 3. 관리자 템플릿 언어(ko) 안의 히어로섹션 문구
  const langPairs = diffPairs(JSON.parse(readFileSync(resolve(ACTIVE, 'resources/lang/ko.json'), 'utf-8')), JSON.parse(file('ko.json')));
  await page.route((url) => /\/api\/templates\/sirsoft-admin_basic\/lang\/ko$/.test(url.pathname), async (route: Route) => {
    const response = await route.fetch();
    const body = await response.json();
    const hits = langPairs.map(() => 0);
    applyPairs(body?.['sirsoft-hero_section'], langPairs, hits);
    if (hits.some((n) => n === 0)) swap.errors.push('언어(ko): 되돌리지 못한 문구가 있음');
    else swap.lang += 1;

    return route.fulfill({ response, json: body });
  });

  return swap;
}

/** 1x1 PNG — 모의 이미지 응답 */
export const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
);
