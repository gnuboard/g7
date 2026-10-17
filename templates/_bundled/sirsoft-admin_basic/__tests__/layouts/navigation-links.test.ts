// e2e:allow 레이아웃 정적 구조(A·href·pageHref·탭 href) 단언 전용 — 브라우저 축(Ctrl·가운데 클릭 새 탭, 보통 클릭 SPA)은 admin-navigation-links.spec.ts 와 M1 링크 행 실측이 덮는다(시나리오 navigation-links.yaml).
/**
 * @file navigation-links.test.ts
 * @description 관리자 템플릿 화면의 「다른 화면으로 옮기는」 자리가 링크(`<a href>`)인지 (#757 WP-L)
 *
 * 화면 이동이 단추·span 에 `navigate` 액션을 붙인 구조라 렌더 시점에 주소가 없어 가운데 클릭·Ctrl+클릭·우클릭
 * 「새 탭에서 열기」가 되지 않았다. 이동은 `.g7.html` 에 `<a href>` 로 직접 쓰고(D10), 보통 클릭은 지금처럼 navigate 가
 * SPA 로 옮긴다(D12 — path 를 비우면 클릭한 a 의 href). 합성 컴포넌트는 주소를 prop 으로 받는다(D14 — 행 메뉴·카드 메뉴
 * `href`, 쪽수 `pageHref`, 탭 `tabs[].href`).
 *
 * 모집단은 관리자 템플릿 레이아웃 JSON(굽기 산출물) 전체에서 파생한다. 「이동」은 click 진입점(이름 있는 액션은 풀어서)이
 * `navigate` 이고 `replace` 가 아니며 확인 창(`confirm`)이 없고, 경로가 그 화면 자신의 라우트가 아닌 것이다 — 자기 화면을
 * 다시 부르는 새로고침·필터·검색·초기화(W6)와 저장·삭제 뒤 이동(sequence·apiCall 안쪽, D13)은 링크 대상이 아니다.
 * 쪽 번호·탭은 같은 라우트라도 쿼리가 바뀌면 대상이다(L0 판정 2026-10-15 — W6 새로고침은 이동 뒤 주소가 지금과 같을 때만).
 *
 * 판정식은 이커머스 잠금 시험(EA)과 같은 규칙이다. 확장마다 독립 패키지라 공용 파일을 import 하지 않고 이 파일에 둔다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 * @scenario component=layout_lock
 * @effects layout_moves_are_links
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const ROOT = resolve(__dirname, '../..');
const LAYOUTS = join(ROOT, 'layouts');

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
 * 레이아웃 파일(layouts 기준, 확장자 없음) → 그 화면 라우트 경로들(`*` 접두 제거)을 만듭니다.
 *
 * @return 레이아웃별 경로 집합
 */
function routePaths(): Map<string, Set<string>> {
    const routes = JSON.parse(readFileSync(join(ROOT, 'routes.json'), 'utf-8')).routes ?? [];
    const map = new Map<string, Set<string>>();
    for (const r of routes) {
        if (!r.layout) continue;
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
        const refs = [...readFileSync(f, 'utf-8').matchAll(/"partial":\s*"([^"]+)"/g)].map((m) => resolve(LAYOUTS, m[1]));
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
        const paths = byLayout.get(relative(LAYOUTS, f).replace(/\\/g, '/').replace(/\.json$/, ''));
        if (paths) add(f, paths);
    }

    return own;
}

/**
 * 액션 안(조건·순서 갈래 포함)의 화면 이동 navigate 경로를 모읍니다.
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
 * 클릭 진입점 안쪽(switch·sequence·conditions·parallel 갈래)의 다른 화면 이동 navigate 경로를 모읍니다(라운드 6).
 *
 * 최상위 navigate 는 ① 이 따로 본다. 요청 성공 뒤 이동(apiCall·onSuccess/onError 를 가진 핸들러 안쪽, D13)·replace 이동·
 * 확인 창이 붙은 이동은 링크 대상이 아니라 뺀다. 삼항 path 도 문자열 그대로 이동으로 센다(어느 갈래든 다른 화면).
 *
 * @param a 클릭 진입점 액션
 * @return 안쪽 navigate 경로들
 */
function innerMovePaths(a: any): string[] {
    const out: string[] = [];
    const walk = (x: any, top: boolean): void => {
        if (Array.isArray(x)) return x.forEach((y) => walk(y, false));
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'apiCall' || x.onSuccess !== undefined || x.onError !== undefined) return;
        if (!top && x.handler === 'navigate' && x.params?.replace !== true && !x.confirm) out.push(String(x.params?.path ?? ''));
        Object.values(x).forEach((v) => walk(v, false));
    };
    if (a?.handler !== 'navigate' && !a?.confirm) walk(a, true);

    return out;
}

/**
 * 액션 안(조건·순서 갈래 포함)에서 곧바로 새 창을 여는 openWindow 경로를 모읍니다 — 요청 응답 뒤에 여는 창(apiCall 안쪽,
 * 미리보기 토큰 등 클릭 시점에 주소가 없는 것)은 링크로 그릴 수 없으므로 뺀다.
 *
 * @param a 액션
 * @return openWindow 경로들
 */
function openWindowPaths(a: any): string[] {
    const out: string[] = [];
    const walk = (x: any): void => {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'apiCall') return;
        if (x.handler === 'openWindow') out.push(String(x.params?.path ?? x.params?.url ?? ''));
        Object.values(x).forEach(walk);
    };
    walk(a);

    return out;
}

/**
 * 액션(순서·조건 갈래 포함)이 **같은 화면의 주소를 바꾸는** 이동(navigate·replace 둘 다)인지 봅니다 — 쿼리 키 이름에 기대지
 * 않는다(탭을 `tab` 키로만 알아보면 `channel`·`category` 같은 키로 바꾸는 탭을 놓친다 — BP1 실측, L0 2026-10-15).
 * 주소가 그대로인 이동(쿼리 없이 현재 쿼리 병합 = 새로고침, W6)과 저장·요청 성공 뒤 이동(apiCall 안쪽, D13)은 뺀다.
 * 잡힌 칸은 링크(쪽·탭·목록 이동)거나 제외 목록에 사유(필터·검색·초기화·기간 프리셋)가 있어야 한다.
 *
 * @param a 액션
 * @param isOwn 그 화면 자신의 경로인지
 * @return 같은 화면 주소 변경 이동 여부
 */
function sameRouteQueryMove(a: any, isOwn: (p: string) => boolean): boolean {
    let found = false;
    const walk = (x: any): void => {
        if (Array.isArray(x)) return x.forEach(walk);
        if (!x || typeof x !== 'object') return;
        if (x.handler === 'apiCall') return;
        if (x.handler === 'navigate' && x.params && isOwn(String(x.params.path ?? ''))) {
            const q = x.params.query;
            const hasQuery = typeof q === 'string' ? q.trim() !== '' : !!q && typeof q === 'object' && Object.keys(q).length > 0;
            if (hasQuery || x.params.mergeQuery !== true) found = true;
        }
        Object.values(x).forEach(walk);
    };
    walk(a);

    return found;
}

/**
 * 식(문자열)으로 만든 항목 목록에서 그 id 항목이 href 를 갖는지 봅니다 — `{ id: 'x', …, href: … }` 객체 글자.
 *
 * @param expr items 식
 * @param id 항목 id
 * @return href 보유 여부
 */
function exprItemHasHref(expr: string, id: string): boolean {
    return [...expr.matchAll(/\{[^{}]*\}/g)].some((m) => new RegExp(`\\bid:\\s*['"]${id}['"]`).test(m[0]) && /\bhref:/.test(m[0]));
}

/**
 * 링크 대상이 아닌 칸 — 판정식이 이동으로 잡지만 계획서상 대상이 아닌 자리. 사유와 함께 둔다(판정표 results/C1-p2.md).
 * 쓰이지 않게 된 항목은 시험이 알린다(아래 「제외 목록이 낡지 않았다」).
 */
const EXCLUDE: { file: string; reason: string; match: (n: any, a: any) => boolean }[] = [
    {
        file: 'partials/admin_analytics/_partial_period_bar.json',
        reason: 'W6 — 기간 필터 초기화(같은 보고서 화면에서 조건만 비운다)',
        match: (n) => n.id === 'period_reset_button',
    },
    {
        file: 'admin_analytics_diagnostics.json',
        reason: 'W6 — 수집 실패 사유 필터 초기화',
        match: (n) => ['failure_filter_reset'].includes(n.id),
    },
    {
        file: 'admin_analytics_privacy_requests.json',
        reason: 'W6 — 검색 실행·검색 초기화(같은 목록의 조건)',
        match: (n) => ['search_button', 'search_reset_button'].includes(n.id),
    },
    {
        file: 'admin_analytics_visitors.json',
        reason: 'W6 — 방문자 필터 초기화',
        match: (n) => ['visitors_filter_reset'].includes(n.id),
    },
    {
        file: 'admin_member_notification_list.json',
        reason: 'W6 — 회원 한정 해제(목록 조건 초기화)',
        match: (n) => ['member_filter_clear'].includes(n.id),
    },
    {
        file: 'partials/admin_activity_log_list/_partial_filter.json',
        reason: 'W6 — 검색 필터 칸의 검색·초기화(로컬 조건 정리 후 같은 목록 다시 조회)',
        match: (_n, a) => a.handler === 'sequence',
    },
    {
        file: 'partials/admin_identity_logs/_partial_filter.json',
        reason: 'W6 — 검색 필터 칸의 검색·초기화(로컬 조건 정리 후 같은 목록 다시 조회)',
        match: (_n, a) => a.handler === 'sequence',
    },
    {
        file: 'partials/admin_member_notification_list/_partial_filter.json',
        reason: 'W6 — 검색 필터 칸의 검색·초기화(로컬 조건 정리 후 같은 목록 다시 조회)',
        match: (_n, a) => a.handler === 'sequence',
    },
    {
        file: 'partials/admin_notification_log_list/_partial_filter.json',
        reason: 'W6 — 검색 필터 칸의 검색·초기화(로컬 조건 정리 후 같은 목록 다시 조회)',
        match: (_n, a) => a.handler === 'sequence',
    },
    {
        file: 'partials/admin_analytics/_partial_period_bar.json',
        reason: 'W6 — 기간 프리셋(오늘·7일·이번 달 등)',
        match: (n) => String(n.id ?? '').startsWith('preset_'),
    },
    {
        file: 'partials/admin_analytics/_partial_report_grid.json',
        reason: 'W6 — 보고서 표 검색·정렬 초기화',
        match: (n) => ['report_filter_reset'].includes(n.id),
    },
    {
        file: 'partials/admin_schedule_list/_tab_schedules.json',
        reason: 'W6 — 등록일 빠른 필터(오늘·7일 등)와 필터 초기화',
        match: (n, a) => n.id === 'reset_button' || /"created_from"/.test(JSON.stringify(a)),
    },
    {
        file: 'partials/admin_settings/_tab_identity_policies.json',
        reason: 'W6 — 정책 검색 실행·필터 초기화',
        match: (_n, a) => /"(search|sub_tab)"/.test(JSON.stringify(a)),
    },
    {
        file: 'partials/admin_settings/_tab_notification_definitions.json',
        reason: 'W6 — 분류 칩(aria-pressed 토글 — 목록을 거르는 필터, 화면 구획을 바꾸는 탭 아님)',
        match: (n) => n.props?.['aria-pressed'] !== undefined,
    },
    {
        file: 'partials/admin_user_list/_partial_filter_section.json',
        reason: 'W6 — 회원 검색 필터 초기화(PC·모바일)',
        match: (n) => ['reset_button_pc', 'reset_button_mobile'].includes(n.id),
    },
    {
        file: 'partials/admin_language_pack_list/_content.json',
        reason: 'W6 — 언어팩 목록 필터 초기화(범위·상태·로케일·검색 조건을 비운다)',
        match: (n) => n.id === 'lp_filter_reset',
    },
    {
        file: 'partials/admin_settings/_modal_unsaved_tab_switch.json',
        reason: 'D13 — 저장 안 한 변경 확인 창 안의 「떠나기」(확인 창이 뜨는 이동)',
        match: (_n, a) => a.handler === 'sequence' && /"pendingSettingsTab"/.test(JSON.stringify(a)),
    },
];
const usedExclusions = new Set<number>();

/**
 * 제외 목록에 든 칸인지 봅니다(쓰인 항목은 기록).
 *
 * @param rel layouts 기준 경로
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

/**
 * 관리자 템플릿 레이아웃을 판정합니다.
 *
 * @return 링크가 아닌 이동 자리·href 없는 메뉴 항목·pageHref 없는 쪽수·href 없는 탭
 */
function scan(): { moves: number; notLinks: Finding[]; itemsWithoutHref: Finding[]; pagesWithoutHref: Finding[]; tabsWithoutHref: Finding[] } {
    const files = jsonFiles(LAYOUTS);
    const own = ownPathsByFile(files);
    const notLinks: Finding[] = [];
    const itemsWithoutHref: Finding[] = [];
    const pagesWithoutHref: Finding[] = [];
    const tabsWithoutHref: Finding[] = [];
    let moves = 0;

    for (const file of files) {
        const rel = relative(LAYOUTS, file).replace(/\\/g, '/');
        const json = JSON.parse(readFileSync(file, 'utf-8'));
        const named = json.named_actions ?? {};
        const mine = own.get(file) ?? new Set<string>();
        // `{{_local.periodPath}}` 는 분석 화면마다 자기 라우트 경로를 담는 값이다(각 화면 initLocal)
        const isOwn = (p: string): boolean => p === '' || /^\{\{\s*(route\.path|_local\.periodPath)\s*\}\}$/.test(p) || mine.has(bare(p));
        const resolveRef = (a: any): any => (a?.actionRef && named[a.actionRef] ? { ...named[a.actionRef], ...a } : a);

        const walk = (n: any): void => {
            if (Array.isArray(n)) return n.forEach(walk);
            if (!n || typeof n !== 'object') return;
            // ①''' 액션이 없어도 `href="#"`·빈 href 인 A 는 가운데 클릭·Ctrl+클릭이 「#」 을 새 탭으로 연다 — 주소 없는 `<a>` 는 두지 않는다
            if (n.name === 'A' && !realHref(n.props?.href) && !n.props?.disabled && !(Array.isArray(n.actions) && n.actions.length)) {
                notLinks.push({ file: rel, what: `${rel}: A${n.id ? '#' + n.id : ''} (href 가 # 이고 이동도 없음)` });
            }
            if (typeof n.name === 'string' && Array.isArray(n.actions)) {
                for (const raw of n.actions) {
                    const a = resolveRef(raw);
                    if (!a) continue;
                    const label = `${rel}: ${n.name}${n.id ? '#' + n.id : ''}`;
                    // 클릭 진입점 — `type="click"`·생략, 또는 `event="onClick"`(엔진은 DOM 이벤트 click 과 React prop onClick 을
                    // 같은 표로 붙인다 — ActionDispatcher DOM_EVENT_PROP_MAP). `event` 로만 판정하면 onClick 단추를 놓친다(L0 VP3)
                    const isClick = (!a.type || a.type === 'click' || a.type === 'action') && (!a.event || a.event === 'onClick')
                        && !(a.type === 'action' && !a.event);
                    // ① click 진입점이 곧바로 이동(navigate)인 노드는 링크여야 한다. path 없는 navigate 는 누른 A 의 href 로 간다
                    const target = String(a.params?.path ?? (n.name === 'A' && realHref(n.props?.href) ? n.props.href : ''));
                    const directMove = isClick && a.handler === 'navigate' && a.params?.replace !== true && !a.confirm && !isOwn(target);
                    if (directMove && !excluded(rel, n, a)) {
                        moves++;
                        if (!(n.name === 'A' && realHref(n.props?.href))) notLinks.push({ file: rel, what: `${label} → ${target}` });
                    }
                    // ①⁺⁰ click 진입점 안쪽(switch·sequence·conditions·parallel) 의 다른 화면 이동도 링크여야 한다 —
                    //     최상위만 보던 사각(라운드 6, U1 사용자 잠금 발견). 자기 화면 이동은 ①'' 가 따로 본다
                    if (isClick && !directMove) {
                        const inner = innerMovePaths(a).filter((p) => !isOwn(p));
                        if (inner.length > 0 && !excluded(rel, n, a)) {
                            moves++;
                            if (!(n.name === 'A' && realHref(n.props?.href))) notLinks.push({ file: rel, what: `${label} → ${inner.join(', ')} (안쪽 이동)` });
                        }
                    }
                    // ①⁺ click 진입점이 곧바로 새 창(openWindow)인 노드도 링크여야 한다 — `<a href target="_blank">` 면 가운데
                    //    클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」와 주소 복사가 된다(결정 2026-10-15 「이미 새 창」도 링크로)
                    if (isClick && a.handler !== 'apiCall' && openWindowPaths(a).length > 0) {
                        moves++;
                        if (!(n.name === 'A' && realHref(n.props?.href) && n.props?.target === '_blank')) {
                            notLinks.push({ file: rel, what: `${label} (새 창 — <a href target="_blank"> 로)` });
                        }
                    }
                    // ①' 링크 모양(`A`)인데 주소가 `#`·없음이면 새 탭이 엉뚱한 곳으로 간다
                    else if (n.name === 'A' && !realHref(n.props?.href) && navigatePaths(a, true).length > 0 && !n.props?.disabled) {
                        notLinks.push({ file: rel, what: `${label} (href 가 실제 주소가 아님)` });
                    }
                    // ①'' 같은 화면의 주소를 바꾸는 단추(키 이름 무관) — 쪽·탭·목록 이동이면 링크, 필터·검색·초기화·기간 프리셋이면
                    //      제외 목록에 사유를 둔다(W6). 이동 뒤 주소가 지금과 같은 새로고침은 sameRouteQueryMove 가 뺀다
                    if (isClick && !directMove && n.name !== 'A' && sameRouteQueryMove(a, isOwn) && !excluded(rel, n, a)) {
                        moves++;
                        notLinks.push({ file: rel, what: `${label} (같은 화면 주소 변경 — 쪽·탭이면 링크, 필터면 제외 사유)` });
                    }
                    // ② 행 메뉴(onRowAction)·메뉴 항목(onItemClick)·카드 메뉴(onAction) 갈래가 다른 화면으로 옮기면 그 항목에 href
                    if (['onRowAction', 'onItemClick', 'onAction'].includes(a.event) && /"handler":"navigate"/.test(JSON.stringify(a))) {
                        const list = n.props?.rowActions ?? n.props?.items;
                        const branches: [string | null, any][] = a.handler === 'switch' && a.cases ? Object.entries<any>(a.cases) : [[null, a]];
                        for (const [id, branch] of branches) {
                            if (navigatePaths(branch).filter((p) => !isOwn(p)).length === 0) continue;
                            moves++;
                            const ok = typeof list === 'string'
                                ? (id !== null ? exprItemHasHref(list, id) : /\bhref:/.test(list))
                                : Array.isArray(list) && (id !== null
                                    ? realHref(list.find((it: any) => it?.id === id)?.href)
                                    : list.length > 0 && list.every((it: any) => realHref(it?.href)));
                            if (!ok) itemsWithoutHref.push({ file: rel, what: `${label} ${a.event} 항목 ${id ?? '(전부)'}` });
                        }
                    }
                    // ②⁺ 메뉴 항목(onRowAction·onItemClick·onAction) 갈래가 곧바로 새 창을 열면 그 항목은 href + target="_blank"
                    if (['onRowAction', 'onItemClick', 'onAction'].includes(a.event)) {
                        const list = n.props?.rowActions ?? n.props?.items;
                        const branches: [string | null, any][] = a.handler === 'switch' && a.cases ? Object.entries<any>(a.cases) : [[null, a]];
                        for (const [id, branch] of branches) {
                            if (openWindowPaths(branch).length === 0) continue;
                            moves++;
                            const item = Array.isArray(list) ? list.find((it: any) => it?.id === id) : null;
                            const ok = typeof list === 'string'
                                ? id !== null && [...list.matchAll(/\{[^{}]*\}/g)].some((m) => new RegExp(`\\bid:\\s*['"]${id}['"]`).test(m[0]) && /\bhref:/.test(m[0]) && /\btarget:\s*['"]_blank['"]/.test(m[0]))
                                : !!item && realHref(item.href) && item.target === '_blank';
                            if (!ok) itemsWithoutHref.push({ file: rel, what: `${label} ${a.event} 항목 ${id ?? '(전부)'} (새 창 — href + target="_blank")` });
                        }
                    }
                    // ②⁺' 카드 주 단추(onPrimaryAction)가 곧바로 새 창을 열면 items 의 primaryAction 에 href + target
                    if (a.event === 'onPrimaryAction' && openWindowPaths(a).length > 0) {
                        moves++;
                        const items = n.props?.items;
                        if (!(typeof items === 'string' && /primaryAction:(?:(?!toggle:|actions:)[\s\S])*?\bhref:(?:(?!toggle:|actions:)[\s\S])*?\btarget:\s*['"]_blank['"]/.test(items))) {
                            itemsWithoutHref.push({ file: rel, what: `${label} onPrimaryAction (새 창 — primaryAction.href + target)` });
                        }
                    }
                    // ②⁺⁺ 같은 화면 패널 선택 — 목록 항목을 고르면 주소에 그 항목을 남기는(replaceUrl·replace navigate) 트리
                    //     목록은 항목마다 같은 목적지의 링크(itemHref)여야 새 탭·주소 복사가 그 항목 패널을 연다(결정 2026-10-15)
                    if (n.name === 'SortableMenuList' && a.event === 'onSelect' && /"handler":"(replaceUrl|navigate)"/.test(JSON.stringify(a))) {
                        moves++;
                        if (!realHref(n.props?.itemHref)) itemsWithoutHref.push({ file: rel, what: `${label} onSelect (패널 선택 — itemHref 없음)` });
                    }
                    // ②⁺⁺' 파일 트리(FileTreePicker) 선택이 주소를 남기면 주소로 가리킬 수 있는 파일 노드에 href.
                    //       예외(링크 아님): 라우트 없는 부분 레이아웃·확장 조각 — 주소가 없어(`route=""`) 새 탭이 그 파일을 열 수 없다
                    if (n.name === 'FileTreePicker' && a.event === 'onSelect' && /"handler":"(replaceUrl|navigate)"/.test(JSON.stringify(a))) {
                        moves++;
                        const nodes = n.props?.nodes;
                        if (!(typeof nodes === 'string' && /\bhref:/.test(nodes))) itemsWithoutHref.push({ file: rel, what: `${label} onSelect (파일 트리 — 주소 있는 노드에 href 없음)` });
                    }
                    // ②' 카드 보조 단추(onSecondaryAction)가 다른 화면으로 옮기면 items 의 secondaryAction 에 href
                    if (a.event === 'onSecondaryAction' && navigatePaths(a).filter((p) => !isOwn(p)).length > 0) {
                        moves++;
                        const items = n.props?.items;
                        // 식 안 secondaryAction 은 `조건 ? {…} : (…)` 꼴이라 다음 키(primaryAction) 앞까지에서 href 를 찾는다
                        if (!(typeof items === 'string' && /secondaryAction:(?:(?!primaryAction:)[\s\S])*?\bhref:/.test(items))) {
                            itemsWithoutHref.push({ file: rel, what: `${label} onSecondaryAction (secondaryAction.href 없음)` });
                        }
                    }
                    // ③ 쪽 번호를 바꾸는 이동(onPageChange)이 있으면 번호가 링크가 되도록 pageHref
                    if (a.event === 'onPageChange' && /"handler":"navigate"/.test(JSON.stringify(a))) {
                        moves++;
                        if (!realHref(n.props?.pageHref)) pagesWithoutHref.push({ file: rel, what: label });
                    }
                    // ④ 탭 전환이 주소를 바꾸면(onTabChange 안 navigate — replace·순서 갈래 포함) 탭마다 href
                    if (a.event === 'onTabChange' && /"handler":"navigate"/.test(JSON.stringify(a))) {
                        moves++;
                        const tabs = n.props?.tabs;
                        const ok = typeof tabs === 'string'
                            ? /\bhref:/.test(tabs)
                            : Array.isArray(tabs) && tabs.length > 0 && tabs.every((t: any) => t?.disabled === true || realHref(t?.href));
                        if (!ok) tabsWithoutHref.push({ file: rel, what: label });
                    }
                }
            }
            Object.values(n).forEach(walk);
        };
        walk(json);
    }

    return { moves, notLinks, itemsWithoutHref, pagesWithoutHref, tabsWithoutHref };
}

const result = scan();

/** 라운드 6 픽스처 — 클릭 진입점 안쪽 이동 판정 */
const innerMoves = innerMovePaths;
const lines = (xs: Finding[]): string[] => xs.map((x) => x.what);

describe('관리자 템플릿 화면 이동은 링크 (#757 WP-L)', () => {
    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('모집단이 레이아웃 전체에서 파생된다', () => {
        expect(result.moves).toBeGreaterThan(40);
    });

    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('다른 화면으로 옮기는 단추·글자는 실제 주소를 가진 링크(A href)다', () => {
        const missing = lines(result.notLinks);
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('행 메뉴·메뉴 항목·카드 단추 중 다른 화면으로 옮기는 항목은 href 를 갖는다', () => {
        const missing = lines(result.itemsWithoutHref);
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('쪽 번호로 목록을 옮기는 표·카드·쪽수 컴포넌트는 pageHref 를 갖는다', () => {
        const missing = lines(result.pagesWithoutHref);
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('주소를 바꾸는 탭은 탭마다 href 를 갖는다', () => {
        const missing = lines(result.tabsWithoutHref);
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('제외 목록이 낡지 않았다 — 항목마다 실제로 걸리는 칸이 있다', () => {
        const stale = EXCLUDE.filter((_e, i) => !usedExclusions.has(i)).map((e) => `${e.file} — ${e.reason}`);
        expect(stale, stale.join('\n')).toEqual([]);
    });
});

describe('잠금 판정 — 클릭 진입점 안쪽 이동 (라운드 6 픽스처)', () => {
    // @scenario component=layout_lock
    // @effects layout_moves_are_links
    it('switch·sequence·conditions·parallel 안쪽과 삼항 path 의 다른 화면 이동을 잡고, 요청 성공 뒤 이동·replace·confirm 은 뺀다', () => {
        expect(innerMoves({ handler: 'switch', cases: { a: { handler: 'navigate', params: { path: '/admin/x' } } } })).toEqual(['/admin/x']);
        expect(innerMoves({ handler: 'sequence', actions: [{ handler: 'setState', params: {} }, { handler: 'navigate', params: { path: '/admin/y' } }] })).toEqual(['/admin/y']);
        expect(innerMoves({ handler: 'conditions', conditions: [{ if: '{{a}}', then: { handler: 'navigate', params: { path: '/admin/z' } } }] })).toEqual(['/admin/z']);
        expect(innerMoves({ handler: 'parallel', actions: [{ handler: 'navigate', params: { path: "{{ok ? '/admin/a' : '/admin/b'}}" } }] })).toEqual(["{{ok ? '/admin/a' : '/admin/b'}}"]);
        // 빼는 것: 요청 성공 뒤(apiCall·onSuccess 가 있는 핸들러) · replace · confirm
        expect(innerMoves({ handler: 'sequence', actions: [{ handler: 'apiCall', onSuccess: [{ handler: 'navigate', params: { path: '/admin/after' } }] }] })).toEqual([]);
        expect(innerMoves({ handler: 'sequence', actions: [{ handler: 'loginPasswordChangePostpone', onSuccess: [{ handler: 'navigate', params: { path: '/admin/after' } }] }] })).toEqual([]);
        expect(innerMoves({ handler: 'sequence', actions: [{ handler: 'navigate', params: { path: '/admin/r', replace: true } }] })).toEqual([]);
        expect(innerMoves({ handler: 'sequence', actions: [{ handler: 'navigate', confirm: 'x', params: { path: '/admin/c' } }] })).toEqual([]);
    });
});
