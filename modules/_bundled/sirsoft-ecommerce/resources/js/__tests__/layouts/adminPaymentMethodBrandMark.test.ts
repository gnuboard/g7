/**
 * 관리자 결제수단 목록 브랜드 마크 렌더 계약 (#639)
 *
 * 간편결제 브랜드 배지는 PG 플러그인 주입 스크립트가 그려진 아이콘을 DOM 에서 바꿔 끼우는 방식이었다.
 * 주입 스크립트를 지운 뒤로는 카탈로그가 실어 주는 `_cached_brand_mark` 를 읽는 노드가 없어 배지가
 * 회색 기본 아이콘으로 보였다. 넓은 화면 표와 좁은 화면 카드가 같은 규칙으로 그리는지 잠근다.
 *
 * @vitest-environment node
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

const partialsDir = path.resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_settings');
const adminComponentsJson = path.resolve(
    __dirname,
    '../../../../../../../templates/_bundled/sirsoft-admin_basic/components.json',
);
const engine = new DataBindingEngine();

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 *
 * @param node 탐색 시작 노드
 * @param match 판정 함수
 * @param out 결과 누적 배열
 * @returns 일치한 노드 목록
 */
function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, match, out));
        return out;
    }
    if (node && typeof node === 'object') {
        if (match(node)) out.push(node);
        Object.values(node).forEach((value) => collect(value, match, out));
    }
    return out;
}

/**
 * `{{...}}` 표현식을 주어진 문맥으로 평가한다.
 *
 * @param expr 바인딩 표현식
 * @param ctx 평가 문맥
 * @returns 평가 결과
 */
function evaluate(expr: string, ctx: Record<string, any>): any {
    return engine.evaluateExpression(expr.slice(2, -2), ctx);
}

const BADGE = { text: 'N', class: 'bg-green-500 text-white' };

describe.each(['_payment_methods_list.json', '_payment_methods_cards.json'])('%s', (file) => {
    const layout = JSON.parse(fs.readFileSync(path.join(partialsDir, file), 'utf-8'));
    const brandMarks = collect(layout, (n) => n.name === 'BrandMark');
    const methodIcons = collect(
        layout,
        (n) => n.name === 'Icon' && typeof n.props?.name === 'string' && n.props.name.includes('_cached_icon'),
    );

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=brand_mark
     * @effects admin_shows_brand_mark
     */
    it('결제수단 행마다 BrandMark 하나가 카탈로그 brand_mark 를 그린다', () => {
        expect(brandMarks).toHaveLength(1);
        expect(brandMarks[0].props.brandMark).toBe('{{$method._cached_brand_mark}}');
    });

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=brand_mark
     * @effects admin_shows_brand_mark, admin_falls_back_to_icon_without_brand_mark
     */
    it('브랜드 마크가 있으면 마크만, 없으면 아이콘만 그린다', () => {
        expect(methodIcons).toHaveLength(1);

        const withMark = { $method: { id: 'naverpay', _cached_brand_mark: BADGE, _cached_icon: 'credit-card' } };
        const withoutMark = { $method: { id: 'dbank', _cached_brand_mark: null, _cached_icon: 'building-columns' } };

        expect(Boolean(evaluate(brandMarks[0].if, withMark))).toBe(true);
        expect(Boolean(evaluate(methodIcons[0].if, withMark))).toBe(false);
        expect(Boolean(evaluate(brandMarks[0].if, withoutMark))).toBe(false);
        expect(Boolean(evaluate(methodIcons[0].if, withoutMark))).toBe(true);
    });
});

describe('관리자 템플릿 컴포넌트 선언', () => {
    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=brand_mark
     * @effects admin_shows_brand_mark
     */
    it('sirsoft-admin_basic 이 BrandMark 를 선언한다 — 없으면 이 목록이 렌더에서 빠진다', () => {
        const manifest = JSON.parse(fs.readFileSync(adminComponentsJson, 'utf-8'));
        const names = collect(manifest, (n) => typeof n.name === 'string' && typeof n.path === 'string').map(
            (n) => n.name,
        );

        expect(names).toContain('BrandMark');
    });
});
