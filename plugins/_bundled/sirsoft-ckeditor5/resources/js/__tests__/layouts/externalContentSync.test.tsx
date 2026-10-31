/**
 * 회귀: 글을 옮겨 다녀도 이전 글의 본문이 새 글에 새지 않는다
 *
 * `html-editor.json` 은 `onMount` 에서만 편집기를 만든다. 관리자 화면에서 글 A 수정 →
 * 글 B 수정 으로 옮기는 이동은 **같은 레이아웃**이라 컴포넌트가 언마운트되지 않아 그 훅이
 * 다시 발화하지 않는다. 편집기는 글 A 의 본문을 그대로 들고 있고 폼만 글 B 로 바뀐다.
 *
 * 그 상태에서 한 글자만 입력해도 `change:data` 가 **화면에 보이는 글 A 의 본문**을 글 B 의
 * `form.content` 에 써 넣고, 저장하면 글 B 의 본문이 글 A 의 것으로 대체된다.
 * 예외도 경고도 남지 않으며 화면에는 "원래 그런 내용이던 글" 로 보인다.
 *
 * 재시딩 판정에서 특히 조심할 지점: 경로가 바뀐 **직후**에는 폼이 아직 이전 글의 값을 들고
 * 있다. 그 순간 "폼과 편집기가 같으니 정상" 으로 판정하면 대기가 새 데이터 도착 전에 풀려,
 * 정작 새 본문이 왔을 때 입력 중으로 오인해 영영 다시 세우지 않는다.
 *
 * 「아직 이전 글 값」 판정은 편집기 값만으로 하지 않는다 — 편집기는 받은 본문을 다듬어 돌려주므로(평문 → `<p>…</p>`)
 * 폼에 남은 이전 글 원문과 글자가 다르다. 이전 경로에서 마지막으로 본 폼 값·마지막으로 내보낸 값도 이전 값으로 본다.
 * 주소가 바뀐 바로 뒤 감시 전 입력도 내보내지 않는다(내보내기 판정이 주소를 직접 본다).
 *
 * @scenario-manifest tests/scenarios/editor-route-reseed.yaml
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { attachExternalContentSync } from '../../handlers/externalContentSync';

/** 편집기 대역 — CKEditor 의 getData/setData 만 흉내낸다 */
function makeEditor(initial: string) {
    let data = initial;
    return {
        getData: () => data,
        setData: (v: string) => { data = v; },
        /** 사용자가 편집한 것처럼 편집기 데이터만 바꾼다 */
        typeInto: (v: string) => { data = v; },
    };
}

/**
 * 로컬 폼 상태 스텁을 설치합니다.
 *
 * @param form 폼 상태 객체 (테스트가 직접 변형한다)
 * @return void
 */
function stubForm(form: Record<string, any>): void {
    (window as any).G7Core = { state: { getLocal: () => ({ form }) } };
}

/**
 * 경로를 바꿉니다 (SPA 이동 흉내).
 *
 * @param path 새 경로
 * @return void
 */
function setPath(path: string): void {
    window.history.pushState({}, '', path);
}

const TICK = 400;

describe('편집기 ↔ 외부 폼 상태 재동기화', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        setPath('/admin/board/notice/474/edit');
    });

    afterEach(() => {
        vi.useRealTimers();
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=unit_reseed_on_path_change
     * @effects editor_shows_new_form_value
     */
    it('글을 옮기면 새 글의 본문으로 편집기를 다시 세운다', () => {
        const form: Record<string, any> = { content: 'AAA-474' };
        stubForm(form);
        const editor = makeEditor('AAA-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });

        setPath('/admin/board/notice/1/edit');
        // 이동 직후 — 폼은 아직 이전 글의 값을 들고 있다
        vi.advanceTimersByTime(TICK);
        expect(editor.getData()).toBe('AAA-474');
        expect(handle.shouldEmit()).toBe(false);

        // 새 글의 폼 데이터가 도착한다
        form.content = 'BBB-1';
        vi.advanceTimersByTime(TICK);

        expect(editor.getData()).toBe('BBB-1');
        expect(handle.shouldEmit()).toBe(true);

        handle.stop();
    });

    /**
     * @scenario case=unit_block_emit_while_awaiting
     * @effects no_emit_before_reseed
     */
    it('새 본문이 도착하기 전에는 편집기가 폼으로 내보내지 못한다 (오염 차단)', () => {
        const form: Record<string, any> = { content: 'AAA-474' };
        stubForm(form);
        const editor = makeEditor('AAA-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });

        setPath('/admin/board/notice/1/edit');
        vi.advanceTimersByTime(TICK);

        // 이 창에서 사용자가 입력해도 그 값은 이전 글의 본문 기반이므로 내보내면 안 된다
        expect(handle.shouldEmit()).toBe(false);

        handle.stop();
    });

    /**
     * @scenario case=unit_typing_lag
     * @effects typing_not_reverted
     */
    it('입력 중에는 되돌리지 않는다 (폼이 디바운스로 뒤처져도)', () => {
        const form: Record<string, any> = { content: '가나다' };
        stubForm(form);
        const editor = makeEditor('가나다');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });

        editor.typeInto('가나다라');
        handle.noteEmitted('가나다라');
        // 폼은 아직 이전 값 — 여기서 되돌리면 입력한 글자가 사라진다
        vi.advanceTimersByTime(TICK);
        expect(editor.getData()).toBe('가나다라');

        form.content = '가나다라';
        vi.advanceTimersByTime(TICK);
        expect(editor.getData()).toBe('가나다라');

        handle.stop();
    });

    /**
     * @scenario case=unit_reseed_timeout
     * @effects awaiting_released_after_timeout
     */
    it('새 값이 끝내 오지 않아도 내보내기를 영구히 막지는 않는다', () => {
        const form: Record<string, any> = { content: 'AAA-474' };
        stubForm(form);
        const editor = makeEditor('AAA-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });

        setPath('/admin/board/notice/1/edit');
        vi.advanceTimersByTime(TICK);
        expect(handle.shouldEmit()).toBe(false);

        vi.advanceTimersByTime(11_000);
        expect(handle.shouldEmit()).toBe(true);

        handle.stop();
    });

    /**
     * @scenario case=unit_multilingual
     * @effects editor_shows_new_form_value
     */
    it('다국어 모드는 로케일별 값을 본다', () => {
        const form: Record<string, any> = { content: { ko: 'KO-474', en: 'EN-474' } };
        stubForm(form);
        const editor = makeEditor('KO-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: true });

        setPath('/admin/board/notice/1/edit');
        vi.advanceTimersByTime(TICK);
        form.content = { ko: 'KO-1', en: 'EN-1' };
        vi.advanceTimersByTime(TICK);

        expect(editor.getData()).toBe('KO-1');

        handle.stop();
    });

    /**
     * @scenario case=unit_stop
     * @effects sync_stops_with_editor
     */
    it('stop() 이후에는 더 이상 편집기를 건드리지 않는다', () => {
        const form: Record<string, any> = { content: 'AAA-474' };
        stubForm(form);
        const editor = makeEditor('AAA-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });

        handle.stop();
        setPath('/admin/board/notice/1/edit');
        form.content = 'BBB-1';
        vi.advanceTimersByTime(TICK * 5);

        expect(editor.getData()).toBe('AAA-474');
    });
    /**
     * 편집기가 다듬은 값(`<p>…</p>`)과 폼의 이전 글 원문(평문)이 달라도 원문을 「새 값」으로 보지 않는다.
     * 종전: 원문으로 다시 세우고 대기를 끝내, 새 글 값('')이 와도 입력 중으로 보아 다시 세우지 않았다.
     *
     * @scenario case=unit_normalized_stale_value
     * @effects editor_shows_new_form_value
     */
    it('편집기가 다듬은 값과 폼 원문이 달라도 이전 글 원문으로 다시 세우지 않는다', () => {
        const form: Record<string, any> = { content: '앞 글 본문' };
        stubForm(form);
        const editor = makeEditor('<p>앞 글 본문</p>');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });
        vi.advanceTimersByTime(TICK);

        setPath('/admin/board/notice/write');
        vi.advanceTimersByTime(TICK);
        // 폼은 아직 이전 글 원문 — 대기를 풀지 않는다
        expect(handle.shouldEmit()).toBe(false);
        expect(editor.getData()).toBe('<p>앞 글 본문</p>');

        form.content = '';
        vi.advanceTimersByTime(TICK);
        expect(editor.getData()).toBe('');
        expect(handle.shouldEmit()).toBe(true);

        handle.stop();
    });

    /**
     * 주소가 바뀐 바로 뒤, 감시가 돌기 전에도 내보내기 판정이 막는다.
     *
     * @scenario case=unit_emit_check_sees_path
     * @effects no_emit_before_reseed
     */
    it('주소가 바뀐 바로 뒤 감시 전에도 내보내지 않는다', () => {
        const form: Record<string, any> = { content: 'AAA-474' };
        stubForm(form);
        const editor = makeEditor('AAA-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });

        setPath('/admin/board/notice/1/edit');
        editor.typeInto('AAA-474x');
        expect(handle.shouldEmit()).toBe(false);

        form.content = 'BBB-1';
        vi.advanceTimersByTime(TICK);
        expect(editor.getData()).toBe('BBB-1');
        expect(handle.shouldEmit()).toBe(true);

        handle.stop();
    });

    /**
     * 내보낸 값이 디바운스로 늦게 폼에 닿은 뒤 주소가 바뀌어도, 그 값은 이전 글 값이다.
     *
     * @scenario case=unit_emitted_value_is_stale
     * @effects no_emit_before_reseed
     */
    it('마지막으로 내보낸 값은 주소가 바뀐 뒤 이전 글 값으로 본다', () => {
        const form: Record<string, any> = { content: '<p>쓰던 글</p>' };
        stubForm(form);
        const editor = makeEditor('<p>쓰던 글</p>');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'ko', multilingual: false });
        vi.advanceTimersByTime(TICK);

        editor.typeInto('<p>쓰던 글!</p>');
        handle.noteEmitted('<p>쓰던 글!</p>');
        setPath('/admin/board/notice/9/edit');
        vi.advanceTimersByTime(TICK);
        // 디바운스가 이제 떨어져 폼이 내보낸 값을 받는다 — 새 글 값이 아니다
        form.content = '<p>쓰던 글!</p>';
        vi.advanceTimersByTime(TICK);
        expect(handle.shouldEmit()).toBe(false);

        form.content = '<p>9번 글</p>';
        vi.advanceTimersByTime(TICK);
        expect(editor.getData()).toBe('<p>9번 글</p>');

        handle.stop();
    });
    /**
     * 새 화면 폼에 이 칸 값이 아예 없어도(다국어 칸 하나가 빠진 경우 등) 내보내기를 영구히 막지는 않는다.
     *
     * @scenario case=unit_missing_value_timeout
     * @effects awaiting_released_after_timeout
     */
    it('새 화면 폼에 값이 없어도 상한 뒤에는 내보내기를 연다', () => {
        const form: Record<string, any> = { content: { ko: 'KO-474' } };
        stubForm(form);
        const editor = makeEditor('KO-474');
        const handle = attachExternalContentSync(editor, { name: 'content', locale: 'en', multilingual: true });

        setPath('/admin/board/notice/1/edit');
        vi.advanceTimersByTime(TICK);
        expect(handle.shouldEmit()).toBe(false);

        vi.advanceTimersByTime(11_000);
        expect(handle.shouldEmit()).toBe(true);

        handle.stop();
    });
});
