/**
 * @file navigation-links.test.ts
 * @description 사용자 템플릿 레이아웃의 화면 이동 자리는 주소를 가진 링크로 그린다 (#757)
 *
 * 검증 방식: 레이아웃 굽기 산출물(JSON) 전수의 정적 판정(DOM 비의존).
 *
 * 결함: 게시글 행·상품 카드·마이페이지 메뉴·탭 칩·페이지 번호 등 이동 자리가 `<button>` + click navigate 라
 *   렌더 시점에 주소가 없었다. 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되지 않고, 화면 낭독기는 「단추」로 읽었다.
 *
 * 판정 규칙:
 *   - 클릭 이동 = click 액션이 주소를 바꾸는 단계(navigate · replaceUrl)를 직접(또는 sequence 바로 아래에) 가진 노드.
 *     switch 갈래 안의 이동도 센다(갈래마다 — 회원·권한 갈래의 화면 이동이 분기 속에 숨지 않게)
 *     쿼리 키 이름(tab·type·page·period…)이나 같은 라우트 여부, 단계 순서에 기대지 않는다 — 같은 화면에서 쿼리만 바꾸는 탭·쪽 이동도 포함
 *   - 클릭 이동 노드는 `A` 이고 비지 않은 `href` 를 가진다(아래 예외 목록만 빼고)
 *   - 경로 없는 navigate 는 실제 주소 링크(`A` + `href`)의 click 에서만 쓴다 — 엔진이 그 링크의 href 로 이동한다
 *   - sequence 안 navigate 는 경로를 그대로 두고, 그 경로가 href 의 경로 부분과 같아야 한다(보통 클릭과 새 탭이 같은 곳)
 *   - 링크의 `mergeQuery` 와 경로 없는 navigate 의 `mergeQuery` 는 짝을 이룬다(새 탭 주소와 SPA 이동이 같은 쿼리)
 *   - onPageChange 에서 주소를 바꾸는(navigate · replaceUrl) Pagination 은 `pageHref` 를 가진다(페이지 번호가 링크)
 *
 * 모집단: layouts/** 의 굽기 산출물 JSON 전부. 비회원 주문 조회 화면(shop/guest_*)은 뺀다 —
 *   비회원 조회 토큰은 탭에 묶여(sessionStorage) 새 탭에 실리지 않아, 새 탭으로 열면 조회 폼으로 되돌아간다.
 *   그 화면들과 함께 쓰는 조각은 셸이 세운 `_local.isGuest` 로 갈래를 나눈다 — 회원 갈래는 링크, 비회원 갈래는 단추.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');

type Node = Record<string, any>;
type Action = Record<string, any>;

const walk = (dir: string, out: string[] = []): string[] => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (p.endsWith('.json')) out.push(p);
  }

  return out;
};

const rel = (file: string): string => path.relative(LAYOUT_ROOT, file).split(path.sep).join('/');

const FILES = walk(LAYOUT_ROOT).filter((f) => !/^shop\/guest_/.test(rel(f)));

/**
 * 링크로 바꾸지 않는 클릭 이동 — 파일 · 노드 · navigate 경로 · 사유.
 * 목록이 실제와 어긋나면(새 단추 이동이 생기거나 예외가 사라지면) 시험이 실패한다.
 */
const EXCEPTIONS: Array<{ file: string; node: string; path: string; reason: string }> = [
  { file: 'auth/reset_password.json', node: 'Modal', path: '/', reason: '모달 자체의 닫기 이동 — 누를 링크 요소가 없다' },
  { file: 'partials/board/form/_password_verify_modal.json', node: 'Modal', path: '/board/{{route.slug}}/{{route.id}}', reason: '모달 자체의 닫기 이동 — 누를 링크 요소가 없다' },
  { file: 'partials/search/_search_input.json', node: 'Button', path: '/search', reason: '검색 실행 — 입력한 검색어로 이동하는 제출 단추' },
  { file: 'partials/shop/list/_search_filter_bar.json', node: 'Button', path: '{{_global.shopBase}}/products', reason: '검색 실행 — 입력한 검색어로 이동하는 제출 단추' },
  { file: 'partials/shop/list/_search_filter_bar.json', node: 'Button', path: '{{_global.shopBase}}/products', reason: '검색 초기화 — 입력값을 비우는 단추' },
  { file: 'shop/category.json', node: 'Button', path: '{{route.path}}', reason: '검색 실행 — 입력한 검색어로 이동하는 제출 단추' },
  { file: 'partials/mypage/claims/_list.json', node: 'Span', path: "{{(_local.claimDetailPathBase ?? '') + '/' + (claim.claim_number ?? '')}}", reason: '비회원 주문상세 셸 전용 번호(same_window) — 조회 토큰이 탭에 묶여 새 탭은 조회 폼으로' },
  { file: 'shop/order_complete.json', node: 'Button', path: "{{(_global.shopBase ?? '/shop') + '/guest/orders/' + orderData?.data?.order_number}}", reason: '비회원 갈래 — 비회원 주문 조회 토큰이 탭에 묶여 새 탭은 조회 폼으로(회원 갈래는 링크)' },
  { file: 'partials/board/index/_write_button.json', node: 'Button', path: '/login', reason: '비로그인 로그인 유도 — 안내 후 로그인 화면으로(권한 있는 회원 갈래는 링크)' },
  { file: 'partials/board/show/_comment_item.json', node: 'Button', path: '/login', reason: '댓글 반응 단추의 비로그인 로그인 유도 — 단추 본래 동작은 반응 API' },
  { file: 'partials/board/types/basic/show.json', node: 'Button', path: '/login', reason: '글 반응 단추의 비로그인 로그인 유도 — 단추 본래 동작은 반응 API' },
];

/** click 액션 — 레이아웃 JSON 의 두 표기(`type: "click"` · `event: "onClick"`)를 모두 센다 */
const isClick = (a: Action): boolean => a?.type === 'click' || a?.event === 'onClick';

/**
 * 비회원 범위(셸·포함 조각)에서 회원 갈래 밖에 서도 되는 링크 — 파일 · href · 사유.
 * 목록이 실제와 어긋나면 시험이 실패한다.
 */
const GUEST_SCOPE_LINK_EXCEPTIONS: Array<{ file: string; href: string; reason: string }> = [
  { file: 'partials/mypage/claims/_detail_items.json', href: '{{item.collection_tracking_url}}', reason: '택배사 배송조회(바깥 사이트, 새 창) — 조회 토큰과 무관' },
  { file: 'partials/mypage/claims/_detail_items.json', href: '{{item.redelivery_tracking_url}}', reason: '택배사 배송조회(바깥 사이트, 새 창) — 조회 토큰과 무관' },
  { file: 'partials/mypage/claims/_detail_shipping.json', href: '{{group.tracking_url}}', reason: '택배사 배송조회(바깥 사이트, 새 창) — 조회 토큰과 무관' },
  { file: 'partials/mypage/orders/_shipping.json', href: '{{shipping.tracking_url}}', reason: '택배사 배송조회(바깥 사이트, 새 창) — 조회 토큰과 무관' },
  {
    file: 'partials/mypage/claims/_list.json',
    href: "{{(_local.claimDetailPathBase ?? '') + '/' + (claim.claim_number ?? '')}}",
    reason: '새 창 상세 번호 링크 — `claimDetailLinkMode: new_window` 는 회원 주문 상세 셸만 세우고 비회원 셸은 same_window(단추)',
  },
];

/**
 * 비회원 셸과 그 셸이 재귀로 포함하는 조각에서 회원 갈래(`!_local.isGuest`) 밖에 선 링크를 찾는다.
 * 비회원 조회 토큰은 탭에 묶여(sessionStorage) 새 탭에 실리지 않는다 — 새 탭으로 열면 조회 폼으로 되돌아간다.
 * 링크 = 액션 유무와 무관한 `A`(href) · `Pagination.pageHref`(비회원이면 빈 주소가 되는 식이어야 함) · 탭 `href`.
 *
 * @param read 레이아웃 상대 경로 → JSON
 * @param shells 비회원 셸 경로들
 * @param exists 경로가 있는지
 * @returns 살펴본 파일과 위반(예외 목록 뺀 것)
 */
const guestScopeLinkViolations = (
  read: (r: string) => Node,
  shells: string[],
  exists: (r: string) => boolean,
): { scope: string[]; bad: string[] } => {
  const partialsOf = (json: unknown): string[] => {
    const out: string[] = [];
    const w = (n: unknown): void => {
      if (Array.isArray(n)) return n.forEach(w);
      if (!n || typeof n !== 'object') return;
      const node = n as Node;
      if (typeof node.partial === 'string') out.push(node.partial);
      for (const k of Object.keys(node)) w(node[k]);
    };
    w(json);

    return out;
  };
  const scope: string[] = [];
  const queue = [...shells];
  while (queue.length) {
    const p = queue.shift()!;
    if (scope.includes(p) || !exists(p)) continue;
    scope.push(p);
    queue.push(...partialsOf(read(p)));
  }

  const memberOnly = (conds: unknown[]): boolean => conds.some((c) => /!\s*_local\.isGuest/.test(String(c ?? '')));
  const excepted = (file: string, href: string): boolean => GUEST_SCOPE_LINK_EXCEPTIONS.some((e) => e.file === file && e.href === href);
  const bad: string[] = [];
  for (const p of scope) {
    const w = (n: unknown, conds: unknown[]): void => {
      if (Array.isArray(n)) return n.forEach((c) => w(c, conds));
      if (!n || typeof n !== 'object') return;
      const node = n as Node;
      const here = [...conds, node.if];
      if (node.name === 'A' && node.props?.href && !memberOnly(here) && !excepted(p, String(node.props.href))) bad.push(`${p} | A | ${node.props.href}`);
      if (node.name === 'Pagination' && node.props?.pageHref && !memberOnly(here) && !/_local\.isGuest\s*\?\s*''/.test(String(node.props.pageHref))) bad.push(`${p} | Pagination | ${node.props.pageHref}`);
      if (node.name === 'TabNavigation' && (node.props?.tabs ?? []).some((t: Node) => t?.href) && !memberOnly(here)) bad.push(`${p} | TabNavigation`);
      for (const k of Object.keys(node)) w(node[k], here);
    };
    w(read(p), []);
  }

  return { scope, bad };
};

/** 비회원 갈래 — 셸이 세운 `_local.isGuest` 가 참일 때만 서는 노드(부정 `!_local.isGuest` 는 회원 갈래) */
const isGuestBranch = (n: Node): boolean => {
  const cond = String(n.if ?? '');

  return /_local\.isGuest/.test(cond) && !/!\s*_local\.isGuest/.test(cond);
};

/** 주소를 바꾸는 단계 — 액션 자체 또는 sequence 바로 아래의 navigate · replaceUrl(키 이름·순서 무관) */
const addressStep = (a: Action): Action | null => {
  const steps: Action[] = a.handler === 'sequence' && Array.isArray(a.actions) ? a.actions : [a];

  return steps.find((x) => x.handler === 'navigate' || x.handler === 'replaceUrl') ?? null;
};

/**
 * click 액션의 주소 바꾸는 단계 전부 — 액션 자체 · sequence 바로 아래 · switch 각 갈래(갈래 안 sequence 바로 아래 포함).
 *
 * @param a click 액션
 * @returns 주소 바꾸는 단계 목록
 */
const clickMoves = (a: Action): Action[] => {
  if (a.handler === 'switch' && a.cases && typeof a.cases === 'object') {
    return Object.values(a.cases as Record<string, Action>).flatMap((c) => {
      const step = c && typeof c === 'object' ? addressStep(c) : null;
      return step ? [step] : [];
    });
  }
  const step = addressStep(a);

  return step ? [step] : [];
};

/** click 액션의 이동 단계 — navigate 자체 또는 sequence 의 마지막 단계인 유일한 navigate(href·이동 경로 대조용) */
const moveOf = (a: Action): Action | null => {
  if (a.handler === 'navigate') return a;
  if (a.handler === 'sequence' && Array.isArray(a.actions)) {
    const navs = a.actions.filter((x: Action) => x.handler === 'navigate');
    if (navs.length === 1 && a.actions[a.actions.length - 1] === navs[0]) return navs[0];
  }

  return null;
};

interface Visit { file: string; node: Node }

const visitNodes = (cb: (v: Visit) => void): void => {
  for (const file of FILES) {
    const json = JSON.parse(fs.readFileSync(file, 'utf8'));
    const seen = new Set<object>();
    const w = (n: unknown): void => {
      if (Array.isArray(n)) return n.forEach(w);
      if (!n || typeof n !== 'object' || seen.has(n)) return;
      seen.add(n);
      const node = n as Node;
      if (typeof node.name === 'string' && Array.isArray(node.actions)) cb({ file: rel(file), node });
      for (const k of Object.keys(node)) w(node[k]);
    };
    w(json);
  }
};

const hrefPathPart = (href: string): string => {
  // 식({{…}}) 밖의 첫 ? 앞까지가 경로다
  let depth = 0;
  for (let i = 0; i < href.length; i++) {
    if (href.startsWith('{{', i)) { depth++; i++; continue; }
    if (href.startsWith('}}', i)) { depth--; i++; continue; }
    if (depth === 0 && href[i] === '?') return href.slice(0, i);
  }

  return href;
};

describe('사용자 템플릿 레이아웃 — 화면 이동은 링크', () => {
  // @scenario component=layout
  // @effects layout_moves_are_links
  it('모집단이 비어 있지 않다', () => {
    expect(FILES.length).toBeGreaterThan(50);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('클릭 이동 노드는 href 를 가진 A 다(예외 목록 제외)', () => {
    const offenders: Array<{ file: string; node: string; path: string }> = [];
    let links = 0;
    visitNodes(({ file, node }) => {
      for (const a of node.actions) {
        if (!isClick(a)) continue;
        for (const nav of clickMoves(a)) {
          const href = node.props?.href;
          if (node.name === 'A' && typeof href === 'string' && href.trim() !== '') {
            links++;
            continue;
          }
          if (isGuestBranch(node)) continue;
          offenders.push({ file, node: node.name, path: String(nav.params?.path ?? '') });
        }
      }
    });

    const key = (x: { file: string; node: string; path: string }) => `${x.file} | ${x.node} | ${x.path}`;
    expect(offenders.map(key).sort()).toEqual(EXCEPTIONS.map(key).sort());
    expect(links).toBeGreaterThan(150);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('비회원 화면과 함께 쓰는 조각의 비회원 갈래 단추마다 같은 자리의 회원 갈래 링크가 있다', () => {
    const guest: string[] = [];
    const twins: string[] = [];
    visitNodes(({ file, node }) => {
      const moves = node.actions.filter((a: Action) => isClick(a) && addressStep(a));
      if (moves.length === 0) return;
      const label = JSON.stringify(node.children ?? node.text ?? '');
      if (isGuestBranch(node) && node.name !== 'A') guest.push(`${file} | ${label}`);
      if (node.name === 'A' && /!\s*_local\.isGuest/.test(String(node.if ?? ''))) twins.push(`${file} | ${label}`);
    });

    expect(guest.length).toBeGreaterThan(0);
    expect(guest.sort()).toEqual(twins.sort());
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('비회원 셸과 그 셸이 포함하는 조각(재귀)의 링크는 회원 갈래(!_local.isGuest)에서만 선다 — 액션 없는 맨 링크 포함, 정당한 예외는 사유와 함께', () => {
    const read = (r: string): Node => JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, r), 'utf8'));
    const shells = walk(LAYOUT_ROOT).map(rel).filter((r) => /^shop\/guest_/.test(r));
    const { scope, bad } = guestScopeLinkViolations(read, shells, (r) => fs.existsSync(path.join(LAYOUT_ROOT, r)));

    expect(shells.length).toBeGreaterThan(0);
    expect(scope.length).toBeGreaterThan(10);
    expect(bad).toEqual([]);
    // 예외는 실제로 있는 링크여야 한다 — 사라진 자리를 목록에 남겨 두지 않는다
    for (const e of GUEST_SCOPE_LINK_EXCEPTIONS) {
      expect(scope, e.file).toContain(e.file);
      expect(JSON.stringify(read(e.file)), `${e.file} ${e.href}`).toContain(JSON.stringify(e.href).slice(1, -1));
    }
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('비회원 범위 판정식은 셸 안 링크 · 액션 없는 맨 링크 · 비회원 갈래 링크 · 쪽/탭 주소를 잡는다(픽스처)', () => {
    const files: Record<string, Node> = {
      'shop/guest_x.json': {
        slots: { content: [
          { name: 'A', props: { href: '/shell-link' } },
          { name: 'Div', partial: 'partials/p.json' },
        ] },
      },
      'partials/p.json': {
        children: [
          { name: 'A', props: { href: '/bare' } },
          { name: 'A', if: '{{_local.isGuest}}', props: { href: '/guest-branch' }, actions: [{ type: 'click', handler: 'navigate' }] },
          { name: 'Div', if: '{{!_local.isGuest}}', children: [{ name: 'A', props: { href: '/member-ok' } }] },
          { name: 'Pagination', props: { pageHref: '/p?page={page}' } },
          { name: 'TabNavigation', props: { tabs: [{ id: 'a', href: '/t?tab=a' }] } },
        ],
      },
    };
    const { bad } = guestScopeLinkViolations((r) => files[r], ['shop/guest_x.json'], (r) => r in files);

    expect(bad).toEqual([
      'shop/guest_x.json | A | /shell-link',
      'partials/p.json | A | /bare',
      'partials/p.json | A | /guest-branch',
      'partials/p.json | Pagination | /p?page={page}',
      'partials/p.json | TabNavigation',
    ]);
  });

  // @scenario component=layout
  // @effects plain_click_spa_navigate
  it('클릭 판정은 type="click" 과 event="onClick" 두 형식을 모두 센다', () => {
    expect(isClick({ type: 'click', handler: 'navigate' })).toBe(true);
    expect(isClick({ event: 'onClick', handler: 'navigate' })).toBe(true);
    expect(isClick({ event: 'onPageChange', handler: 'navigate' })).toBe(false);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('링크 안에는 HTML 로 그리는 본문(HtmlContent)을 두지 않는다 — 본문에 <a> 가 오면 링크 안 링크가 된다', () => {
    const bad: string[] = [];
    visitNodes(({ file, node }) => {
      if (node.name !== 'A' || !node.props?.href) return;
      const w = (n: unknown): void => {
        if (Array.isArray(n)) return n.forEach(w);
        if (!n || typeof n !== 'object') return;
        const c = n as Node;
        // 글자 모드(isHtml=false)는 이스케이프된 글자만 그리므로 링크가 생길 수 없다
        if (c.name === 'HtmlContent' && c.props?.isHtml !== false) bad.push(`${file} | ${String(node.props.href).slice(0, 60)}`);
        if (Array.isArray(c.children)) w(c.children);
      };
      w(node.children ?? []);
    });

    expect(bad).toEqual([]);
  });

  // @scenario component=layout
  // @effects nested_button_does_not_navigate
  it('링크 안에는 대화형 요소(단추·링크·입력·작성자 메뉴)를 두지 않는다 — 카드 전체 링크는 덮는 링크로 그리고 안쪽 조작은 그 위에 둔다', () => {
    const INTERACTIVE = new Set(['Button', 'A', 'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'UserInfo', 'Form', 'Label']);
    const bad: string[] = [];
    visitNodes(({ file, node }) => {
      if (node.name !== 'A' || !node.props?.href) return;
      const w = (n: unknown): void => {
        if (Array.isArray(n)) return n.forEach(w);
        if (!n || typeof n !== 'object') return;
        const c = n as Node;
        if (INTERACTIVE.has(c.name)) bad.push(`${file} | ${String(node.props.href).slice(0, 50)} | ${c.name}`);
        if (Array.isArray(c.children)) w(c.children);
      };
      w(node.children ?? []);
    });

    expect(bad).toEqual([]);
  });

  // @scenario component=layout
  // @effects modified_click_opens_browser_default
  it('새 창 링크(target="_blank")는 rel="noopener" 를 단다', () => {
    const bad: string[] = [];
    visitNodes(({ file, node }) => {
      if (node.name === 'A' && node.props?.target === '_blank' && !/\bnoopener\b/.test(String(node.props?.rel ?? ''))) {
        bad.push(`${file} | ${String(node.props?.href).slice(0, 60)}`);
      }
    });
    // visitNodes 는 액션을 가진 노드만 돈다 — 액션 없는 새 창 링크도 보도록 파일 전수
    for (const f of FILES) {
      const w = (n: unknown): void => {
        if (Array.isArray(n)) return n.forEach(w);
        if (!n || typeof n !== 'object') return;
        const c = n as Node;
        if (c.name === 'A' && !Array.isArray(c.actions) && c.props?.target === '_blank' && !/\bnoopener\b/.test(String(c.props?.rel ?? ''))) {
          bad.push(`${rel(f)} | ${String(c.props?.href).slice(0, 60)}`);
        }
        for (const k of Object.keys(c)) w(c[k]);
      };
      w(JSON.parse(fs.readFileSync(f, 'utf8')));
    }

    expect(bad).toEqual([]);
  });

  // @scenario component=layout
  // @effects plain_click_spa_navigate
  it('경로 없는 navigate 는 실제 주소 링크의 click 에서만 쓴다', () => {
    const bad: string[] = [];
    visitNodes(({ file, node }) => {
      for (const a of node.actions) {
        const navs: Action[] = a.handler === 'navigate' ? [a] : (a.actions ?? []).filter((x: Action) => x.handler === 'navigate');
        for (const nav of navs) {
          if (nav.params?.path || nav.params?.target) continue;
          const href = node.props?.href;
          if (!(node.name === 'A' && isClick(a) && typeof href === 'string' && href.trim() !== '')) {
            bad.push(`${file} | ${node.name} | ${a.type ?? a.event}`);
          }
        }
      }
    });

    expect(bad).toEqual([]);
  });

  // @scenario component=layout
  // @effects merge_query_href
  it('링크의 href 와 click 이동이 같은 곳·같은 쿼리 규칙을 쓴다', () => {
    const bad: string[] = [];
    visitNodes(({ file, node }) => {
      if (node.name !== 'A' || typeof node.props?.href !== 'string') return;
      for (const a of node.actions) {
        if (!isClick(a)) continue;
        const nav = moveOf(a);
        if (!nav) continue;
        const href: string = node.props.href;
        const navPath = nav.params?.path;
        if (navPath && hrefPathPart(href) !== hrefPathPart(navPath)) bad.push(`${file} | 경로 불일치 | ${navPath} ≠ ${href}`);
        if (Boolean(nav.params?.mergeQuery) !== Boolean(node.props.mergeQuery)) {
          bad.push(`${file} | mergeQuery 짝 불일치 | ${href}`);
        }
      }
    });

    expect(bad).toEqual([]);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('onTabChange 에서 주소를 바꾸는 TabNavigation 은 탭마다 href 를 가진다(같은 화면 쿼리 탭은 현재 쿼리를 합친다)', () => {
    const bad: string[] = [];
    let tabNavs = 0;
    visitNodes(({ file, node }) => {
      if (node.name !== 'TabNavigation') return;
      for (const a of node.actions) {
        if (a.event !== 'onTabChange') continue;
        const steps: Action[] = a.handler === 'sequence' ? a.actions ?? [] : [a];
        const moves = steps.filter((s) => s.handler === 'navigate' || s.handler === 'replaceUrl');
        if (moves.length === 0) continue;
        tabNavs++;
        const tabs: Node[] = Array.isArray(node.props?.tabs) ? node.props.tabs : [];
        for (const t of tabs) {
          if (typeof t.href !== 'string' || t.href.trim() === '') bad.push(`${file} | 탭 ${t.id} href 없음`);
        }
        // 쿼리를 합쳐 옮기는 탭이면 새 탭 주소도 현재 쿼리를 이고 간다
        if (moves.some((m) => m.params?.mergeQuery === true) && node.props?.tabHrefMergeQuery !== true) {
          bad.push(`${file} | tabHrefMergeQuery 없음`);
        }
      }
    });

    expect(bad).toEqual([]);
    expect(tabNavs).toBeGreaterThan(0);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  it('onPageChange 에서 이동하는 Pagination 은 pageHref 를 가진다', () => {
    const bad: string[] = [];
    let paginations = 0;
    visitNodes(({ file, node }) => {
      if (node.name !== 'Pagination') return;
      for (const a of node.actions) {
        if (a.event !== 'onPageChange' || !addressStep(a)) continue;
        paginations++;
        const href = node.props?.pageHref;
        if (typeof href !== 'string' || !href.includes('{page}')) bad.push(file);
      }
    });

    expect(bad).toEqual([]);
    expect(paginations).toBeGreaterThan(0);
  });
});
