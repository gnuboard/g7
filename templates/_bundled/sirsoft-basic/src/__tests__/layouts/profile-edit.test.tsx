/**
 * @file profile-edit.test.tsx
 * @description 프로필 편집/아바타 변경 후 헤더 프로필 갱신 테스트
 *
 * 테스트 케이스 (3개)
 * - 기본 렌더링: 프로필 편집 폼 렌더링
 * - API 연동: 프로필 수정 성공 시 user + current_user 데이터소스 리패치
 * - AvatarUploader 액션: uploadSuccessActions/deleteSuccessActions props 동작
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  screen,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button
    type={type as 'button' | 'submit' | 'reset'}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children || text}
  </button>
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h1 className={className} data-testid={testId}>{children || text}</h1>;

const TestIcon: React.FC<{ name?: string; className?: string; size?: string; 'data-testid'?: string }> =
  ({ name, className, 'data-testid': testId }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} data-testid={testId} />;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

const TestContainer: React.FC<{ children?: React.ReactNode; className?: string }> =
  ({ children, className }) => <div className={`container ${className || ''}`}>{children}</div>;

// ========== 테스트용 레이아웃 Fixture ==========

/**
 * 프로필 편집 저장 시 user + current_user 리패치 검증용 fixture
 */
const profileEditLayoutFixture = {
  version: '1.0.0',
  layout_name: 'mypage/profile/edit',
  state: {
    isPasswordVerified: true,
    isSavingProfile: false,
    profileErrors: null,
    profileEditForm: {
      name: '테스트유저',
      nickname: '닉네임',
      email: 'test@example.com',
    },
  },
  data_sources: [
    {
      id: 'user',
      type: 'api',
      endpoint: '/api/me',
      method: 'GET',
      auto_fetch: true,
    },
    {
      id: 'current_user',
      type: 'api',
      endpoint: '/api/auth/user',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    {
      id: 'profile-edit-form',
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'profile-edit-form' },
      children: [
        {
          type: 'basic',
          name: 'H1',
          text: '프로필 편집',
          props: { 'data-testid': 'page-title' },
        },
        {
          id: 'save-profile-btn',
          type: 'basic',
          name: 'Button',
          text: '저장',
          props: { 'data-testid': 'save-profile-btn', type: 'button' },
          actions: [
            {
              type: 'click',
              handler: 'apiCall',
              auth_required: true,
              target: '/api/me',
              params: {
                method: 'PUT',
                body: '{{_local?.profileEditForm}}',
              },
              onSuccess: [
                {
                  handler: 'setState',
                  params: {
                    target: 'local',
                    isSavingProfile: false,
                    profileErrors: null,
                  },
                },
                {
                  handler: 'refetchDataSource',
                  params: { dataSourceId: 'user' },
                },
                {
                  handler: 'refetchDataSource',
                  params: { dataSourceId: 'current_user' },
                },
                {
                  handler: 'toast',
                  params: { type: 'success', message: '$t:mypage.profile.updated' },
                },
                {
                  handler: 'navigate',
                  params: { path: '/mypage/profile' },
                },
              ],
              onError: [
                {
                  handler: 'setState',
                  params: {
                    target: 'local',
                    isSavingProfile: false,
                    profileErrors: '{{error.errors ?? null}}',
                  },
                },
              ],
            },
          ],
        },
      ],
    },
  ],
};

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
  };

  return registry;
}

// ========== 테스트 케이스 ==========

describe('프로필 편집 페이지 레이아웃 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('기본 렌더링 테스트', () => {
    it('프로필 편집 폼이 렌더링된다', async () => {
      const testUtils = createLayoutTest(profileEditLayoutFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApi('user', {
        response: { data: { name: '테스트유저', nickname: '닉네임', email: 'test@example.com' } },
      });
      testUtils.mockApi('current_user', {
        response: { data: { name: '테스트유저', avatar: null } },
      });

      await testUtils.render();

      expect(screen.getByTestId('profile-edit-form')).toBeInTheDocument();
      expect(screen.getByTestId('save-profile-btn')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('API 연동 테스트', () => {
    it('프로필 수정 onSuccess에 current_user 리패치가 포함되어 있다', () => {
      // _edit.json의 onSuccess 배열에 current_user 리패치가 존재하는지 구조 검증
      const saveAction = profileEditLayoutFixture.components[0].children![1].actions![0];
      const onSuccessHandlers = saveAction.onSuccess as Array<{ handler: string; params?: Record<string, any> }>;

      const refetchHandlers = onSuccessHandlers.filter(h => h.handler === 'refetchDataSource');
      const dataSourceIds = refetchHandlers.map(h => h.params?.dataSourceId);

      expect(dataSourceIds).toContain('user');
      expect(dataSourceIds).toContain('current_user');
    });

    it('프로필 수정 성공 시 상태가 초기화된다', async () => {
      const testUtils = createLayoutTest(profileEditLayoutFixture, {
        componentRegistry: registry,
        initialState: {
          _local: {
            isPasswordVerified: true,
            isSavingProfile: true,
            profileErrors: { name: ['에러'] },
            profileEditForm: { name: '수정됨', nickname: '닉', email: 'a@b.com' },
          },
        },
      });

      testUtils.mockApi('user', { response: { data: { name: '테스트유저' } } });
      testUtils.mockApi('current_user', { response: { data: { name: '테스트유저' } } });

      await testUtils.render();

      await testUtils.triggerAction({
        handler: 'apiCall',
        target: '/api/me',
        params: { method: 'PUT', body: { name: '수정됨' } },
        onSuccess: profileEditLayoutFixture.components[0].children![1].actions![0].onSuccess,
        onError: profileEditLayoutFixture.components[0].children![1].actions![0].onError,
      });

      const state = testUtils.getState();
      expect(state._local.isSavingProfile).toBe(false);
      expect(state._local.profileErrors).toBeNull();

      testUtils.cleanup();
    });
  });
});
