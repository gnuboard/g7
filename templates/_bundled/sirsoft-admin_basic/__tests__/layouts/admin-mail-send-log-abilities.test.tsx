/**
 * @file admin-mail-send-log-abilities.test.tsx
 * @description 메일 발송 이력 관리 페이지 abilities 기반 권한 제어 렌더링 테스트
 *
 * 테스트 대상:
 * - collection-level abilities (can_delete) → 일괄 삭제 버튼 disabled
 * - DataGrid selectable 조건 (can_delete)
 * - per-row abilities → 삭제 rowAction disabledField
 * - errorHandling 403 데이터소스 설정 존재 여부
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * collection-level abilities 테스트 레이아웃
 * - bulk_action_bar: 선택 시에만 표시 (if 조건), 삭제 버튼은 can_delete로 disabled
 * - selectable → 체크박스 표시 여부 제어
 */
const collectionAbilitiesLayout = {
  version: '1.0.0',
  layout_name: 'test_mail_send_log_collection_abilities',
  data_sources: [
    {
      id: 'mailSendLogs',
      type: 'api',
      endpoint: '/api/admin/mail-send-logs',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
      fallback: {
        data: {
          data: {
            data: [],
            abilities: { can_delete: false },
            pagination: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
          },
        },
      },
      errorHandling: {
        '403': {
          handler: 'showErrorPage',
          params: { target: 'content' },
        },
      },
    },
  ],
  state: {},
  components: [
    {
      id: 'page_content',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'bulk_action_bar',
          type: 'basic',
          name: 'Div',
          props: {
            'data-testid': 'bulk-action-bar',
          },
          children: [
            {
              type: 'basic',
              name: 'Span',
              props: {
                'data-testid': 'selected-count',
              },
              text: '{{(_global.selectedIds_admin_mail_send_logs ?? []).length}}건 선택됨',
            },
            {
              id: 'bulk_delete_button',
              type: 'basic',
              name: 'Button',
              props: {
                type: 'button',
                disabled: '{{(_global.selectedIds_admin_mail_send_logs ?? []).length === 0 || mailSendLogs?.data?.abilities?.can_delete !== true}}',
                'data-testid': 'bulk-delete-button',
              },
              text: '선택 삭제',
            },
          ],
        },
        {
          id: 'selectable_indicator',
          type: 'basic',
          name: 'Span',
          props: {
            'data-testid': 'selectable-indicator',
          },
          text: '{{mailSendLogs?.data?.abilities?.can_delete === true ? "selectable" : "not-selectable"}}',
        },
      ],
    },
  ],
};

/**
 * per-row abilities 테스트 레이아웃
 * DataGrid의 row context를 _local.row로 시뮬레이션
 */
const rowAbilitiesLayout = {
  version: '1.0.0',
  layout_name: 'test_mail_send_log_row_abilities',
  data_sources: [],
  state: {
    row: {
      id: 1,
      abilities: { can_delete: false },
    },
  },
  components: [
    {
      id: 'row_content',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'row_delete_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{_local.row.abilities?.can_delete !== true}}',
            'data-testid': 'row-delete-button',
          },
          text: '삭제',
        },
      ],
    },
  ],
};

// Mock API 응답 생성 헬퍼
// 테스트 유틸리티는 API 응답에서 data 필드를 추출하여 dataContext에 저장
// 실제 엔진에서는 mailSendLogs = { loading, data: <api_data>, error } 형태
// 테스트 유틸리티에서는 mailSendLogs = <api_data> 형태로 저장
// 레이아웃에서 mailSendLogs?.data?.abilities로 접근하므로
// 테스트 mock에서도 data 안에 중첩된 data/abilities가 있어야 함
function createMailSendLogsResponse(canDelete: boolean) {
  return {
    data: {
      data: [
        {
          id: 1,
          recipient_email: 'test@example.com',
          recipient_name: '테스트',
          subject: '테스트 메일',
          status: 'sent',
          sent_at: '2026-03-10 10:00:00',
          abilities: { can_delete: canDelete },
        },
      ],
      abilities: { can_delete: canDelete },
      pagination: {
        current_page: 1,
        last_page: 1,
        per_page: 20,
        total: 1,
      },
    },
  };
}

describe('메일 발송 이력 관리 - abilities 기반 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('collection-level abilities - can_delete', () => {
    it('can_delete=true → selectable 활성화 표시', async () => {
      const testUtils = createLayoutTest(collectionAbilitiesLayout);
      testUtils.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(true),
      });

      await testUtils.render();

      // progressive 로딩 후 API 응답이 반영될 때까지 대기
      await waitFor(() => {
        const indicator = screen.getByTestId('selectable-indicator');
        expect(indicator.textContent).toBe('selectable');
      });

      testUtils.cleanup();
    });

    it('can_delete=false → selectable 비활성화 표시', async () => {
      const testUtils = createLayoutTest(collectionAbilitiesLayout);
      testUtils.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(false),
      });

      await testUtils.render();

      const indicator = screen.getByTestId('selectable-indicator');
      expect(indicator.textContent).toBe('not-selectable');

      testUtils.cleanup();
    });

    it('bulk_action_bar 상시 표시 + 선택 개수 표시', async () => {
      const testUtils = createLayoutTest(collectionAbilitiesLayout);
      testUtils.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(true),
      });

      await testUtils.render();

      // 선택 항목 없어도 bulk_action_bar 항상 표시
      expect(screen.getByTestId('bulk-action-bar')).toBeTruthy();
      // 선택 개수 0 표시
      const selectedCount = screen.getByTestId('selected-count');
      expect(selectedCount.textContent).toContain('0');

      testUtils.cleanup();
    });

    it('선택 항목 없음 → 일괄 삭제 버튼 비활성화', async () => {
      const testUtils = createLayoutTest(collectionAbilitiesLayout);
      testUtils.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(true),
      });

      await testUtils.render();

      // can_delete=true여도 선택 항목 없으면 disabled
      await waitFor(() => {
        const bulkDeleteBtn = screen.getByTestId('bulk-delete-button');
        expect(bulkDeleteBtn).toBeDisabled();
      });

      testUtils.cleanup();
    });

    it('can_delete=true + 선택 항목 있음 → 일괄 삭제 버튼 활성화', async () => {
      const testUtils = createLayoutTest(collectionAbilitiesLayout);
      testUtils.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(true),
      });

      testUtils.setState('selectedIds_admin_mail_send_logs', [1], 'global');
      await testUtils.render();

      // progressive 로딩 후 API 응답이 반영될 때까지 대기
      await waitFor(() => {
        const bulkDeleteBtn = screen.getByTestId('bulk-delete-button');
        expect(bulkDeleteBtn).not.toBeDisabled();
      });

      testUtils.cleanup();
    });

    it('can_delete=false + 선택 항목 있음 → 일괄 삭제 버튼 비활성화', async () => {
      const testUtils = createLayoutTest(collectionAbilitiesLayout);
      testUtils.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(false),
      });

      testUtils.setState('selectedIds_admin_mail_send_logs', [1], 'global');
      await testUtils.render();

      const bulkDeleteBtn = screen.getByTestId('bulk-delete-button');
      expect(bulkDeleteBtn).toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('per-row abilities - can_delete', () => {
    it('can_delete=true → 행 삭제 버튼 활성화', async () => {
      const layout = {
        ...rowAbilitiesLayout,
        layout_name: 'test_row_can_delete',
        state: {
          row: {
            id: 1,
            abilities: { can_delete: true },
          },
        },
      };

      const testUtils = createLayoutTest(layout);
      await testUtils.render();

      expect(screen.getByTestId('row-delete-button')).not.toBeDisabled();

      testUtils.cleanup();
    });

    it('can_delete=false → 행 삭제 버튼 비활성화', async () => {
      const testUtils = createLayoutTest(rowAbilitiesLayout);
      await testUtils.render();

      expect(screen.getByTestId('row-delete-button')).toBeDisabled();

      testUtils.cleanup();
    });
  });

  describe('errorHandling 설정 검증', () => {
    it('데이터소스에 403 errorHandling이 설정되어 있음', () => {
      const dataSource = collectionAbilitiesLayout.data_sources[0];
      expect(dataSource.errorHandling).toBeDefined();
      expect(dataSource.errorHandling['403']).toBeDefined();
      expect(dataSource.errorHandling['403'].handler).toBe('showErrorPage');
      expect(dataSource.errorHandling['403'].params.target).toBe('content');
    });
  });

  describe('읽기 전용 사용자 (삭제 권한 없음)', () => {
    it('collection + row 모든 삭제 관련 기능 비활성화', async () => {
      // collection-level
      const collectionTest = createLayoutTest(collectionAbilitiesLayout);
      collectionTest.mockApi('mail_send_logs', {
        response: createMailSendLogsResponse(false),
      });

      await collectionTest.render();

      const indicator = screen.getByTestId('selectable-indicator');
      expect(indicator.textContent).toBe('not-selectable');

      collectionTest.cleanup();

      // row-level
      const rowTest = createLayoutTest(rowAbilitiesLayout);
      await rowTest.render();

      expect(screen.getByTestId('row-delete-button')).toBeDisabled();

      rowTest.cleanup();
    });
  });
});
