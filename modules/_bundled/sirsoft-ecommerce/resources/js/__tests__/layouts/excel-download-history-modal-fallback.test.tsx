/**
 * @file excel-download-history-modal-fallback.test.tsx
 * @description 엑셀 다운로드 이력 모달의 미선택 상태 표시 가드 (공개 이슈 #92 동일 결함 클래스)
 *
 * 결함 클래스: 부모 레이아웃 state 에 `null` 로만 선언된 키를 옵셔널 체이닝 없이 하위 접근하면,
 *   그 키를 채우는 유일한 지점(행 클릭 setState)이 아직 실행되지 않은 구간에서 표시가 비거나
 *   조건식이 예외로 떨어진다. `admin_ecommerce_excel_download_index.json` 의 `selectedItem` 이
 *   그 형태이고, 이 모달에는 selectedItem 을 검사하는 `if` 가드가 없다.
 *
 * 정정: 모달의 모든 `selectedItem` 하위 접근에 `?.` + 표시식 폴백(`?? ''`, count 인자는 `?? 0`)을 부여한다.
 *   값이 채워진 뒤의 출력은 이전과 바이트 동일하므로 기능 변화는 없다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import downloadHistoryModal from '../../../layouts/admin/partials/admin_ecommerce_excel_download_index/_modal_download_history.json';
import excelDownloadIndex from '../../../layouts/admin/admin_ecommerce_excel_download_index.json';

/**
 * JSON 트리에서 특정 문자열을 포함하는 문자열 값을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @param needle 찾을 부분 문자열
 * @returns 매칭된 문자열 값 목록
 */
function collectStringsContaining(node: unknown, needle: string): string[] {
    const out: string[] = [];
    const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (!n || typeof n !== 'object') return;
        const obj = n as Record<string, unknown>;
        for (const v of Object.values(obj)) {
            if (typeof v === 'string' && v.includes(needle)) out.push(v);
        }
        Object.values(obj).forEach(walk);
    };
    walk(node);
    return out;
}

const refs = collectStringsContaining(downloadHistoryModal, 'selectedItem');

describe('_modal_download_history.json — selectedItem 표시 가드', () => {
    /**
     * @scenario selection_state=unselected
     * @effects history_modal_display_guarded
     */
    it('부모 레이아웃이 selectedItem 을 null 로만 시드한다 (전제 확인)', () => {
        const state = (excelDownloadIndex as any).state ?? {};
        expect(
            Object.prototype.hasOwnProperty.call(state, 'selectedItem'),
            'selectedItem 시드가 사라졌다면 이 테스트의 전제를 재검토할 것'
        ).toBe(true);
        expect(state.selectedItem).toBeNull();
    });

    /**
     * @scenario selection_state=unselected
     * @effects history_modal_display_guarded
     */
    it('selectedItem 하위 접근은 전부 옵셔널 체이닝을 쓴다', () => {
        expect(refs.length, 'selectedItem 참조를 찾지 못했다').toBeGreaterThan(0);
        const unguarded = refs.filter((r) => /selectedItem\.[A-Za-z_]/.test(r));
        expect(
            unguarded,
            `selectedItem 뒤에 '?.' 없는 역참조: ${unguarded.join(' | ')}`
        ).toEqual([]);
    });

    /**
     * 다운로드 기록 목록이 문자열 형태 `"iteration": "{{...}}"` 로 선언되어 있어
     * 엔진이 `iteration.source` 를 읽지 못하고 매 렌더마다 TypeError 로 떨어졌다
     * (목록이 통째로 비어 보임 — 브라우저 실측에서 발견).
     *
     * @scenario selection_state=selected
     * @effects history_list_renders
     */
    it('다운로드 기록 iteration 이 source/item_var/index_var 객체 형태다', () => {
        const iterations: unknown[] = [];
        const walk = (n: unknown): void => {
            if (Array.isArray(n)) { n.forEach(walk); return; }
            if (!n || typeof n !== 'object') return;
            const obj = n as Record<string, unknown>;
            if ('iteration' in obj) iterations.push(obj.iteration);
            Object.values(obj).forEach(walk);
        };
        walk(downloadHistoryModal);

        expect(iterations.length, 'iteration 선언을 찾지 못했다').toBeGreaterThan(0);
        for (const it of iterations) {
            expect(typeof it, `문자열 형태 iteration 은 엔진이 해석하지 못한다: ${String(it)}`).toBe('object');
            const cfg = it as Record<string, unknown>;
            expect(typeof cfg.source).toBe('string');
            expect(cfg.item_var).toBeTruthy();
        }
    });

    /**
     * @scenario selection_state=selected
     * @effects history_modal_display_guarded
     */
    it('표시식(text)은 값 부재 시 폴백을 갖는다', () => {
        const textRefs: string[] = [];
        const walk = (n: unknown): void => {
            if (Array.isArray(n)) {
                n.forEach(walk);
                return;
            }
            if (!n || typeof n !== 'object') return;
            const obj = n as Record<string, unknown>;
            if (typeof obj.text === 'string' && obj.text.includes('selectedItem')) {
                textRefs.push(obj.text);
            }
            Object.values(obj).forEach(walk);
        };
        walk(downloadHistoryModal);

        // 개수를 고정한다 — `> 0` 만 두면 표시식이 삭제돼도 남은 것들이 폴백을 갖는 한 통과한다.
        // 현재 6건: file_content / category_name / created_date / expire_date / download_count /
        // download_history.length (마지막 1건은 원래부터 가드가 있어 #92 수정 대상 5건에 포함되지 않는다)
        expect(textRefs.length, `selectedItem 표시식 개수 변동: ${textRefs.join(' | ')}`).toBe(6);
        for (const t of textRefs) {
            expect(t, `폴백 없는 표시식: ${t}`).toMatch(/\?\?\s*('{2}|0)/);
        }
    });
});
