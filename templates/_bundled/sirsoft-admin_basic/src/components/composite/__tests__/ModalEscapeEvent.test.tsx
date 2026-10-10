/**
 * @file ModalEscapeEvent.test.tsx
 * @description Esc 로 창을 닫을 때 닫기 처리기에 keydown 이벤트를 넘기는지
 *
 * 관리자 화면의 창(레이아웃 `<modal>`)은 닫기 처리기로 템플릿 엔진의 액션 처리기를 받는다. 엔진 처리기는
 * 이벤트를 받는다고 보고 첫 줄에서 `event.type` 을 읽는데, Esc 경로만 `onClose()` 를 빈 인자로 불러
 * `TypeError: Cannot read properties of undefined (reading 'type')` 가 나고 창이 닫히지 않았다
 * (이커머스 전수 실측 S5, 2026-10-07 — 클레임 목록 「프리셋 관리」 창에서 Esc 무반응, X 로는 닫힘).
 * 닫기(X)·바깥 클릭처럼 Esc 도 원본 이벤트를 넘겨야 한다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { Modal } from '../Modal';
import { ConfirmModal } from '../../modals/ConfirmModal';

/**
 * 엔진 액션 처리기처럼 이벤트의 type 을 읽는 닫기 처리기를 만듭니다.
 *
 * @return 호출 기록을 가진 처리기
 */
function engineLikeHandler() {
    return vi.fn((event: Event) => event.type);
}

describe('Esc 로 닫을 때 닫기 처리기에 이벤트를 넘긴다', () => {
    /**
     * @scenario case=c01
     * @effects escape_close_passes_event
     */
    it('Modal — onClose 가 keydown 이벤트를 받는다', () => {
        const onClose = engineLikeHandler();
        render(<Modal isOpen onClose={onClose as any} title="t">본문</Modal>);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onClose.mock.calls[0][0]?.type).toBe('keydown');
        expect(onClose.mock.results[0].type).toBe('return');
    });

    /**
     * @scenario case=c02
     * @effects escape_close_passes_event
     */
    it('Modal — closeOnEscape=false 면 부르지 않는다', () => {
        const onClose = engineLikeHandler();
        render(<Modal isOpen onClose={onClose as any} closeOnEscape={false} title="t">본문</Modal>);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=c03
     * @effects escape_close_passes_event
     */
    it('ConfirmModal — onClose 가 keydown 이벤트를 받는다', () => {
        const onClose = engineLikeHandler();
        render(<ConfirmModal isOpen onClose={onClose as any} message="m" onConfirm={vi.fn()} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onClose.mock.calls[0][0]?.type).toBe('keydown');
        expect(onClose.mock.results[0].type).toBe('return');
    });
});
