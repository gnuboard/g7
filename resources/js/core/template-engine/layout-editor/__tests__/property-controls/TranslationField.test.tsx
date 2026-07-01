/**
 * TranslationField.test.tsx — 속성 모달 [번역] 탭 RTL
 *
 *  - 커스텀 키 노드 → 전체 활성 로케일 입력 폼, 미번역 회색 + "번역 필요" 마크.
 *  - 값 변경 → blur/저장 → PUT /custom-translations/{id} (전체 values + expected_lock_version).
 *  - 커스텀 키 아님(평문/바인딩식) → "다국어 키 아님" 안내.
 *  - 409 → 충돌 안내.
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

vi.mock('../../../TranslationContext', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import {
  TranslationField,
  extractCustomKeyFromNode,
  deriveParamLabelsFromNode,
} from '../../components/property-controls/TranslationField';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { getPendingValue, clearPending } from '../../hooks/pendingCustomTranslations';

const t = (k: string) => k;
const TEMPLATE = 'sirsoft-basic';
const LOCALES = ['ko', 'en', 'ja'];

// TranslationField 는 node 비의존(customKey/paramLabels prop). 테스트는 기존 node
// 픽스처에서 호출처([번역]탭)와 동일하게 어댑트해 렌더한다(같은 SSoT 검증).
function renderField(node: EditorNode) {
  return render(
    <TranslationField
      customKey={extractCustomKeyFromNode(node)}
      templateIdentifier={TEMPLATE}
      t={t}
      paramLabels={deriveParamLabelsFromNode(node)}
      locales={LOCALES}
    />,
  );
}

const indexResponse = (values: Record<string, string>, lockVersion = 0) => ({
  ok: true,
  status: 200,
  json: async () => ({
    data: [{ id: 7, translation_key: 'custom.home.1', values, lock_version: lockVersion }],
  }),
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  clearPending();
});

beforeEach(() => {
  localStorage.setItem('auth_token', 'test-token');
  clearPending();
});

describe('TranslationField', () => {
  it('커스텀 키 노드 → 활성 로케일 입력 폼 + 미번역 마크', async () => {
    const fetchMock = vi.fn().mockResolvedValue(indexResponse({ ko: '환영합니다', en: '' }));
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    renderField(node);

    await waitFor(() => expect(screen.getByTestId('g7le-translation-field')).toBeTruthy());
    expect((screen.getByTestId('g7le-translation-input-ko') as HTMLInputElement).value).toBe('환영합니다');
    // en 은 빈값 → 미번역 마크.
    expect(screen.getByTestId('g7le-translation-missing-en')).toBeTruthy();
    // ja 는 행에 없음 → 활성 로케일이므로 빈 입력 + 미번역 마크.
    expect(screen.getByTestId('g7le-translation-input-ja')).toBeTruthy();
    expect(screen.getByTestId('g7le-translation-missing-ja')).toBeTruthy();
  });

  it('값 변경 후 blur → PUT (전체 values + expected_lock_version)', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(indexResponse({ ko: '환영합니다', en: '' }, 3))
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({ data: { id: 7, lock_version: 4 } }),
      });
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-input-en')).toBeTruthy());

    const enInput = screen.getByTestId('g7le-translation-input-en') as HTMLInputElement;
    fireEvent.change(enInput, { target: { value: 'Welcome' } });
    fireEvent.blur(enInput);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    const putCall = fetchMock.mock.calls[1];
    expect(putCall[1].method).toBe('PUT');
    const body = JSON.parse(putCall[1].body);
    expect(body.values.en).toBe('Welcome');
    expect(body.values.ko).toBe('환영합니다'); // 다른 로케일 보존
    expect(body.expected_lock_version).toBe(3);
  });

  it('커스텀 키 아닌 노드(평문) → "다국어 키 아님" 안내', () => {
    vi.stubGlobal('fetch', vi.fn());
    const node: EditorNode = { name: 'Span', text: '그냥 평문' };
    renderField(node);
    expect(screen.getByTestId('g7le-translation-not-a-key')).toBeTruthy();
  });

  // 토큰 불변 가드 — 번역값의 `{{...}}` 보간 토큰은 보존돼야 한다.
  it('보간 토큰 보존 시 저장 성공 (라벨/번역만 자유 편집)', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(indexResponse({ ko: '작성자 {{user.name}}', en: '' }, 2))
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ data: { id: 7, lock_version: 3 } }) });
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-input-en')).toBeTruthy());

    // en 에 토큰을 포함한 번역(라벨만 다름) — 토큰 보존이므로 허용.
    const enInput = screen.getByTestId('g7le-translation-input-en') as HTMLInputElement;
    fireEvent.change(enInput, { target: { value: 'Author {{user.name}}' } });
    fireEvent.blur(enInput);
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(fetchMock.mock.calls[1][1].method).toBe('PUT');
  });

  it('보간 토큰 삭제 시 저장 차단 + 안내(데이터 연결 무손상)', async () => {
    const fetchMock = vi.fn().mockResolvedValue(indexResponse({ ko: '작성자 {{user.name}}', en: '' }, 2));
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-input-ko')).toBeTruthy());

    // ko 의 토큰을 삭제 — 차단.
    const koInput = screen.getByTestId('g7le-translation-input-ko') as HTMLInputElement;
    fireEvent.change(koInput, { target: { value: '작성자' } });
    fireEvent.blur(koInput);

    await waitFor(() => expect(screen.getByTestId('g7le-translation-save-error')).toBeTruthy());
    expect(screen.getByTestId('g7le-translation-save-error').textContent).toContain(
      'layout_editor.translation.token_mismatch',
    );
    // PUT 미발생(GET 1회만).
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  // param 정규화 키 — 키 값은 `{p0}` 자리표시 문장이고, 번역 탭은 자리표시를
  // 원자 칩(PlaceholderChipInput)으로, 그 사이 평문을 편집 가능 span 으로 렌더한다. 평문 input 대신
  // 칩 합성 위젯이 뜬다. 평문 편집은 평문 span 의 textContent 를 바꾸고 input 이벤트로 recompose.
  /** 칩 합성 위젯의 평문 span 들 textContent 를 합쳐 현재 키 값을 재현(테스트 헬퍼). */
  const setChipText = (locale: string, spanIndex: number, value: string): void => {
    const wrap = screen.getByTestId(`g7le-translation-chip-${locale}`);
    const span = wrap.querySelector(`[data-testid="g7le-chip-text-${locale}-${spanIndex}"]`) as HTMLElement;
    span.textContent = value;
    fireEvent.input(span);
  };

  it('param 키 노드($t:custom.X|p0=..) → 번역 탭이 칩 합성 위젯으로 키 값 노출(자리표시=칩)', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(indexResponse({ ko: '{p0} 님 환영합니다', en: '' }, 1));
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1|p0={{user.name}}' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-field')).toBeTruthy());
    // 평문 input 이 아니라 칩 합성 위젯.
    expect(screen.queryByTestId('g7le-translation-input-ko')).toBeNull();
    expect(screen.getByTestId('g7le-translation-chip-ko')).toBeTruthy();
    // {p0} 는 원자 칩으로 렌더.
    expect(screen.getByTestId('g7le-chip-ko-p0')).toBeTruthy();
  });

  it('param 키 저장 → 즉시 PUT 아님(버퍼 기록, 자리표시 보존)', async () => {
    // param 키 저장은 저장-지연 버퍼에 기록(레이아웃 저장 시 flush). 즉시 PUT 없음(GET index 1회만).
    const fetchMock = vi.fn().mockResolvedValue(indexResponse({ ko: '{p0} 님 환영합니다', en: '' }, 2));
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1|p0={{user.name}}' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-chip-ko')).toBeTruthy());

    setChipText('ko', 2, ' 님 안녕'); // 칩 뒤 평문(인덱스 2) 편집 — 칩(자리표시) 보존.
    fireEvent.click(screen.getByTestId('g7le-translation-save'));
    // 즉시 PUT 없음 — GET index(1회)만. 값은 버퍼에.
    await waitFor(() => expect(getPendingValue('custom.home.1', 'ko')).toContain('{p0}'));
    expect(fetchMock.mock.calls.every((c) => (c[1]?.method ?? 'GET') !== 'PUT')).toBe(true);
    expect(screen.queryByTestId('g7le-translation-save-error')).toBeNull();
  });

  it('칩(자리표시)은 원자 — 평문만 비워도 {p0} 보존되어 저장(버퍼) 허용', async () => {
    const fetchMock = vi.fn().mockResolvedValue(indexResponse({ ko: '{p0} 님 환영합니다', en: '' }, 2));
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1|p0={{user.name}}' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-chip-ko')).toBeTruthy());

    setChipText('ko', 2, ''); // 칩 뒤 평문 전삭제 — 칩 원자라 {p0} 남음.
    fireEvent.click(screen.getByTestId('g7le-translation-save'));
    await waitFor(() => expect(getPendingValue('custom.home.1', 'ko')).toContain('{p0}'));
    expect(screen.queryByTestId('g7le-translation-save-error')).toBeNull();
  });

  it('409 응답 → 충돌 안내', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(indexResponse({ ko: 'A' }, 1))
      .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({}) });
    vi.stubGlobal('fetch', fetchMock);

    const node: EditorNode = { name: 'Span', text: '$t:custom.home.1' };
    renderField(node);
    await waitFor(() => expect(screen.getByTestId('g7le-translation-input-ko')).toBeTruthy());

    fireEvent.click(screen.getByTestId('g7le-translation-save'));
    await waitFor(() => expect(screen.getByTestId('g7le-translation-save-error')).toBeTruthy());
    expect(screen.getByTestId('g7le-translation-save-error').textContent).toContain(
      'layout_editor.translation.conflict',
    );
  });
});
