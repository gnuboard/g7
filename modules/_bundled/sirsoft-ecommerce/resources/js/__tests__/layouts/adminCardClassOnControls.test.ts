/**
 * @file adminCardClassOnControls.test.ts
 * @description 관리자 화면의 단추·작은 요소에 카드 상자 클래스(`admin-card`)를 쓰지 않는지
 *
 * `admin-card` 는 카드 **컨테이너**용 자산이다 — 흰 바탕·테두리·16px 안쪽 여백에 **아래 24px 바깥 여백**(mb-6)과
 * `flex-col` 까지 함께 넣는다. 단추에 붙이면 단추가 카드처럼 그려지고, 바깥 여백만큼 줄어들어 옆 요소와 높이가 어긋난다.
 *
 * - 상품 수정 > 기본정보 > 브랜드 줄의 [해제(×)] 단추가 이렇게 그려져, 옆 회색 브랜드 상자(80px)보다 낮은 흰 카드(56px)로
 *   깨져 보였다. 같은 카드 안 분류 칩의 × 처럼 단추 자산(`btn btn-outline`)으로 그린다.
 * - 상품 폼 언어 추가 창의 언어 단추(English·日本語·中文)도 같은 클래스라, 옆 한국어 단추(py-4 px-6 가운데 정렬)와
 *   크기·정렬이 어긋났다. 한국어 단추와 같은 골격에 선택되지 않은 단추 색(`btn-secondary`)을 쓴다.
 *
 * 선택 카드(`admin-card-clickable`·`admin-card-selected`)는 카드 모양 자체가 의도이므로 이 검사 밖이다.
 * 소스가 아니라 **구워진 짝 `.json`**(런타임이 실제로 먹는 값)을 검사한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, relative, join } from 'node:path';

const LAYOUTS = resolve(__dirname, '../../../layouts/admin');
const PRODUCT_FORM = 'partials/admin_ecommerce_product_form';

type Node = Record<string, any>;

/** 상자가 아닌 단추·글자·입력 같은 작은 요소의 컴포넌트 이름. */
const CONTROL_NAMES = new Set(['Button', 'A', 'Span', 'Icon', 'Label', 'Input', 'Select', 'Img', 'Textarea']);

/**
 * className 값(정적 문자열 또는 `{{ ... }}` 식)에서 클래스 토큰을 뽑습니다.
 *
 * @param cn className 값
 * @return 토큰 목록
 */
function classTokens(cn: unknown): string[] {
    // `dark:`·`hover:` 접두 클래스를 쪼개지 않도록 `:` 는 구분자로 쓰지 않는다(삼항식의 `?`·`:` 는 공백으로 떨어진다)
    return String(cn ?? '').split(/[\s'"`{}()]+/).filter(Boolean);
}

/**
 * 트리를 깊이 순회해 조건을 만족하는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param acc 누적
 * @return 노드 목록
 */
function collect(node: unknown, match: (n: Node) => boolean, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((n) => collect(n, match, acc));

        return acc;
    }
    if (node && typeof node === 'object') {
        if (match(node as Node)) acc.push(node as Node);
        Object.values(node).forEach((v) => collect(v, match, acc));
    }

    return acc;
}

/**
 * 관리자 레이아웃 디렉터리의 모든 `.json` 경로를 모읍니다.
 *
 * @param dir 디렉터리
 * @return 파일 경로 목록
 */
function layoutFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const p = join(dir, e.name);
        if (e.isDirectory()) return layoutFiles(p);

        return e.name.endsWith('.json') ? [p] : [];
    });
}

const load = (rel: string): Node => JSON.parse(readFileSync(resolve(LAYOUTS, `${rel}.json`), 'utf8'));

describe('관리자 화면 — 단추·작은 요소에 카드 상자 클래스를 쓰지 않는다', () => {
    /**
     * @scenario section=card_class_on_controls
     * @effects controls_not_styled_as_card_container
     */
    it('관리자 레이아웃 전체에서 단추·작은 요소에 admin-card 가 없다(선택 카드 제외)', () => {
        const files = layoutFiles(LAYOUTS);
        expect(files.length).toBeGreaterThan(50);

        const misuse = files.flatMap((file) => collect(JSON.parse(readFileSync(file, 'utf8')), (n) => {
            if (!CONTROL_NAMES.has(n.name)) return false;
            const tokens = classTokens(n.props?.className);

            return tokens.includes('admin-card')
                && !tokens.includes('admin-card-clickable')
                && !tokens.includes('admin-card-selected');
        }).map((n) => `${relative(LAYOUTS, file).replace(/\\/g, '/')} :: ${n.name}#${n.id ?? ''}`));

        expect(misuse).toEqual([]);
    });

    /**
     * @scenario section=card_class_on_controls
     * @effects controls_not_styled_as_card_container
     */
    it('상품 폼 브랜드 줄의 해제 단추는 단추 자산(btn btn-outline)이고 이름(aria-label)을 지닌다', () => {
        const layout = load(`${PRODUCT_FORM}/_partial_basic_info`);
        const brandRows = collect(layout, (n) => n.id === 'brand_selected');
        expect(brandRows).toHaveLength(1);

        const buttons = collect(brandRows[0], (n) => n.name === 'Button');
        expect(buttons).toHaveLength(1);
        const [clear] = buttons;
        const tokens = classTokens(clear.props?.className);

        expect(tokens).not.toContain('admin-card');
        expect(tokens).toEqual(expect.arrayContaining(['btn', 'btn-outline']));
        expect(clear.props?.['aria-label']).toBe('$t:sirsoft-ecommerce.common.remove');
        expect(JSON.stringify(clear.children ?? [])).toContain('"name":"xmark"');
        expect(JSON.stringify(clear.actions ?? [])).toContain('form.brand_id');
    });

    /**
     * @scenario section=card_class_on_controls
     * @effects controls_not_styled_as_card_container
     */
    it.each(['lang_btn_en', 'lang_btn_ja', 'lang_btn_zh_cn', 'lang_btn_zh_tw'])(
        '언어 추가 창 %s 단추는 한국어 단추와 같은 골격(가운데 정렬·여백)에 선택 안 된 색(btn-secondary)이다',
        (id) => {
            const layout = load(`${PRODUCT_FORM}/_modal_add_language`);
            const [ko] = collect(layout, (n) => n.id === 'lang_btn_ko');
            const [btn] = collect(layout, (n) => n.id === id);
            expect(ko).toBeDefined();
            expect(btn).toBeDefined();

            const tokens = classTokens(btn.props?.className);
            expect(tokens).not.toContain('admin-card');
            expect(tokens).toContain('btn-secondary');
            const skeleton = classTokens(ko.props?.className).filter((t) => !/^(dark:)?(bg|text)-/.test(t));
            expect(tokens).toEqual(expect.arrayContaining(skeleton));

            // 한국어 단추처럼 언어 이름·코드 두 줄을 곧바로 품는다(구분선이 생기는 row-stack 감싸개 없음)
            expect((btn.children ?? []).map((c: Node) => c.name)).toEqual(['Span', 'Span']);
        },
    );
});
