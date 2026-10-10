/**
 * @file cartItemIconButtonNames.test.ts
 * @description 장바구니 상품 줄의 아이콘 버튼(옵션 변경·삭제)이 화면 언어의 읽기 이름을 갖는지
 *
 * 장바구니 줄의 톱니(옵션 변경)·휴지통(삭제) 버튼은 글자 없이 아이콘만 그린다. 버튼에 aria-label 이 없으면
 * 읽기 이름은 안쪽 `Icon` 이 내는 아이콘 이름(「cog」·「trash can」)이 되어, 한국어 화면에서도 화면 낭독기가
 * 영어 아이콘 이름을 읽는다(이커머스 전수 실측 S5, 2026-10-07 — 톱니에 붙은 title 은 내용 이름에 밀려 쓰이지 않는다).
 *
 * 구운 레이아웃(_cart_item.json)에서 자식이 아이콘뿐인 버튼을 모두 찾아, `$t:` 번역 키 aria-label 이 있고
 * 그 키가 한국어·영어 문구에 있는지 본다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const TEMPLATE_ROOT = resolve(__dirname, '../../..');

type Node = Record<string, any>;

const layout: Node = JSON.parse(readFileSync(join(TEMPLATE_ROOT, 'layouts/partials/shop/_cart_item.json'), 'utf8'));

/**
 * 자식이 아이콘뿐인(글자 없는) 버튼 노드를 모두 모읍니다.
 *
 * @param node 시작 노드
 * @param acc 누적 목록
 * @return 아이콘 버튼 노드 목록
 */
function iconOnlyButtons(node: any, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((n) => iconOnlyButtons(n, acc));

        return acc;
    }
    if (node && typeof node === 'object') {
        const children: Node[] = Array.isArray(node.children) ? node.children : [];
        if (node.name === 'Button' && children.length > 0 && children.every((c) => c?.name === 'Icon') && !node.text) {
            acc.push(node);
        }
        Object.values(node).forEach((v) => iconOnlyButtons(v, acc));
    }

    return acc;
}

/**
 * 문구 파일에서 점 경로 값을 찾습니다(shop.* 는 shop.json 부분 파일).
 *
 * @param locale 언어
 * @param key `shop.` 으로 시작하는 키
 * @return 값
 */
function lookup(locale: string, key: string): unknown {
    const [ns, ...rest] = key.split('.');
    const lang: Node = JSON.parse(readFileSync(join(TEMPLATE_ROOT, `lang/partial/${locale}/${ns}.json`), 'utf8'));

    return rest.reduce((acc: any, k) => (acc == null ? undefined : acc[k]), lang);
}

const buttons = iconOnlyButtons(layout);

describe('장바구니 줄 아이콘 버튼 읽기 이름', () => {
    /**
     * @scenario case=c01
     * @effects cart_icon_buttons_named
     */
    it('아이콘 버튼(옵션 변경·삭제)을 찾았다', () => {
        const icons = buttons.map((b) => b.children[0]?.props?.name);
        expect(icons).toContain('settings');
        expect(icons).toContain('trash-can');
    });

    /**
     * @scenario case=c02
     * @effects cart_icon_buttons_named
     */
    it.each(buttons.map((b) => [b.children[0]?.props?.name, b] as const))('「%s」 버튼은 번역된 aria-label 을 갖는다', (_icon, button) => {
        const label = String(button.props?.['aria-label'] ?? '');
        expect(label).toMatch(/^\$t:shop\./);
        for (const locale of ['ko', 'en']) {
            expect(typeof lookup(locale, label.replace(/^\$t:/, '')), `${locale}: ${label}`).toBe('string');
        }
    });
});
