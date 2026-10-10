/**
 * 게시판 관리 화면의 입력 오류 상자 서식 — 경고 아이콘이 제목 첫 줄에 맞고, 상자 아래가 다음 요소와 띄워진다.
 *
 * 배경: #524 에서 게시판 생성·수정·게시글 작성·환경설정 상자의 아이콘 정렬(items-start + leading-5)과
 * 아래 여백(mb-6)을 맞췄는데, 게시판 생성·수정 폼만 15차 병합에서 옛 서식(`alert-danger`, 아이콘 `mt-0.5`)으로
 * 돌아가 있었다. 브라우저 실측(K3, 2026-10-07): 생성 화면 아이콘 중심이 제목 중심보다 43px 아래, 수정 화면 15px 아래,
 * 상자와 폼 사이 0px. 같은 날 환경설정 상자는 차 0px·간격 24px.
 *
 * 허용 확장자 칸은 422 가 가리키는 칸인데 칸 아래 오류 자리가 없어 형제 칸(최대 파일 용량·개수)과 달랐다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { asArray, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const boardForm = require('../../../layouts/admin/admin_board_form.json');
const postForm = require('../../../layouts/admin/admin_board_post_form.json');
const settings = require('../../../layouts/admin/admin_board_settings.json');
const postTab = require('../../../layouts/admin/partials/admin_board_form/_tab_post.json');

/** 오류 상자(경고 아이콘을 품은 alert-danger)와 그 아이콘을 찾는다 */
function errorBox(layout: any): { box: any; icon: any } {
    const banner = findById(layout, 'validation_error');
    const stack: any[] = [banner];
    while (stack.length) {
        const node = stack.shift();
        if (!node || typeof node !== 'object') continue;
        if (String(node.props?.className ?? '').includes('alert-danger')) {
            const icons: any[] = [];
            const walk = (n: any) => {
                if (!n || typeof n !== 'object') return;
                if (n.name === 'Icon' && n.props?.name === 'triangle-exclamation') icons.push(n);
                asArray(n.children).forEach(walk);
            };
            walk(node);
            return { box: node, icon: icons[0] };
        }
        asArray(node.children).forEach((c: any) => stack.push(c));
    }
    return { box: undefined, icon: undefined };
}

describe('입력 오류 상자 서식', () => {
    const cases: Array<[string, any]> = [
        ['게시판 생성·수정', boardForm],
        ['게시글 작성', postForm],
        ['환경설정', settings],
    ];

    for (const [label, layout] of cases) {
        // @scenario screen=board_form
        // @effects validation_error_icon_aligns_with_title
        it(`${label}: 아이콘을 제목 첫 줄에 맞추고 상자 아래를 띄운다`, () => {
            const { box, icon } = errorBox(layout);
            expect(box, label).toBeTruthy();
            expect(box.props.className, label).toContain('items-start');
            expect(box.props.className, label).toContain('mb-6');
            expect(icon, label).toBeTruthy();
            expect(icon.props.className, label).toContain('leading-5');
            expect(icon.props.className, label).not.toContain('mt-0.5');
        });
    }

    // @scenario screen=board_form
    // @effects board_form_allowed_extensions_shows_field_error
    it('게시판 폼 허용 확장자 칸 아래에 그 칸의 422 문구 자리가 있다', () => {
        const field = findById(postTab, 'allowed_extensions_field');
        expect(field).toBeTruthy();
        const spans = asArray(field.children).filter((c: any) => String(c.if ?? '').includes('_local.errors?.allowed_extensions'));
        expect(spans).toHaveLength(1);
        expect(String(spans[0].text)).toContain('_local.errors?.allowed_extensions?.[0]');
    });
});
