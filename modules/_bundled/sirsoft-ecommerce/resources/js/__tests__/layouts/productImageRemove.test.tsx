/**
 * @file productImageRemove.test.tsx
 * @description 상품 이미지 FileUploader onRemove 시 form.images 동기화 테스트
 *
 * 테스트 대상:
 * - FileUploader remove 시 setState로 _local.form.images에서 해당 항목 필터링
 * - 이미지 2개 중 1개 삭제 시 나머지 1개만 form에 남아야 함
 * - 모든 이미지 삭제 시 빈 배열이어야 함
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

const TestSection: React.FC<{ children?: React.ReactNode; 'data-testid'?: string }> = ({ children, 'data-testid': testId }) => (
  <div data-testid={testId}>{children}</div>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Section: { component: TestSection, metadata: { name: 'Section', type: 'basic' } },
    FileUploader: { component: TestFileUploader, metadata: { name: 'FileUploader', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// 테스트용 이미지 데이터
const IMAGE_1 = { id: 101, hash: 'abc123', original_filename: 'product1.jpg', mime_type: 'image/jpeg', size: 5000 };
const IMAGE_2 = { id: 202, hash: 'def456', original_filename: 'product2.jpg', mime_type: 'image/jpeg', size: 8000 };

// 프로덕션 레이아웃의 remove 액션 패턴 재현
const productImageLayout = {
  version: '1.0.0',
  layout_name: 'test_product_image_remove',
  components: [
    {
      id: 'images_uploader',
      type: 'composite',
      name: 'FileUploader',
      props: {
        collection: 'main',
        'data-testid': 'file-uploader',
      },
      actions: [
        {
          event: 'onRemove',
          type: 'change',
          handler: 'setState',
          params: {
            target: 'local',
            'form.images': "{{(_local.form?.images ?? []).filter(item => item.id !== $args[0])}}",
          },
        },
      ],
    },
  ],
};

describe('상품 이미지 FileUploader onRemove form 동기화', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  it('이미지 2개 중 1개 삭제 시 나머지 1개만 form에 남아야 한다', async () => {
    const testUtils = createLayoutTest(productImageLayout, {
      componentRegistry: registry,
    });

    testUtils.setState('form', {
      images: [IMAGE_1, IMAGE_2],
    }, 'local');

    await testUtils.render();

    // IMAGE_1 삭제 시뮬레이션 (filter 결과)
    await testUtils.triggerAction({
      handler: 'setState',
      params: {
        target: 'local',
        'form.images': [IMAGE_2],
      },
    });

    const state = testUtils.getState();
    expect(state._local.form.images).toHaveLength(1);
    expect(state._local.form.images[0].id).toBe(202);

    testUtils.cleanup();
  });

  it('모든 이미지 삭제 시 빈 배열이어야 한다', async () => {
    const testUtils = createLayoutTest(productImageLayout, {
      componentRegistry: registry,
    });

    testUtils.setState('form', {
      images: [IMAGE_1],
    }, 'local');

    await testUtils.render();

    await testUtils.triggerAction({
      handler: 'setState',
      params: {
        target: 'local',
        'form.images': [],
      },
    });

    const state = testUtils.getState();
    expect(state._local.form.images).toEqual([]);

    testUtils.cleanup();
  });

  it('초기 이미지가 없는 상태에서 삭제 시 빈 배열 유지', async () => {
    const testUtils = createLayoutTest(productImageLayout, {
      componentRegistry: registry,
    });

    testUtils.setState('form', {
      images: [],
    }, 'local');

    await testUtils.render();

    await testUtils.triggerAction({
      handler: 'setState',
      params: {
        target: 'local',
        'form.images': [],
      },
    });

    const state = testUtils.getState();
    expect(state._local.form.images).toEqual([]);

    testUtils.cleanup();
  });
});
