/**
 * @file multilingual-input-textarea-prop.test.ts
 * @description 다국어 입력(MultilingualInput)의 여러 줄 입력은 inputType="textarea" 로만 켠다 — 없는 prop `multiline` 금지
 *
 * 배경 (#17):
 *  MultilingualInput 은 `inputType: 'text' | 'textarea'` 로만 textarea 를 그리고 `multiline` prop 을 모른다.
 *  `multiline: true` 를 주면 조용히 한 줄 입력으로 떨어지고 `rows` 도 무시된다 — 스케줄 편집 창 설명(7.2.0 전에는
 *  textarea), 역할 설명, 쇼핑몰 카테고리 설명이 그렇게 한 줄 입력이 됐다.
 *  이 컴포넌트를 쓰는 레이아웃은 관리자 템플릿·모듈·플러그인 전역이므로 번들 레이아웃 전체를 훑는다.
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
            roots.push(path.join(base, id, 'resources/layouts'));
        }
    }
    return roots;
}

/** 객체 트리에서 이름이 MultilingualInput 인 노드를 모읍니다. */
function multilingualInputs(node: unknown, out: Record<string, unknown>[] = []): Record<string, unknown>[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((n) => multilingualInputs(n, out));
        return out;
    }
    const record = node as Record<string, unknown>;
    if (record.name === 'MultilingualInput') out.push(record);
    Object.values(record).forEach((v) => multilingualInputs(v, out));
    return out;
}

describe('MultilingualInput — 여러 줄 입력은 inputType="textarea"', () => {
    // @scenario case=multilingual_textarea_prop
    // @effects multilingual_textarea_uses_input_type
    it('번들 레이아웃 전체에서 MultilingualInput 에 multiline prop 이 없고, rows 를 주면 inputType 이 textarea 다', () => {
        const offenders: string[] = [];
        let count = 0;
        for (const root of layoutRoots()) {
            for (const file of layoutFiles(root)) {
                let json: unknown;
                try {
                    json = JSON.parse(fs.readFileSync(file, 'utf8'));
                } catch {
                    continue;
                }
                for (const input of multilingualInputs(json)) {
                    count++;
                    const props = (input.props ?? {}) as Record<string, unknown>;
                    const where = `${path.relative(REPO_ROOT, file)} → ${String(props.name ?? input.id ?? '?')}`;
                    if ('multiline' in props) offenders.push(`${where}: multiline`);
                    if ('rows' in props && props.inputType !== 'textarea') offenders.push(`${where}: rows 인데 inputType ${String(props.inputType ?? 'text')}`);
                }
            }
        }

        // 모집단이 비면(경로 오류) 조용히 통과하지 않게 한다.
        expect(count).toBeGreaterThan(20);
        expect(offenders).toEqual([]);
    });
});
