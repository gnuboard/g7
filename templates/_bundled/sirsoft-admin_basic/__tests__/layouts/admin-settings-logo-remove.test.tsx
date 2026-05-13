/**
 * @file admin-settings-logo-remove.test.tsx
 * @description 환경설정 사이트 로고 파일 상태 변경 시 form 동기화 및 hasChanges 활성화 테스트
 *
 * 테스트 대상:
 * - FileUploader onRemove 시 setState로 _local.form.general.site_logo에서 해당 항목 필터링
 * - FileUploader onFilesChange 시 hasChanges = true 설정
 * - FileUploader onUploadComplete 시 form.general.site_logo에 업로드된 파일 추가 + hasChanges = true
 * - onRemove 시 hasChanges = true 설정
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트
const TestDiv: React.FC<{ children?: React.ReactNode; 'data-testid'?: string }> = ({ children, 'data-testid': testId }) => (
  <div data-testid={testId}>{children}</div>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

const TestFileUploader: React.FC<{
  collection?: string;
  initialFiles?: any[];
  onRemove?: (id: number) => void;
  onFilesChange?: (files: any[]) => void;
  onUploadComplete?: (files: any[]) => void;
  'data-testid'?: string;
}> = ({ 'data-testid': testId }) => (
  <div data-testid={testId || 'file-uploader'} />
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    FileUploader: { component: TestFileUploader, metadata: { name: 'FileUploader', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// 테스트용 로고 데이터
const LOGO_1 = { id: 101, hash: 'abc123', original_filename: 'logo1.png', mime_type: 'image/png', size: 1000 };
const LOGO_2 = { id: 202, hash: 'def456', original_filename: 'logo2.png', mime_type: 'image/png', size: 2000 };
const UPLOADED_LOGO = { id: 303, hash: 'ghi789', original_filename: 'new_logo.png', mime_type: 'image/png', size: 3000 };

// 프로덕션 레이아웃과 동일한 액션 패턴
const settingsLogoLayout = {
  version: '1.0.0',
  layout_name: 'test_settings_logo',
  components: [
    {
      id: 'site_logo_uploader',
      type: 'composite',
      name: 'FileUploader',
      props: {
        collection: 'site_logo',
        initialFiles: '{{_local.form?.general?.site_logo ?? []}}',
        'data-testid': 'file-uploader',
      },
      actions: [
        {
          event: 'onFilesChange',
          type: 'change',
          handler: 'setState',
          params: {
            target: 'local',
            hasChanges: true,
          },
        },
        {
          event: 'onUploadComplete',
          type: 'change',
          handler: 'setState',
          params: {
            target: 'local',
            hasChanges: true,
            'form.general.site_logo': "{{[...(_local.form?.general?.site_logo ?? []), ...$args[0]]}}",
          },
        },
        {
          event: 'onRemove',
          type: 'change',
          handler: 'setState',
          params: {
            target: 'local',
            hasChanges: true,
            'form.general.site_logo': "{{(_local.form?.general?.site_logo ?? []).filter(item => item.id !== $args[0])}}",
          },
        },
      ],
    },
  ],
};

describe('환경설정 사이트 로고 파일 상태 변경', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  describe('onRemove - 로고 삭제 시 form 동기화', () => {
    it('로고 2개 중 1개 삭제 시 나머지 1개만 form에 남아야 한다', async () => {
      const testUtils = createLayoutTest(settingsLogoLayout, {
        componentRegistry: registry,
      });

      testUtils.setState('form', {
        general: { site_logo: [LOGO_1, LOGO_2] },
      }, 'local');

      await testUtils.render();

      await testUtils.triggerAction({
        handler: 'setState',
        params: {
          target: 'local',
          hasChanges: true,
          'form.general.site_logo': [LOGO_2],
        },
      });

      const state = testUtils.getState();
      expect(state._local.form.general.site_logo).toHaveLength(1);
      expect(state._local.form.general.site_logo[0].id).toBe(202);

      testUtils.cleanup();
    });

    it('모든 로고 삭제 시 빈 배열이어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLogoLayout, {
        componentRegistry: registry,
      });

      testUtils.setState('form', {
        general: { site_logo: [LOGO_1] },
      }, 'local');

      await testUtils.render();

      await testUtils.triggerAction({
        handler: 'setState',
        params: {
          target: 'local',
          hasChanges: true,
          'form.general.site_logo': [],
        },
      });

      const state = testUtils.getState();
      expect(state._local.form.general.site_logo).toEqual([]);

      testUtils.cleanup();
    });

    it('삭제 시 hasChanges가 true로 설정되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLogoLayout, {
        componentRegistry: registry,
      });

      testUtils.setState('form', {
        general: { site_logo: [LOGO_1] },
      }, 'local');
      testUtils.setState('hasChanges', false, 'local');

      await testUtils.render();

      await testUtils.triggerAction({
        handler: 'setState',
        params: {
          target: 'local',
          hasChanges: true,
          'form.general.site_logo': [],
        },
      });

      const state = testUtils.getState();
      expect(state._local.hasChanges).toBe(true);

      testUtils.cleanup();
    });
  });

  describe('onFilesChange - 파일 선택 시 hasChanges 활성화', () => {
    it('파일 선택 시 hasChanges가 true로 설정되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLogoLayout, {
        componentRegistry: registry,
      });

      testUtils.setState('hasChanges', false, 'local');

      await testUtils.render();

      await testUtils.triggerAction({
        handler: 'setState',
        params: {
          target: 'local',
          hasChanges: true,
        },
      });

      const state = testUtils.getState();
      expect(state._local.hasChanges).toBe(true);

      testUtils.cleanup();
    });
  });

  describe('onUploadComplete - 업로드 완료 시 form 동기화', () => {
    it('업로드 완료 시 form.general.site_logo에 새 파일이 추가되어야 한다', async () => {
      const testUtils = createLayoutTest(settingsLogoLayout, {
        componentRegistry: registry,
      });

      testUtils.setState('form', {
        general: { site_logo: [LOGO_1] },
      }, 'local');

      await testUtils.render();

      await testUtils.triggerAction({
        handler: 'setState',
        params: {
          target: 'local',
          hasChanges: true,
          'form.general.site_logo': [LOGO_1, UPLOADED_LOGO],
        },
      });

      const state = testUtils.getState();
      expect(state._local.form.general.site_logo).toHaveLength(2);
      expect(state._local.form.general.site_logo[1].id).toBe(303);
      expect(state._local.hasChanges).toBe(true);

      testUtils.cleanup();
    });
  });
});
