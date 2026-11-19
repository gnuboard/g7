/**
 * @file named-radio-autobinding-guard.test.ts
 * @description 폼 자동 바인딩(dataKey) 아래의 이름 있는 라디오는 :autoBinding="false" — 번들 레이아웃 전체
 *
 * 배경 (#17):
 *  dataKey 가 있는 폼 안의 이름 있는 입력은 엔진이 `{dataKey}.{name}` 상태에 자동으로 묶는다. 라디오도 예외가 아니라서
 *  상태값이 boolean 이 아니면 value 를 지금 상태값으로 덮어 같은 이름의 라디오가 모두 같은 value 를 갖고, 바꿀 때 옛 값이
 *  다시 써지거나(스케줄 필터에서 처음 확인) value 가 없는 라디오는 브라우저 기본값 "on" 을 폼 상태에 흘린다.
 *  라디오는 checked 식과 change 액션으로 직접 다루므로 자동 바인딩을 끈다(:autoBinding="false").
 *  처음에는 화면 몇 개를 손으로 고른 목록이었고 그 밖의 화면(게시판 설정·상품 옵션)이 빠졌다 — 그래서 목록을 손으로
 *  적지 않고 번들 관리자 템플릿·모듈·플러그인의 모든 레이아웃을 partial 포함까지 따라가며 훑는다.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import { describe, it, expect } from 'vitest';

const REPO_ROOT = path.resolve(__dirname, '../../../../..');

/** 디렉터리 아래의 레이아웃 JSON 경로를 모읍니다. */
function layoutFiles(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return layoutFiles(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/** 번들 관리자 템플릿·모듈·플러그인의 레이아웃 루트를 저장소에서 찾습니다. */
function layoutRoots(): string[] {
    const roots = [path.join(REPO_ROOT, 'templates/_bundled/sirsoft-admin_basic/layouts')];
    for (const kind of ['modules', 'plugins']) {
        const base = path.join(REPO_ROOT, kind, '_bundled');
        for (const id of fs.readdirSync(base)) {
            const dir = path.join(base, id, 'resources/layouts');
            if (fs.existsSync(dir)) roots.push(dir);
        }
    }
    return roots;
}

/** partial 참조를 그 레이아웃이 있는 디렉터리부터 위로 올라가며 실제 파일로 찾습니다. */
function resolvePartial(fromFile: string, ref: string, root: string): string | null {
    let dir = path.dirname(fromFile);
    while (dir.startsWith(root)) {
        const candidate = path.join(dir, ref);
        if (fs.existsSync(candidate)) return candidate;
        const parent = path.dirname(dir);
        if (parent === dir) break;
        dir = parent;
    }
    return null;
}

type Offender = string;

/** 노드를 돌며 dataKey 아래의 자동 바인딩 켜진 이름 있는 라디오를 모읍니다(partial 포함). */
function scan(node: unknown, ctx: { file: string; root: string; inForm: boolean; seen: Set<string> }, out: Offender[], counter: { radios: number }): void {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        node.forEach((n) => scan(n, ctx, out, counter));
        return;
    }
    const record = node as Record<string, unknown>;
    const props = (record.props ?? {}) as Record<string, unknown>;
    const inForm = ctx.inForm || typeof record.dataKey === 'string' || typeof props.dataKey === 'string';

    if (record.name === 'Input' && props.type === 'radio' && typeof props.name === 'string' && inForm) {
        counter.radios++;
        if (props.autoBinding !== false) {
            out.push(`${path.relative(REPO_ROOT, ctx.file)} → name=${props.name}`);
        }
    }

    if (typeof record.partial === 'string') {
        const target = resolvePartial(ctx.file, record.partial, ctx.root);
        const key = `${target}|${inForm}`;
        if (target && !ctx.seen.has(key)) {
            ctx.seen.add(key);
            const json = JSON.parse(fs.readFileSync(target, 'utf8'));
            scan(json, { ...ctx, file: target, inForm }, out, counter);
        }
    }

    for (const [k, v] of Object.entries(record)) {
        if (k === 'partial') continue;
        scan(v, { ...ctx, inForm }, out, counter);
    }
}

describe('폼 자동 바인딩 아래 이름 있는 라디오 — :autoBinding="false"', () => {
    // @scenario case=named_radio_under_form
    // @effects named_radio_under_form_disables_auto_binding
    it('번들 레이아웃 전체(partial 포함)에서 dataKey 아래의 이름 있는 라디오가 자동 바인딩을 끈다', () => {
        const offenders: Offender[] = [];
        const counter = { radios: 0 };
        for (const root of layoutRoots()) {
            for (const file of layoutFiles(root)) {
                if (file.split(path.sep).includes('partials')) continue;
                let json: unknown;
                try {
                    json = JSON.parse(fs.readFileSync(file, 'utf8'));
                } catch {
                    continue;
                }
                scan(json, { file, root, inForm: false, seen: new Set() }, offenders, counter);
            }
        }

        // 모집단이 비면(경로·포함 해석 오류) 조용히 통과하지 않게 한다 — 회원·상품·쿠폰 필터만으로도 수십 개다.
        expect(counter.radios).toBeGreaterThan(30);
        expect([...new Set(offenders)].sort()).toEqual([]);
    });
});
