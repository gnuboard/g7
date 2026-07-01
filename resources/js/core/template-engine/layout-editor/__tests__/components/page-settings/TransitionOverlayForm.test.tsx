// e2e:allow [로딩 화면] 폼 단위(RTL) — 토글/세그먼트/picker 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * TransitionOverlayForm.test.tsx — [로딩 화면] 탭 폼 RTL
 *
 * 검증:
 *  ① enabled 토글 — 꺼짐 시 하위를 숨기지 않고 회색 비활성(D-M), 불리언 간편형 정규화
 *  ② 덮기 범위 세그먼트(전체/특정영역) → target 유무
 *  ③ 스타일 5종 + 스타일별 부가옵션(skeleton/spinner 만)
 *  ④ wait_for progressive 후보 체크 / 후보 0 안내
 *  ⑤ base 상속 배너 + 모두 기본값으로(자식 키 전체 삭제)
 *  ⑥ LoadingComponentPicker role 필터
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { TransitionOverlayForm } from '../../../components/page-settings/TransitionOverlayForm';
import { clearWidgetRegistry, registerWidget } from '../../../spec/widgetRegistry';
import { EditorModalProvider, EditorModalRoot } from '../../../EditorModalContext';

const t = (k: string) => k;

/**
 * LoadingComponentPicker 가 별도 모달(useEditorModal.open)을 쓰므로 Provider/Root 로
 * 래핑한다. 그 외 테스트는 Provider 가 있어도 무해(picker 미사용 시 modal 미열림).
 */
function renderOverlay(ui: React.ReactElement) {
  return render(
    <EditorModalProvider>
      {ui}
      <EditorModalRoot />
    </EditorModalProvider>,
  );
}

const LOADING = [
  { name: 'PageLoading', role: 'page', label: '$t:전체 로딩' },
  { name: 'LoadingSpinner', role: 'spinner', label: '$t:스피너' },
  { name: 'SkeletonRenderer', role: 'skeleton', label: '$t:골격' },
];

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  // ComponentTargetPicker 스텁(EditorModalContext 회피).
  registerWidget('component-target-picker', ({ value, onChange }) => (
    <input data-testid="target-stub" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />
  ));
});

describe('TransitionOverlayForm', () => {
  it('enabled OFF 시 하위 옵션을 숨기지 않고 회색 비활성으로 표시한다(D-M)', () => {
    renderOverlay(<TransitionOverlayForm value={{ enabled: false }} patch={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-overlay-enabled')).toBeInTheDocument();
    // 숨김 금지 — 본문은 DOM 에 존재하되 회색 비활성(data-disabled=true).
    const body = screen.getByTestId('g7le-overlay-body');
    expect(body).toBeInTheDocument();
    expect(body).toHaveAttribute('data-disabled', 'true');
    expect(screen.getByTestId('g7le-overlay-scope')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-overlay-style')).toBeInTheDocument();
  });

  it('불리언 간편형(true) 을 객체로 정규화해 패치한다', () => {
    const patch = vi.fn();
    renderOverlay(<TransitionOverlayForm value={true} patch={patch} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-overlay-enabled')); // true → enabled:false 토글
    expect(patch).toHaveBeenLastCalledWith({ enabled: false });
  });

  it('덮기 범위 세그먼트 — 특정영역 미선택 시 picker 회색 비활성, 선택 시 target 키 보장(D-I/D-M)', () => {
    const patch = vi.fn();
    const { rerender } = renderOverlay(<TransitionOverlayForm value={{ enabled: true }} patch={patch} t={t} />);
    // picker 는 숨기지 않고 항상 표시하되, scope 가 region 이 아니면 회색 비활성.
    const regionBox = screen.getByTestId('g7le-overlay-region-box');
    expect(regionBox).toHaveAttribute('data-disabled', 'true');
    // 특정영역 클릭 → target 키 보장(빈 문자열). scope 판정은 키 존재 여부(D-I).
    fireEvent.click(screen.getByTestId('g7le-overlay-scope-region'));
    expect(patch).toHaveBeenLastCalledWith({ enabled: true, target: '' });
    // target 키가 있으면(빈 문자열이라도) scope=region → picker 활성.
    rerender(
      <EditorModalProvider>
        <TransitionOverlayForm value={{ enabled: true, target: '' }} patch={patch} t={t} />
        <EditorModalRoot />
      </EditorModalProvider>,
    );
    expect(screen.getByTestId('g7le-overlay-region-box')).toHaveAttribute('data-disabled', 'false');
  });

  it('스타일 skeleton 선택 시 부가옵션(렌더러/애니/횟수) 노출', () => {
    renderOverlay(<TransitionOverlayForm value={{ enabled: true, style: 'skeleton' }} patch={vi.fn()} t={t} loadingComponents={LOADING} />);
    expect(screen.getByTestId('g7le-overlay-skeleton-anim')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-overlay-skeleton-count')).toBeInTheDocument();
    // spinner 옵션은 부재.
    expect(screen.queryByTestId('g7le-overlay-spinner-text')).not.toBeInTheDocument();
  });

  it('opaque 등 비-부가 스타일은 부가옵션을 숨긴다', () => {
    renderOverlay(<TransitionOverlayForm value={{ enabled: true, style: 'opaque' }} patch={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-overlay-skeleton-anim')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-overlay-spinner-text')).not.toBeInTheDocument();
  });

  it('LoadingComponentPicker [선택] → 별도 모달 + role 필터(skeleton) ', () => {
    renderOverlay(<TransitionOverlayForm value={{ enabled: true, style: 'skeleton' }} patch={vi.fn()} t={t} loadingComponents={LOADING} />);
    // 트리거 행만 인라인 — 클릭 전엔 후보 목록이 DOM 에 없다(인라인 드롭다운 아님).
    expect(screen.queryByTestId('g7le-overlay-skeleton-component-modal')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-overlay-skeleton-component-open'));
    // 클릭 후 별도 모달이 열리고 그 안에 후보가 렌더된다.
    expect(screen.getByTestId('g7le-overlay-skeleton-component-modal')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-overlay-skeleton-component-item-SkeletonRenderer')).toBeInTheDocument();
    // spinner/page 역할은 skeleton 후보에서 제외.
    expect(screen.queryByTestId('g7le-overlay-skeleton-component-item-LoadingSpinner')).not.toBeInTheDocument();
  });

  it('wait_for 는 접이식(▸ 토글) — 펼친 뒤 후보 체크 → 배열 토글, 후보 0 안내 (W3)', () => {
    const patch = vi.fn();
    const { rerender } = renderOverlay(
      <TransitionOverlayForm value={{ enabled: true }} patch={patch} t={t} progressiveDataSources={[{ id: 'products', friendly: '상품', source: null }]} />,
    );
    // 접이식 — 기본 닫힘(wait_for 선택값 없음). 토글 전엔 후보 목록 DOM 부재.
    expect(screen.queryByTestId('g7le-overlay-waitfor-products')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-overlay-waitfor-toggle'));
    // 펼친 뒤 후보 체크.
    fireEvent.click(screen.getByTestId('g7le-overlay-waitfor-products').querySelector('input')!);
    expect(patch).toHaveBeenLastCalledWith({ enabled: true, wait_for: ['products'] });

    rerender(
      <EditorModalProvider>
        <TransitionOverlayForm value={{ enabled: true }} patch={patch} t={t} progressiveDataSources={[]} />
        <EditorModalRoot />
      </EditorModalProvider>,
    );
    // rerender 는 waitForOpen state 를 유지(이미 펼침) → 토글 재클릭 없이 empty 안내가 보인다.
    expect(screen.getByTestId('g7le-overlay-waitfor-empty')).toBeInTheDocument();
  });

  it('wait_for 칩이 친화명·보조 id·확장 출처 배지를 노출(데이터 탭과 동일 표기)', () => {
    const patch = vi.fn();
    renderOverlay(
      <TransitionOverlayForm
        value={{ enabled: true }}
        patch={patch}
        t={t}
        progressiveDataSources={[
          { id: 'products', friendly: '상품 목록', source: null },
          { id: 'gdprMyConsent', friendly: null, source: '플러그인: GDPR (sirsoft-gdpr)' },
        ]}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-overlay-waitfor-toggle'));
    // 친화명 있으면 제목=친화명 + 보조 id.
    expect(screen.getByTestId('g7le-overlay-waitfor-products-title').textContent).toBe('상품 목록');
    expect(screen.getByTestId('g7le-overlay-waitfor-products-id').textContent).toBe('products');
    // 친화명 없으면 제목=id, 보조 id 미노출.
    expect(screen.getByTestId('g7le-overlay-waitfor-gdprMyConsent-title').textContent).toBe('gdprMyConsent');
    expect(screen.queryByTestId('g7le-overlay-waitfor-gdprMyConsent-id')).not.toBeInTheDocument();
    // 확장 출처 배지.
    expect(screen.getByTestId('g7le-overlay-waitfor-gdprMyConsent-source').textContent).toBe('플러그인: GDPR (sirsoft-gdpr)');
    expect(screen.queryByTestId('g7le-overlay-waitfor-products-source')).not.toBeInTheDocument();
  });

  it('base 상속 시 필드별 〔상속됨〕 배지 + [이 화면만 바꾸기] (W4 L1979)', () => {
    const patch = vi.fn();
    // base 만 enabled/style 정의, 자식은 빈 객체 → 두 필드 모두 상속 상태.
    renderOverlay(
      <TransitionOverlayForm value={{}} patch={patch} t={t} baseValue={{ enabled: true, style: 'opaque' }} />,
    );
    // enabled/style 상속 배지 + override 버튼.
    expect(screen.getByTestId('g7le-overlay-inherited-enabled')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-overlay-override-enabled')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-overlay-inherited-style')).toBeInTheDocument();
    // [이 화면만 바꾸기] 클릭 → base 값을 자식 키로 복사(override 시작).
    fireEvent.click(screen.getByTestId('g7le-overlay-override-enabled'));
    expect(patch).toHaveBeenLastCalledWith({ enabled: true });
  });

  it('base 상속 + 자식이 override 한 필드는 〔이 화면에서 바꿈〕 배지 (W4)', () => {
    const patch = vi.fn();
    // 자식이 style 을 override(자기 키 보유) → style 은 재정의, enabled 는 상속.
    renderOverlay(
      <TransitionOverlayForm value={{ style: 'blur' }} patch={patch} t={t} baseValue={{ enabled: true, style: 'opaque' }} />,
    );
    expect(screen.getByTestId('g7le-overlay-overridden-style')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-overlay-inherited-enabled')).toBeInTheDocument();
  });

  it('base 상속 시 배너 + 모두 기본값으로(자식 키 전체 삭제)', () => {
    const patch = vi.fn();
    render(
      <TransitionOverlayForm
        value={{ enabled: true }}
        patch={patch}
        t={t}
        baseValue={{ enabled: true, style: 'opaque' }}
      />,
    );
    expect(screen.getByTestId('g7le-overlay-inherit-banner')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-overlay-reset-inherit'));
    expect(patch).toHaveBeenLastCalledWith(undefined);
  });
});
