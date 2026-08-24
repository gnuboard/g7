/**
 * @file template_request_reentrancy_guard.test.ts
 * @description 검수 신청 체인 재진입(더블 클릭) 가드 회귀 테스트 (#597 §6.3 10c)
 *
 * 배경: 검수 신청(POST …/request)은 kapi add/update 를 동반하므로 더블 클릭이
 * 두 체인을 발화하면 카카오측 중복 등록·중복 채번이 발생할 수 있다. disabled
 * prop 은 React 리렌더 이후에만 반영되어 같은 태스크에서 연속 디스패치되는
 * 두 번째 click 을 막지 못한다 — 실측: 더블 클릭 → PUT 2회 + request 2회.
 *
 * 방어는 click 액션(sequence) 레벨의 `if` 재진입 가드다(코어 선례:
 * admin_role_form.json 등의 `"if": "{{!_global.isSaving}}"`). 엔진 if 평가는
 * 디스패치 시점의 동기 검사라 리렌더를 기다리지 않는다.
 *
 * 이 테스트는 신청 체인을 포함하는 모든 click 액션이 재진입 가드(if)를
 * 보유하는지 산출 파일 전수(편집 모달 푸터 1본 + 관리 화면)로 고정한다. 가드가 한 파일에서라도 빠지면
 * 그 면에서만 중복 신청이 재발한다.
 *
 * 실행 검증은 §6.3 10c 브라우저 실측이 담당한다. 라운드 5 실측 결과를 여기 남긴다:
 * **클라이언트 if 가드만으로는 요청 2회가 나간다.** setState 가 전역 스토어에 반영되기
 * 전에 두 번째 click 이 디스패치되기 때문이며, 30ms 간격 더블 클릭에서 재현된다.
 *
 * 중복 신청을 실제로 막는 것은 **서버의 원자 선점**(claimForInspection)이다 — 실측에서
 * 두 번째 POST …/request 는 422 "현재 상태(requested)에서는 검수를 신청할 수 없습니다."
 * 로 거부됐고, 카카오측 중복 등록은 발생하지 않았다. 저장(PUT)은 멱등이라 무해하다.
 *
 * 따라서 이 구조 테스트는 "가드가 선언돼 있다" 만 고정한다 — 효력의 SSoT 는 서버 가드이고,
 * 그것은 PHPUnit(claimForInspection)과 §6.3 10c 실측이 고정한다.
 *
 * @effects request_chain_click_actions_carry_reentrancy_guard, save_chain_click_actions_carry_reentrancy_guard
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const BASE = path.resolve(__dirname, '../../../..');

const FILES = [
    // 3면 공유 1본 — 코어 [편집] 모달 푸터에 주입되는 통합 저장/검수 신청 버튼(제품 결정 2026-08-23)
    'resources/extensions/notification_template_form_footer_actions.json',
    // 플러그인 관리 화면의 자기 모달
    'resources/layouts/admin/plugin_settings.json',
];

interface ClickAction {
    file: string;
    targets: string[];
    ifExpr: string | undefined;
    raw: string;
    buttonDisabled: string | undefined;
}

/**
 * 검수 신청(…/request) apiCall 을 포함하는 click 액션을 전수 수집합니다.
 * cancel-request 는 서버 상태 가드(422)로 순차 중복이 무해하므로 제외한다.
 *
 * @param file 저장소 루트 기준 상대 경로
 * @returns 신청 체인을 품은 click 액션 목록
 */
function collectRequestClickActions(file: string): ClickAction[] {
    const doc = JSON.parse(fs.readFileSync(path.join(BASE, file), 'utf-8'));
    const found: ClickAction[] = [];

    const collectTargets = (node: unknown, acc: string[]): void => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) {
            node.forEach((c) => collectTargets(c, acc));
            return;
        }
        const n = node as Record<string, any>;
        if (
            n.handler === 'apiCall' &&
            typeof n.target === 'string' &&
            /\/request$/.test(n.target)
        ) {
            acc.push(n.target);
        }
        Object.values(n).forEach((v) => collectTargets(v, acc));
    };

    const walk = (node: unknown): void => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) {
            node.forEach(walk);
            return;
        }
        const n = node as Record<string, any>;
        if (Array.isArray(n.actions)) {
            for (const action of n.actions) {
                if (action?.type !== 'click') continue;
                const targets: string[] = [];
                collectTargets(action, targets);
                if (targets.length > 0) {
                    found.push({
                        file,
                        targets,
                        ifExpr: action.if,
                        raw: JSON.stringify(action),
                        buttonDisabled: n.name === 'Button' ? n.props?.disabled : undefined,
                    });
                }
            }
        }
        Object.values(n).forEach(walk);
    };

    walk(doc);
    return found;
}

describe('검수 신청 체인 재진입 가드 (#597 10c)', () => {
    const all = FILES.flatMap(collectRequestClickActions);

    it('신청 체인을 포함한 click 액션이 산출 파일 전체에서 수집된다 (0건이면 아래 단언은 공회전한다)', () => {
        // 편집 모달 푸터 [저장 후 검수 신청] 1 + 관리 화면(모달 저장 후 신청 신규/수정 + 행 신청)
        expect(all.length).toBeGreaterThanOrEqual(3);
    });

    it.each(FILES)('%s 의 신청 체인 click 액션은 모두 재진입 if 가드를 갖는다', (file) => {
        const actions = all.filter((a) => a.file === file);
        for (const action of actions) {
            expect(
                action.ifExpr,
                `${file} 의 신청 체인(${action.targets[0]})에 if 재진입 가드가 없다 — 더블 클릭이 중복 신청을 발화한다`,
            ).toBeTruthy();
            expect(
                action.ifExpr,
                `${file} 의 신청 체인 if 가드는 in-flight 플래그(isSaving/bz_row_busy)를 부정 조건으로 검사해야 한다`,
            ).toMatch(/!\((_global\.bz_tpl_modal\?\.isSaving|_global\.bz_row_busy) \?\? false\)/);
        }
    });

    it('신청 체인 버튼은 in-flight 플래그를 disabled 로 바인딩한다 (구독 없으면 액션 컨텍스트가 갱신되지 않아 if 가드가 항상 stale false 를 본다 — 실측)', () => {
        for (const action of all) {
            const flag = action.ifExpr?.includes('bz_row_busy') ? 'bz_row_busy' : 'bz_tpl_modal?.isSaving';
            expect(
                action.buttonDisabled,
                `${action.file} 의 신청 체인 버튼(${action.targets[0]})은 disabled 를 ${flag} 로 바인딩해야 한다 — 바인딩이 없으면 리렌더·구독이 일어나지 않아 더블 클릭이 그대로 통과한다`,
            ).toContain(flag);
        }
    });

    it('bz_row_busy 플래그를 쓰는 체인은 성공·실패 양쪽에서 플래그를 해제한다', () => {
        const rowChains = all.filter((a) => a.ifExpr?.includes('bz_row_busy'));
        for (const action of rowChains) {
            const resets = action.raw.match(/"bz_row_busy": ?false/g) ?? [];
            expect(
                resets.length,
                `${action.file} 의 row 신청 체인은 onSuccess·onError 양쪽에서 bz_row_busy 를 해제해야 한다 — 한쪽만 해제하면 실패 후 버튼이 영구 잠긴다`,
            ).toBeGreaterThanOrEqual(2);
        }
    });
});

describe('저장 체인 재진입 가드 (#597 라운드 5 §6.3 10c 실측 파생)', () => {
    /**
     * 브라우저 실측에서 [저장] 더블 클릭 시 PUT 이 **2회** 나갔다.
     *
     * 형제 버튼인 [저장 후 검수 신청] 은 이미 액션 레벨 if 로 재진입을 막고 있었는데
     * 저장만 빠져 있었다 — 같은 모달의 같은 결함군에 규칙이 두 벌이었던 셈이다.
     * disabled prop 은 리렌더 이후에만 반영되므로 같은 태스크의 두 번째 click 을 막지 못한다.
     */
    const GUARD = '{{!(_global.bz_tpl_modal?.isSaving ?? false)}}';

    /** 저장 버튼(btn_save)의 click 액션을 전수 수집한다. */
    const collectSaveClickActions = (file: string): Array<{ file: string; ifExpr?: string }> => {
        const doc = JSON.parse(fs.readFileSync(path.join(BASE, file), 'utf-8'));
        const found: Array<{ file: string; ifExpr?: string }> = [];

        const walk = (node: unknown): void => {
            if (!node || typeof node !== 'object') return;
            if (Array.isArray(node)) { node.forEach(walk); return; }
            const n = node as Record<string, any>;
            // 관리 화면 모달: text=form.btn_save / 편집 모달 푸터(통합 저장): 자식 Span 이 $t:common.save
            const isSave = n.name === 'Button'
                && ((typeof n.text === 'string' && n.text.endsWith('sirsoft-message_bizppurio.template.form.btn_save'))
                    || (Array.isArray(n.children) && JSON.stringify(n.children).includes('$t:common.save')
                        && JSON.stringify(n.props ?? {}).includes('bz_tpl_modal')));
            if (isSave && Array.isArray(n.actions)) {
                for (const a of n.actions) {
                    if (a?.type === 'click') found.push({ file, ifExpr: a.if });
                }
            }
            Object.values(n).forEach(walk);
        };

        walk(doc);

        return found;
    };

    it('저장 버튼의 click 액션이 산출 파일 전수에서 재진입 가드를 보유한다', () => {
        const all = FILES.flatMap(collectSaveClickActions);
        // 편집 모달 푸터 통합 [저장] 1 + 관리 화면 모달 (신규/수정) 2버튼
        expect(all.length, '저장 버튼 click 액션 수').toBe(3);

        for (const a of all) {
            // 편집 모달 푸터의 통합 [저장]은 isSaving 가드에 상세 GET 완료 전 가드(loading)를 AND 로 더 단다 — 포함 판정
            expect(a.ifExpr, `${a.file}: 저장 체인에 재진입 가드(if)가 없다 — 더블 클릭 시 중복 저장`)
                .toContain(GUARD.slice(2, -2));
        }
    });
});
