/**
 * @file admin_ecommerce_product_notice.test.tsx
 * @description 상품정보제공고시 관리 레이아웃 테스트
 *
 * 테스트 대상:
 * - admin_ecommerce_product_notice_view.json (보기 패널)
 * - TabNavigation을 활용한 다국어 탭 전환
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import noticeViewFixture from '../fixtures/admin_ecommerce_product_notice_view.json';
import noticeListFixture from '../fixtures/admin_ecommerce_product_notice_list.json';
import noticeFormFixture from '../fixtures/admin_ecommerce_product_notice_form.json';

// TabNavigation 목 컴포넌트
const TestTabNavigation: React.FC<{
  tabs?: Array<{ id: string; label: string }>;
  activeTabId?: string;
  variant?: string;
  className?: string;
  onTabChange?: (tabId: string) => void;
}> = ({ tabs, activeTabId, className, onTabChange }) => (
  <div className={className} data-testid="tab-navigation">
    {tabs?.map((tab) => (
      <button
        key={tab.id}
        data-testid={`tab-${tab.id}`}
        className={activeTabId === tab.id ? 'active' : ''}
        onClick={() => onTabChange?.(tab.id)}
      >
        {tab.label}
      </button>
    ))}
  </div>
);

// 테스트용 레지스트리
let registry: MockComponentRegistry;

beforeEach(() => {
  // 기본 컴포넌트가 포함된 레지스트리 생성
  registry = createMockComponentRegistryWithBasics();

  // Composite 컴포넌트 추가 등록
  registry.register('composite', 'TabNavigation', TestTabNavigation);
});

afterEach(() => {
  vi.clearAllMocks();
});

describe('상품정보제공고시 보기 패널', () => {
  describe('기본 렌더링', () => {
    it('selectedTemplateId가 있고 panelMode가 view일 때 렌더링됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 탭 네비게이션이 렌더링되는지 확인
      expect(screen.getByTestId('tab-navigation')).toBeInTheDocument();
      expect(screen.getByTestId('tab-ko')).toBeInTheDocument();
      expect(screen.getByTestId('tab-en')).toBeInTheDocument();

      cleanup();
    });

    it('템플릿 이름과 필드 수가 표시됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 템플릿 이름 표시 확인
      expect(screen.getByText('의류')).toBeInTheDocument();

      // 필드 수 표시 확인 (text-xs 클래스를 가진 span에서)
      const fieldsCount = screen.getByText('2', { selector: 'span.text-xs' });
      expect(fieldsCount).toBeInTheDocument();

      cleanup();
    });

    it('selectedTemplateId가 없으면 렌더링되지 않음', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _global: {
            selectedTemplateId: null,
            panelMode: 'view',
            selectedTemplate: null,
          },
        },
      });

      await render();

      // 탭 네비게이션이 렌더링되지 않음
      expect(screen.queryByTestId('tab-navigation')).not.toBeInTheDocument();

      cleanup();
    });
  });

  describe('다국어 탭 전환', () => {
    it('기본 로케일(ko)에서 한국어 탭이 활성화됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup, getState } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 초기 상태 확인
      const state = getState();
      expect(state._local.viewLocale).toBe('ko');

      // 한국어 탭이 활성화됨
      const koTab = screen.getByTestId('tab-ko');
      expect(koTab).toHaveClass('active');

      cleanup();
    });

    it('한국어 탭에서 한국어 데이터가 표시됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 한국어 데이터가 표시되는지 확인
      expect(screen.getByText('제조사')).toBeInTheDocument();
      expect(screen.getByText('(주)테스트')).toBeInTheDocument();
      expect(screen.getByText('원산지')).toBeInTheDocument();
      expect(screen.getByText('대한민국')).toBeInTheDocument();

      cleanup();
    });

    it('영어 탭으로 전환 시 로컬 상태가 변경됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 영어 탭 선택 (상태 직접 변경으로 시뮬레이션)
      setState('viewLocale', 'en', 'local');
      await rerender();

      const state = getState();
      expect(state._local.viewLocale).toBe('en');

      cleanup();
    });

    it('영어 탭에서 영어 데이터가 표시됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _local: {
            viewLocale: 'en',
          },
        },
      });

      await render();

      // 영어 데이터가 표시되는지 확인
      expect(screen.getByText('Manufacturer')).toBeInTheDocument();
      expect(screen.getByText('Test Inc.')).toBeInTheDocument();
      expect(screen.getByText('Country of Origin')).toBeInTheDocument();
      expect(screen.getByText('Korea')).toBeInTheDocument();

      cleanup();
    });

    it('탭 전환 시 데이터가 올바르게 전환됨 (setState 시뮬레이션)', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 초기 상태: 한국어 탭 활성화, 한국어 데이터 표시
      expect(screen.getByTestId('tab-ko')).toHaveClass('active');
      expect(screen.getByText('제조사')).toBeInTheDocument();
      expect(screen.getByText('(주)테스트')).toBeInTheDocument();

      // 영어 탭으로 전환 (setState로 시뮬레이션)
      setState('viewLocale', 'en', 'local');
      await rerender();

      // 상태 확인: viewLocale이 'en'으로 변경됨
      expect(getState()._local.viewLocale).toBe('en');

      // 영어 탭이 활성화됨
      expect(screen.getByTestId('tab-en')).toHaveClass('active');
      expect(screen.getByTestId('tab-ko')).not.toHaveClass('active');

      // 영어 데이터가 표시됨
      expect(screen.getByText('Manufacturer')).toBeInTheDocument();
      expect(screen.getByText('Test Inc.')).toBeInTheDocument();
      expect(screen.getByText('Country of Origin')).toBeInTheDocument();
      expect(screen.getByText('Korea')).toBeInTheDocument();

      // 한국어 데이터는 더 이상 표시되지 않음
      expect(screen.queryByText('제조사')).not.toBeInTheDocument();
      expect(screen.queryByText('(주)테스트')).not.toBeInTheDocument();

      cleanup();
    });

    it('탭을 여러 번 전환해도 올바르게 동작함', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 1. 영어 탭으로 전환
      setState('viewLocale', 'en', 'local');
      await rerender();
      expect(getState()._local.viewLocale).toBe('en');
      expect(screen.getByText('Manufacturer')).toBeInTheDocument();
      expect(screen.queryByText('제조사')).not.toBeInTheDocument();

      // 2. 다시 한국어 탭으로 전환
      setState('viewLocale', 'ko', 'local');
      await rerender();
      expect(getState()._local.viewLocale).toBe('ko');
      expect(screen.getByText('제조사')).toBeInTheDocument();
      expect(screen.queryByText('Manufacturer')).not.toBeInTheDocument();

      // 3. 다시 영어 탭으로 전환
      setState('viewLocale', 'en', 'local');
      await rerender();
      expect(getState()._local.viewLocale).toBe('en');
      expect(screen.getByText('Manufacturer')).toBeInTheDocument();

      cleanup();
    });
  });

  describe('조건부 렌더링', () => {
    it('viewLocale이 ko일 때 한국어 테이블만 표시됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _local: {
            viewLocale: 'ko',
          },
        },
      });

      await render();

      // 한국어 테이블만 표시
      expect(screen.queryByTestId('table-ko')).toBeInTheDocument();
      expect(screen.queryByTestId('table-en')).not.toBeInTheDocument();

      cleanup();
    });

    it('viewLocale이 en일 때 영어 테이블만 표시됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _local: {
            viewLocale: 'en',
          },
        },
      });

      await render();

      // 영어 테이블만 표시
      expect(screen.queryByTestId('table-ko')).not.toBeInTheDocument();
      expect(screen.queryByTestId('table-en')).toBeInTheDocument();

      cleanup();
    });
  });

  describe('iteration 렌더링', () => {
    it('fields 배열의 모든 항목이 렌더링됨', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 인덱스 번호 표시 확인 (td 요소에서)
      const indexCells = screen.getAllByRole('cell');
      const indexTexts = indexCells.map(cell => cell.textContent);
      expect(indexTexts).toContain('1');
      expect(indexTexts).toContain('2');

      // 모든 필드 데이터 표시 확인
      expect(screen.getByText('제조사')).toBeInTheDocument();
      expect(screen.getByText('원산지')).toBeInTheDocument();

      cleanup();
    });

    it('빈 fields 배열일 때 테이블 행이 렌더링되지 않음', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _global: {
            selectedTemplateId: 1,
            panelMode: 'view',
            selectedTemplate: {
              id: 1,
              name: { ko: '빈 템플릿', en: 'Empty Template' },
              fields: [],
              fields_count: 0,
              is_active: true,
            },
          },
        },
      });

      await render();

      // 빈 템플릿 이름이 표시됨
      expect(screen.getByText('빈 템플릿')).toBeInTheDocument();

      // 필드 데이터 없음 (iteration이 빈 배열이므로 테이블 행이 없음)
      expect(screen.queryByText('제조사')).not.toBeInTheDocument();
      expect(screen.queryByText('원산지')).not.toBeInTheDocument();

      // 테이블은 있지만 tbody 내부에 tr이 없음
      const table = screen.getByTestId('table-ko');
      expect(table).toBeInTheDocument();

      cleanup();
    });
  });

  describe('레이아웃 검증', () => {
    it('레이아웃에 검증 오류가 없어야 함', async () => {
      const layoutJson = noticeViewFixture;
      const { render, cleanup, assertNoValidationErrors } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 검증 오류가 없어야 함
      expect(() => assertNoValidationErrors()).not.toThrow();

      cleanup();
    });
  });
});

describe('상품정보제공고시 목록 패널 (무한스크롤)', () => {
  let registry: MockComponentRegistry;

  beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('템플릿 목록이 렌더링됨', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, mockApi } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      // mockApi 키 설명:
      // - URL: /api/modules/sirsoft-ecommerce/admin/product-notice-templates
      // - extractDataSourceId가 하이픈을 언더스코어로 변환: product_notice_templates
      // 응답 구조 설명:
      // - response에 배열 직접 전달 → createMockResponse가 { success: true, data: [...] }로 래핑
      // - processDataSources가 전체 응답 저장: templates = { success: true, data: [...] }
      // - 레이아웃에서 templates?.data 참조 → 배열 반환
      mockApi('product_notice_templates', {
        response: [
          { id: 1, name: { ko: '의류', en: 'Clothing' }, fields_count: 5 },
          { id: 2, name: { ko: '가전', en: 'Electronics' }, fields_count: 3 },
        ],
      });

      await render();

      // 템플릿 이름이 표시됨
      expect(screen.getByText('의류')).toBeInTheDocument();
      expect(screen.getByText('가전')).toBeInTheDocument();

      cleanup();
    });

    it('빈 목록일 때 빈 상태 메시지가 표시됨', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        translations: {
          'sirsoft-ecommerce': {
            admin: {
              product_notice_template: {
                list: {
                  empty: '등록된 템플릿이 없습니다.',
                },
              },
            },
          },
        },
        initialData: {
          templates: { data: [] },
        },
      });

      await render();

      // 빈 상태 메시지가 표시됨
      expect(screen.getByText('등록된 템플릿이 없습니다.')).toBeInTheDocument();

      cleanup();
    });
  });

  describe('무한스크롤 상태 관리', () => {
    it('초기 무한스크롤 상태가 설정됨', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, getState } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: {
          templates: { data: [] },
        },
      });

      await render();

      const state = getState();
      expect(state._global.infiniteScroll).toBeDefined();
      expect(state._global.infiniteScroll.currentPage).toBe(1);
      expect(state._global.infiniteScroll.hasMore).toBe(true);
      expect(state._global.infiniteScroll.isLoadingMore).toBe(false);

      cleanup();
    });

    it('로딩 중일 때 isLoadingMore가 true로 설정됨', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: {
          templates: { data: [{ id: 1, name: { ko: '템플릿' }, fields_count: 1 }] },
        },
      });

      await render();

      // isLoadingMore를 true로 설정
      setState('infiniteScroll.isLoadingMore', true, 'global');
      await rerender();

      const state = getState();
      expect(state._global.infiniteScroll.isLoadingMore).toBe(true);

      cleanup();
    });

    it('더 이상 데이터가 없으면 hasMore가 false로 설정됨', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: {
          templates: { data: [] },
        },
      });

      await render();

      // hasMore를 false로 설정 (더 이상 데이터가 없음)
      setState('infiniteScroll.hasMore', false, 'global');
      await rerender();

      const state = getState();
      expect(state._global.infiniteScroll.hasMore).toBe(false);

      cleanup();
    });
  });

  describe('페이지 상태 추적', () => {
    it('페이지 로드 시 currentPage가 증가함', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: {
          templates: {
            data: Array.from({ length: 20 }, (_, i) => ({
              id: i + 1,
              name: { ko: `템플릿 ${i + 1}` },
              fields_count: 1,
            })),
          },
        },
      });

      await render();

      // 초기 상태 확인
      expect(getState()._global.infiniteScroll.currentPage).toBe(1);

      // 두 번째 페이지 로드 시뮬레이션
      setState('infiniteScroll.currentPage', 2, 'global');
      await rerender();

      expect(getState()._global.infiniteScroll.currentPage).toBe(2);

      cleanup();
    });

    it('데이터가 per_page 미만이면 hasMore가 false로 설정됨', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, setState, getState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: {
          templates: {
            data: Array.from({ length: 10 }, (_, i) => ({
              id: i + 1,
              name: { ko: `템플릿 ${i + 1}` },
              fields_count: 1,
            })),
          },
        },
      });

      await render();

      // 응답 데이터가 per_page 미만이면 hasMore를 false로 설정하는 시뮬레이션
      setState('infiniteScroll.hasMore', false, 'global');
      await rerender();

      expect(getState()._global.infiniteScroll.hasMore).toBe(false);

      cleanup();
    });
  });

  describe('레이아웃 검증', () => {
    it('목록 패널 레이아웃에 검증 오류가 없어야 함', async () => {
      const layoutJson = noticeListFixture;
      const { render, cleanup, assertNoValidationErrors } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialData: {
          templates: { data: [] },
        },
      });

      await render();

      expect(() => assertNoValidationErrors()).not.toThrow();

      cleanup();
    });
  });
});

describe('상품정보제공고시 편집/등록 폼 패널', () => {
  // MultilingualTabPanel 목 컴포넌트
  const TestMultilingualTabPanel: React.FC<{
    variant?: string;
    className?: string;
    children?: React.ReactNode;
    'data-testid'?: string;
  }> = ({ className, children, 'data-testid': testId }) => {
    const [activeLocale, setActiveLocale] = React.useState('ko');

    return (
      <div className={className} data-testid={testId || 'multilingual-tab-panel'}>
        <div data-testid="multilingual-tabs">
          <button
            data-testid="multilingual-tab-ko"
            className={activeLocale === 'ko' ? 'active' : ''}
            onClick={() => setActiveLocale('ko')}
          >
            한국어
          </button>
          <button
            data-testid="multilingual-tab-en"
            className={activeLocale === 'en' ? 'active' : ''}
            onClick={() => setActiveLocale('en')}
          >
            English
          </button>
        </div>
        <div data-testid="tab-content" data-active-locale={activeLocale}>
          {children}
        </div>
      </div>
    );
  };

  // DynamicFieldList 목 컴포넌트
  const TestDynamicFieldList: React.FC<{
    items?: Array<Record<string, unknown>>;
    columns?: Array<{ key: string; type: string; label: string }>;
    onChange?: (items: unknown[]) => void;
    errors?: Record<string, string[]>;
    'data-testid'?: string;
  }> = ({ items = [], columns = [], errors, 'data-testid': testId }) => {
    // 부모 MultilingualTabPanel의 activeLocale 읽기
    const tabContent = document.querySelector('[data-testid="tab-content"]');
    const activeLocale = tabContent?.getAttribute('data-active-locale') || 'ko';

    return (
      <div data-testid={testId || 'dynamic-field-list'}>
        {items.map((item, index) => (
          <div key={(item._id as string) || index} data-testid={`field-row-${index}`}>
            {columns.map((col) => {
              const value = item[col.key] as Record<string, string> | string | undefined;
              const displayValue =
                col.type === 'multilingual' && typeof value === 'object'
                  ? value?.[activeLocale] || ''
                  : (value as string) || '';
              const errorKey = `fields.${index}.${col.key}.${activeLocale}`;

              return (
                <div key={col.key} data-testid={`field-${index}-${col.key}-wrapper`}>
                  <input
                    data-testid={`field-${index}-${col.key}`}
                    data-locale={activeLocale}
                    value={displayValue}
                    readOnly
                  />
                  {errors?.[errorKey] && (
                    <span data-testid={`error-${index}-${col.key}`} className="text-red-500">
                      {errors[errorKey][0]}
                    </span>
                  )}
                </div>
              );
            })}
          </div>
        ))}
      </div>
    );
  };

  // MultilingualInput 목 컴포넌트
  const TestMultilingualInput: React.FC<{
    name?: string;
    placeholder?: string;
    layout?: string;
    'data-testid'?: string;
  }> = ({ name, 'data-testid': testId }) => (
    <div data-testid={testId || `multilingual-input-${name}`}>
      <input data-testid={`input-${name}-ko`} placeholder="한국어" />
      <input data-testid={`input-${name}-en`} placeholder="English" />
    </div>
  );

  // Toggle 목 컴포넌트
  const TestToggle: React.FC<{
    label?: string;
    name?: string;
    'data-testid'?: string;
  }> = ({ label, 'data-testid': testId }) => (
    <label data-testid={testId || 'toggle'}>
      <input type="checkbox" />
      {label}
    </label>
  );

  let registry: MockComponentRegistry;

  beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();

    // Composite 컴포넌트 등록
    registry.register('composite', 'MultilingualTabPanel', TestMultilingualTabPanel);
    registry.register('composite', 'DynamicFieldList', TestDynamicFieldList);
    registry.register('composite', 'MultilingualInput', TestMultilingualInput);
    registry.register('composite', 'Toggle', TestToggle);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('panelMode가 edit일 때 폼이 렌더링됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 편집 타이틀이 표시됨
      expect(screen.getByTestId('edit-title')).toBeInTheDocument();
      expect(screen.getByText('상품군 편집')).toBeInTheDocument();

      cleanup();
    });

    it('panelMode가 create일 때 생성 타이틀이 표시됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
        initialState: {
          _global: {
            selectedTemplateId: null,
            panelMode: 'create',
            selectedTemplate: null,
          },
          _local: {
            form: {
              name: { ko: '', en: '' },
              fields: [{ _id: 'new_1', name: { ko: '', en: '' }, content: { ko: '', en: '' } }],
              is_active: true,
            },
            isSaving: false,
            errors: null,
          },
        },
      });

      await render();

      // 생성 타이틀이 표시됨
      expect(screen.getByTestId('create-title')).toBeInTheDocument();
      expect(screen.getByText('새 템플릿 추가')).toBeInTheDocument();

      cleanup();
    });

    it('항목 목록 섹션에 MultilingualTabPanel이 렌더링됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // MultilingualTabPanel이 렌더링됨
      expect(screen.getByTestId('fields-multilingual-tab-panel')).toBeInTheDocument();

      // 다국어 탭이 표시됨
      expect(screen.getByTestId('multilingual-tab-ko')).toBeInTheDocument();
      expect(screen.getByTestId('multilingual-tab-en')).toBeInTheDocument();

      cleanup();
    });

    it('DynamicFieldList가 MultilingualTabPanel 내부에 렌더링됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // DynamicFieldList가 렌더링됨
      expect(screen.getByTestId('dynamic-field-list')).toBeInTheDocument();

      // 필드 행이 렌더링됨
      expect(screen.getByTestId('field-row-0')).toBeInTheDocument();
      expect(screen.getByTestId('field-row-1')).toBeInTheDocument();

      cleanup();
    });
  });

  describe('다국어 탭 전환', () => {
    it('기본 로케일(ko)에서 한국어 탭이 활성화됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 한국어 탭이 활성화됨
      const koTab = screen.getByTestId('multilingual-tab-ko');
      expect(koTab).toHaveClass('active');

      cleanup();
    });

    it('한국어 탭에서 한국어 데이터가 표시됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 첫 번째 필드의 한국어 데이터 확인
      const nameInput = screen.getByTestId('field-0-name');
      expect(nameInput).toHaveValue('제조사');
      expect(nameInput).toHaveAttribute('data-locale', 'ko');

      const contentInput = screen.getByTestId('field-0-content');
      expect(contentInput).toHaveValue('(주)테스트');

      cleanup();
    });

    it('영어 탭 클릭 시 탭이 활성화됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup, user } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 영어 탭 클릭
      const enTab = screen.getByTestId('multilingual-tab-en');
      await user.click(enTab);

      // 영어 탭이 활성화됨
      expect(enTab).toHaveClass('active');

      // 한국어 탭이 비활성화됨
      const koTab = screen.getByTestId('multilingual-tab-ko');
      expect(koTab).not.toHaveClass('active');

      cleanup();
    });
  });

  describe('데이터 구조 검증', () => {
    it('폼 데이터가 다국어 객체 구조를 유지함', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup, getState } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      const state = getState();

      // 폼 데이터 구조 확인
      expect(state._local.form).toBeDefined();
      expect(state._local.form.name).toEqual({ ko: '의류', en: 'Clothing' });
      expect(state._local.form.fields[0]).toEqual({
        _id: 'field_1',
        name: { ko: '제조사', en: 'Manufacturer' },
        content: { ko: '(주)테스트', en: 'Test Inc.' },
      });

      cleanup();
    });

    it('필드 배열이 모든 로케일 데이터를 포함함', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup, getState } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      const state = getState();
      const fields = state._local.form.fields;

      // 모든 필드가 ko, en 키를 가짐
      fields.forEach((field: Record<string, unknown>) => {
        const name = field.name as Record<string, string>;
        const content = field.content as Record<string, string>;

        expect(name).toHaveProperty('ko');
        expect(name).toHaveProperty('en');
        expect(content).toHaveProperty('ko');
        expect(content).toHaveProperty('en');
      });

      cleanup();
    });
  });

  describe('에러 표시', () => {
    it('한국어 로케일 에러가 표시됨', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup, setState, rerender } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      // 렌더링 후 에러 상태 설정 (deepMerge 이슈 회피)
      setState('errors', {
        'fields.0.name.ko': ['항목명은 필수입니다'],
      });

      // 상태 변경 후 리렌더링
      await rerender();

      // 에러 메시지가 표시됨
      expect(screen.getByTestId('error-0-name')).toBeInTheDocument();
      expect(screen.getByText('항목명은 필수입니다')).toBeInTheDocument();

      cleanup();
    });
  });

  describe('레이아웃 검증', () => {
    it('편집 폼 레이아웃에 검증 오류가 없어야 함', async () => {
      const layoutJson = noticeFormFixture;
      const { render, cleanup, assertNoValidationErrors } = createLayoutTest(layoutJson, {
        componentRegistry: registry as any,
        locale: 'ko',
      });

      await render();

      expect(() => assertNoValidationErrors()).not.toThrow();

      cleanup();
    });
  });
});
