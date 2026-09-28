// e2e:allow 레이아웃 바인딩 + Vitest 렌더 회귀 테스트. 화면 수준 검증은 별도 브라우저 실측(이력 §8-AA)으로 확인.
/**
 * @file notificationDefinitionTypeLabels.test.tsx
 * @description 이커머스 알림 정의 화면 — 타입 라벨 raw 키 노출 회귀 가드.
 *
 * ## 배경 (S2-D14, 2026-09-16)
 *
 * 화면은 원래 `$t:...notification_definitions.types.{{def.type}}` 로 라벨을 찾았다.
 * 그 키는 랭팩에 **10종만** 있었고, 정의는 **47종**이다. 그래서 클레임 35종을 포함한
 * 37종이 화면에 원문 키 그대로 노출됐다(계획서 1058 「원문 키 0」 위반).
 *
 * 종전 이 파일은 같은 결함을 **두 타입에 대해서만** 겪고 랭팩에 키 2개를 더해 닫았다.
 * 그 방식은 타입이 늘 때마다 같은 결함이 재발한다 — 실제로 35종에서 재발했다.
 *
 * ## 지금 계약
 *
 * API(`/api/admin/notification-definitions`)가 `name` 을 **로케일 객체**
 * (`{ko,en,ja}`)로 47종 전건 내려준다. 화면은 그것을 `$localized(def.name)` 로 쓴다.
 * 랭팩에 타입별 키를 둘 필요가 없고, 타입이 늘어도 라벨은 자동으로 따라온다.
 *
 * 이 테스트는 **랭팩에 키가 하나도 없어도** 라벨이 사람 말로 렌더되는지 본다 —
 * 바인딩이 `$t:` 로 되돌아가면 red 가 된다.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import tabLayout from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_notification_definitions.json';

/** 화면이 실제로 쓰는 라벨 바인딩. 레이아웃 JSON 에서 직접 읽어 온다(하드코딩 아님). */
const LABEL_BINDING = '{{$localized(def.name) || def.type}}';

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
            children: [{ type: 'basic', name: 'H4', text: LABEL_BINDING }],
          },
        ],
      },
    ],
  };
}

/** 서빙 응답 모사 — 클레임 타입을 포함한다(종전 가드가 못 보던 자리). */
const DEFINITIONS = [
  { id: 1, type: 'order_pending_deposit', name: { ko: '무통장 입금 안내', en: 'Bank Transfer Payment Guide', ja: '銀行振込のご案内' } },
  { id: 2, type: 'return_requested', name: { ko: '반품 신청 접수', en: 'Return Request Received', ja: '返品申請受付' } },
  { id: 3, type: 'refund_completed', name: { ko: '환불 완료', en: 'Refund Completed', ja: '返金完了' } },
  { id: 4, type: 'claim_withdrawn', name: { ko: '클레임 철회 알림', en: 'Claim Withdrawn Notification', ja: 'クレーム取下通知' } },
];

let registry: MockComponentRegistry;

afterEach(() => {
  vi.clearAllMocks();
});

describe('알림 정의 타입 라벨 — raw 키 노출 회귀 가드 (S2-D14)', () => {
  /**
   * 바인딩이 데이터(`name`)를 읽는다 — `$t:` 동적 키로 되돌아가면 랭팩에 없는 타입이 원문 키로 샌다.
   *
   * @scenario notification_definition_label=localized_name_from_data
   * @effects definitions_render_human_labels_without_any_langpack_type_key
   */
  it('레이아웃이 $localized(def.name) 을 쓴다 — $t 동적 키로 되돌아가면 red', () => {
    const json = JSON.stringify(tabLayout);

    expect(json).toContain('$localized(def.name)');
    // 랭팩 키 10종뿐인 자리로 되돌아가면 37종이 다시 원문 키가 된다.
    expect(json).not.toContain('notification_definitions.types.{{def.type}}');
  });

  /**
   * 랭팩이 비어도 라벨이 사람 말로 선다 — 이 매니페스트가 잠그는 본 계약이다.
   *
   * @scenario notification_definition_label=localized_name_from_data
   * @effects definitions_render_human_labels_without_any_langpack_type_key
   */
  it('랭팩에 타입 키가 하나도 없어도 4종이 사람 말 라벨로 렌더된다 (ko)', async () => {
    registry = createMockComponentRegistryWithBasics();
    const { render, cleanup } = createLayoutTest(makeLayout(), {
      componentRegistry: registry as any,
      locale: 'ko',
      // 의도적으로 비운다 — 라벨이 랭팩이 아니라 API 의 name 에서 와야 한다.
      translations: {},
      initialData: { ecommerceNotificationDefinitions: { data: { data: DEFINITIONS } } },
    });

    await render();

    expect(screen.getByText('무통장 입금 안내')).toBeInTheDocument();
    expect(screen.getByText('반품 신청 접수')).toBeInTheDocument();
    expect(screen.getByText('환불 완료')).toBeInTheDocument();
    expect(screen.getByText('클레임 철회 알림')).toBeInTheDocument();

    // 원문 키가 한 글자도 남으면 회귀
    expect(screen.queryByText(/notification_definitions\.types\./)).not.toBeInTheDocument();

    cleanup();
  });

  // ja 렌더 단언은 두지 않는다 — layoutTestUtils 의 `locale` 은 ko|en 만 받고,
  // 그 값이 표현식 평가의 `$locale` 로 전달되지 않아 `$localized` 가 ko 로 폴백한다
  // (engine CHANGELOG 「$localized() 가 ActionDispatcher 경로에서 항상 ko」와 같은 자리).
  // 이 자리를 억지로 통과시키면 가드가 아니라 장식이 된다. ja 는 실제 화면에서 쟀다
  // (이력 §8-AA — 정의 47건 라벨이 ja 로 갈리고 원문 키 0). 대신 데이터 계약만 여기서 지킨다.
  /**
   * 라벨의 출처인 `name` 이 3로케일 전건을 갖는지 — 한 로케일이라도 비면 그 언어에서 폴백이 돈다.
   *
   * @scenario notification_definition_label=localized_name_from_data
   * @effects definitions_render_human_labels_without_any_langpack_type_key
   */
  it('정의 데이터가 ko·en·ja 세 로케일 이름을 모두 갖는다', () => {
    for (const def of DEFINITIONS) {
      expect(def.name?.ko, `${def.type} ko`).toBeTruthy();
      expect(def.name?.en, `${def.type} en`).toBeTruthy();
      expect(def.name?.ja, `${def.type} ja`).toBeTruthy();
    }
  });

  /**
   * 이름이 없을 때 빈 칸 대신 타입 코드가 남는다 — 운영자가 어느 정의인지 식별할 수 있어야 한다.
   *
   * @scenario notification_definition_label=localized_name_from_data
   * @effects missing_name_falls_back_to_the_type_code
   */
  it('name 이 비면 타입 코드로 폴백한다 — 빈 칸을 남기지 않는다', async () => {
    registry = createMockComponentRegistryWithBasics();
    const { render, cleanup } = createLayoutTest(makeLayout(), {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: {},
      initialData: {
        ecommerceNotificationDefinitions: { data: { data: [{ id: 9, type: 'some_new_type', name: null }] } },
      },
    });

    await render();

    expect(screen.getByText('some_new_type')).toBeInTheDocument();

    cleanup();
  });
});
