// e2e:allow 레이아웃 정적 구조(A·href·pageHref) 단언 전용 — 브라우저 축(Ctrl·가운데 클릭 새 탭)은 관리자 템플릿 admin-navigation-links.spec.ts 와 M1 링크 행 실측이 덮는다(시나리오 navigation-links.yaml).
/**
 * @file navigation-links.test.ts
 * @description 이커머스 관리자 화면의 「다른 화면으로 옮기는」 자리가 링크(`<a href>`)인지 (#757 WP-L)
 *
 * 화면 이동이 단추·span 에 `navigate` 액션을 붙인 구조라 렌더 시점에 주소가 없어 가운데 클릭·Ctrl+클릭·우클릭
 * 「새 탭에서 열기」 가 되지 않았다. 운영자는 여러 관리 화면을 동시에 열어 두고 일한다(2026-10-14). 이동은
 * `.g7.html` 에 `<a href>` 로 직접 쓰고(D10), 보통 클릭은 지금처럼 navigate 가 SPA 로 옮긴다(D12 — path 를 비우면
 * 클릭한 a 의 href). 합성 컴포넌트는 주소를 prop 으로 받는다(D14 — 행 메뉴 `href`, 쪽수 `pageHref`).
 *
 * 모집단은 이커머스 관리자 레이아웃·확장 조각 JSON 전체에서 파생한다. 「이동」 은 click 진입점(이름 있는 액션은 풀어서)이
 * `navigate` 이고 `replace` 가 아니며 확인 창(`confirm`)이 없고, 경로가 그 화면 자신의 라우트가 아닌 것이다 — 자기 화면을
 * 다시 부르는 새로고침·필터·검색·초기화(W6)와 저장·삭제 뒤 이동(sequence·apiCall 안쪽, D13)은 링크 대상이 아니다.
 *
 * @scenario surface=admin_layout
 * @effects screen_moves_are_links
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { basename, join, relative, resolve } from 'node:path';

const ROOT = resolve(__dirname, '../../../..');
const LAYOUTS = join(ROOT, 'resources/layouts');
const EXTENSIONS = join(ROOT, 'resources/extensions');

/**
 * 디렉터리 아래 JSON 경로를 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @return JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return jsonFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

/**
 * 실제 주소인지 봅니다 — 빈 값·`#`·`javascript:` 는 주소가 아니다.
 *
 * @param href href 값
 * @return 실제 주소 여부
 */
function realHref(href: unknown): boolean {
    if (typeof href !== 'string') return false;
    const h = href.trim();

    return h !== '' && h !== '#' && !/^javascript:/i.test(h);
}

/**
 * 경로에서 쿼리를 떼고 끝 `/` 를 정리합니다.
 *
 * @param p 경로
 * @return 비교용 경로
 */
function bare(p: string): string {
    return p.split('?')[0].replace(/\/+$/, '');
}

/**
 * 레이아웃 이름 → 그 화면 라우트 경로들(`*` 접두 제거)을 만듭니다.
 *
 * @return 레이아웃별 경로 집합
 */
function routePaths(): Map<string, Set<string>> {
    const routes = JSON.parse(readFileSync(join(ROOT, 'resources/routes/admin.json'), 'utf-8')).routes ?? [];
    const map = new Map<string, Set<string>>();
    for (const r of routes) {
        const set = map.get(r.layout) ?? new Set<string>();
        set.add(bare(String(r.path).replace(/^\*/, '')));
        map.set(r.layout, set);
    }

    return map;
}

/**
 * 각 레이아웃 파일(부분 레이아웃 포함)이 그려지는 화면의 라우트 경로를 모읍니다 — 부분 레이아웃은 그것을 포함한 화면들의 경로.
 *
 * @param files 레이아웃 JSON 경로들
 * @return 파일 → 자기 화면 경로 집합
 */
function ownPathsByFile(files: string[]): Map<string, Set<string>> {
    const byLayout = routePaths();
    const includes = new Map<string, string[]>();
    for (const f of files) {
        const refs = [...readFileSync(f, 'utf-8').matchAll(/"partial":\s*"([^"]+)"/g)].map((m) => resolve(join(LAYOUTS, 'admin'), m[1]));
        includes.set(f, refs);
    }
    const own = new Map<string, Set<string>>();
    const add = (file: string, paths: Set<string>, seen = new Set<string>()): void => {
        if (seen.has(file)) return;
        seen.add(file);
        const set = own.get(file) ?? new Set<string>();
        paths.forEach((p) => set.add(p));
        own.set(file, set);
        (includes.get(file) ?? []).forEach((child) => add(child, paths, seen));
    };
    for (const f of files) {
        const paths = byLayout.get(basename(f, '.json'));
        if (paths) add(f, paths);
    }

    return own;
}

/**
 * 액션 안(조건·순서 갈래 포함)에 화면 이동 navigate 가 있는지 봅니다 — 새 창(openWindow)은 이미 새 탭이라 빼고 센다.
 *
 * @param a 액션
 * @param withReplace replace 이동(같은 화면 필터 등)도 셀지
 * @return navigate 경로들
 */
function navigatePaths(a: any, withReplace = false): string[] {
    const out: string[] = [];
    const walk = (x: any): void => {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'navigate' && (withReplace || x.params?.replace !== true)) out.push(String(x.params?.path ?? ''));
        Object.values(x).forEach(walk);
    };
    walk(a);

    return out;
}

/**
 * 액션(순서 갈래 포함)이 쿼리의 쪽·탭 키만 바꾸는 이동(navigate·replace·주소만 바꾸는 replaceUrl)인지 봅니다 — 같은
 * 라우트라도 쿼리가 바뀌면 다른 주소다(L0 판정 2026-10-15: W6 새로고침은 이동 뒤 주소가 지금과 같을 때만).
 * 키는 이름 끝으로 본다 — `sub_tab`·`*_page`·`*_channel` 처럼 접두가 붙은 쪽·탭 키도 같은 이동이다(BP1 이 `tab` 만 보다
 * `channel` 탭을 놓친 결함군, L0 재점검 2026-10-15).
 *
 * @param a 액션
 * @return 쪽·탭 이동 여부
 */
function pageOrTabMove(a: any): boolean {
    const PAGE_OR_TAB_KEY = /(^|_)(page|tab|channel|type)$/;
    let found = false;
    const walk = (x: any): void => {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'navigate' || x.handler === 'replaceUrl') {
            const keys = Object.keys(x.params?.query ?? {});
            if (keys.length > 0 && keys.every((k) => PAGE_OR_TAB_KEY.test(k))) found = true;
        }
        Object.values(x).forEach(walk);
    };
    walk(a);

    return found;
}

/**
 * 클릭에서 동기로 곧바로 닿는 navigate 를 모읍니다 — switch·conditions 의 모든 갈래, sequence·parallel 의 자식.
 * 저장·확인 창 뒤 이동(D13)은 뺀다: sequence 는 apiCall·openModal·confirm 을 만나면 그 뒤를 보지 않고, apiCall 의
 * 성공·실패 콜백 안으로는 들어가지 않으며, confirm 을 단 액션은 통째로 뺀다.
 *
 * @param a 액션
 * @return 곧바로 닿는 navigate 액션들
 */
function directNavigates(a: any): any[] {
    if (Array.isArray(a)) return a.flatMap(directNavigates);
    if (!a || typeof a !== 'object' || a.confirm) return [];
    const blocks = (x: any): boolean => !!x?.confirm || x?.handler === 'apiCall' || x?.handler === 'openModal';
    switch (a.handler) {
        case 'navigate':
            return [a];
        case 'sequence': {
            const out: any[] = [];
            for (const x of a.actions ?? []) {
                if (blocks(x)) break;
                out.push(...directNavigates(x));
            }

            return out;
        }
        case 'parallel':
            return (a.actions ?? []).filter((x: any) => !blocks(x)).flatMap(directNavigates);
        case 'switch':
            return Object.values(a.cases ?? {}).flatMap(directNavigates);
        case 'conditions':
            return (a.conditions ?? []).flatMap((c: any) => directNavigates(c?.then ?? []));
        default:
            return [];
    }
}

/**
 * 경로가 그 화면 자신인지 봅니다 — 삼항 식(`{{x ? '/a' : '/b'}}`)이면 안의 경로 글자를 모두 보고 전부 자기 화면일 때만
 * 자신으로 본다(라운드 6 — 식 글자를 통째로 비교하면 자기 화면으로만 가는 삼항도 다른 화면으로 셌다).
 *
 * @param p 경로(식 포함)
 * @param isOwn 단일 경로 판정
 * @return 자기 화면 여부
 */
function isOwnPath(p: string, isOwn: (p: string) => boolean): boolean {
    if (p.includes('{{')) {
        const literals = [...p.matchAll(/['"`](\/[^'"`]*)['"`]/g)].map((m) => m[1]);
        if (literals.length > 0) return literals.every((l) => isOwn(l));
    }

    return isOwn(p);
}

/**
 * 액션(순서 갈래 포함) 안의 새 창 열기(openWindow) 주소를 모읍니다(빈 주소 제외).
 *
 * @param a 액션
 * @return openWindow 주소 목록
 */
function openWindowPaths(a: any): string[] {
    const out: string[] = [];
    const walk = (x: any): void => {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'openWindow' && String(x.params?.path ?? x.params?.url ?? '') !== '') out.push(String(x.params?.path ?? x.params?.url));
        Object.values(x).forEach(walk);
    };
    walk(a);

    return out;
}

/**
 * 클릭 진입점인지 봅니다 — `type`·`event` 어느 키로 적었든(생략·`click`·`onClick`) 엔진은 같은 클릭 핸들러로 붙인다
 * (ActionDispatcher `DOM_EVENT_PROP_MAP` 의 `click → onClick`). `event` 만 보면 `event="onClick"` 형식의 이동을 놓친다
 * (validate-plan 라운드 3 대조 C, L0 2026-10-15).
 *
 * @param a 액션
 * @return 클릭 진입점 여부
 */
function isClickEntry(a: any): boolean {
    const CLICK = new Set([undefined, 'click', 'onClick']);

    return CLICK.has(a.type) && CLICK.has(a.event);
}

/**
 * 액션(순서 갈래 포함)에 쿼리를 남기는 replaceUrl(주소만 바꾸기)이 있는지 봅니다.
 *
 * @param a 액션
 * @return 쿼리를 가진 replaceUrl 여부
 */
function replaceUrlWithQuery(a: any): boolean {
    let found = false;
    const walk = (x: any): void => {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'replaceUrl' && Object.keys(x.params?.query ?? {}).length > 0) found = true;
        Object.values(x).forEach(walk);
    };
    walk(a);

    return found;
}

/**
 * 노드 안(자손)에 같은 쿼리 키를 replaceUrl 로 남기는 실제 주소 링크(A)가 있는지 봅니다 — 카드 클릭과 같은 선택을
 * 새 탭으로도 열 수 있는 링크가 카드 안에 있다는 뜻이다.
 *
 * @param n 카드 노드
 * @param a 카드의 클릭 액션
 * @return 같은 선택을 하는 링크가 안에 있는지
 */
function hasSelectionLinkInside(n: any, a: any): boolean {
    const keysOf = (x: any): string[] => {
        const out: string[] = [];
        const walk = (y: any): void => {
            if (Array.isArray(y)) return y.forEach(walk);
            if (!y || typeof y !== 'object') return;
            if (y.handler === 'replaceUrl') out.push(...Object.keys(y.params?.query ?? {}));
            Object.values(y).forEach(walk);
        };
        walk(x);

        return [...new Set(out)].sort();
    };
    const want = keysOf(a).join(',');
    let found = false;
    const walk = (x: any): void => {
        if (found || Array.isArray(x)) return Array.isArray(x) ? x.forEach(walk) : undefined;
        if (!x || typeof x !== 'object') return;
        if (x !== n && x.name === 'A' && realHref(x.props?.href) && keysOf(x.actions ?? []).join(',') === want) found = true;
        Object.values(x).forEach(walk);
    };
    walk(n.children ?? []);

    return found;
}

/**
 * 링크 대상이 아닌 칸 — 판정식이 이동으로 잡지만 계획서상 대상이 아닌 자리. 사유와 함께 둔다(EB 판정, handoff/EB-p2-EA-nav-links-exclusions.md).
 * 쓰이지 않게 된 항목은 시험이 알린다(아래 「제외 목록이 낡지 않았다」).
 */
const EXCLUDE: { file: string; reason: string; match: (n: any, a: any) => boolean }[] = [
    // 저장 안 한 변경 확인 창의 「떠나기」는 이동을 직접 하지 않고 탭 전환 이벤트만 보낸다(#753 — 이동은
    // 탭 본문 틀의 이벤트 받기가 한다). 판정식이 잡을 칸이 없어져 제외 항목을 두지 않는다.
    {
        file: 'layouts/admin/partials/admin_ecommerce_settings/_tab_identity_policies.json',
        reason: 'W6 — 필터 초기화(쿼리를 지금 탭 하나로 비운다)',
        match: (_n, a) => /"query":\{"tab":"identity_policies"\}/.test(JSON.stringify(a)),
    },
    {
        file: 'layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.json',
        reason: 'W6 — 필터 초기화(지금 유형 type 만 남기고 조건을 지운다; 유형 전환은 claim_type_subtabs 탭)',
        match: (n) => n.id === 'f_912_reset_button' || n.props?.['data-ref'] === 'f_912_reset_button',
    },
    {
        file: 'layouts/admin/partials/admin_ecommerce_shipping_policy_list/_modal_copy.json',
        reason: 'D13 — 복사 확인 창 안의 「복사 진행」(창을 닫고 원본 id 를 실어 등록 화면으로 — 확인 창이 뜨는 이동). 라운드 6 에서 sequence 안 이동을 보게 되며 잡혔다',
        match: (_n, a) => /"path":"\/admin\/ecommerce\/shipping-policies\/create"/.test(JSON.stringify(a)) && /copy_id/.test(JSON.stringify(a)),
    },
    // 목록-편집 패널의 항목 고르기(replaceUrl ?id&mode)는 한때 「같은 화면 안 패널 선택」으로 뺐으나 결정
    // (2026-10-15)으로 이번에 링크화 — 제외하지 않는다(①''''' 가 링크인지 본다).
];
const usedExclusions = new Set<number>();

/**
 * 제외 목록에 든 칸인지 봅니다(쓰인 항목은 기록).
 *
 * @param rel resources 기준 경로
 * @param n 노드
 * @param a 액션
 * @return 제외 여부
 */
function excluded(rel: string, n: any, a: any): boolean {
    const i = EXCLUDE.findIndex((e) => e.file === rel && e.match(n, a));
    if (i < 0) return false;
    usedExclusions.add(i);

    return true;
}

interface Finding {
    file: string;
    what: string;
}

interface Judged {
    moves: number;
    notLinks: Finding[];
    rowActionsWithoutHref: Finding[];
    pagesWithoutHref: Finding[];
}

/**
 * 판정 누적값을 새로 만듭니다.
 *
 * @return 빈 누적값
 */
function emptyJudged(): Judged {
    return { moves: 0, notLinks: [], rowActionsWithoutHref: [], pagesWithoutHref: [] };
}

/**
 * 레이아웃·확장 조각 JSON 하나를 판정해 누적값에 더합니다(픽스처 시험이 같은 판정식을 쓰도록 떼어 냄).
 *
 * @param rel resources 기준 경로
 * @param json 레이아웃 JSON
 * @param mine 그 화면 자신의 경로들(쿼리 뗀 꼴)
 * @param acc 누적값
 */
function judgeFile(rel: string, json: any, mine: Set<string>, acc: Judged): void {
    const { notLinks, rowActionsWithoutHref, pagesWithoutHref } = acc;
    const named = json.named_actions ?? {};
    const isOwn = (p: string): boolean => p === '' || mine.has(bare(p));
    const resolveRef = (a: any): any => (a?.actionRef && named[a.actionRef] ? { ...named[a.actionRef], ...a } : a);

    const walk = (n: any): void => {
        if (Array.isArray(n)) return n.forEach(walk);
        if (!n || typeof n !== 'object') return;
        // ①''' 액션이 없어도 `href="#"`·빈 href 인 A 는 가운데 클릭·Ctrl+클릭이 「#」 을 새 탭으로 연다(M1 수정 전 실측 T10 —
        //       이커머스 14곳). 갈 곳이 있으면 실제 주소를, 없으면 링크가 아닌 글자로 둔다.
        if (n.name === 'A' && !realHref(n.props?.href) && !n.props?.disabled && !(Array.isArray(n.actions) && n.actions.length)) {
            notLinks.push({ file: rel, what: `${rel}: A${n.props?.['data-ref'] ? '#' + n.props['data-ref'] : ''} (href 가 # 이고 이동도 없음)` });
        }
        if (typeof n.name === 'string' && Array.isArray(n.actions)) {
            for (const raw of n.actions) {
                const a = resolveRef(raw);
                if (!a) continue;
                const label = `${rel}: ${n.name}${n.props?.['data-ref'] ? '#' + n.props['data-ref'] : ''}`;
                // ① click 진입점이 곧바로 이동(navigate)인 노드는 링크여야 한다. path 없는 navigate 는 누른 A 의
                //    href 로 간다(E1 handoff/E1-p2-api.md) — 변환된 칸도 이동으로 센다
                //    클릭에서 동기로 곧바로 닿는 이동(switch·conditions 갈래, sequence·parallel 안)도 같다 — 라운드 6 사각 대조
                const hrefOfA = n.name === 'A' && realHref(n.props?.href) ? String(n.props.href) : '';
                const targets = isClickEntry(a)
                    ? directNavigates(a)
                        .filter((x) => x.params?.replace !== true)
                        .map((x) => String(x.params?.path ?? hrefOfA))
                        .filter((p) => !isOwnPath(p, isOwn))
                    : [];
                const target = targets[0] ?? '';
                const directMove = targets.length > 0;
                if (directMove && !excluded(rel, n, a)) {
                    acc.moves++;
                    if (!(n.name === 'A' && realHref(n.props?.href))) notLinks.push({ file: rel, what: `${label} → ${target}` });
                }
                // ①' 링크 모양(`A`)인데 주소가 `#`·없음이면 새 탭이 엉뚱한 곳으로 간다 — 같은 화면을 거르는 이동(replace)이라도
                //     이동하는 링크면 그 결과 주소를 단다(계획서 L5 「href="#" + navigate 인 A」)
                else if (n.name === 'A' && !realHref(n.props?.href) && navigatePaths(a, true).length > 0 && !n.props?.disabled) {
                    notLinks.push({ file: rel, what: `${label} (href 가 실제 주소가 아님)` });
                }
                // ①'' 손으로 그린 쪽·탭 단추 — 같은 화면이라도 쿼리가 쪽·탭(page·tab·channel·type)만 바꾸는 이동은
                //      새 탭으로 그 쪽·탭을 여는 대상이다(D13 「페이지 번호·탭」, L0 판정 2026-10-15). 필터·검색은 W6 로 뺀다.
                if (isClickEntry(a) && !directMove && n.name !== 'A' && pageOrTabMove(a) && !excluded(rel, n, a)) {
                    acc.moves++;
                    notLinks.push({ file: rel, what: `${label} (쪽·탭 이동)` });
                }
                // ①''''' 클릭·항목 고르기(onSelect)가 주소만 바꿔(replaceUrl) 쿼리를 남기면 새 탭으로 그 상태를 열 수 있는
                //        자리다 — 링크이거나 사유와 함께 제외 목록에 있어야 한다(쪽·탭이면 ①'' 가 이미 셈). L0 재점검 2026-10-15
                const selectEntry = isClickEntry(a) || a.event === 'onSelect';
                if (selectEntry && !pageOrTabMove(a) && replaceUrlWithQuery(a) && !excluded(rel, n, a)) {
                    acc.moves++;
                    // 항목을 그리는 목록 컴포넌트(onSelect)는 항목마다 주소를 다는 prop 으로 링크가 된다. 스위치 같은 컨트롤을
                    // 품은 카드는 카드 전체를 A 로 감쌀 수 없어(링크 안 컨트롤) 카드 안 이름 링크가 같은 선택을 맡는다
                    const linked = (n.name === 'A' && realHref(n.props?.href)) || realHref(n.props?.itemHref)
                        || hasSelectionLinkInside(n, a);
                    if (!linked) notLinks.push({ file: rel, what: `${label} (주소에 남는 선택)` });
                }
                // ①'''' 새 창(openWindow)으로 여는 자리는 실제 href + target="_blank" 인 링크(A) — 주소가 `#` 면 Ctrl·가운데
                //        클릭이 같은 화면을 새 탭으로 연다(M1 수정 전 실측 T10). 링크가 아닌 단추·글자도 대상이다 — 한때
                //        「이미 새 창」으로 뺐으나 결정(2026-10-15)으로 이번에 링크화
                if (isClickEntry(a) && openWindowPaths(a).length > 0 && !excluded(rel, n, a)) {
                    acc.moves++;
                    if (!(n.name === 'A' && realHref(n.props?.href) && n.props?.target === '_blank')) {
                        notLinks.push({ file: rel, what: `${label} (새 창 이동 → A href target=_blank) ${openWindowPaths(a)[0]}` });
                    }
                }
                // ② 행 메뉴(onRowAction)·메뉴 항목(onItemClick) 갈래가 다른 화면으로 옮기면 그 항목에 href
                if ((a.event === 'onRowAction' || a.event === 'onItemClick') && a.handler === 'switch' && a.cases) {
                    const items: any[] = n.props?.rowActions ?? n.props?.items ?? [];
                    for (const [id, branch] of Object.entries<any>(a.cases)) {
                        // 새 창으로 여는 항목(openWindow)도 대상 — href 와 target="_blank"(결정 2026-10-15)
                        const opensWindow = openWindowPaths(branch).length > 0;
                        if (!opensWindow && navigatePaths(branch).filter((p) => !isOwnPath(p, isOwn)).length === 0) continue;
                        acc.moves++;
                        const item = items.find((it) => it?.id === id);
                        if (!item || !realHref(item.href) || (opensWindow && item.target !== '_blank')) {
                            rowActionsWithoutHref.push({ file: rel, what: `${label} 항목 ${id}${opensWindow ? ' (새 창 → href + target=_blank)' : ''}` });
                        }
                    }
                }
                // ②' 탭 컴포넌트(onTabChange)가 주소를 바꾸면(navigate·replaceUrl — 키 이름과 무관) 탭 항목마다 href.
                //     주소에 남는 탭은 새 탭으로 그 탭을 여는 대상이다(L0 재점검 2026-10-15)
                if (a.event === 'onTabChange' && /"handler":"(navigate|replaceUrl)"/.test(JSON.stringify(a))) {
                    const tabs: any[] = Array.isArray(n.props?.tabs) ? n.props.tabs : [];
                    acc.moves++;
                    const bare = tabs.filter((t) => t && !t.disabled && !realHref(t.href)).map((t) => t.id);
                    if (tabs.length === 0 || bare.length > 0) {
                        rowActionsWithoutHref.push({ file: rel, what: `${label} 탭 항목 href 없음${bare.length ? ' (' + bare.join(',') + ')' : ''}` });
                    }
                }
                // ③ 쪽 번호를 바꾸는 이동(onPageChange)이 있으면 번호가 링크가 되도록 pageHref
                if (a.event === 'onPageChange' && /"handler":"navigate"/.test(JSON.stringify(a)) && !realHref(n.props?.pageHref)) {
                    pagesWithoutHref.push({ file: rel, what: label });
                }
            }
        }
        Object.values(n).forEach(walk);
    };
    walk(json);
}

/**
 * 이커머스 관리자 레이아웃·확장 조각을 판정합니다.
 *
 * @return 링크가 아닌 이동 자리·href 없는 행 메뉴 항목·pageHref 없는 쪽수
 */
function scan(): Judged {
    const layoutFiles = jsonFiles(join(LAYOUTS, 'admin'));
    // 확장 조각은 관리자·회원 화면 모두 — 조각에는 자기 라우트가 없으므로 다른 화면 navigate 는 전부 링크 대상(EB 제안)
    const extFiles = jsonFiles(EXTENSIONS);
    const own = ownPathsByFile(layoutFiles);
    const acc = emptyJudged();

    for (const file of [...layoutFiles, ...extFiles]) {
        const rel = relative(join(ROOT, 'resources'), file).replace(/\\/g, '/');
        judgeFile(rel, JSON.parse(readFileSync(file, 'utf-8')), own.get(file) ?? new Set<string>(), acc);
    }

    return acc;
}

const result = scan();
const lines = (xs: Finding[]): string[] => xs.map((x) => x.what);

describe('이커머스 화면 이동은 링크 (#757 WP-L)', () => {
    // @scenario surface=admin_layout
    // @effects screen_moves_are_links
    it('모집단이 레이아웃·확장 조각 전체에서 파생된다', () => {
        expect(result.moves).toBeGreaterThan(20);
    });

    // @scenario surface=admin_layout
    // @effects screen_moves_are_links
    it('다른 화면으로 옮기는 단추·글자는 실제 주소를 가진 링크(A href)다', () => {
        const missing = lines(result.notLinks);
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_row
    // @effects row_menu_items_are_links
    it('행 메뉴·메뉴 항목·탭 항목 중 다른 화면·주소로 옮기는 항목은 href 를 갖는다', () => {
        const missing = lines(result.rowActionsWithoutHref);
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_layout
    // @effects screen_moves_are_links
    it('클릭 진입점은 type·event 어느 키로 적어도(생략·click·onClick) 같은 클릭으로 판정한다', () => {
        expect(isClickEntry({})).toBe(true);
        expect(isClickEntry({ type: 'click' })).toBe(true);
        expect(isClickEntry({ event: 'onClick' })).toBe(true);
        expect(isClickEntry({ type: 'click', event: 'onClick' })).toBe(true);
        expect(isClickEntry({ event: 'click' })).toBe(true);
        expect(isClickEntry({ event: 'onRowAction' })).toBe(false);
        expect(isClickEntry({ type: 'change' })).toBe(false);
        expect(isClickEntry({ type: 'click', event: 'onSelect' })).toBe(false);
    });

    // 판정식 사각 대조(라운드 6, L0 2026-10-16 — U1 사용자 잠금 시험이 switch 갈래·삼항 path 를 못 보던 결함군).
    // 실제 레이아웃이 아니라 판정식 자체를 픽스처로 잰다 — 모양마다 「잡아야 할 것」과 「빼야 할 것」을 함께 둔다.
    describe('판정식 픽스처 — 갈래 안 이동·삼항 경로', () => {
        const SELF = new Set(['/admin/ecommerce/self']);
        const judge = (actions: any[], node: Record<string, any> = { name: 'Button', props: {} }): Judged => {
            const acc = emptyJudged();
            judgeFile('fixture.json', { layout_name: 'fixture', components: [{ ...node, actions }] }, SELF, acc);

            return acc;
        };
        const go = (path?: string) => ({ handler: 'navigate', params: path === undefined ? {} : { path } });

        const CATCH: [string, any[]][] = [
            ['switch 갈래 안 이동', [{ type: 'click', handler: 'switch', params: { value: '{{x}}' }, cases: { a: go('/admin/other'), b: { handler: 'setState', params: {} } } }]],
            ['sequence 안 이동(상태 바꾼 뒤)', [{ type: 'click', handler: 'sequence', actions: [{ handler: 'setState', params: {} }, go('/admin/other')] }]],
            ['conditions 갈래 안 이동', [{ type: 'click', handler: 'conditions', conditions: [{ if: '{{x}}', then: [go('/admin/other')] }, { then: { handler: 'toast', params: {} } }] }]],
            ['삼항 경로 이동', [{ type: 'click', ...go("{{x ? '/admin/a' : '/admin/b'}}") }]],
            ['sequence 안 switch 안 이동', [{ type: 'click', handler: 'sequence', actions: [{ handler: 'switch', cases: { a: go('/admin/other') } }] }]],
        ];
        for (const [what, actions] of CATCH) {
            // @scenario surface=admin_layout
            // @effects screen_moves_are_links
            it(`${what} — 링크가 아니면 잡는다`, () => {
                expect(judge(actions).notLinks).toHaveLength(1);
                // 같은 액션을 실제 주소 A 에 걸면 통과(path 없는 navigate 는 href 로 간다)
                expect(judge(actions, { name: 'A', props: { href: '/admin/other' } }).notLinks).toHaveLength(0);
            });
        }

        const SKIP: [string, any[]][] = [
            ['저장(apiCall) 뒤 이동 — D13', [{ type: 'click', handler: 'sequence', actions: [{ handler: 'apiCall', params: {} }, go('/admin/other')] }]],
            ['확인 창(openModal) 뒤 이동 — D13', [{ type: 'click', handler: 'sequence', actions: [{ handler: 'openModal', target: 'm' }, go('/admin/other')] }]],
            ['apiCall 성공 콜백 안 이동 — D13', [{ type: 'click', handler: 'apiCall', params: {}, onSuccess: [go('/admin/other')] }]],
            ['confirm 을 단 이동 — D13', [{ type: 'click', handler: 'sequence', confirm: '정말?', actions: [go('/admin/other')] }]],
            ['자기 화면으로 가는 삼항 경로 — W6', [{ type: 'click', ...go("{{x ? '/admin/ecommerce/self' : '/admin/ecommerce/self?page=2'}}") }]],
            ['자기 화면 replace 이동(필터) — W6', [{ type: 'click', handler: 'sequence', actions: [{ handler: 'navigate', params: { path: '/admin/other', replace: true } }] }]],
        ];
        for (const [what, actions] of SKIP) {
            // @scenario surface=admin_layout
            // @effects screen_moves_are_links
            it(`${what} — 링크 대상이 아니다`, () => {
                expect(judge(actions).notLinks).toHaveLength(0);
            });
        }
    });

    // @scenario surface=admin_layout
    // @effects screen_moves_are_links
    it('제외 목록이 낡지 않았다 — 항목마다 실제로 걸리는 칸이 있다', () => {
        const stale = EXCLUDE.filter((_e, i) => !usedExclusions.has(i)).map((e) => `${e.file} — ${e.reason}`);
        expect(stale, stale.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_row
    // @effects page_numbers_are_links
    it('쪽 번호로 목록을 옮기는 표·쪽수 컴포넌트는 pageHref 를 갖는다', () => {
        const missing = lines(result.pagesWithoutHref);
        expect(missing, missing.join('\n')).toEqual([]);
    });
});
