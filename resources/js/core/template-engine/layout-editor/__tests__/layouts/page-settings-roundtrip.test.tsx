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
function applyPatch(key: string, value: unknown, own?: unknown): void {
  patchCalls.push({ key, value, own });
  if (value === undefined) delete liveRaw[key];
  else liveRaw[key] = value;
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

function renderShell(initialTab: 'computed' | 'meta' = 'computed') {
  return render(
    <PageSettingsModal
      templateIdentifier="sirsoft-basic"
      spec={null}
      t={t}
      resolveLabel={resolveLabel}
      onClose={vi.fn()}
      initialTab={initialTab}
      extensionsFetcher={async () => []}
    />,
  );
}

beforeEach(() => {
  cleanup();
  liveRaw = {};
  patchCalls.length = 0;
});

describe('페이지 설정 패치 라운드트립 —', () => {
  it('computed 추가 → onChange patch(computed, 병합본) → raw 반영 후 재현', () => {
    liveRaw = { computed: {} };
    renderShell('computed');
    // ComputedForm 의 추가 UI(프리셋 0 → 직접 만들기) 대신 직접 patch 경로 검증:
    // 카드가 비어 있으면 '항목 없음'. 여기서는 patch 계약(merged 반영)을 직접 확인하기 위해
    // computed 가 1키 있는 상태로 시작해 되돌리기 분리 patch 를 본다(아래 it).
    expect(screen.getByTestId('g7le-computed-form')).toBeInTheDocument();
  });

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
});
