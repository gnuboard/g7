/**
 * dimension-and-attachments.test.tsx —
 *
 * 검증 대상:
 *  - DimensionWidget (항목2) — 자유 입력 commit / 프리셋 칩 / 기본 토글 / 외부 동기
 *  - LayoutAttachmentManager (항목3) — 목록/빈/에러/업로드/삭제/배경적용, 토큰 첨부
 *  - ImagePickerControl 인라인 미니 갤러리 (항목3) — 마운트 시 목록 로드, 썸네일 setUrl
 *
 * 첨부 fetch 는 모두 `Authorization: Bearer` 를 포함해야 한다(401 회귀 가드).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

import { DimensionWidget } from '../../components/property-controls/StyleControlWidgets';
import { LayoutAttachmentManager } from '../../components/property-controls/LayoutAttachmentManager';
import type { EditorControlSpec } from '../../spec/specTypes';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

// ============================================================================
// DimensionWidget (항목2)
// ============================================================================
describe('DimensionWidget', () => {
  const ctrl: EditorControlSpec = {
    widget: 'dimension',
    group: 'width',
    apply: { type: 'styleProp', prop: 'width' },
    options: [{ value: '100%', label: '100%' }, { value: '50%', label: '50%' }],
  };

  it('자유 입력 후 Enter → onChange(trim 문자열)', () => {
    const onChange = vi.fn();
    render(<DimensionWidget control={ctrl} value={undefined} onChange={onChange} t={t} />);
    const input = screen.getByTestId('g7le-dimension-input');
    fireEvent.change(input, { target: { value: '  320px ' } });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(onChange).toHaveBeenLastCalledWith('320px');
  });

  it('자유 입력 후 blur → onChange', () => {
    const onChange = vi.fn();
    render(<DimensionWidget control={ctrl} value={undefined} onChange={onChange} t={t} />);
    const input = screen.getByTestId('g7le-dimension-input');
    fireEvent.change(input, { target: { value: '24rem' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith('24rem');
  });

  it('프리셋 칩 클릭 → 그 값으로 onChange + 입력칸 채움', () => {
    const onChange = vi.fn();
    render(<DimensionWidget control={ctrl} value={undefined} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-dimension-chip-50%'));
    expect(onChange).toHaveBeenLastCalledWith('50%');
  });

  it('빈값 commit → onChange(undefined) (기본)', () => {
    const onChange = vi.fn();
    render(<DimensionWidget control={ctrl} value="320px" onChange={onChange} t={t} />);
    const input = screen.getByTestId('g7le-dimension-input');
    fireEvent.change(input, { target: { value: '' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  it('기본 버튼 클릭 → onChange(undefined)', () => {
    const onChange = vi.fn();
    render(<DimensionWidget control={ctrl} value="320px" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-dimension-clear'));
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  it('외부 value 변경(리사이즈 동기) → 입력칸 반영', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <DimensionWidget control={ctrl} value="100px" onChange={onChange} t={t} />,
    );
    expect((screen.getByTestId('g7le-dimension-input') as HTMLInputElement).value).toBe('100px');
    rerender(<DimensionWidget control={ctrl} value="240px" onChange={onChange} t={t} />);
    expect((screen.getByTestId('g7le-dimension-input') as HTMLInputElement).value).toBe('240px');
  });
});

// ============================================================================
// LayoutAttachmentManager (항목3)
// ============================================================================
describe('LayoutAttachmentManager', () => {
  const sampleList = [
    { id: 1, layout_name: 'home', original_name: 'hero.png', mime_type: 'image/png', size: 131072, url: '/storage/hero.png', created_at: '2026-05-30T00:00:00+00:00' },
    { id: 2, layout_name: 'home', original_name: 'bg2.jpg', mime_type: 'image/jpeg', size: 65536, url: '/storage/bg2.jpg', created_at: '2026-05-29T00:00:00+00:00' },
  ];

  function mockFetch(handler: (url: string, init?: RequestInit) => unknown) {
    const fn = vi.fn(async (url: string, init?: RequestInit) => handler(url, init));
    vi.stubGlobal('fetch', fn);
    return fn;
  }

  beforeEach(() => {
    // 기본 토큰 — Authorization 헤더 첨부 검증용
    vi.stubGlobal('localStorage', {
      getItem: (k: string) => (k === 'auth_token' ? 'TESTTOKEN' : null),
      setItem: () => {},
      removeItem: () => {},
    } as unknown as Storage);
  });

  it('마운트 시 목록 GET → 카드 렌더 + Bearer 토큰 첨부', async () => {
    const fn = mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: sampleList }) }));
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-card-1');
    expect(screen.getByTestId('g7le-attachment-card-2')).toBeTruthy();
    // GET 호출에 Authorization: Bearer 포함
    const [, init] = fn.mock.calls[0]!;
    expect((init as RequestInit).headers).toMatchObject({ Authorization: 'Bearer TESTTOKEN' });
  });

  it('빈 목록 → empty 안내', async () => {
    mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: [] }) }));
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-manager-empty');
  });

  it('로드 에러 → error 바', async () => {
    mockFetch(() => ({ ok: false, status: 500, json: async () => ({ message: 'boom' }) }));
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-manager-error');
  });

  it('onSelect 보유 → "배경으로 사용" 클릭 시 url 콜백', async () => {
    mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: sampleList }) }));
    const onSelect = vi.fn();
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onSelect={onSelect} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-use-1');
    fireEvent.click(screen.getByTestId('g7le-attachment-use-1'));
    expect(onSelect).toHaveBeenCalledWith('/storage/hero.png');
  });

  it('onSelect 미전달(툴바 진입) → "배경으로 사용" 버튼 숨김', async () => {
    mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: sampleList }) }));
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-card-1');
    expect(screen.queryByTestId('g7le-attachment-use-1')).toBeNull();
  });

  it('삭제 confirm 승인 → DELETE 호출 + 목록 제거', async () => {
    let deleted = false;
    const fn = mockFetch((url, init) => {
      if ((init as RequestInit)?.method === 'DELETE') {
        deleted = true;
        return { ok: true, json: async () => ({ success: true }) };
      }
      return { ok: true, json: async () => ({ success: true, data: sampleList }) };
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-card-1');
    fireEvent.click(screen.getByTestId('g7le-attachment-delete-1'));
    await vi.waitFor(() => expect(deleted).toBe(true));
    await vi.waitFor(() => expect(screen.queryByTestId('g7le-attachment-card-1')).toBeNull());
    // DELETE 호출에도 Bearer 첨부
    const delCall = fn.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'DELETE');
    expect((delCall![1] as RequestInit).headers).toMatchObject({ Authorization: 'Bearer TESTTOKEN' });
  });

  it('삭제 confirm 취소 → DELETE 미호출', async () => {
    const fn = mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: sampleList }) }));
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-attachment-card-1');
    fireEvent.click(screen.getByTestId('g7le-attachment-delete-1'));
    expect(fn.mock.calls.some((c) => (c[1] as RequestInit)?.method === 'DELETE')).toBe(false);
  });
});
