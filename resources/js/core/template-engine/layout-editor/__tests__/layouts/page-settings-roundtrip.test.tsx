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
import type { EditorSpec } from '../../spec/specTypes';

const t = (k: string, p?: Record<string, string | number>) => (p ? `${k}` : k);
const resolveLabel = (k: string) => k;

/** [자동 계산] 보기 1종 — 추가 목록에 프리셋 버튼이 생기게 하는 최소 스펙. */
const SPEC_WITH_COMPUTED_RECIPE = {
  computedRecipes: {
    filterDefault: {
      label: '$t:필터 자동 채우기',
      group: 'common',
      params: [{ key: 'localPath', widget: 'text' }],
      expr: "_local.{localPath} ?? ''",
    },
  },
} as unknown as EditorSpec;

function renderShell(initialTab: 'computed' | 'meta' = 'computed', spec: EditorSpec | null = null) {
  return render(
    <PageSettingsModal
      templateIdentifier="sirsoft-basic"
      spec={spec}
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
  /**
   * @scenario roundtrip=page_settings
   * @effects computed_add_patches_merged_and_reappears
   */
  it('computed 추가 → onChange patch(computed, 병합본) → raw 반영 후 재현', () => {
    liveRaw = { computed: { keep: '{{ 1 }}' } };
    renderShell('computed', SPEC_WITH_COMPUTED_RECIPE);
    // 추가 → 보기(filterDefault) 선택 = ComputedForm.onChange → 셸 patch('computed', 병합본).
    fireEvent.click(screen.getByTestId('g7le-computed-add'));
    fireEvent.click(screen.getByTestId('g7le-computed-preset-filterDefault'));
    const last = patchCalls.at(-1)!;
    expect(last.key).toBe('computed');
    // 병합본 = 기존 키 보존 + 새 키 1개. 추가는 own 분리 patch 가 아니다.
    const merged = last.value as Record<string, string>;
    expect(Object.keys(merged).sort()).toEqual(['filterDefault', 'keep']);
    expect(merged.keep).toBe('{{ 1 }}');
    expect(typeof merged.filterDefault).toBe('string');
    expect(merged.filterDefault).not.toBe('');
    expect(last.own).toBeUndefined();
    // raw 반영 후 다시 열면 새 키 카드가 재현된다.
    expect(liveRaw.computed).toEqual(merged);
    cleanup();
    renderShell('computed', SPEC_WITH_COMPUTED_RECIPE);
    expect(screen.getByTestId('g7le-computed-item-filterDefault')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-computed-item-keep')).toBeInTheDocument();
  });

  /**
   * @scenario roundtrip=page_settings
   * @effects computed_route_override_revert_patches_merged_and_own
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
   * @scenario roundtrip=page_settings
   * @effects computed_plain_remove_patches_merged_only
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
    // 순수 자식 키 제거는 own(자식 original) 분리 patch 를 싣지 않는다.
    expect(last.own).toBeUndefined();
  });
});
