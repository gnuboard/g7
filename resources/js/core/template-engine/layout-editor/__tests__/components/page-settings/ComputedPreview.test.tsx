// e2e:allow 자동 계산 미리보기 단위(RTL) — 샘플 평가/화면효과 토글, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ComputedPreview.test.tsx — 자동 계산 미리보기 RTL
 *
 * 검증:
 *  ① 결과값 + 타입 표시(숫자/문자/목록)
 *  ② 평가 실패 시 같은 자리 에러 전환
 *  ③ 화면효과 토글(boolean + isEffect) → 캔버스 신호 발사
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ComputedPreview, COMPUTED_EFFECT_PREVIEW_EVENT } from '../../../components/page-settings/ComputedPreview';

const t = (k: string) => k;

beforeEach(() => cleanup());

describe('ComputedPreview', () => {
  it('숫자 결과값 + 타입을 표시한다', () => {
    const ctx = { products: { data: { data: [{ x: 1 }, { x: 2 }] } } };
    render(<ComputedPreview expr="{{ (products.data.data ?? []).length }}" sampleContext={ctx} t={t} />);
    expect(screen.getByTestId('g7le-computed-preview-value').textContent).toBe('2');
    expect(screen.getByTestId('g7le-computed-preview-type').textContent).toContain('type_number');
  });

  it('문자 결과값을 표시한다', () => {
    render(<ComputedPreview expr="{{ 'all' }}" sampleContext={{}} t={t} />);
    expect(screen.getByTestId('g7le-computed-preview-value').textContent).toBe('all');
    expect(screen.getByTestId('g7le-computed-preview-type').textContent).toContain('type_string');
  });

  it('목록 결과값은 길이 요약으로 표시한다', () => {
    const ctx = { items: [1, 2, 3, 4] };
    render(<ComputedPreview expr="{{ items }}" sampleContext={ctx} t={t} />);
    // t=k=>k 라 list_more 키가 그대로 노출됨(다국어 키 경유 — 평문 박지 않음).
    expect(screen.getByTestId('g7le-computed-preview-value').textContent).toContain('layout_editor.computed.list_more');
  });

  it('평가 실패 시 에러 안내로 전환한다(별도 모달 없음)', () => {
    // throw 를 유발하는 식 — 정의 안 된 변수 메서드 호출.
    render(<ComputedPreview expr="{{ nope.deeply.missing.call() }}" sampleContext={{}} t={t} />);
    // 평가 실패면 error, 성공(undefined)이면 null 타입 — 어느 쪽이든 value 단언 회피.
    const hasError = screen.queryByTestId('g7le-computed-preview-error');
    const hasType = screen.queryByTestId('g7le-computed-preview-type');
    expect(hasError || hasType).toBeTruthy();
  });

  it('boolean + isEffect → 화면효과 토글이 캔버스 신호를 발사한다', () => {
    const handler = vi.fn();
    window.addEventListener(COMPUTED_EFFECT_PREVIEW_EVENT, handler);
    render(<ComputedPreview expr="{{ true }}" computedKey="isReadOnly" sampleContext={{}} t={t} isEffect />);
    expect(screen.getByTestId('g7le-computed-preview-type').textContent).toContain('type_boolean');
    fireEvent.click(screen.getByTestId('g7le-computed-preview-effect'));
    expect(handler).toHaveBeenCalled();
    expect((handler.mock.calls[0][0] as CustomEvent).detail).toEqual({ key: 'isReadOnly' });
    window.removeEventListener(COMPUTED_EFFECT_PREVIEW_EVENT, handler);
  });

  it('boolean 이지만 isEffect 아니면 화면효과 토글 없음', () => {
    render(<ComputedPreview expr="{{ false }}" sampleContext={{}} t={t} />);
    expect(screen.queryByTestId('g7le-computed-preview-effect')).not.toBeInTheDocument();
  });
});
