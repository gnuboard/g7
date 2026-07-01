/**
 * CustomTranslationManager.test.tsx —
 *
 * 검증 대상: 커스텀 다국어 키 관리 모달
 *  - 목록/빈/에러 상태
 *  - orphaned 배지 표시
 *  - 상태 필터(전체/사용중/미사용)
 *  - 번역 편집(PUT, lock_version) + 409 충돌
 *  - 개별 삭제 + 일괄 삭제 + 미사용 전체 삭제
 *  - 모든 fetch 에 Authorization: Bearer 첨부
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

import { CustomTranslationManager } from '../../components/property-controls/CustomTranslationManager';
import { collectReferencedCustomKeys } from '../../utils/customTranslations';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

const sampleList = [
  {
    id: 1,
    template_id: 1,
    layout_name: 'home',
    translation_key: 'custom.home.1',
    values: { ko: '안녕', en: 'Hi' },
    status: 'active' as const,
    lock_version: 0,
    updated_at: '2026-06-01T00:00:00+00:00',
  },
  {
    id: 2,
    template_id: 1,
    layout_name: 'home',
    translation_key: 'custom.home.2',
    values: { ko: '버려짐', en: 'orphan' },
    status: 'orphaned' as const,
    lock_version: 1,
    updated_at: '2026-06-02T00:00:00+00:00',
  },
];

function mockFetch(handler: (url: string, init?: RequestInit) => unknown) {
  const fn = vi.fn(async (url: string, init?: RequestInit) => handler(url, init));
  vi.stubGlobal('fetch', fn);
  return fn;
}

beforeEach(() => {
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => (k === 'auth_token' ? 'TESTTOKEN' : null),
    setItem: () => {},
    removeItem: () => {},
  } as unknown as Storage);
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function listOk() {
  return { ok: true, json: async () => ({ success: true, data: sampleList }) };
}

describe('collectReferencedCustomKeys (TS 스캐너 — 백엔드 트윈)', () => {
  it('중첩 노드 text/props 에서 custom 키 수집 + dedup', () => {
    const content = {
      components: [
        { type: 'Span', text: '$t:custom.home.1' },
        { type: 'Div', children: [{ text: '$t:custom.home.2' }, { props: { label: '$t:custom.home.1' } }] },
      ],
    };
    const keys = collectReferencedCustomKeys(content);
    expect([...keys].sort()).toEqual(['custom.home.1', 'custom.home.2']);
  });

  it('custom 아닌 $t: 키 제외', () => {
    const keys = collectReferencedCustomKeys([{ text: '$t:common.save' }, { text: '$t:custom.home.3' }]);
    expect([...keys]).toEqual(['custom.home.3']);
  });

  it('참조 없으면 빈 Set', () => {
    expect(collectReferencedCustomKeys([{ text: 'plain' }]).size).toBe(0);
  });
});

describe('CustomTranslationManager', () => {
  it('마운트 시 목록 GET → 행 렌더 + Bearer 토큰 첨부', async () => {
    const fn = mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    await screen.findByTestId('g7le-translation-row-2');
    const [, init] = fn.mock.calls[0]!;
    expect((init as RequestInit).headers).toMatchObject({ Authorization: 'Bearer TESTTOKEN' });
  });

  it('orphaned 행 → "미사용/연결 끊김" 배지 노출', async () => {
    mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-badge-orphaned-2');
    // active 행에는 배지 없음
    expect(screen.queryByTestId('g7le-translation-badge-orphaned-1')).toBeNull();
  });

  it('목록은 최근 업데이트 순(updated_at desc)으로 정렬', async () => {
    mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-list');
    const rows = Array.from(document.querySelectorAll('[data-testid^="g7le-translation-row-"]'));
    // row-2(2026-06-02) 가 row-1(2026-06-01) 보다 먼저 와야 한다.
    expect(rows.map((r) => r.getAttribute('data-testid'))).toEqual([
      'g7le-translation-row-2',
      'g7le-translation-row-1',
    ]);
  });

  it('빈 목록 → empty 안내', async () => {
    mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: [] }) }));
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-manager-empty');
  });

  it('로드 에러 → error 바', async () => {
    mockFetch(() => ({ ok: false, status: 500, json: async () => ({ message: 'boom' }) }));
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-manager-error');
  });

  it('미사용 필터 → orphaned 행만 표시', async () => {
    mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    fireEvent.click(screen.getByTestId('g7le-translation-filter-orphaned'));
    expect(screen.queryByTestId('g7le-translation-row-1')).toBeNull();
    expect(screen.getByTestId('g7le-translation-row-2')).toBeInTheDocument();
  });

  it('번역 편집 → PUT(values + expected_lock_version) + Bearer', async () => {
    let putBody: unknown = null;
    const fn = mockFetch((_url, init) => {
      if ((init as RequestInit)?.method === 'PUT') {
        putBody = JSON.parse(String((init as RequestInit).body));
        return { ok: true, json: async () => ({ success: true, data: { ...sampleList[0], lock_version: 1, values: { ko: '수정', en: 'Hi' } } }) };
      }
      return listOk();
    });
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    fireEvent.change(screen.getByTestId('g7le-translation-input-1-ko'), { target: { value: '수정' } });
    fireEvent.click(screen.getByTestId('g7le-translation-save-1'));
    await vi.waitFor(() => expect(putBody).not.toBeNull());
    expect(putBody).toMatchObject({ values: { ko: '수정', en: 'Hi' }, expected_lock_version: 0 });
    const putCall = fn.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'PUT');
    expect((putCall![1] as RequestInit).headers).toMatchObject({ Authorization: 'Bearer TESTTOKEN' });
  });

  // 칩 이동/추가는 즉시 PUT 하지 않고 저장-지연 버퍼(pending)에만 기록한다.
  // 본 모달이 서버 값만 보면 레이아웃 [저장] 전까지 `{pN}` 누락된 stale 값을 보여 준다
  // 서버 값 위에 pending 을 덮어
  // 캔버스/엔진과 동일한 라이브 값을 표시해야 한다.
  it('pending 버퍼가 있으면 서버 값 대신 버퍼 값(칩 위치 반영)을 표시', async () => {
    const { setPendingValue, clearPending } = await import('../../hooks/pendingCustomTranslations');
    clearPending();
    // 서버 값은 {p0} 없는 plainBase, pending 버퍼엔 칩 이동된 {p0} 위치 문장.
    const serverList = [
      {
        id: 7,
        template_id: 1,
        layout_name: 'home',
        translation_key: 'custom.home.7',
        values: { ko: '발 행일:', en: 'Published:', ja: '発行:' },
        status: 'active' as const,
        lock_version: 0,
        updated_at: '2026-06-09T00:00:00+00:00',
      },
    ];
    setPendingValue('sirsoft-basic', 'custom.home.7', 'ko', '발{p0}행일:');
    setPendingValue('sirsoft-basic', 'custom.home.7', 'en', 'Published: {p0}');
    setPendingValue('sirsoft-basic', 'custom.home.7', 'ja', '発行: {p0}');
    mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: serverList }) }));
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-7');
    // `{p0}` 자리표시가 든 키 값은 raw textarea 가 아니라 **칩 합성 위젯**으로
    // 렌더된다(평문 input 아님). 전 로케일 행이 pending 값({p0} 포함)을 칩으로 보여야 한다.
    expect(screen.queryByTestId('g7le-translation-input-7-ko')).toBeNull(); // 평문 textarea 아님.
    expect(screen.getByTestId('g7le-translation-chip-7-ko')).toBeTruthy();
    expect(screen.getByTestId('g7le-translation-chip-7-en')).toBeTruthy();
    expect(screen.getByTestId('g7le-translation-chip-7-ja')).toBeTruthy();
    // {p0} 자리표시는 원자 칩으로 렌더(전 로케일).
    expect(screen.getByTestId('g7le-chip-7-ko-p0')).toBeTruthy();
    expect(screen.getByTestId('g7le-chip-7-en-p0')).toBeTruthy();
    expect(screen.getByTestId('g7le-chip-7-ja-p0')).toBeTruthy();
    clearPending();
  });

  // 평문 값은 textarea, `{pN}` 자리표시 값은 칩. 한 행에 평문 로케일과
  // param 로케일이 섞여 있어도 각 로케일별로 위젯을 분기한다(혼합 안전).
  it('평문 로케일=textarea / {pN} 로케일=칩 (로케일별 분기)', async () => {
    const { clearPending } = await import('../../hooks/pendingCustomTranslations');
    clearPending();
    const serverList = [
      {
        id: 9, template_id: 1, layout_name: 'home', translation_key: 'custom.home.9',
        // ko 는 평문, en 은 {p0} 자리표시.
        values: { ko: '안녕하세요', en: 'Hi {p0}' },
        status: 'active' as const, lock_version: 0, updated_at: '2026-06-09T00:00:00+00:00',
      },
    ];
    mockFetch(() => ({ ok: true, json: async () => ({ success: true, data: serverList }) }));
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-9');
    // ko 평문 → textarea, en {p0} → 칩 위젯.
    expect((screen.getByTestId('g7le-translation-input-9-ko') as HTMLTextAreaElement).value).toBe('안녕하세요');
    expect(screen.queryByTestId('g7le-translation-chip-9-ko')).toBeNull();
    expect(screen.queryByTestId('g7le-translation-input-9-en')).toBeNull();
    expect(screen.getByTestId('g7le-translation-chip-9-en')).toBeTruthy();
    expect(screen.getByTestId('g7le-chip-9-en-p0')).toBeTruthy();
    clearPending();
  });

  it('번역 편집 409 → 충돌 메시지 표시', async () => {
    mockFetch((_url, init) => {
      if ((init as RequestInit)?.method === 'PUT') {
        return { ok: false, status: 409, json: async () => ({ message: 'conflict' }) };
      }
      return listOk();
    });
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    fireEvent.click(screen.getByTestId('g7le-translation-save-1'));
    const err = await screen.findByTestId('g7le-translation-row-error-1');
    expect(err.textContent).toContain('layout_editor.translation_manager.conflict');
  });

  it('개별 삭제 confirm 승인 → DELETE + 행 제거', async () => {
    let deleted = false;
    mockFetch((url, init) => {
      if ((init as RequestInit)?.method === 'DELETE' && /custom-translations\/1$/.test(url)) {
        deleted = true;
        return { ok: true, json: async () => ({ success: true }) };
      }
      return listOk();
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    fireEvent.click(screen.getByTestId('g7le-translation-delete-1'));
    await vi.waitFor(() => expect(deleted).toBe(true));
    await vi.waitFor(() => expect(screen.queryByTestId('g7le-translation-row-1')).toBeNull());
  });

  it('선택 후 일괄 삭제 → DELETE(body ids) + 행 제거', async () => {
    let bulkBody: unknown = null;
    mockFetch((url, init) => {
      const method = (init as RequestInit)?.method;
      if (method === 'DELETE' && /custom-translations$/.test(url)) {
        bulkBody = JSON.parse(String((init as RequestInit).body));
        return { ok: true, json: async () => ({ success: true, data: { deleted: 2 } }) };
      }
      return listOk();
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    fireEvent.click(screen.getByTestId('g7le-translation-select-1'));
    fireEvent.click(screen.getByTestId('g7le-translation-select-2'));
    fireEvent.click(screen.getByTestId('g7le-translation-bulk-delete'));
    await vi.waitFor(() => expect(bulkBody).not.toBeNull());
    expect((bulkBody as { ids: number[] }).ids.sort()).toEqual([1, 2]);
    await vi.waitFor(() => expect(screen.queryByTestId('g7le-translation-row-1')).toBeNull());
  });

  it('로케일 라벨 → 언어 명칭(해석) + 식별자 표시', async () => {
    // 언어 명칭 키를 실제 명칭으로 해석하는 t 더블 — 라벨 = "명칭 (식별자)" 형태 검증
    const tLocale = (k: string, params?: Record<string, string | number>) => {
      const names: Record<string, string> = {
        'layout_editor.locale.ko': '한국어',
        'layout_editor.locale.en': 'English',
        'layout_editor.locale.ja': '日本語',
      };
      if (names[k]) return names[k];
      return params ? `${k}(${JSON.stringify(params)})` : k;
    };
    mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={tLocale} onClose={vi.fn()} />);
    const field = await screen.findByTestId('g7le-translation-field-1-ko');
    const label = field.querySelector('label');
    // 언어 명칭 + 식별자 둘 다 노출
    expect(label?.textContent).toContain('한국어');
    expect(label?.textContent).toContain('(ko)');
  });

  it('referencedKeys 전달 → 현재 캔버스 사용중/미사용 라이브 배지 표시', async () => {
    mockFetch(() => listOk());
    // custom.home.1(id=1) 은 캔버스 참조, custom.home.2(id=2) 는 미참조.
    const referencedKeys = new Set<string>(['custom.home.1']);
    render(
      <CustomTranslationManager
        templateIdentifier="sirsoft-basic"
        layoutName="home"
        t={t}
        referencedKeys={referencedKeys}
        onClose={vi.fn()}
      />,
    );
    await screen.findByTestId('g7le-translation-row-1');
    // id=1 → 라이브 사용중 배지, id=2 → 라이브 미사용 배지
    expect(screen.getByTestId('g7le-translation-badge-live-inuse-1')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-translation-badge-live-unused-1')).toBeNull();
    expect(screen.getByTestId('g7le-translation-badge-live-unused-2')).toBeInTheDocument();
    // 저장된 status 배지(영속)는 별개로 유지 — id=2 는 orphaned status 라 둘 다 노출
    expect(screen.getByTestId('g7le-translation-badge-orphaned-2')).toBeInTheDocument();
  });

  it('referencedKeys 미전달 → 라이브 배지 생략(저장 status 배지만)', async () => {
    mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-row-1');
    expect(screen.queryByTestId('g7le-translation-badge-live-inuse-1')).toBeNull();
    expect(screen.queryByTestId('g7le-translation-badge-live-unused-2')).toBeNull();
  });

  it('번역 입력은 textarea (긴 텍스트 세로 확장 대응)', async () => {
    mockFetch(() => listOk());
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    const input = await screen.findByTestId('g7le-translation-input-1-ko');
    expect(input.tagName).toBe('TEXTAREA');
  });

  it('미사용 전체 삭제 → orphaned id 만 bulkDelete', async () => {
    let bulkBody: unknown = null;
    mockFetch((url, init) => {
      const method = (init as RequestInit)?.method;
      if (method === 'DELETE' && /custom-translations$/.test(url)) {
        bulkBody = JSON.parse(String((init as RequestInit).body));
        return { ok: true, json: async () => ({ success: true, data: { deleted: 1 } }) };
      }
      return listOk();
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<CustomTranslationManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onClose={vi.fn()} />);
    await screen.findByTestId('g7le-translation-purge-orphaned');
    fireEvent.click(screen.getByTestId('g7le-translation-purge-orphaned'));
    await vi.waitFor(() => expect(bulkBody).not.toBeNull());
    // orphaned 행(id=2)만 대상
    expect((bulkBody as { ids: number[] }).ids).toEqual([2]);
  });
});
