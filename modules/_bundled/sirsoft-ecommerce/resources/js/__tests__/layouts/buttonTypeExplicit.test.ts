// e2e:allow 레이아웃 정적 구조(단추의 type 명시) 단언 전용 — 화면 동작은 바뀌지 않는다(폼 밖 단추는 type 이 없어도 제출하지 않는다). 브라우저 축은 7.1.1 출시 전 캠페인 재실측(헤더 통화 선택 단추 `type="button"`)이 덮는다.
/**
 * @file buttonTypeExplicit.test.ts
 * @description 이커머스 레이아웃·확장 조각의 모든 단추가 `type` 을 적는다 (7.1.1 관찰)
 *
 * HTML `<button>` 은 type 을 적지 않으면 `submit` 이다. 폼 안에 놓이면 누르는 순간 폼이 제출된다. 감사 룰
 * `layout-form-button-type` 은 같은 파일 안 Form 자손만 보므로, 다른 화면의 자리(슬롯·확장 지점)에 주입되는 확장 조각
 * (헤더 통화 선택 `header-currency-selector-{admin,user}`)이나 나중에 폼으로 감싸일 패널의 단추는 잡지 못한다.
 * 공개 규정(docs/frontend/components.md 「폼 안의 Button 은 type="button" 명시」)을 이커머스 전체로 넓혀 잠근다.
 *
 * 판정: 이커머스 `resources/layouts/**`·`resources/extensions/**` JSON 의 모든 Button 노드가 `props.type`(button·submit·reset)을 갖는다.
 *
 * @scenario surface=admin_layout
 * @effects button_type_explicit
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const ROOT = resolve(__dirname, '../../../..');

/**
 * 디렉터리 아래 JSON 경로를 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return jsonFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

/**
 * type 을 적지 않은(또는 허용 밖 값을 적은) Button 노드를 모은다.
 *
 * @param x 탐색 대상
 * @param out 결과
 * @returns 요약 목록
 */
function untypedButtons(x: any, out: string[] = []): string[] {
    if (Array.isArray(x)) {
        x.forEach((y) => untypedButtons(y, out));

        return out;
    }
    if (!x || typeof x !== 'object') return out;
    if (x.name === 'Button' && (x.type || x.props || x.children)) {
        const t = x.props?.type;
        if (!['button', 'submit', 'reset'].includes(String(t))) out.push(`Button${x.id ? `#${x.id}` : ''} type=${String(t)} .${String(x.props?.className ?? '').slice(0, 40)}`);
    }
    Object.entries(x).forEach(([k, v]) => {
        if (k !== 'actions') untypedButtons(v, out);
    });

    return out;
}

describe('이커머스 단추 type 명시', () => {
    // @scenario surface=admin_layout
    // @effects button_type_explicit
    it('레이아웃·확장 조각의 모든 단추가 type(button·submit·reset)을 적는다', () => {
        const files = [...jsonFiles(join(ROOT, 'resources/layouts')), ...jsonFiles(join(ROOT, 'resources/extensions'))];
        expect(files.length).toBeGreaterThan(200);
        const missing = files.flatMap((path) =>
            untypedButtons(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${s}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_extension
    // @effects button_type_explicit
    it('헤더 통화 선택 조각(관리자·사용자)의 트리거·항목 단추는 type="button" 이다', () => {
        for (const name of ['header-currency-selector-admin', 'header-currency-selector-user']) {
            const json = JSON.parse(readFileSync(join(ROOT, `resources/extensions/${name}.json`), 'utf-8'));
            const buttons: any[] = [];
            (function visit(o: any): void {
                if (Array.isArray(o)) return o.forEach(visit);
                if (!o || typeof o !== 'object') return;
                if (o.name === 'Button') buttons.push(o);
                Object.values(o).forEach(visit);
            })(json);
            expect(buttons.length).toBeGreaterThanOrEqual(3);
            expect(buttons.map((b) => b.props?.type)).toEqual(buttons.map(() => 'button'));
        }
    });
});
