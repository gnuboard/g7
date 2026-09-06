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
import { renderTextareaFallback } from '../../handlers/textareaFallback';

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

/**
 * 저장 대상이 아닌 편집기는 폼을 "변경됨" 으로 만들지 않는다.
 *
 * 위 수정이 `hasChanges` 를 **렌더를 일으키는** setLocal 로 승격시키면서, 설정 화면의
 * 미리보기 편집기처럼 저장 대상이 아닌 편집기의 입력까지 [저장] 버튼을 즉시 켜게 됐다.
 * 미리보기 내용은 저장 액션의 body 에서 제외되므로 잘못 저장될 위험은 없지만, 운영자에게는
 * "바뀐 것이 없는데 바뀐 것처럼" 보인다.
 *
 * 어느 편집기가 저장 대상인지는 **레이아웃이 안다**. 그래서 핸들러가 필드명(`preview_content`)
 * 을 알아보는 대신 `trackChanges` 선언으로 받는다 — 필드명 하드코딩은 다른 확장이 같은
 * 미리보기 패턴을 쓸 때 그대로 재발한다.
 */
describe('편집기 본문 동기화 — 저장 대상이 아닌 편집기 (trackChanges:false)', () => {
    beforeEach(() => {
        stubG7Core();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    it('trackChanges:false 면 hasChanges 를 아예 보내지 않는다', () => {
        syncToForm('preview_content', 'ko', '<p>시험 입력</p>', true, false);

        expect(
            hasChangesCalls(),
            '미리보기 입력이 [저장] 버튼을 켜면 안 된다',
        ).toHaveLength(0);
    });

    it('trackChanges:false 여도 본문 동기화는 그대로 한다 (미리보기 렌더가 이 값을 읽는다)', () => {
        syncToForm('preview_content', 'ko', '<p>시험 입력</p>', true, false);

        const body = contentCalls();
        expect(body, '본문은 종전대로 보낸다').toHaveLength(1);
        expect(body[0].updates['form.preview_content.ko']).toBe('<p>시험 입력</p>');
        expect(body[0].updates['form.preview_content_mode']).toBe('html');
        expect(body[0].options?.render).toBe(false);
        expect(body[0].options?.selfManaged).toBe(true);
    });

    it('생략하면 종전대로 hasChanges 를 보낸다 (기본값 true — 게시글 편집기 보호)', () => {
        syncToForm('content', 'ko', '<p>본문</p>', false);

        expect(hasChangesCalls(), '기본값이 false 로 뒤집히면 저장 버튼 회귀가 되돌아온다').toHaveLength(1);
    });

    it('trackChanges:false 인 편집기는 이미 켜진 hasChanges 를 끄지도 않는다', () => {
        // 다른 입력이 이미 폼을 변경 상태로 만든 뒤 미리보기를 건드리는 순서
        stubG7Core({ hasChanges: true });

        syncToForm('preview_content', 'ko', '<p>시험</p>', true, false);

        expect(hasChangesCalls(), '건드리지 않는다 — 끄면 진짜 변경이 묻힌다').toHaveLength(0);
        expect(localState.hasChanges, '기존 변경 상태는 유지돼야 한다').toBe(true);
    });
});

/**
 * 회귀: 평문 폴백에서도 본문만 고치면 저장 버튼이 활성화된다 (형제 경로 패리티)
 *
 * `syncToForm` 의 결함을 고치면서 **형제 경로인 `syncFallbackToForm` 은 그대로 남았다.**
 * 그쪽도 `hasChanges: true` 를 `render:false + selfManaged:true` 배치에 넣으므로,
 * 편집기 자산을 못 불러와 평문 입력창으로 내려간 상태에서 본문만 고치면
 * `admin_board_post_form.json` 의 저장 버튼
 * (`disabled: "{{... || (!!route?.id && !_local.hasChanges)}}"`)이 계속 비활성이다 —
 * **글 수정 자체가 불가능하다.** 폐쇄망·방화벽·광고차단기 환경에서는 그 폴백이 정상 경로다.
 *
 * 폴백에는 편집기에 없는 결이 하나 더 있다: `syncFallbackToForm` 은 사용자 입력뿐 아니라
 * **렌더 시점에도** 불린다(`_mode='text'` 를 미리 심어 두려고). 그 자리까지 플래그를 켜면
 * 사용자가 아무것도 입력하지 않았는데 화면을 여는 것만으로 "변경됨" 이 된다. 그래서
 * 사용자 입력에서 온 호출만 플래그를 올린다.
 */
describe('평문 폴백 본문 동기화 — hasChanges 렌더 분리 (형제 경로)', () => {
    let container: HTMLElement;

    /**
     * 폴백을 세울 컨테이너를 만듭니다.
     *
     * @return 문서에 붙은 빈 컨테이너
     */
    function mountContainer(): HTMLElement {
        const el = document.createElement('div');
        document.body.appendChild(el);
        return el;
    }

    beforeEach(() => {
        stubG7Core();
        container = mountContainer();
    });

    afterEach(() => {
        container.remove();
        delete (window as any).G7Core;
    });

    it('폴백이 서는 것만으로는 폼이 변경됨이 되지 않는다', () => {
        renderTextareaFallback({
            container,
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: false,
            initialContent: '<p>서버 원본</p>',
        });

        expect(
            hasChangesCalls(),
            '입력이 없는데 [저장] 이 켜지면 운영자가 바뀐 줄 알고 누른다',
        ).toHaveLength(0);
        // 평문 계약은 그대로 심어야 한다 (서버가 HTML 로 신뢰하면 안 된다)
        expect(contentCalls().at(-1)?.updates['form.content_mode']).toBe('text');
    });

    it('폴백 입력창에 글자를 치면 hasChanges 가 렌더를 일으키며 올라간다', () => {
        renderTextareaFallback({
            container,
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: false,
            initialContent: '',
        });

        const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
        textarea.value = '폴백으로 쓴 본문';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        const flag = hasChangesCalls();
        expect(flag, '입력했는데 플래그가 안 오르면 저장 버튼이 비활성으로 남는다').toHaveLength(1);
        expect(flag[0].updates.hasChanges).toBe(true);
        expect(flag[0].options?.render, 'render:false 면 React 가 버튼을 다시 그리지 않는다').not.toBe(false);
        expect(flag[0].options?.selfManaged, 'selfManaged 면 안 된다').not.toBe(true);
    });

    it('본문 배치에는 hasChanges 가 섞이지 않는다', () => {
        renderTextareaFallback({
            container,
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: false,
            initialContent: '',
        });

        const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
        textarea.value = 'x';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        for (const c of contentCalls()) {
            expect('hasChanges' in c.updates, 'render:false 배치에 섞이면 저장소 A 가 못 받는다').toBe(false);
        }
    });

    it('연속 입력에서도 hasChanges 렌더는 첫 1회뿐이다', () => {
        renderTextareaFallback({
            container,
            name: 'content',
            height: 400,
            readOnly: false,
            multilingual: false,
            initialContent: '',
        });

        const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
        for (const v of ['a', 'ab', 'abc']) {
            textarea.value = v;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        expect(hasChangesCalls(), '편집 세션당 추가 렌더는 최대 1회').toHaveLength(1);
    });

    it('저장 대상이 아닌 편집기의 폴백은 폼을 변경됨으로 만들지 않는다', () => {
        renderTextareaFallback({
            container,
            name: 'preview_content',
            height: 400,
            readOnly: false,
            multilingual: false,
            initialContent: '',
            trackChanges: false,
        });

        const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
        textarea.value = '미리보기 시험';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));

        expect(
            hasChangesCalls(),
            '미리보기가 폴백으로 내려앉아도 저장 대상이 아닌 것은 그대로다',
        ).toHaveLength(0);
        expect(contentCalls().at(-1)?.updates['form.preview_content'], '본문 동기화는 유지').toBe('미리보기 시험');
    });
});
