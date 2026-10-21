// e2e:allow 레이아웃 정적 구조(반복 행 클릭 요소의 요소 종류·숫자칸 자기 이름) 단언 전용 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(키보드 Tab·Enter 로 회원 고르기, 접근성 트리 spinbutton 이름)이 덮는다.
/**
 * @file adminModalRowKeyboard.test.ts
 * @description 이커머스 관리자 창(모달) 안 반복 행 선택이 키보드로 되는지, 마일리지 수동 지급 창 「금액」 칸이 자기 이름을 갖는지
 *
 * 마일리지 수동 지급/차감 창의 회원 검색 결과 행이 클릭만 받는 DIV(role·tabindex 없음)라 Tab 으로 닿지 않고 Enter 로 고를
 * 수 없었다(7.1.1 출시 전 브라우저 실측). 같은 일을 하는 쿠폰 직접 발급 창은 행마다 단추를 두어 키보드로 고른다.
 * 판정: 창 안 반복 행(data-each)에서 클릭을 받는 요소는 단추·링크 같은 키보드 요소이거나, 아니면 role·tabIndex·keydown
 * 셋을 모두 갖춘다. 모집단은 이커머스 관리자 레이아웃 JSON 전체의 창 안 반복 행에서 파생한다 — 새 창에도 그대로 걸린다.
 *
 * 같은 창 「금액」 숫자칸은 보이는 제목 없이 placeholder(「금액」)만 있었다. Chromium 접근성 트리는 HTML-AAM 대로 숫자칸도
 * placeholder 를 이름으로 쓰지만(실측 spinbutton name="금액", NameFrom placeholder), Playwright 의 접근 이름 계산(ariaSnapshot·
 * getByRole)은 숫자칸에서 placeholder 를 쓰지 않아 이름 없는 spinbutton 으로 읽혔다. 값을 넣으면 화면에서도 제목이 사라지므로
 * 칸이 자기 이름(aria-label)을 단다.
 *
 * @scenario surface=admin_row
 * @effects modal_row_selection_keyboard_operable
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import manualTransactionModal from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json';

const ROOT = resolve(__dirname, '../../../..');

/** 스스로 키보드로 닿고 Enter·Space 로 누를 수 있는 부품(또는 그 안의 칸이 키보드를 받는 부품) */
const KEYBOARD_NATIVE = new Set([
    'Button',
    'A',
    'Link',
    'Input',
    'Select',
    'Textarea',
    'Checkbox',
    'Toggle',
    'Label',
    'SearchableDropdown',
    'ActionMenu',
]);

/**
 * 디렉터리 아래 JSON 경로를 모읍니다.
 *
 * @param dir 시작 디렉터리
 * @return JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return jsonFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

const isNode = (x: any): boolean =>
    Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children));
const eventsOf = (n: any): string[] => actionsOf(n).map((a: any) => String(a?.type ?? a?.event ?? ''));

/**
 * 키보드로 고를 수 있는 요소인지 판정합니다.
 *
 * @param n 클릭을 받는 노드
 * @return 단추·링크 부품이거나 role·tabIndex·keydown 을 모두 갖추면 true
 */
function keyboardOperable(n: any): boolean {
    if (KEYBOARD_NATIVE.has(n.name)) return true;
    const p = n.props ?? {};
    const tab = p.tabIndex ?? p.tabindex;

    return Boolean(p.role) && tab !== undefined && eventsOf(n).some((e) => /^key(down|up|press)$/i.test(e));
}

/**
 * 창(Modal) 안 반복 행에서 클릭을 받는데 키보드로 고를 수 없는 요소를 모읍니다.
 *
 * @param root 레이아웃 JSON
 * @return 이름·클래스 요약 목록
 */
function clickOnlyRowsInModal(root: any): string[] {
    const out: string[] = [];
    const walk = (x: any, inModal: boolean, inRepeat: boolean): void => {
        if (Array.isArray(x)) return x.forEach((y) => walk(y, inModal, inRepeat));
        if (!x || typeof x !== 'object') return;
        const modal = inModal || x.name === 'Modal';
        const repeat = inRepeat || Boolean(x.iteration);
        if (isNode(x) && modal && repeat && eventsOf(x).includes('click') && !keyboardOperable(x)) {
            out.push(`${x.name}${x.iteration ? `[each ${String(x.iteration.source).slice(0, 60)}]` : ''} .${String(x.props?.className ?? '').slice(0, 40)}`);
        }
        Object.values(x).forEach((v) => walk(v, modal, repeat));
    };
    walk(root, false, false);

    return out;
}

/**
 * 트리에서 조건을 만족하는 첫 노드를 찾습니다.
 *
 * @param x 탐색 대상
 * @param pred 조건
 * @return 찾은 노드, 없으면 undefined
 */
function findFirst(x: any, pred: (n: any) => boolean): any {
    if (Array.isArray(x)) {
        for (const y of x) {
            const hit = findFirst(y, pred);
            if (hit) return hit;
        }

        return undefined;
    }
    if (!x || typeof x !== 'object') return undefined;
    if (isNode(x) && pred(x)) return x;
    for (const v of Object.values(x)) {
        const hit = findFirst(v, pred);
        if (hit) return hit;
    }

    return undefined;
}

describe('이커머스 관리자 창 안 반복 행 키보드 선택 · 수동 지급 금액 칸 이름', () => {
    // @scenario surface=admin_row
    // @effects modal_row_selection_keyboard_operable
    it('창 안 반복 행에서 클릭을 받는 요소는 키보드로 고를 수 있다(단추·링크 또는 role·tabIndex·keydown)', () => {
        const files = jsonFiles(join(ROOT, 'resources/layouts/admin'));
        expect(files.length).toBeGreaterThan(50);
        const missing = files.flatMap((path) => {
            const rel = relative(join(ROOT, 'resources'), path).replace(/\\/g, '/');

            return clickOnlyRowsInModal(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${rel}: ${s}`);
        });
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=admin_row
    // @effects modal_row_selection_keyboard_operable
    it('수동 지급 창 회원 검색 결과 행은 단추이고, 누르면 회원을 고른다', () => {
        const row = findFirst(manualTransactionModal, (n) =>
            String(n.iteration?.source ?? '').includes('mileageManual?.memberSearchResults'),
        );
        expect(row).toBeTruthy();
        expect(row.name).toBe('Button');
        expect(row.props?.type).toBe('button');
        const click = actionsOf(row).find((a: any) => a.type === 'click');
        expect(click.handler).toBe('setState');
        expect(click.params['mileageManual.selectedMember']).toContain('uuid: member.uuid');
        // 단추 안에는 블록 요소(Div)를 두지 않는다 — 단추 이름은 회원 이름·이메일 글자다
        expect(findFirst(row.children, (n) => n.name === 'Div')).toBeUndefined();
    });

    // @scenario surface=admin_layout
    // @effects controls_have_accessible_name
    it('수동 지급 창 「금액」 숫자칸은 placeholder 가 아닌 자기 이름(aria-label)을 갖는다', () => {
        const amount = findFirst(
            manualTransactionModal,
            (n) => n.name === 'Input' && n.props?.type === 'number' && String(n.props?.value ?? '').includes('mileageManual?.amount'),
        );
        expect(amount).toBeTruthy();
        expect(amount.props['aria-label']).toBe('$t:sirsoft-ecommerce.admin.mileage_transactions.modal_manual.amount');
    });
});
