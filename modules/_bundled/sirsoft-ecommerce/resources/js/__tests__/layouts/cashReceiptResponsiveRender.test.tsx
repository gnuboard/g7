/**
 * @file cashReceiptResponsiveRender.test.tsx
 * @description 체크아웃 현금영수증 폼의 반응형 오버라이드 실렌더 검증 (#454 S3, F5)
 *
 * cashReceiptUi.test.tsx 는 node 환경(렌더 없음)이라 JSON 구조만 대조한다.
 * 그래서 "responsive.portable 이 선언되어 있다" 까지만 알 수 있고,
 * "좁은 화면에서 실제로 세로 스택으로 렌더된다" 는 확인하지 못한다.
 *
 * 본 파일은 DynamicRenderer 를 실제로 렌더해 그 간극을 메운다:
 *  - DynamicRenderer 는 useResponsive().width 를 읽어 responsiveManager.getMatchingKey 로
 *    오버라이드 키를 고른다(DynamicRenderer.tsx:1413, 1445). 그 width 를 mock 으로 주입한다.
 *  - portable 은 0~1023px 단일 오버라이드다(§6-0). 1024px 이상에서는 base 가 그대로 남아야 한다.
 *
 * 회귀 방지 대상: 누군가 responsive.portable 을 지우고 Tailwind `md:` 로 되돌리면
 * 위지윅 편집기의 디바이스 전환(overrideWidth)이 먹지 않는다 — 그때 이 테스트가 깨진다.
 *
 * 주의: 이 docblock 에 vitest 환경 지시자 토큰을 문자열로 적지 말 것.
 * vitest 는 파일 상단 주석을 정규식으로 훑어 환경을 결정하므로, 산문 속 언급도
 * 그대로 적용되어 jsdom 대신 node 로 실행된다(그러면 window 부재로 수집 단계에서 죽는다).
 *
 * @scenario actor=guest, change_mode=manual
 *
 * @effects portable_override_applies_below_1024_on_render,
 *   portable_override_absent_at_1024_on_render,
 *   new_ui_has_no_tailwind_breakpoint
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import extensionJson from '../../../extensions/checkout_cash_receipt.json';

/** 현재 테스트가 주입할 화면 너비 (아래 mock 이 읽는다) */
let mockWidth = 1280;

vi.mock('@core/template-engine/ResponsiveContext', () => ({
  ResponsiveContext: React.createContext(null),
  ResponsiveProvider: ({ children }: { children?: React.ReactNode }) => children,
  useResponsive: () => ({
    width: mockWidth,
    isMobile: mockWidth < 768,
    isTablet: mockWidth >= 768 && mockWidth < 1024,
    isDesktop: mockWidth >= 1024,
    matchedPreset: (mockWidth < 768 ? 'mobile' : mockWidth < 1024 ? 'tablet' : 'desktop') as
      | 'mobile'
      | 'tablet'
      | 'desktop',
  }),
}));

/** id·className 을 그대로 DOM 에 흘리는 스텁 (검사 대상이 그 두 속성이다) */
const Stub: React.FC<Record<string, any>> = ({ children, className, id, text }) => (
  <div id={id} className={className}>
    {children ?? text}
  </div>
);

/**
 * ComponentRegistry 싱글톤을 스텁으로 채운다.
 *
 * Fragment 는 createLayoutTest 가 루트 컨테이너로 쓴다 — 빠뜨리면 자식 트리가
 * 통째로 렌더되지 않고 빈 컨테이너만 남는다.
 */
function setupTestRegistry(): void {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: Stub, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: Stub, metadata: { name: 'Span', type: 'basic' } },
    P: { component: Stub, metadata: { name: 'P', type: 'basic' } },
    Label: { component: Stub, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: Stub, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: Stub, metadata: { name: 'Select', type: 'basic' } },
    Fragment: {
      component: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
      metadata: { name: 'Fragment', type: 'layout' },
    },
  };
}

/** 슬롯 컴포넌트 트리를 독립 레이아웃으로 감싼다 (확장 JSON 은 extension_point 채움용이다) */
function layoutWithSlotComponents() {
  return {
    version: '1.0',
    layout_name: 'test_checkout_cash_receipt',
    data_sources: [],
    components: JSON.parse(JSON.stringify((extensionJson as any).components)),
  };
}

/**
 * 루트 gate 가 읽는 paymentSettings 응답.
 *
 * 호스트 레이아웃(shop/checkout.json)이 선언하는 데이터소스라 슬롯 JSON 에는 없다.
 * 반응형 검증이 목적이므로 fetch 를 태우지 않고 initialData 로 직접 주입한다.
 */
const PAYMENT_SETTINGS = {
  data: { order_settings: { cash_receipt_provider: 'tosspayments' } },
};

/**
 * 신청 폼이 펼쳐진 상태로 렌더한다.
 *
 * 두 개의 gate 를 모두 열어야 검사 대상 노드가 마운트된다:
 *  - 루트: paymentSettings.data.order_settings.cash_receipt_provider 가 truthy
 *  - 입력 영역: _local.cashReceiptRequested 가 truthy
 *
 * @param  width  주입할 화면 너비 (px)
 */
async function renderAtWidth(width: number) {
  mockWidth = width;
  setupTestRegistry();

  const testUtils = createLayoutTest(layoutWithSlotComponents() as any, {
    initialData: { paymentSettings: PAYMENT_SETTINGS },
    initialState: {
      _local: {
        paymentMethod: 'dbank',
        cashReceiptRequested: true,
        cashReceiptType: 'income',
        cashReceiptIdentifierType: 'phone',
      },
    },
  });

  await testUtils.render();

  // 두 gate 가 모두 열렸는지 확인한다. 트리가 비면 아래 검사들이 조용히 통과해 버린다.
  expect(
    document.getElementById('ext_checkout_cash_receipt_fields'),
    '신청 폼이 펼쳐지지 않았다 — gate 조건이 바뀌었는지 확인할 것'
  ).not.toBeNull();

  return testUtils;
}

/**
 * 렌더된 노드의 클래스 목록을 반환한다.
 *
 * @param  id  레이아웃 JSON 에 선언된 노드 id
 * @return 클래스 토큰 배열
 */
const classesOf = (id: string): string[] => {
  const el = document.getElementById(id);
  expect(el, `#${id} 가 렌더되지 않았다`).not.toBeNull();

  return (el!.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);
};

describe('현금영수증 신청 폼 — 반응형 실렌더 (F5)', () => {
  beforeEach(() => {
    mockWidth = 1280;
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('데스크톱(1280px)에서는 용도 라디오가 가로 배치된다', async () => {
    const t = await renderAtWidth(1280);

    const cls = classesOf('ext_checkout_cash_receipt_purpose');
    expect(cls).toContain('flex-row');
    expect(cls).not.toContain('flex-col');

    t.cleanup();
  });

  it('모바일(375px)에서는 용도 라디오가 세로 스택으로 바뀐다', async () => {
    const t = await renderAtWidth(375);

    const cls = classesOf('ext_checkout_cash_receipt_purpose');
    expect(cls).toContain('flex-col');
    expect(cls).not.toContain('flex-row');

    t.cleanup();
  });

  it('모바일에서 발급수단·번호 입력행이 1열로 접힌다', async () => {
    const t = await renderAtWidth(375);

    const cls = classesOf('ext_checkout_cash_receipt_identifier_row');
    expect(cls).toContain('grid-cols-1');
    expect(cls).not.toContain('grid-cols-2');

    t.cleanup();
  });

  it('태블릿(1023px)까지 portable 이 적용되고 1024px 부터 base 로 돌아온다', async () => {
    // portable 은 0~1023px 단일 오버라이드 — 경계값을 양쪽에서 고정한다.
    const tablet = await renderAtWidth(1023);
    expect(classesOf('ext_checkout_cash_receipt_purpose')).toContain('flex-col');
    tablet.cleanup();
    cleanup();

    const desktop = await renderAtWidth(1024);
    expect(classesOf('ext_checkout_cash_receipt_purpose')).toContain('flex-row');
    desktop.cleanup();
  });

  it('어떤 너비에서도 Tailwind 브레이크포인트 접두사를 쓰지 않는다 (§6-0)', async () => {
    const t = await renderAtWidth(375);

    const rendered = document.body.innerHTML;
    expect(rendered).not.toMatch(/\bmd:/);
    expect(rendered).not.toMatch(/\blg:/);
    expect(rendered).not.toMatch(/\bsm:/);

    t.cleanup();
  });
});
