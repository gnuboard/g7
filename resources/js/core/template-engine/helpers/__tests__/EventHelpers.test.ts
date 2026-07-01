/**
 * EventHelpers 단위 테스트
 *
 * createChangeEvent 의 checkbox/radio value 직렬화 회귀 가드.
 * 배경: Toggle 이 createChangeEvent({checked}) 로 만든 이벤트가 Form 자동바인딩 value
 * 경로로 처리될 때 value 가 "true"/"false"(문자열)이면 백엔드 boolean 검증이 422 로
 * 거부하던 결함(설정 "취소 시 재고 복구" 토글 ON 저장 422). checkbox/radio 의 value 는
 * boolean(checked)으로 두어 어느 바인딩 경로로 가도 boolean 이 저장되도록 한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { createChangeEvent } from '../EventHelpers';

describe('createChangeEvent — value 직렬화', () => {
    it('checkbox(기본 type) — checked=true 면 value 가 boolean true', () => {
        const e = createChangeEvent({ checked: true, name: 'order_settings.stock_restore_on_cancel' });

        expect(e.target.checked).toBe(true);
        // 문자열 "true" 가 아니라 boolean true 여야 한다 (백엔드 boolean 검증 통과)
        expect(e.target.value as unknown).toBe(true);
        expect(typeof (e.target.value as unknown)).toBe('boolean');
    });

    it('checkbox — checked=false 면 value 가 boolean false', () => {
        const e = createChangeEvent({ checked: false, name: 'flag' });

        expect(e.target.checked).toBe(false);
        expect(e.target.value as unknown).toBe(false);
        expect(typeof (e.target.value as unknown)).toBe('boolean');
    });

    it('type: checkbox 명시 — value 가 boolean', () => {
        const e = createChangeEvent({ checked: true, name: 'flag', type: 'checkbox' });

        expect(e.target.value as unknown).toBe(true);
    });

    it('type: radio — value 가 boolean', () => {
        const e = createChangeEvent({ checked: true, name: 'r', type: 'radio' });

        expect(e.target.value as unknown).toBe(true);
    });

    it('명시적 value 전달 시 그 값을 그대로 사용 (checkbox 라도)', () => {
        const e = createChangeEvent({ checked: true, value: 'apple', name: 'r', type: 'checkbox' });

        expect(e.target.value).toBe('apple');
    });

    it('text 입력 — value 를 그대로 문자열로 사용', () => {
        const e = createChangeEvent({ value: 'hello', name: 'title', type: 'text' });

        expect(e.target.value).toBe('hello');
    });

    it('text 입력 — value 미지정 + checked 미지정이면 빈 문자열', () => {
        const e = createChangeEvent({ name: 'title', type: 'text' });

        expect(e.target.value).toBe('');
    });

    it('event 표면 형태 — change 타입 + name 보존', () => {
        const e = createChangeEvent({ checked: true, name: 'foo' });

        expect(e.type).toBe('change');
        expect(e.target.name).toBe('foo');
        expect(e.currentTarget).toBe(e.target);
    });
});
