/**
 * CoreIdControl.test.tsx — 코어 "요소 ID" 위젯
 *
 * 가드:
 *  (1) HTML 안전 문자 sanitize — 평문 세그먼트 한정(칩 보존)
 *  (2) 데이터 칩(`{{...}}`) 연동 — 칩 포함 시 칩 편집기, 정적 id + 후보 시 [+데이터] 진입점
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CoreIdControl, sanitizeElementId } from '../../components/property-controls/CoreIdControl';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { BindingCandidate } from '../../spec/bindingCandidates';

const control: EditorControlSpec = { widget: 'core-id', label: 'id' };
const t = (k: string) => k;

const CANDIDATES: BindingCandidate[] = [
  {
    expression: '{{row.id}}',
    source: 'data_source' as BindingCandidate['source'],
    sourceId: 'list',
    path: 'row.id',
    shape: 'scalar',
    preview: '1',
  },
];

function renderControl(value: unknown, onChange = vi.fn(), bindingCandidates?: BindingCandidate[]) {
  render(
    <CoreIdControl
      control={control}
      value={value}
      onChange={onChange}
      t={t}
      bindingCandidates={bindingCandidates}
    />,
  );
  return onChange;
}

describe('sanitizeElementId (HTML 안전 문자)', () => {
  /**
   * @scenario subject=core_id_control
   * @effects element_id_sanitize_strips_disallowed_chars
   */
  it('한글/공백 등 불허 문자를 제거한다(평문)', () => {
    expect(sanitizeElementId('으로으로')).toBe('');
    expect(sanitizeElementId('main 콘텐츠 area')).toBe('mainarea');
    expect(sanitizeElementId('헤더_header 1')).toBe('_header1');
  });

  /**
   * @scenario subject=core_id_control
   * @effects element_id_sanitize_keeps_safe_chars
   */
  it('영문자/숫자/-/_/:/. 는 통과시킨다', () => {
    expect(sanitizeElementId('main-content_2:tab.1')).toBe('main-content_2:tab.1');
    expect(sanitizeElementId('Header123')).toBe('Header123');
  });

  /**
   * @scenario subject=core_id_control
   * @effects element_id_sanitize_keeps_binding_chips
   */
  it('`{{...}}` 칩 토큰은 보존하고 평문 세그먼트만 정리한다', () => {
    // 칩은 그대로, 평문 "item_" / "_x" 만 안전화
    expect(sanitizeElementId('item_{{$idx}}')).toBe('item_{{$idx}}');
    expect(sanitizeElementId('행 {{row.id}} 끝')).toBe('{{row.id}}');
    expect(sanitizeElementId('a{{ x ?? 0 }}b')).toBe('a{{ x ?? 0 }}b');
  });
});

describe('CoreIdControl 위젯 — 정적 id', () => {
  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_emits_sanitized_value
   */
  it('정적 값 입력 시 안전 문자만 onChange 로 전달', () => {
    const onChange = renderControl('');
    const input = screen.getByTestId('g7le-widget-core-id') as HTMLInputElement;
    fireEvent.change(input, { target: { value: 'my 헤더 id!' } });
    expect(onChange).toHaveBeenCalledWith('myid');
  });

  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_empty_emits_undefined
   */
  it('빈 입력은 onChange(undefined) — prop 삭제', () => {
    const onChange = renderControl('existing');
    const input = screen.getByTestId('g7le-widget-core-id') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '' } });
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_shows_static_value
   */
  it('정적 기존 값을 그대로 표시(편집 가능)', () => {
    renderControl('main-content');
    const input = screen.getByTestId('g7le-widget-core-id') as HTMLInputElement;
    expect(input.value).toBe('main-content');
    expect(input.disabled).toBe(false);
  });

  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_hides_data_entry_without_candidates
   */
  it('후보가 없으면 [+데이터] 진입점을 숨긴다', () => {
    renderControl('main-content');
    expect(screen.queryByTestId('g7le-core-id-add-data')).toBeNull();
  });

  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_shows_data_entry_with_candidates
   */
  it('후보가 있으면 [+데이터] 진입점을 노출한다', () => {
    renderControl('main-content', vi.fn(), CANDIDATES);
    expect(screen.getByTestId('g7le-core-id-add-data')).toBeTruthy();
  });
});

describe('CoreIdControl 위젯 — 데이터 칩 연동', () => {
  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_binding_value_opens_chip_editor
   */
  it('바인딩(`{{...}}`) 값이면 칩 편집기로 노출(읽기전용 아님)', () => {
    renderControl('item_{{$idx}}', vi.fn(), CANDIDATES);
    // 칩 편집기 컨테이너가 뜬다(이전 디그레이드 g7le-core-id-bound 아님)
    expect(screen.getByTestId('g7le-core-id-chip')).toBeTruthy();
    expect(screen.queryByTestId('g7le-core-id-bound')).toBeNull();
  });

  /**
   * @scenario subject=core_id_control
   * @effects core_id_control_data_click_switches_to_chips
   */
  it('정적 id 에서 [+데이터] 클릭 시 칩 편집기로 전환', () => {
    renderControl('main-content', vi.fn(), CANDIDATES);
    fireEvent.click(screen.getByTestId('g7le-core-id-add-data'));
    expect(screen.getByTestId('g7le-core-id-chip')).toBeTruthy();
  });
});
