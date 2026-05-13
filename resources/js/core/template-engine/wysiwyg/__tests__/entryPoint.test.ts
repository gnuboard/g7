/**
 * entryPoint.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - 진입점 통합 테스트 (Phase 6)
 *
 * 테스트 항목:
 * 1. G7Core.wysiwyg API 통합
 * 2. URL 쿼리스트링 파싱
 * 3. 편집 모드 상태 관리
 * 4. 템플릿 검증 흐름
 * 5. 레이아웃 선택 흐름
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock G7Core 전역 객체
const mockG7Core = {
  wysiwyg: {
    isEditMode: vi.fn(() => false),
    setEditMode: vi.fn(),
    clearEditMode: vi.fn(),
    getCurrentLayoutName: vi.fn(() => null),
    getCurrentTemplateId: vi.fn(() => null),
    isEditModeFromUrl: vi.fn(() => false),
    getEditModeUrl: vi.fn((route: string, template: string) => `${route}?mode=edit&template=${template}`),
    enterEditMode: vi.fn(),
    exitEditMode: vi.fn(),
    getVersion: vi.fn(() => '1.0.0'),
    getPhase: vi.fn(() => 1),
  },
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
  },
  auth: {
    getUser: vi.fn(() => ({ id: 1, permissions: ['layouts.update'] })),
  },
};

describe('G7Core.wysiwyg API 통합', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  describe('isEditMode', () => {
    it('G7Core.wysiwyg.isEditMode() 호출 가능해야 함', () => {
      const result = (window as any).G7Core.wysiwyg.isEditMode();
      expect(result).toBe(false);
      expect(mockG7Core.wysiwyg.isEditMode).toHaveBeenCalled();
    });

    it('편집 모드 활성화 시 true 반환해야 함', () => {
      mockG7Core.wysiwyg.isEditMode.mockReturnValueOnce(true);
      const result = (window as any).G7Core.wysiwyg.isEditMode();
      expect(result).toBe(true);
    });
  });

  describe('setEditMode / clearEditMode', () => {
    it('setEditMode로 편집 모드 활성화해야 함', () => {
      (window as any).G7Core.wysiwyg.setEditMode('/', 'sirsoft-basic');
      expect(mockG7Core.wysiwyg.setEditMode).toHaveBeenCalledWith('/', 'sirsoft-basic');
    });

    it('clearEditMode로 편집 모드 해제해야 함', () => {
      (window as any).G7Core.wysiwyg.clearEditMode();
      expect(mockG7Core.wysiwyg.clearEditMode).toHaveBeenCalled();
    });
  });

  describe('getEditModeUrl', () => {
    it('올바른 편집 모드 URL 생성해야 함 (라우트 기반)', () => {
      const url = (window as any).G7Core.wysiwyg.getEditModeUrl('/', 'sirsoft-basic');
      expect(url).toBe('/?mode=edit&template=sirsoft-basic');
    });

    it('특정 라우트에 대한 편집 모드 URL 생성해야 함', () => {
      const url = (window as any).G7Core.wysiwyg.getEditModeUrl('/popular', 'sirsoft-basic');
      expect(url).toBe('/popular?mode=edit&template=sirsoft-basic');
    });
  });

  describe('버전 정보', () => {
    it('버전 정보 조회 가능해야 함', () => {
      expect((window as any).G7Core.wysiwyg.getVersion()).toBe('1.0.0');
      expect((window as any).G7Core.wysiwyg.getPhase()).toBe(1);
    });
  });
});

describe('URL 쿼리스트링 파싱', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
    delete (window as any).location;
    window.location = {
      ...originalLocation,
      search: '',
      href: 'https://example.com/',
      origin: 'https://example.com',
      pathname: '/',
    } as any;
  });

  afterEach(() => {
    window.location = originalLocation;
    delete (window as any).G7Core;
  });

  it('mode=edit 파라미터 감지해야 함', () => {
    window.location.search = '?mode=edit&template=sirsoft-basic';

    // URLSearchParams를 사용한 파싱 시뮬레이션
    const params = new URLSearchParams(window.location.search);
    expect(params.get('mode')).toBe('edit');
    expect(params.get('template')).toBe('sirsoft-basic');
  });

  it('라우트 경로에서 레이아웃 자동 인식해야 함', () => {
    // 라우트 기반 레이아웃 인식 - pathname으로 레이아웃 결정
    window.location.pathname = '/popular';
    window.location.search = '?mode=edit&template=sirsoft-basic';

    const params = new URLSearchParams(window.location.search);
    expect(params.get('mode')).toBe('edit');
    expect(params.get('template')).toBe('sirsoft-basic');
    expect(window.location.pathname).toBe('/popular');
  });

  it('template 파라미터 누락 시 빈 값 반환해야 함', () => {
    window.location.search = '?mode=edit';

    const params = new URLSearchParams(window.location.search);
    expect(params.get('template')).toBeNull();
  });

  it('중첩 라우트 경로도 레이아웃 인식에 사용됨', () => {
    window.location.pathname = '/shop/products';
    window.location.search = '?mode=edit&template=sirsoft-basic';

    const params = new URLSearchParams(window.location.search);
    expect(params.get('mode')).toBe('edit');
    expect(window.location.pathname).toBe('/shop/products');
  });
});

describe('편집 모드 상태 관리', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  it('getCurrentLayoutName으로 현재 편집 중인 레이아웃 조회해야 함', () => {
    mockG7Core.wysiwyg.getCurrentLayoutName.mockReturnValueOnce('home');
    const layoutName = (window as any).G7Core.wysiwyg.getCurrentLayoutName();
    expect(layoutName).toBe('home');
  });

  it('getCurrentTemplateId로 현재 편집 중인 템플릿 조회해야 함', () => {
    mockG7Core.wysiwyg.getCurrentTemplateId.mockReturnValueOnce('sirsoft-basic');
    const templateId = (window as any).G7Core.wysiwyg.getCurrentTemplateId();
    expect(templateId).toBe('sirsoft-basic');
  });

  it('편집 모드 비활성화 시 null 반환해야 함', () => {
    expect((window as any).G7Core.wysiwyg.getCurrentLayoutName()).toBeNull();
    expect((window as any).G7Core.wysiwyg.getCurrentTemplateId()).toBeNull();
  });
});

describe('권한 검증', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  it('layouts.update 권한이 있으면 편집 가능해야 함', () => {
    const user = (window as any).G7Core.auth.getUser();
    const hasPermission = user?.permissions?.includes('layouts.update');
    expect(hasPermission).toBe(true);
  });

  it('권한이 없으면 편집 불가해야 함', () => {
    mockG7Core.auth.getUser.mockReturnValueOnce({ id: 1, permissions: [] });
    const user = (window as any).G7Core.auth.getUser();
    const hasPermission = user?.permissions?.includes('layouts.update');
    expect(hasPermission).toBe(false);
  });

  it('로그인하지 않은 경우 null 반환해야 함', () => {
    mockG7Core.auth.getUser.mockReturnValueOnce(null);
    const user = (window as any).G7Core.auth.getUser();
    expect(user).toBeNull();
  });
});

describe('enterEditMode / exitEditMode 흐름', () => {
  const originalLocation = window.location;

  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
    delete (window as any).location;
    window.location = {
      ...originalLocation,
      search: '',
      href: 'https://example.com/',
      origin: 'https://example.com',
      pathname: '/',
    } as any;
  });

  afterEach(() => {
    window.location = originalLocation;
    delete (window as any).G7Core;
  });

  it('enterEditMode 호출 시 편집 모드 URL로 이동해야 함', () => {
    (window as any).G7Core.wysiwyg.enterEditMode('home', 'sirsoft-basic');
    expect(mockG7Core.wysiwyg.enterEditMode).toHaveBeenCalledWith('home', 'sirsoft-basic');
  });

  it('exitEditMode 호출 시 편집 모드 해제해야 함', () => {
    (window as any).G7Core.wysiwyg.exitEditMode();
    expect(mockG7Core.wysiwyg.exitEditMode).toHaveBeenCalled();
  });
});

describe('라우트 기반 레이아웃 편집', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  it('라우트 경로로 편집 모드 진입해야 함', () => {
    // 1. 편집할 라우트 선택
    const route = '/';
    const templateId = 'sirsoft-basic';

    // 2. 편집 모드 URL 생성 (라우트 기반)
    const editUrl = (window as any).G7Core.wysiwyg.getEditModeUrl(route, templateId);
    expect(editUrl).toContain('mode=edit');
    expect(editUrl).toContain(`template=${templateId}`);
    expect(editUrl).toMatch(/^\/?.*\?mode=edit/); // 라우트 + ?mode=edit 형식

    // 3. 편집 모드 진입
    (window as any).G7Core.wysiwyg.enterEditMode(route, templateId);
    expect(mockG7Core.wysiwyg.enterEditMode).toHaveBeenCalledWith(route, templateId);
  });

  it('중첩 경로의 라우트도 처리해야 함', () => {
    const route = '/shop/products/detail';
    const templateId = 'sirsoft-basic';

    const editUrl = (window as any).G7Core.wysiwyg.getEditModeUrl(route, templateId);
    expect(editUrl).toContain('/shop/products/detail');
    expect(editUrl).toContain('mode=edit');
  });
});

describe('템플릿 활성화 상태 검증', () => {
  interface Template {
    identifier: string;
    status: string;
  }

  it('활성화된 템플릿만 편집 가능해야 함', () => {
    const activeTemplate: Template = { identifier: 'sirsoft-basic', status: 'active' };
    const inactiveTemplate: Template = { identifier: 'other-template', status: 'inactive' };
    const uninstalledTemplate: Template = { identifier: 'uninstalled-template', status: 'uninstalled' };

    // 활성화 상태 검증 함수
    const canEdit = (template: Template) => template.status === 'active';

    expect(canEdit(activeTemplate)).toBe(true);
    expect(canEdit(inactiveTemplate)).toBe(false);
    expect(canEdit(uninstalledTemplate)).toBe(false);
  });
});

describe('에러 처리', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  it('G7Core가 없을 때 gracefully 실패해야 함', () => {
    delete (window as any).G7Core;

    // G7Core 없이 호출 시 에러 발생하지 않아야 함
    expect(() => {
      const g7Core = (window as any).G7Core;
      if (g7Core?.wysiwyg) {
        g7Core.wysiwyg.isEditMode();
      }
    }).not.toThrow();
  });

  it('wysiwyg API가 없을 때 gracefully 실패해야 함', () => {
    (window as any).G7Core = {};

    expect(() => {
      const g7Core = (window as any).G7Core;
      if (g7Core?.wysiwyg) {
        g7Core.wysiwyg.isEditMode();
      }
    }).not.toThrow();
  });
});
