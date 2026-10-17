/**
 * 회원 상세 링크 — 주소가 없으면 링크를 그리지 않는다 (#757 2단계 후속, M1 실측)
 *
 * @description
 * 관리자 게시판 화면의 작성자·회원 자리는 `/admin/users/{uuid}` 링크(또는 메뉴 항목)다. 작성자 응답은
 * 비회원이면 `is_guest: true` 지만, 회원 글인데 회원 행이 없으면(삭제된 계정) `is_guest: false · uuid: null` 이다.
 * `!is_guest` 만 보고 링크를 그리면 그 행에 글자 없는 `href="/admin/users/"` 링크가 생긴다(실측: 게시글 목록 첫 행).
 *
 * 판정(굽기 산출물 JSON):
 *   ① 링크·메뉴 항목의 uuid 가 자기 또는 조상의 `if` 에 들어 있다 — 행 메뉴(rowActions)는 `disabledField` 로 막는다.
 *   ② 링크·메뉴 바로 옆에 uuid 가 없을 때 이름을 그리는 자리가 있다(조건에 그 uuid 를 부정으로 쓴다) — 행 메뉴는 제외.
 *
 * @scenario guard=member_link
 * @effects member_link_requires_uuid, member_name_shown_without_uuid
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const DIRS = ['resources/layouts/admin', 'resources/extensions'];
const MEMBER_HREF = /^\/admin\/users\/\{\{(.+)\}\}$/;

type Site = { where: string; kind: 'link' | 'menu' | 'row_action'; uuids: string[]; guard: string; siblings: string[]; disabledField?: string };

/** 식에서 `…uuid` 경로를 뽑아 `?` 를 지운다. */
const uuidPaths = (expr: string): string[] => [...new Set((expr.match(/[\w?.]+\?*\.uuid/g) ?? []).map((p) => p.replace(/\?/g, '')))];
const norm = (s: string): string => s.replace(/\?/g, '').replace(/\s+/g, ' ');

function collect(): Site[] {
    const files: string[] = [];
    const walk = (dir: string): void => {
        if (!fs.existsSync(dir)) return;
        for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
            const p = path.join(dir, e.name);
            if (e.isDirectory()) walk(p);
            else if (e.name.endsWith('.json')) files.push(p);
        }
    };
    DIRS.forEach((d) => walk(path.join(ROOT, d)));

    const sites: Site[] = [];
    for (const file of files) {
        const rel = path.relative(ROOT, file).replace(/\\/g, '/');
        const visit = (node: any, ifs: string[], siblings: any[]): void => {
            if (!node || typeof node !== 'object') return;
            if (Array.isArray(node)) {
                node.forEach((n) => visit(n, ifs, node));
                return;
            }
            const here = typeof node.if === 'string' ? [...ifs, node.if] : ifs;
            const props = node.props ?? {};
            const sibIfs = siblings.filter((s) => s !== node && s && typeof s.if === 'string').map((s) => norm(s.if));
            const label = `${rel}#${node.id ?? node.name}`;
            const hrefMatch = typeof props.href === 'string' ? MEMBER_HREF.exec(props.href) : null;
            if (hrefMatch) sites.push({ where: label, kind: 'link', uuids: uuidPaths(hrefMatch[1]), guard: norm(here.join(' && ')), siblings: sibIfs });
            for (const item of Array.isArray(props.items) ? props.items : []) {
                const m = typeof item?.href === 'string' ? MEMBER_HREF.exec(item.href) : null;
                if (m) sites.push({ where: `${label} › ${item.id}`, kind: 'menu', uuids: uuidPaths(m[1]), guard: norm(here.join(' && ')), siblings: sibIfs });
            }
            for (const item of Array.isArray(props.rowActions) ? props.rowActions : []) {
                const m = typeof item?.href === 'string' ? MEMBER_HREF.exec(item.href) : null;
                if (m) sites.push({ where: `${label} › ${item.id}`, kind: 'row_action', uuids: uuidPaths(m[1]), guard: '', siblings: [], disabledField: item.disabledField });
            }
            // 자식·슬롯은 물론 props 아래(DataGrid 열의 셀 자식 등)까지 내려간다 — items·rowActions 는 위에서 따로 봤다
            for (const [key, value] of Object.entries(node)) {
                if (key === 'items' || key === 'rowActions') continue;
                if (value && typeof value === 'object') visit(value, here, Array.isArray(value) ? value : siblings);
            }
        };
        visit(JSON.parse(fs.readFileSync(file, 'utf8')), [], []);
    }

    return sites;
}

const SITES = collect();

describe('회원 상세 링크 — 주소가 없으면 링크를 그리지 않는다 (#757)', () => {
    // @scenario guard=member_link
    // @effects member_link_requires_uuid
    it('모집단이 비지 않았다(작성자 링크 1 · 작성자 메뉴 5 · 신고자 메뉴 1 · 행 메뉴 2 이상)', () => {
        expect(SITES.length).toBeGreaterThanOrEqual(9);
    });

    // @scenario guard=member_link
    // @effects member_link_requires_uuid
    it.each(SITES.map((s) => [s.where, s] as const))('%s — uuid 가 조건에 들어 있다', (_where, site) => {
        expect(site.uuids.length, '링크에서 uuid 경로를 찾지 못했다').toBeGreaterThan(0);
        if (site.kind === 'row_action') {
            // 행 메뉴의 disabledField 는 행(row) 기준 경로다
            expect(site.uuids.map((u) => u.replace(/^row\./, ''))).toContain(site.disabledField);
            return;
        }
        for (const uuid of site.uuids) expect(site.guard, `조건: ${site.guard}`).toContain(uuid);
    });

    // @scenario guard=member_link
    // @effects member_name_shown_without_uuid
    it.each(SITES.filter((s) => s.kind !== 'row_action').map((s) => [s.where, s] as const))(
        '%s — uuid 가 없을 때 이름을 그리는 자리가 옆에 있다',
        (_where, site) => {
            const fallback = site.siblings.find((cond) => site.uuids.every((u) => cond.includes(u)) && cond.includes('!'));
            expect(fallback, `옆 자리 조건: ${site.siblings.join(' | ')}`).toBeTruthy();
        },
    );
});
