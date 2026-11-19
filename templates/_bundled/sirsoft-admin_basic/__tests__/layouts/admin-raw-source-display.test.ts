// e2e:allow 레이아웃 정적 구조(원문 표시 바인딩의 raw: 표식) + 엔진 번역 단계 단언 — 브라우저 축은(활동 로그 「지우기 전 본문 보기」의 `$t:` 보존)이 덮는다.
/**
 * @file admin-raw-source-display.test.ts
 * @description 저장된 원문(레이아웃 본문·변경 전후 값·요청/응답 JSON)을 그대로 보여 주는 관리자 화면은 `{{raw:…}}` 로 그린다
 *
 * 일반 텍스트 바인딩은 엔진의 `$t:` 번역 단계(`resolveTranslationsDeep`)를 거쳐, 본문 안의 `"$t:error.default.title"` 이
 * 번역 키를 못 찾으면 `"error.default.title"` 로 접두가 빠져 보였다(활동 로그 「지우기 전 본문 보기」). 화면에서 복사해 되살리면
 * 다국어 키가 깨진다. 엔진의 번역 면제 표식 `raw:`(docs/frontend/data-binding-i18n.md 「번역 면제 바인딩」)로 그린다.
 *
 * @scenario-manifest tests/scenarios/admin-raw-source-display.yaml
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

type Json = Record<string, any>;

const LAYOUTS: Record<string, Json> = import.meta.glob('../../layouts/**/*.json', { eager: true, import: 'default' });

/** 원문을 보여 주는 값 — 이 값을 그리는 글자 바인딩은 raw: 여야 한다 */
const RAW_SOURCES = [/snapshot\?\.content_preview/, /JSON\.stringify\(/, /\bchange\.(old|new)\b/];

/**
 * 트리의 글자 노드(text)를 모두 모은다.
 *
 * @param node 트리
 * @param file 파일 이름
 * @param out 결과
 * @returns [파일, 글자] 목록
 */
function texts(node: unknown, file: string, out: Array<[string, string]> = []): Array<[string, string]> {
    if (Array.isArray(node)) {
        node.forEach((c) => texts(c, file, out));

        return out;
    }
    if (node && typeof node === 'object') {
        const n = node as Json;
        if (typeof n.text === 'string' && typeof n.name === 'string') out.push([file, n.text]);
        Object.entries(n).forEach(([k, v]) => {
            if (k !== 'actions' && k !== 'lifecycle') texts(v, file, out);
        });
    }

    return out;
}

describe('원문 표시 — 번역 단계를 건너뛴다', () => {
    /**
     * @scenario surface=activity_log_snapshot
     * @effects raw_source_keeps_translation_tokens
     * @return void 원문을 그리는 글자 바인딩이 모두 raw: 면 통과
     */
    it('원문(지우기 전 본문·변경 전후 값·JSON)을 그리는 글자 바인딩은 {{raw:…}} 다', () => {
        const all = Object.entries(LAYOUTS).flatMap(([file, doc]) => texts(doc, file.replace(/^\.\.\/\.\.\/layouts\//, '')));
        const targets = all.filter(([, text]) => RAW_SOURCES.some((re) => re.test(text)));
        expect(targets.some(([f]) => f.endsWith('admin_activity_log_list/_partial_datagrid.json'))).toBe(true);
        const offenders = targets.filter(([, text]) => !/^\{\{raw:/.test(text.trim())).map(([f, text]) => `${f}: ${text.slice(0, 80)}`);
        expect(offenders).toEqual([]);
    });

    /**
     * 표의 줄 데이터(`DataGrid` `data`)도 raw 로 넘긴다 — 엔진은 컴포넌트 props 를 번역 단계(`resolveTranslationsDeep`)에 통과시켜,
     * 줄 데이터 안 문자열의 `$t:` 를 펼친 줄이 그리기 **전에** 번역해 버린다(3차 재실측 원인). 셀의 raw 만으로는 막지 못한다.
     *
     * @scenario surface=activity_log_snapshot
     * @effects raw_source_keeps_translation_tokens
     * @return void 원문을 펼쳐 보이는 표 세 곳의 data 가 raw 면 통과
     */
    it('원문을 펼쳐 보이는 표(활동 로그·본인인증 기록·큐 처리 기록)의 줄 데이터는 {{raw:…}} 다', () => {
        for (const tail of ['admin_activity_log_list/_partial_datagrid.json', 'admin_identity_logs/_partial_datagrid.json', 'admin_schedule_list/_queue_logs_grid.json']) {
            const doc = Object.entries(LAYOUTS).find(([f]) => f.endsWith(tail))![1];
            const grids: Json[] = [];
            const walk = (x: unknown): void => {
                if (Array.isArray(x)) return x.forEach(walk);
                if (x && typeof x === 'object') {
                    if ((x as Json).name === 'DataGrid') grids.push(x as Json);
                    Object.values(x as Json).forEach(walk);
                }
            };
            walk(doc);
            expect(grids.length, tail).toBeGreaterThan(0);
            grids.forEach((g) => expect([tail, String(g.props?.data ?? '').startsWith('{{raw:')]).toEqual([tail, true]));
        }
    });

    /**
     * 엔진 단언 — raw: 로 평가한 본문은 번역 단계를 거쳐도 `$t:` 접두가 남는다(일반 바인딩은 키만 남는다).
     *
     * @scenario surface=activity_log_snapshot
     * @effects raw_source_keeps_translation_tokens
     * @return void raw 결과가 원문과 같으면 통과
     */
    it('raw: 로 그린 본문은 `$t:` 를 그대로 둔다', () => {
        const engine = new DataBindingEngine();
        const body = '{"text": "$t:error.default.title"}';
        const ctx = { row: { snapshot: { content_preview: body } } };
        const raw = engine.resolveBindings('{{raw:row.snapshot?.content_preview ?? \'\'}}', ctx as any, { skipCache: true });
        // 엔진은 raw 결과를 번역 면제 표식으로 감싼다 — 표식을 벗기면 원문 그대로
        expect(String(raw).replace(/[﷐﷑]/g, '')).toBe(body);
        expect(String(raw)).toMatch(/﷐/);
    });
});
