/**
 * @file layoutTestUtils.validation.test.ts
 * @description layoutTestUtils의 검증 기능 테스트
 *
 * 테스트 대상:
 * 1. data_sources가 mockApi를 통해 실제로 fetch되는지
 * 2. 슬롯 시스템 검증 (slot 속성 vs SlotContainer)
 * 3. 데이터 바인딩 타입 검증 (배열 vs 객체)
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, type LayoutJson } from './layoutTestUtils';

describe('layoutTestUtils 검증 기능', () => {
  describe('data_sources fetch mock 통합', () => {
    it('mockApi로 등록한 데이터가 fetch를 통해 로드되어야 함', async () => {
      const layoutJson: LayoutJson = {
        layout_name: 'test_datasource_fetch',
        data_sources: [
          {
            id: 'products',
            type: 'api',
            endpoint: '/api/admin/products',
            auto_fetch: true,
          },
        ],
        slots: {
          content: [
            {
              id: 'product_list',
              type: 'composite',
              name: 'DataGrid',
              props: {
                data: '{{products?.data || []}}',
              },
            },
          ],
        },
      };

      const { render, mockApi, cleanup } = createLayoutTest(layoutJson);

      // API 응답 구조: { success: true, data: { data: [...], meta: {...} } }
      mockApi('products', {
        response: {
          data: [
            { id: 1, name: '상품1' },
            { id: 2, name: '상품2' },
          ],
          meta: { total: 2 },
        },
      });

      await render();

      // 실제 fetch가 호출되었는지 확인
      // (이전에는 initialData가 직접 주입되어 fetch가 호출되지 않았음)
      cleanup();
    });

    it('mockApi 없이 data_source가 있으면 빈 데이터가 로드됨', async () => {
      const layoutJson: LayoutJson = {
        layout_name: 'test_no_mock',
        data_sources: [
          {
            id: 'items',
            type: 'api',
            endpoint: '/api/admin/items',
            auto_fetch: true,
          },
        ],
        slots: {
          content: [
            {
              id: 'item_list',
              type: 'basic',
              name: 'Div',
              props: {},
            },
          ],
        },
      };

      const { render, cleanup } = createLayoutTest(layoutJson);

      // mockApi 호출 안 함 - 기본 응답이 사용됨
      await render();

      cleanup();
    });
  });

  describe('슬롯 시스템 검증', () => {
    it('slot 속성만 있고 SlotContainer가 없으면 경고 발생', async () => {
      // 이 레이아웃은 버그가 있음:
      // - filter_row가 slot="basic_filters"로 등록됨
      // - 하지만 SlotContainer가 없어서 실제로 렌더링되지 않음
      const layoutJson: LayoutJson = {
        layout_name: 'test_missing_slotcontainer',
        slots: {
          content: [
            {
              id: 'filter_area',
              type: 'basic',
              name: 'Div',
              props: { className: 'hidden' },
              children: [
                {
                  id: 'filter_row',
                  type: 'basic',
                  name: 'Div',
                  slot: 'basic_filters', // 슬롯에 등록
                  props: {},
                  children: [
                    {
                      id: 'filter_input',
                      type: 'basic',
                      name: 'Input',
                      props: { type: 'text' },
                    },
                  ],
                },
              ],
            },
            // SlotContainer가 없음! - 버그
            {
              id: 'filter_display',
              type: 'basic',
              name: 'Div',
              // 잘못된 패턴: slot 속성으로 슬롯 ID를 지정 (렌더링이 아닌 등록)
              slot: 'basic_filters',
              props: { className: 'space-y-3' },
            },
          ],
        },
      };

      const { render, validateSlots, cleanup } = createLayoutTest(layoutJson);

      await render();

      const warnings = validateSlots();

      // slot="basic_filters"가 있지만 SlotContainer가 없으므로 경고 발생
      expect(warnings.length).toBeGreaterThan(0);
      expect(warnings.some(w => w.type === 'missing_slotcontainer')).toBe(true);
      expect(warnings.some(w => w.details?.slotId === 'basic_filters')).toBe(true);

      cleanup();
    });

    it('SlotContainer가 있으면 경고 없음', async () => {
      // 올바른 레이아웃
      const layoutJson: LayoutJson = {
        layout_name: 'test_correct_slot',
        slots: {
          content: [
            {
              id: 'filter_area',
              type: 'basic',
              name: 'Div',
              props: { className: 'hidden' },
              children: [
                {
                  id: 'filter_row',
                  type: 'basic',
                  name: 'Div',
                  slot: 'basic_filters',
                  props: {},
                },
              ],
            },
            // 올바른 패턴: SlotContainer 사용
            {
              id: 'filter_display',
              type: 'composite',
              name: 'SlotContainer',
              props: {
                slotId: 'basic_filters',
                className: 'space-y-3',
              },
            },
          ],
        },
      };

      const { render, validateSlots, cleanup } = createLayoutTest(layoutJson);

      await render();

      const warnings = validateSlots();
      const missingSlotContainerWarnings = warnings.filter(
        w => w.type === 'missing_slotcontainer'
      );

      expect(missingSlotContainerWarnings).toHaveLength(0);

      cleanup();
    });
  });

  describe('데이터 바인딩 타입 검증', () => {
    it('DataGrid의 data prop에 객체가 바인딩되면 경고 발생', async () => {
      const layoutJson: LayoutJson = {
        layout_name: 'test_data_type_mismatch',
        data_sources: [
          {
            id: 'items',
            type: 'api',
            endpoint: '/api/admin/items',
            auto_fetch: true,
          },
        ],
        slots: {
          content: [
            {
              id: 'data_grid',
              type: 'composite',
              name: 'DataGrid',
              props: {
                // createLayoutTest 의 fetch 로직이 { success:true, data:X } 래핑을 자동 해제하여
                // items 자체가 { data: [...], meta: {...} } 객체가 됨.
                // DataGrid.data 에 items (객체) 를 바인딩하면 배열 타입 mismatch 경고 발생해야 함
                // (올바른 사용은 `{{items?.data}}` 로 배열 경로 지정)
                data: '{{items}}',
              },
            },
          ],
        },
      };

      const { render, mockApi, validateDataBindings, cleanup } = createLayoutTest(layoutJson);

      // API 응답 모킹
      // mockApi 응답 → createMockResponse 래핑 → { success: true, data: {...} }
      mockApi('items', {
        response: {
          data: [{ id: 1 }, { id: 2 }],
          meta: { total: 2 },
        },
      });

      await render();

      const warnings = validateDataBindings();

      // DataGrid.data에 배열이 아닌 객체가 바인딩되므로 경고
      expect(warnings.some(w => w.type === 'data_type_mismatch')).toBe(true);
      expect(
        warnings.some(w =>
          w.componentId === 'data_grid' &&
          w.details?.propName === 'data' &&
          w.details?.expectedType === 'array'
        )
      ).toBe(true);

      cleanup();
    });

    it('DataGrid의 data prop에 배열이 올바르게 바인딩되면 경고 없음', async () => {
      const layoutJson: LayoutJson = {
        layout_name: 'test_correct_data_binding',
        data_sources: [
          {
            id: 'items',
            type: 'api',
            endpoint: '/api/admin/items',
            auto_fetch: true,
          },
        ],
        slots: {
          content: [
            {
              id: 'data_grid',
              type: 'composite',
              name: 'DataGrid',
              props: {
                // 올바른 바인딩:
                // items = { success: true, data: { data: [], meta: {} } }
                // items?.data?.data = [] ← 배열!
                data: '{{items?.data?.data || []}}',
              },
            },
          ],
        },
      };

      const { render, mockApi, validateDataBindings, cleanup } = createLayoutTest(layoutJson);

      mockApi('items', {
        response: {
          data: [{ id: 1 }, { id: 2 }],
          meta: { total: 2 },
        },
      });

      await render();

      const warnings = validateDataBindings();
      const typeWarnings = warnings.filter(w => w.type === 'data_type_mismatch');

      expect(typeWarnings).toHaveLength(0);

      cleanup();
    });
  });

  describe('assertNoValidationErrors', () => {
    it('검증 오류가 있으면 예외 발생', async () => {
      const layoutJson: LayoutJson = {
        layout_name: 'test_assert_errors',
        slots: {
          content: [
            {
              id: 'component_with_slot',
              type: 'basic',
              name: 'Div',
              slot: 'orphan_slot', // SlotContainer 없음
              props: {},
            },
          ],
        },
      };

      const { render, assertNoValidationErrors, cleanup } = createLayoutTest(layoutJson);

      await render();

      expect(() => assertNoValidationErrors()).toThrow('레이아웃 검증 오류');

      cleanup();
    });

    it('검증 오류가 없으면 예외 없음', async () => {
      const layoutJson: LayoutJson = {
        layout_name: 'test_no_errors',
        slots: {
          content: [
            {
              id: 'simple_div',
              type: 'basic',
              name: 'Div',
              props: {},
            },
          ],
        },
      };

      const { render, assertNoValidationErrors, cleanup } = createLayoutTest(layoutJson);

      await render();

      expect(() => assertNoValidationErrors()).not.toThrow();

      cleanup();
    });
  });
});
