/**
 * @file ModalEscapeEvent.test.tsx
 * @description Esc 로 창·모바일 메뉴를 닫을 때 닫기 처리기에 keydown 이벤트를 넘기는지
 *
 * 레이아웃 창의 닫기 처리기는 템플릿 엔진의 액션 처리기이고, 엔진 처리기는 첫 줄에서 `event.type` 을 읽는다.
 * Esc 경로만 `onClose()` 를 빈 인자로 불러 `TypeError … reading 'type'` 가 나고 창이 닫히지 않았다
 * (이커머스 전수 실측 S5, 2026-10-07 — 관리자 창에서 발견, 사용자 템플릿 Modal·MobileNav 도 같은 꼴).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { Modal } from '../Modal';
import MobileNav from '../MobileNav';

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
    it('MobileNav — onClose 가 keydown 이벤트를 받는다', () => {
        const onClose = engineLikeHandler();
        render(<MobileNav isOpen onClose={onClose as any} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalled();
        expect(onClose.mock.calls[0][0]?.type).toBe('keydown');
        expect(onClose.mock.results[0].type).toBe('return');
    });
});
