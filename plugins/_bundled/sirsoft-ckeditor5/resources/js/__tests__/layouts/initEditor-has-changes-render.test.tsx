/**
 * 회귀: 본문만 고쳐도 저장 버튼이 활성화된다 (`hasChanges` 를 본문 배치에서 분리)
 *
 * 결함: `syncToForm` 이 `hasChanges: true` 를 본문과 **같은**
 * `setLocal({ render:false, selfManaged:true })` 배치에 넣었다. 그 배치는 React 렌더를
 * 일으키지 않으므로(성능 — 37,000+ 바인딩 재평가 회피) 저장소 A 는 플래그를 받지 못하고,
 * 저장 버튼의 활성 조건 `{{!_local.hasChanges || _local.isSaving}}` 이 재평가되지 않는다.
 * 결과적으로 **본문만 고친 운영자는 저장 자체를 할 수 없다** — 오류도 안내도 없이 버튼이
 * 계속 비활성으로 남는다.
 *
 * 제목 등 다른 입력을 함께 건드리면 그 입력의 자동바인딩이 렌더를 일으켜 증상이 가려지므로,
 * 화면·게시판에 따라 드러나기도 하고 아니기도 한다(관리자 게시글 수정 화면에서 실측 재현).
 *
 * 계약:
 *  1. `hasChanges` 는 렌더를 일으키는 별도 setLocal 로 보낸다 (render:false 배치에 넣지 않는다)
 *  2. 이미 true 면 보내지 않는다 — 편집 세션당 추가 렌더는 최대 1회 (성능 회귀 차단)
 *  3. 본문은 종전대로 debounce + render:false + selfManaged:true 를 유지한다
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { syncToForm } from '../../handlers/initEditor';

type Call = { updates: Record<string, any>; options?: Record<string, any> };

let calls: Call[] = [];
let localState: Record<string, any> = {};

/**
 * G7Core 전역 스텁을 설치합니다.
 *
 * @param initialLocal 초기 로컬 상태 (`hasChanges` 포함 가능)
 */
function stubG7Core(initialLocal: Record<string, any> = {}): void {
    localState = { ...initialLocal };
    calls = [];
    (window as any).G7Core = {
        state: {
            getLocal: () => localState,
            setLocal: (updates: Record<string, any>, options?: Record<string, any>) => {
                calls.push({ updates, options });
                localState = { ...localState, ...updates };
            },
        },
    };
}

/** `hasChanges` 를 보낸 호출만 고른다. */
const hasChangesCalls = () => calls.filter((c) => 'hasChanges' in c.updates);
/** 본문(form.*)을 보낸 호출만 고른다. */
const contentCalls = () => calls.filter((c) => Object.keys(c.updates).some((k) => k.startsWith('form.')));

describe('편집기 본문 동기화 — hasChanges 렌더 분리 (저장 버튼 비활성 회귀)', () => {
    beforeEach(() => {
        stubG7Core();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    it('hasChanges 를 render:false 배치에 넣지 않는다 (넣으면 저장 버튼이 안 켜진다)', () => {
        syncToForm('content', 'ko', '<p>본문</p>', false);

        for (const c of contentCalls()) {
            expect(
                'hasChanges' in c.updates,
                'hasChanges 가 본문 배치에 섞이면 저장소 A 가 못 받아 버튼이 비활성으로 남는다',
            ).toBe(false);
        }
    });

    it('hasChanges 는 렌더를 일으키는 setLocal 로 보낸다', () => {
        syncToForm('content', 'ko', '<p>본문</p>', false);

        const flag = hasChangesCalls();
        expect(flag, 'hasChanges 를 보내는 호출이 있어야 한다').toHaveLength(1);
        expect(flag[0].updates.hasChanges).toBe(true);
        // render:false / selfManaged 가 붙으면 React 가 다시 그리지 않아 버튼이 갱신되지 않는다
        expect(flag[0].options?.render, 'render:false 면 안 된다').not.toBe(false);
        expect(flag[0].options?.selfManaged, 'selfManaged 면 안 된다').not.toBe(true);
    });

    it('이미 hasChanges 가 true 면 다시 보내지 않는다 (세션당 추가 렌더 1회)', () => {
        stubG7Core({ hasChanges: true });

        syncToForm('content', 'ko', '<p>1</p>', false);
        syncToForm('content', 'ko', '<p>12</p>', false);
        syncToForm('content', 'ko', '<p>123</p>', false);

        expect(hasChangesCalls(), '이미 true 면 추가 렌더를 만들지 않는다').toHaveLength(0);
    });

    it('연속 입력에서도 hasChanges 렌더는 첫 1회뿐이다', () => {
        syncToForm('content', 'ko', '<p>a</p>', false);
        syncToForm('content', 'ko', '<p>ab</p>', false);
        syncToForm('content', 'ko', '<p>abc</p>', false);

        expect(hasChangesCalls(), '첫 입력에서만 플래그를 올린다').toHaveLength(1);
        expect(contentCalls(), '본문은 매번 보낸다 (디바운스는 엔진이 처리)').toHaveLength(3);
    });

    it('본문 배치는 종전 성능 옵션을 유지한다 (debounce + render:false + selfManaged)', () => {
        syncToForm('content', 'ko', '<p>본문</p>', false);

        const body = contentCalls();
        expect(body).toHaveLength(1);
        expect(body[0].options?.render).toBe(false);
        expect(body[0].options?.selfManaged).toBe(true);
        expect(body[0].options?.debounce).toBe(300);
        expect(body[0].updates['form.content']).toBe('<p>본문</p>');
        expect(body[0].updates['form.content_mode']).toBe('html');
    });

    it('다국어 모드는 로케일 경로로 보낸다', () => {
        syncToForm('content', 'ja', '<p>本文</p>', true);

        const body = contentCalls();
        expect(body[0].updates['form.content.ja']).toBe('<p>本文</p>');
        expect(hasChangesCalls()).toHaveLength(1);
    });
});
