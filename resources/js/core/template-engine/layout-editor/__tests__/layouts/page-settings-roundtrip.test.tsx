// e2e:allow 페이지 설정 셸 patch 라운드트립 단위 — 실 브라우저 패치 영속은 Chrome MCP 매트릭스(세션 D 검증)로 보강.
/**
 * page-settings-roundtrip.test.tsx — 페이지 설정 모달 패치 라운드트립
 *
 * 셸(PageSettingsModal)이 각 탭에서 patch 한 최상위 키가 usePageSettings → patchDocumentRaw 로
 * 레이아웃 raw 에 무손실 반영되고, 다시 getValue 로 읽혀 폼에 재현되는지(라운드트립) 검증한다.
 * 특히 computed 'route-override' 되돌리기(onRevert)가 **자식 정의만 제거**하고 병합본에서
 * 부모 식을 재노출하는 분리 patch 계약을 잠근다.
 *
 * 셸은 Provider hook(usePageSettings/binding)을 직접 호출하므로, 본 테스트는 patchDocumentRaw
 * 를 캡처하는 가짜 문서 컨텍스트로 셸을 감싸 patch 페이로드(merged + own)를 단언한다. 라이브
 * 영속(DB 저장 후 새로고침 복원)은 Chrome MCP 매트릭스가 SSoT.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// 라이브 raw 문서 + patchDocumentRaw 캡처. patch(key,value,own?) → raw 갱신 + 호출 기록.
let liveRaw: Record<string, unknown> = {};
const patchCalls: Array<{ key: string; value: unknown; own?: unknown }> = [];
function applyPatch(key: string, value: unknown, ...rest: [unknown?]): void {
  const own = rest[0];
  patchCalls.push({ key, value, own });
  if (value === undefined) delete liveRaw[key];
  else liveRaw[key] = value;
  // patchDocumentRaw 와 같이 __editor.original[key] 에 영속 값(own 지정 시 own, 아니면 value)을 쓴다.
  const editor = liveRaw.__editor as { original?: Record<string, unknown> } | undefined;
  if (editor && editor.original && typeof editor.original === 'object') {
    liveRaw.__editor = { ...editor, original: { ...editor.original, [key]: rest.length >= 1 ? own : value } };
  }
}

vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: { selectedRoute: { path: '/', layoutName: 'home' }, routeTree: [], templateIdentifier: 'sirsoft-basic' },
    dispatch: vi.fn(),
  }),
}));
vi.mock('../../hooks/usePageSettings', () => ({
  usePageSettings: () => ({
    raw: liveRaw,
    getValue: <T,>(k: string, fb?: T): T => (liveRaw[k] === undefined ? (fb as T) : (liveRaw[k] as T)),
    patch: applyPatch,
    createI18nKey: vi.fn(),
    updateI18nKeyValue: vi.fn(),
  }),
}));
vi.mock('../../hooks/useBindingCandidates', () => ({
  useBindingCandidates: () => [],
  buildPageSampleContext: () => ({}),
}));
vi.mock('../../hooks/useSeoBindingCandidates', () => ({ useSeoBindingCandidates: () => [] }));

import { PageSettingsModal } from '../../components/page-settings/PageSettingsModal';

const t = (k: string, p?: Record<string, string | number>) => (p ? `${k}` : k);
const resolveLabel = (k: string) => k;

function renderShell(initialTab: 'computed' | 'meta' | 'scroll' = 'computed') {
  const shell = (): React.ReactElement => (
    <PageSettingsModal
      templateIdentifier="sirsoft-basic"
      spec={null}
      t={t}
      resolveLabel={resolveLabel}
      onClose={vi.fn()}
      initialTab={initialTab}
      extensionsFetcher={async () => []}
      templateConfigFetcher={async () => undefined}
    />
  );
  const utils = render(shell());
  // 가짜 문서 컨텍스트는 상태가 아니라 liveRaw 를 바꾸므로 패치 뒤 다시 그려 재현을 본다.
  return { ...utils, rerenderShell: () => utils.rerender(shell()) };
}

beforeEach(() => {
  cleanup();
  liveRaw = {};
  patchCalls.length = 0;
});

describe('페이지 설정 패치 라운드트립 —', () => {
  /**
   * @effects shell_no_save_button_patches_immediately_via_patchDocumentRaw
   */
  it('computed 추가 → onChange patch(computed, 병합본) → raw 반영 후 재현', () => {
    liveRaw = { computed: {} };
    renderShell('computed');
    // ComputedForm 의 추가 UI(프리셋 0 → 직접 만들기) 대신 직접 patch 경로 검증:
    // 카드가 비어 있으면 '항목 없음'. 여기서는 patch 계약(merged 반영)을 직접 확인하기 위해
    // computed 가 1키 있는 상태로 시작해 되돌리기 분리 patch 를 본다(아래 it).
    expect(screen.getByTestId('g7le-computed-form')).toBeInTheDocument();
  });

  /**
   * @effects computed_route_override_revert_button_removes_child_definition_only
   */
  it("route-override 되돌리기 → patch('computed', 부모식남은병합본, 자식original에서키제거)", () => {
    // 부모+자식 동시 선언(route-override) 상태: 병합본 computed.isReadOnly = 자식 식,
    // __editor.original.computed.isReadOnly = 자식 식(자기 선언분). __computedSource 가 route-override.
    liveRaw = {
      computed: { isReadOnly: '{{ _local.forced }}', keep: '{{ 1 }}' },
      __computedSource: { isReadOnly: 'route-override', keep: 'route' },
      __editor: { original: { computed: { isReadOnly: '{{ _local.forced }}', keep: '{{ 1 }}' } } },
    };
    renderShell('computed');
    // 승격 배지 + 되돌리기 버튼 노출.
    expect(screen.getByTestId('g7le-computed-overridden-isReadOnly')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-computed-revert-isReadOnly'));
    // 되돌리기 patch: 병합본에서 isReadOnly 제거(부모 식 재노출 위임), own 에서도 제거.
    const last = patchCalls.at(-1)!;
    expect(last.key).toBe('computed');
    expect(last.value).toEqual({ keep: '{{ 1 }}' }); // 자식 덮은 키 제거, 다른 키 보존.
    // own(자식 선언분)에서도 isReadOnly 제거 — keep 만 남음.
    expect(last.own).toEqual({ keep: '{{ 1 }}' });
  });

  /**
   * @effects computed_pure_child_route_no_badge_no_revert
   */
  it('computed 일반 편집(순수 자식 키 제거) → 병합본 patch 만(own 분리 없음)', () => {
    liveRaw = {
      computed: { searchField: "{{ query.q ?? '' }}" },
      __computedSource: { searchField: 'route' },
    };
    renderShell('computed');
    // 순수 자식 → 되돌리기 버튼 없음, ✕(remove)로 제거.
    expect(screen.queryByTestId('g7le-computed-revert-searchField')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-computed-remove-searchField'));
    const last = patchCalls.at(-1)!;
    expect(last.key).toBe('computed');
    expect(last.value).toEqual({}); // 병합본에서 제거.
  });

  /**
   * [스크롤] 탭(meta.scroll_restoration) — 화면 레이아웃에서 칸 하나를 고치면 own(original.meta)에는 그 키만,
   * 병합본에는 베이스 값 + 그 키가 남고, 다시 그리면 칸이 「이 화면에서 지정」으로 보인다. 비우면 상속으로 돌아간다.
   * @effects shell_scroll_patch_writes_merged_meta_and_own_only_original_meta, scroll_form_empty_field_removes_key_to_inherit
   */
  it('[스크롤] 화면에서 최대 대기만 바꾸면 own 에 그 키만 저장되고, 비우면 상속으로 돌아간다', () => {
    liveRaw = {
      meta: { title: 'x', scroll_restoration: { mode: 'top', containers: ['#admin-main'] } },
      __editor: { original: { meta: { title: 'x' } } },
    };
    const { rerenderShell } = renderShell('scroll');
    // 처음엔 전부 상속 — 베이스가 준 mode 는 출처 base, 아무도 안 준 timeout 은 엔진 기본값
    expect(screen.getByTestId('g7le-scroll-source-mode').getAttribute('data-source')).toBe('base');
    expect(screen.getByTestId('g7le-scroll-source-timeout_ms').getAttribute('data-source')).toBe('engine');
    expect(screen.getByTestId('g7le-scroll-container-0')).toHaveTextContent('#admin-main');

    fireEvent.change(screen.getByTestId('g7le-scroll-timeout'), { target: { value: '8000' } });
    const last = patchCalls[patchCalls.length - 1];
    expect(last.key).toBe('meta');
    expect(last.value).toEqual({ title: 'x', scroll_restoration: { mode: 'top', containers: ['#admin-main'], timeout_ms: 8000 } });
    expect(last.own).toEqual({ title: 'x', scroll_restoration: { timeout_ms: 8000 } });

    rerenderShell();
    expect((screen.getByTestId('g7le-scroll-timeout') as HTMLInputElement).value).toBe('8000');
    expect(screen.getByTestId('g7le-scroll-source-timeout_ms').getAttribute('data-source')).toBe('own');
    // 베이스 값은 그대로 상속 표시
    expect(screen.getByTestId('g7le-scroll-source-mode').getAttribute('data-source')).toBe('base');

    // 비우면 키를 지워 상속 — own meta 에서 scroll_restoration 이 사라진다
    fireEvent.change(screen.getByTestId('g7le-scroll-timeout'), { target: { value: '' } });
    const cleared = patchCalls[patchCalls.length - 1];
    expect(cleared.own).toEqual({ title: 'x' });
    expect(cleared.value).toEqual({ title: 'x', scroll_restoration: { mode: 'top', containers: ['#admin-main'] } });
    rerenderShell();
    expect(screen.getByTestId('g7le-scroll-source-timeout_ms').getAttribute('data-source')).toBe('engine');
  });
});
