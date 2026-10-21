/**
 * 플러그인 sirsoft-daum_postcode 레이아웃 — 화면 이동 링크 잠금 시험 (#757 WP-L L6)
 *
 * @description
 * 화면 이동을 단추·글자에 붙인 클릭 동작으로만 두면 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 되지 않는다.
 * 이동은 레이아웃의 `<a href>`(path 없는 navigate)로, 표·카드·탭·행 메뉴는 그 컴포넌트의 주소 prop(pageHref·탭
 * href·항목 href)으로 쓴다(D10·D12·D14). 굽기 산출물(JSON) 전수를 정적으로 판정한다 — 새 화면도 자동으로 잠긴다.
 *
 * 판정:
 *   - click_move: click 진입점이 navigate(replace 아님)인 노드는 `A` 이고 href 가 실제 주소(빈 값·#·javascript: 아님)
 *   - tab_or_page: 쿼리 키가 tab·page 뿐인 이동(레이아웃이 그린 탭·페이지 단추, replace·sequence 포함)도 같은 조건
 *   - page / tab / menu_item: onPageChange·onTabChange·onRowAction·onItemClick 의 이동 갈래 → pageHref·탭 href·항목 href
 *   - sequence 안쪽 일반 이동·저장 뒤 이동·확인 창 이동은 대상이 아니다(D13). 아래 EXCLUDED 는 대상이지만 바꾸지
 *     않는 자리와 그 사유를 적는 곳이다 — 이 확장은 제외가 없다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/** 확장 루트 */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
/** 모집단 디렉터리(확장 루트 기준) */
const ROOTS = ['resources/layouts', 'resources/extensions'];

/** 링크로 바꾸지 않는 자리 — 키는 「파일#data-ref(또는 노드 이름)[?쿼리]」 */
const EXCLUDED: Record<string, string> = {

};

/**
 * 디렉터리 아래 .json 을 모두 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns .json 절대경로 목록
 */
function collectJson(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return collectJson(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/** 노드 판정 — name 이 있는 객체 */
const isLayoutNode = (x) => !!x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string';
/** 실제 주소 — 빈 값·'#'·javascript: 는 링크가 아니다 */
const isRealHref = (h) => typeof h === 'string' && h.trim() !== '' && h.trim() !== '#' && !/^javascript:/i.test(h.trim());
/** 액션(또는 그 안쪽)에 navigate 가 있는가 */
const hasNavigate = (a) => JSON.stringify(a ?? null).includes('"handler":"navigate"');

/**
 * 액션 안쪽의 navigate 를 모두 모은다(sequence·parallel·conditions·switch 갈래 포함).
 *
 * @param a 액션
 * @param acc 담을 배열
 * @returns acc
 */
function navigatesIn(a, acc = []) {
    if (Array.isArray(a)) {
        a.forEach((x) => navigatesIn(x, acc));
        return acc;
    }
    if (!a || typeof a !== 'object') return acc;
    if (a.handler === 'navigate') acc.push(a);
    Object.values(a).forEach((v) => navigatesIn(v, acc));
    return acc;
}

/**
 * 레이아웃 하나의 화면 이동 자리를 모은다.
 *
 * - click 진입점: 최상위 navigate(replace 아님) = 이동, 또는 어떤 갈래든 탭·페이지 쿼리(`tab`·`page`)를 바꾸는 navigate = 탭·페이지.
 *   sequence 안쪽의 일반 이동·저장 뒤 이동·확인 창 이동은 링크 대상이 아니다(D13).
 * - 합성 컴포넌트: 페이지(onPageChange)·탭(onTabChange)·행 메뉴(onRowAction)·ActionMenu(onItemClick) 의 이동 갈래.
 *
 * @param json 레이아웃 JSON
 * @param rel 보고용 경로
 * @returns 판정 목록
 */
function judgeNavigation(json, rel) {
    const named = (json && json.named_actions) || {};
    const resolve = (a) => (a && a.actionRef && named[a.actionRef] ? { ...named[a.actionRef], ...a } : a);
    const found = [];
    (function visit(o) {
        if (Array.isArray(o)) return o.forEach(visit);
        if (!o || typeof o !== 'object') return;
        if (isLayoutNode(o) && Array.isArray(o.actions)) {
            const p = o.props || {};
            const ref = String(p['data-ref'] ?? o.id ?? '');
            const where = `${rel}#${ref || o.name}`;
            for (const raw of o.actions) {
                const a = resolve(raw);
                if (!a) continue;
                const event = a.event || a.type;
                // 클릭 진입점 — `click` · `onClick`(엔진이 click 과 같이 처리) · 이벤트 생략(기본 click) 모두
                if (event === 'click' || event === 'onClick' || (!a.event && (!a.type || a.type === 'click' || a.type === 'onClick'))) {
                    if (a.confirm) continue;
                    // 탭·페이지 = 쿼리 키가 탭 키(tab·channel — 알림 설정 채널 탭)와 page 뿐인 이동(필터·검색은 다른 키를 함께 바꾼다)
                    const tabNav = navigatesIn(a).find((n) => {
                        const q = n.params && n.params.query;
                        if (!q || typeof q !== 'object') return false;
                        const keys = Object.keys(q);
                        return keys.length > 0 && keys.every((k) => k === 'tab' || k === 'channel' || k === 'page');
                    });
                    let kind = null;
                    let key = where;
                    if (a.handler === 'navigate' && a.params?.replace !== true) kind = 'click_move';
                    else if (tabNav && a.handler !== 'apiCall') {
                        kind = 'tab_or_page';
                        key = `${where}?${JSON.stringify(tabNav.params.query)}`;
                    } else if (['switch', 'conditions', 'sequence', 'parallel'].includes(a.handler)
                        // 갈래(switch·conditions)·묶음(sequence·parallel) 안의 이동도 클릭 이동이다(라운드 6). 단 저장·모달·확인 창이
                        // 섞인 흐름(저장 뒤 이동·확인 뒤 이동)은 D13 그대로 대상이 아니다.
                        && !/"handler":"(apiCall|openModal|closeModal|confirm|showConfirm)"/.test(JSON.stringify(a))
                        && navigatesIn(a).some((n) => n.params?.replace !== true)) {
                        kind = 'click_move';
                    }
                    if (kind) found.push({ kind, where: key, ref, ok: o.name === 'A' && isRealHref(p.href), label: `${key} (${o.name})` });
                }
                if (event === 'onPageChange' && hasNavigate(a) && /^(DataGrid|CardGrid|Pagination)$/.test(o.name)) {
                    found.push({ kind: 'page', where, ref, ok: isRealHref(p.pageHref), label: `${where} ${o.name} pageHref` });
                }
                if (event === 'onTabChange' && hasNavigate(a) && /^TabNavigation/.test(o.name)) {
                    const tabs = Array.isArray(p.tabs) ? p.tabs : [];
                    for (const t of tabs) {
                        found.push({ kind: 'tab', where, ref, ok: isRealHref(t && t.href), label: `${where} ${o.name} tab ${t && (t.id ?? t.value ?? t.key)}` });
                    }
                }
                if ((event === 'onRowAction' || event === 'onItemClick') && a.handler === 'switch' && a.cases) {
                    const items = event === 'onRowAction' ? p.rowActions : p.items;
                    for (const [id, c] of Object.entries(a.cases)) {
                        // 새 창 이동(openWindow, target 이 _self 가 아닌 것)도 같은 결함군 — 항목이 href + target=_blank 링크여야 한다(결정 2026-10-15)
                        const newWindow = c && c.handler === 'openWindow' && (c.params?.target ?? '_blank') !== '_self';
                        if (!newWindow && (!hasNavigate(c) || c.handler !== 'navigate')) continue;
                        const item = Array.isArray(items) ? items.find((it) => it && String(it.id) === id) : null;
                        if (!item) continue;
                        const ok = isRealHref(item.href) && (!newWindow || item.target === '_blank');
                        found.push({ kind: 'menu_item', where: `${where}@${id}`, ref, ok, label: `${where} ${o.name} item ${id}${newWindow ? ' (새 창)' : ''}` });
                    }
                }
            }
        }
        // 새 창 링크 항목 — 항목이 target 을 가지면(href + target 링크로 바꾼 뒤 옛 openWindow 갈래는 지운다) 갈래가 없어도
        // 모집단에 넣는다. href 가 실제 주소이고 target 이 _blank 여야 한다(_self 는 같은 창이라 대상 아님).
        if (o && typeof o === 'object' && o.props) {
            const where = `${rel}#${String(o.props['data-ref'] ?? o.id ?? '') || o.name}`;
            for (const list of [o.props.items, o.props.rowActions]) {
                for (const item of Array.isArray(list) ? list : []) {
                    if (!item || item.target === undefined || item.target === '_self') continue;
                    const key = `${where}@${item.id}`;
                    if (found.some((x) => x.where === key)) continue;
                    found.push({ kind: 'menu_item', where: key, ref: String(o.props['data-ref'] ?? o.id ?? ''), ok: isRealHref(item.href) && item.target === '_blank', label: `${where} ${o.name} item ${item.id} (새 창)` });
                }
            }
        }
        for (const v of Object.values(o)) if (v && typeof v === 'object') visit(v);
    })(json);
    return found;
}

describe('플러그인 sirsoft-daum_postcode — 화면 이동 링크 (#757)', () => {
    const files = ROOTS.flatMap((r) => collectJson(path.join(ROOT, r)));
    const found = files.flatMap((f) =>
        judgeNavigation(JSON.parse(fs.readFileSync(f, 'utf8')), path.relative(ROOT, f).split(path.sep).join('/')),
    );

    /**
     * 클릭으로 다른 화면에 가는 노드 — A 이고 href 가 실제 주소다.
     *
     * @scenario entry=click_move
     * @effects navigation_is_link_with_href
     */
    it('click_move: 클릭으로 다른 화면에 가는 노드는 A 이고 href 가 실제 주소다', () => {
        const here = found.filter((x) => x.kind === 'click_move' && !(x.where in EXCLUDED));
        expect(here.length).toBeGreaterThan(0);
        expect(here.filter((x) => !x.ok).map((x) => x.label)).toEqual([]);
    });

    /**
     * 제외 목록은 지금 레이아웃에 실재하는 자리만 가리킨다 — 자리가 사라지거나 이름이 바뀌면 낡은 제외가 남지 않게.
     *
     * @scenario entry=click_move
     * @effects exclusions_point_to_live_nodes
     */
    it('제외 목록의 자리가 모두 실재한다', () => {
        const live = new Set(found.map((x) => x.where));
        expect(Object.keys(EXCLUDED).filter((k) => !live.has(k))).toEqual([]);
    });
});
