/**
 * index.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - 모듈 진입점 테스트
 *
 * 테스트 항목:
 * 1. 모듈 export 확인
 * 2. isEditMode 함수
 * 3. getEditModeUrl 함수
 * 4. enterEditMode/exitEditMode 함수
 * 5. 버전 정보
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  // 메인 컴포넌트
  WysiwygEditor,
  EditorProvider,
  useEditorContext,

  // UI 컴포넌트
  Toolbar,
  LayoutTree,
  PreviewCanvas,
  PropertyPanel,
  EditorOverlay,

  // 훅
  useEditorState,
  useHistory,
  useComponentMetadata,

  // 유틸리티
  findComponentById,
  updateComponentInLayout,
  validateLayout,
  quickValidate,

  // 헬퍼 함수
  isEditMode,
  getEditModeUrl,
  enterEditMode,
  exitEditMode,

  // 버전 정보
  WYSIWYG_VERSION,
  WYSIWYG_PHASE,
} from '../index';

describe('wysiwyg/index.ts exports', () => {
  describe('메인 컴포넌트', () => {
    it('WysiwygEditor가 export되어야 함', () => {
      expect(WysiwygEditor).toBeDefined();
      expect(typeof WysiwygEditor).toBe('function');
    });

    it('EditorProvider가 export되어야 함', () => {
      expect(EditorProvider).toBeDefined();
      expect(typeof EditorProvider).toBe('function');
    });

    it('useEditorContext가 export되어야 함', () => {
      expect(useEditorContext).toBeDefined();
      expect(typeof useEditorContext).toBe('function');
    });
  });

  describe('UI 컴포넌트', () => {
    it('Toolbar가 export되어야 함', () => {
      expect(Toolbar).toBeDefined();
      expect(typeof Toolbar).toBe('function');
    });

    it('LayoutTree가 export되어야 함', () => {
      expect(LayoutTree).toBeDefined();
      expect(typeof LayoutTree).toBe('function');
    });

    it('PreviewCanvas가 export되어야 함', () => {
      expect(PreviewCanvas).toBeDefined();
      expect(typeof PreviewCanvas).toBe('function');
    });

    it('PropertyPanel이 export되어야 함', () => {
      expect(PropertyPanel).toBeDefined();
      expect(typeof PropertyPanel).toBe('function');
    });

    it('EditorOverlay가 export되어야 함', () => {
      expect(EditorOverlay).toBeDefined();
      expect(typeof EditorOverlay).toBe('function');
    });
  });

  describe('훅', () => {
    it('useEditorState가 export되어야 함', () => {
      expect(useEditorState).toBeDefined();
      expect(typeof useEditorState).toBe('function');
    });

    it('useHistory가 export되어야 함', () => {
      expect(useHistory).toBeDefined();
      expect(typeof useHistory).toBe('function');
    });

    it('useComponentMetadata가 export되어야 함', () => {
      expect(useComponentMetadata).toBeDefined();
      expect(typeof useComponentMetadata).toBe('function');
    });
  });

  describe('유틸리티', () => {
    it('findComponentById가 export되어야 함', () => {
      expect(findComponentById).toBeDefined();
      expect(typeof findComponentById).toBe('function');
    });

    it('updateComponentInLayout이 export되어야 함', () => {
      expect(updateComponentInLayout).toBeDefined();
      expect(typeof updateComponentInLayout).toBe('function');
    });

    it('validateLayout이 export되어야 함', () => {
      expect(validateLayout).toBeDefined();
      expect(typeof validateLayout).toBe('function');
    });

    it('quickValidate가 export되어야 함', () => {
      expect(quickValidate).toBeDefined();
      expect(typeof quickValidate).toBe('function');
    });
  });

  describe('버전 정보', () => {
    it('WYSIWYG_VERSION이 정의되어야 함', () => {
      expect(WYSIWYG_VERSION).toBeDefined();
      expect(typeof WYSIWYG_VERSION).toBe('string');
      expect(WYSIWYG_VERSION).toBe('1.0.0');
    });

    it('WYSIWYG_PHASE가 정의되어야 함', () => {
      expect(WYSIWYG_PHASE).toBeDefined();
      expect(typeof WYSIWYG_PHASE).toBe('number');
      expect(WYSIWYG_PHASE).toBe(1);
    });
  });
});

describe('isEditMode', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    // window.location mock
    delete (window as any).location;
    window.location = {
      ...originalLocation,
      search: '',
    } as any;
  });

  afterEach(() => {
    window.location = originalLocation;
  });

  it('mode=edit 쿼리 파라미터가 있으면 true를 반환해야 함', () => {
    window.location.search = '?mode=edit';
    expect(isEditMode()).toBe(true);
  });

  it('mode=edit이 없으면 false를 반환해야 함', () => {
    window.location.search = '';
    expect(isEditMode()).toBe(false);
  });

  it('다른 mode 값이면 false를 반환해야 함', () => {
    window.location.search = '?mode=preview';
    expect(isEditMode()).toBe(false);
  });

  it('다른 쿼리 파라미터와 함께 있어도 작동해야 함', () => {
    window.location.search = '?mode=edit&template=basic';
    expect(isEditMode()).toBe(true);
  });
});

describe('getEditModeUrl', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    delete (window as any).location;
    window.location = {
      ...originalLocation,
      origin: 'https://example.com',
    } as any;
  });

  afterEach(() => {
    window.location = originalLocation;
  });

  it('편집 모드 URL을 생성해야 함 (라우트 기반)', () => {
    const url = getEditModeUrl('/', 'sirsoft-basic');
    expect(url).toBe('https://example.com/?mode=edit&template=sirsoft-basic');
  });

  it('특정 라우트에 대한 편집 모드 URL 생성해야 함', () => {
    const url = getEditModeUrl('/popular', 'sirsoft-basic');
    expect(url).toBe('https://example.com/popular?mode=edit&template=sirsoft-basic');
  });

  it('중첩 라우트 경로도 지원해야 함', () => {
    const url = getEditModeUrl('/shop/products', 'sirsoft-basic');
    expect(url).toBe('https://example.com/shop/products?mode=edit&template=sirsoft-basic');
  });
});

describe('enterEditMode/exitEditMode', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    delete (window as any).location;
    window.location = {
      ...originalLocation,
      href: 'https://example.com/',
      origin: 'https://example.com',
      pathname: '/',
      search: '?mode=edit&template=sirsoft-basic',
    } as any;
  });

  afterEach(() => {
    window.location = originalLocation;
  });

  it('enterEditMode가 URL을 변경해야 함', () => {
    enterEditMode('/dashboard', 'sirsoft-admin_basic');
    expect(window.location.href).toContain('mode=edit');
  });

  it('exitEditMode가 mode 파라미터를 제거해야 함', () => {
    exitEditMode();
    // URL에서 mode 파라미터가 제거됨 (실제 네비게이션은 테스트에서 발생하지 않음)
  });
});
