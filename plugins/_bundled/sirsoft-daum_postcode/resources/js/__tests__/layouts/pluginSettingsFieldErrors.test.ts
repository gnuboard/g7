// e2e:allow 칸 아래 오류 표시·숫자 범위 속성 추가의 구조 검증 — 같은 화면은 Playwright 브라우저로 ko/en 재실측해 작업 이력에 남김
/**
 * @file pluginSettingsFieldErrors.test.ts
 * @description 우편번호 환경설정 화면이 저장 오류를 그 칸 아래에 보이고, 숫자 칸이 서버와 같은 범위를 갖는지
 *
 * 회귀 배경(7.1.0 실측 M1-O11): 팝업 너비 -10 이 저장됐고(스키마에 범위 없음), 서버 오류는 상단 상자에만
 * 나와 어느 칸을 고쳐야 하는지 칸에서는 보이지 않았다. 범위는 플러그인 설정 스키마(min 100 · max 2000)와 같다.
 *
 * @effects postcode_settings_field_error_under_input, postcode_popup_size_input_range_matches_schema
 */

import { describe, it, expect } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';

/**
 * 트리의 모든 노드를 펼친다.
 *
 * @param node 시작 노드
 * @returns 노드 목록
 */
function flatten(node: unknown): any[] {
    const out: any[] = [];
    const stack: unknown[] = [node];
    while (stack.length) {
        const cur = stack.pop();
        if (Array.isArray(cur)) {
            stack.push(...cur);
            continue;
        }
        if (!cur || typeof cur !== 'object') continue;
        out.push(cur);
        stack.push(...Object.values(cur as Record<string, unknown>));
    }
    return out;
}

const nodes = flatten(layout);

/**
 * 표현식을 문맥으로 평가한다.
 *
 * @param expr `{{ }}` 표현식
 * @param ctx 문맥
 * @returns 결과
 */
function evaluate(expr: string, ctx: Record<string, unknown>): unknown {
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(ctx), 'return (' + expr.replace(/^\{\{/, '').replace(/\}\}$/, '') + ');')(...Object.values(ctx));
}

describe('우편번호 설정 — 칸 아래 저장 오류', () => {
    /**
     * @scenario check=field_error_under_input
     * @effects postcode_settings_field_error_under_input
     */
    it.each(['display_mode', 'popup_width', 'popup_height', 'theme_color'])('%s 오류는 그 칸 아래에 첫 문구를 보인다', (field) => {
        const span = nodes.find((n) => n.props?.className === 'form-error' && String(n.if ?? '').includes(`errors?.${field}`));
        expect(span).toBeTruthy();
        const ctx = { _local: { errors: { [field]: ['팝업 너비 (px) 필드는 최소 100 이상이어야 합니다.'] } } };
        expect(evaluate(span.if, ctx)).toBeTruthy();
        expect(evaluate(span.text, ctx)).toBe('팝업 너비 (px) 필드는 최소 100 이상이어야 합니다.');
        expect(evaluate(span.if, { _local: { errors: null } })).toBeFalsy();
    });
});

describe('우편번호 설정 — 숫자 칸 범위', () => {
    /**
     * @scenario check=number_range_attributes
     * @effects postcode_popup_size_input_range_matches_schema
     */
    it.each(['popup_width', 'popup_height'])('%s 입력칸 min·max 가 스키마(100~2000)와 같다', (field) => {
        const input = nodes.find((n) => n.name === 'Input' && n.props?.name === field);
        expect(input?.props?.min).toBe('100');
        expect(input?.props?.max).toBe('2000');
    });
});
