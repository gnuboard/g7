// e2e:allow 라벨 데이터(settings.json) + Vitest 렌더 회귀 테스트만 변경 — 레이아웃 동작/구조 무변경. 알림 정의 화면의 실제 렌더 경로($t: 동적 키 해석)는 본 render 테스트가 커버하고, 화면 수준 검증은 별도 브라우저 실측(handoff §10)으로 확인.
/**
 * @file notificationDefinitionTypeLabels.test.tsx
 * @description 이커머스 알림 정의 화면 — 타입 라벨 다국어 키 raw 노출 회귀 가드.
 *
 * 배경: `order_pending_deposit`, `order_delivered` 두 알림 타입이 module.php 에는
 * 정의됐으나 라벨을 읽는 settings.json 의 `notification_definitions.types` 에 이름이
 * 등록되지 않아, 알림 정의 화면에서
 * `sirsoft-ecommerce.admin.settings.notification_definitions.types.order_pending_deposit`
 * 같은 raw 키가 그대로 노출됐다.
 *
 * 본 테스트는 _tab_notification_definitions.json 이 사용하는 실제 바인딩
 * `$t:sirsoft-ecommerce.admin.settings.notification_definitions.types.{{def.type}}` 을
 * 재현하고, **실제 settings.json** 라벨을 주입해 두 타입이 raw 키가 아닌 사람이 읽는
 * 라벨로 렌더되는지 검증한다. settings.json 에서 어느 한 키가 제거되면 이 테스트가
 * 다시 red 로 떨어져 회귀를 차단한다.
 */

import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import settingsKo from '../../../lang/partial/ko/admin/settings.json';
import settingsEn from '../../../lang/partial/en/admin/settings.json';

// 실제 화면(_tab_notification_definitions.json line ~371)의 타입 라벨 바인딩을 그대로 축약 재현.
// 알림 목록 항목마다 H4 에 `$t:...types.{{def.type}}` 로 이름을 표시한다.
const LABEL_BINDING =
  '$t:sirsoft-ecommerce.admin.settings.notification_definitions.types.{{def.type}}';

function makeLayout() {
  return {
    name: 'notification_definition_type_labels_probe',
    version: '1.0.0',
    components: [
      {
        type: 'basic',
        name: 'Div',
        children: [
          {
            type: 'basic',
            name: 'Div',
            iteration: {
              source: '{{ecommerceNotificationDefinitions?.data?.data ?? []}}',
              item_var: 'def',
              index_var: 'defIdx',
            },
            children: [
              {
                type: 'basic',
                name: 'H4',
                text: LABEL_BINDING,
              },
            ],
          },
        ],
      },
    ],
  };
}

// 실제 서빙 데이터를 모사한 알림 정의 목록 — 문제의 두 타입 포함.
const DEFINITIONS = [
  { id: 1, type: 'order_pending_deposit', templates: [], variables: [] },
  { id: 2, type: 'order_delivered', templates: [], variables: [] },
];

// settings.json 은 `notification_definitions` 를 admin.settings 하위가 아닌 파일 루트에 둔다.
// 화면 바인딩은 `sirsoft-ecommerce.admin.settings.notification_definitions...` 네임스페이스를
// 쓰므로, 주입 시 admin.settings 아래로 매핑한다(활성 언어팩 로더의 병합 결과와 동일 구조).
function buildTranslations(settings: Record<string, any>) {
  return {
    'sirsoft-ecommerce': {
      admin: {
        settings: settings,
      },
    },
  };
}

let registry: MockComponentRegistry;

afterEach(() => {
  vi.clearAllMocks();
});

describe('알림 정의 타입 라벨 — 다국어 키 raw 노출 회귀 가드', () => {
  it('ko: order_pending_deposit / order_delivered 가 라벨로 렌더된다 (raw 키 미노출)', async () => {
    registry = createMockComponentRegistryWithBasics();
    const { render, cleanup } = createLayoutTest(makeLayout(), {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: buildTranslations(settingsKo),
      initialData: {
        ecommerceNotificationDefinitions: { data: { data: DEFINITIONS } },
      },
    });

    await render();

    expect(screen.getByText('무통장 입금 안내')).toBeInTheDocument();
    expect(screen.getByText('배송 완료')).toBeInTheDocument();

    // raw 키가 화면에 남아있으면 회귀
    expect(
      screen.queryByText(/notification_definitions\.types\.order_pending_deposit/),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(/notification_definitions\.types\.order_delivered/),
    ).not.toBeInTheDocument();

    cleanup();
  });

  it('en: order_pending_deposit / order_delivered 가 라벨로 렌더된다 (raw 키 미노출)', async () => {
    registry = createMockComponentRegistryWithBasics();
    const { render, cleanup } = createLayoutTest(makeLayout(), {
      componentRegistry: registry as any,
      locale: 'en',
      translations: buildTranslations(settingsEn),
      initialData: {
        ecommerceNotificationDefinitions: { data: { data: DEFINITIONS } },
      },
    });

    await render();

    expect(screen.getByText('Bank Transfer Payment Guide')).toBeInTheDocument();
    expect(screen.getByText('Order Delivered')).toBeInTheDocument();

    expect(
      screen.queryByText(/notification_definitions\.types\.order_pending_deposit/),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(/notification_definitions\.types\.order_delivered/),
    ).not.toBeInTheDocument();

    cleanup();
  });

  it('라벨 제거 시 raw 키가 노출됨 — 회귀 가드가 실제로 결함을 감지한다', async () => {
    // 이 테스트는 위 두 가드가 "우연히 통과"하는 것이 아님을 증명한다:
    // settings.json 에서 두 타입 라벨을 제거하면 화면에 raw 키가 그대로 노출된다.
    registry = createMockComponentRegistryWithBasics();
    const stripped = JSON.parse(JSON.stringify(settingsKo));
    delete stripped.notification_definitions.types.order_pending_deposit;
    delete stripped.notification_definitions.types.order_delivered;

    const { render, cleanup } = createLayoutTest(makeLayout(), {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: buildTranslations(stripped),
      initialData: {
        ecommerceNotificationDefinitions: { data: { data: DEFINITIONS } },
      },
    });

    await render();

    expect(screen.queryByText('무통장 입금 안내')).not.toBeInTheDocument();
    expect(
      screen.getByText(/notification_definitions\.types\.order_pending_deposit/),
    ).toBeInTheDocument();

    cleanup();
  });

  it('settings.json(ko/en) 의 types 에 두 신규 타입 라벨이 존재한다', () => {
    const koTypes = (settingsKo as any).notification_definitions.types;
    const enTypes = (settingsEn as any).notification_definitions.types;

    expect(koTypes.order_pending_deposit).toBe('무통장 입금 안내');
    expect(koTypes.order_delivered).toBe('배송 완료');
    expect(enTypes.order_pending_deposit).toBe('Bank Transfer Payment Guide');
    expect(enTypes.order_delivered).toBe('Order Delivered');
  });
});
