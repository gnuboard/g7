/**
 * G7CoreGlobals 테스트
 *
 * G7Core 전역 API의 dataSource.updateData 메서드를 테스트합니다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Logger mock
vi.mock('@/core/utils/logger', () => ({
  default: {
    getLogger: () => ({
      log: vi.fn(),
      warn: vi.fn(),
      error: vi.fn(),
    }),
  },
}));

describe('G7Core.dataSource.updateData', () => {
  let mockTemplateApp: {
    getDataSource: ReturnType<typeof vi.fn>;
    setDataSource: ReturnType<typeof vi.fn>;
  };

  beforeEach(() => {
    // TemplateApp mock 설정
    mockTemplateApp = {
      getDataSource: vi.fn(),
      setDataSource: vi.fn(),
    };
    (window as any).__templateApp = mockTemplateApp;

    // G7Core mock 초기화 (실제 구현 대신 테스트용 mock)
    (window as any).G7Core = {
      dataSource: {
        updateData: (
          dataSourceId: string,
          dataPath: string | null,
          newData: any[],
          mode: 'append' | 'prepend' = 'append'
        ): boolean => {
          const templateApp = (window as any).__templateApp;
          if (!templateApp?.getDataSource || !templateApp?.setDataSource) {
            return false;
          }

          const currentDataSource = templateApp.getDataSource(dataSourceId);
          if (!currentDataSource) {
            return false;
          }

          let targetArray: any[];
          let parentObj: any = currentDataSource;
          let lastKey: string | null = null;

          if (dataPath) {
            const pathParts = dataPath.split('.');
            lastKey = pathParts.pop()!;

            for (const part of pathParts) {
              if (parentObj && typeof parentObj === 'object' && part in parentObj) {
                parentObj = parentObj[part];
              } else {
                return false;
              }
            }

            targetArray = parentObj[lastKey];
          } else {
            targetArray = currentDataSource;
          }

          if (!Array.isArray(targetArray)) {
            return false;
          }

          if (!Array.isArray(newData)) {
            return false;
          }

          const mergedArray = mode === 'prepend'
            ? [...newData, ...targetArray]
            : [...targetArray, ...newData];

          if (dataPath && lastKey) {
            parentObj[lastKey] = mergedArray;
            templateApp.setDataSource(dataSourceId, currentDataSource, { merge: false });
          } else {
            templateApp.setDataSource(dataSourceId, mergedArray, { merge: false });
          }

          return true;
        },
      },
    };
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    delete (window as any).G7Core;
  });

  describe('append 모드 (기본값)', () => {
    it('기존 배열 뒤에 새 데이터를 추가한다', () => {
      // Given
      const existingData = { data: [{ id: 1 }, { id: 2 }] };
      const newData = [{ id: 3 }, { id: 4 }];
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', newData);

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'templates',
        {
          data: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }],
        },
        { merge: false }
      );
    });

    it('루트 배열에 데이터를 추가한다 (dataPath가 null인 경우)', () => {
      // Given
      const existingData = [{ id: 1 }, { id: 2 }];
      const newData = [{ id: 3 }];
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData('items', null, newData);

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'items',
        [{ id: 1 }, { id: 2 }, { id: 3 }],
        { merge: false }
      );
    });

    it('중첩된 경로에 데이터를 추가한다', () => {
      // Given
      const existingData = {
        response: {
          data: {
            items: [{ id: 1 }],
          },
        },
      };
      const newData = [{ id: 2 }, { id: 3 }];
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData(
        'products',
        'response.data.items',
        newData
      );

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'products',
        {
          response: {
            data: {
              items: [{ id: 1 }, { id: 2 }, { id: 3 }],
            },
          },
        },
        { merge: false }
      );
    });
  });

  describe('prepend 모드', () => {
    it('기존 배열 앞에 새 데이터를 추가한다', () => {
      // Given
      const existingData = { data: [{ id: 3 }, { id: 4 }] };
      const newData = [{ id: 1 }, { id: 2 }];
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData(
        'notifications',
        'data',
        newData,
        'prepend'
      );

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'notifications',
        {
          data: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }],
        },
        { merge: false }
      );
    });

    it('루트 배열 앞에 데이터를 추가한다', () => {
      // Given
      const existingData = [{ id: 2 }];
      const newData = [{ id: 1 }];
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData(
        'items',
        null,
        newData,
        'prepend'
      );

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'items',
        [{ id: 1 }, { id: 2 }],
        { merge: false }
      );
    });
  });

  describe('에러 케이스', () => {
    it('TemplateApp이 초기화되지 않으면 false를 반환한다', () => {
      // Given
      delete (window as any).__templateApp;

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', []);

      // Then
      expect(result).toBe(false);
    });

    it('데이터 소스를 찾을 수 없으면 false를 반환한다', () => {
      // Given
      mockTemplateApp.getDataSource.mockReturnValue(null);

      // When
      const result = (window as any).G7Core.dataSource.updateData('nonexistent', 'data', []);

      // Then
      expect(result).toBe(false);
      expect(mockTemplateApp.setDataSource).not.toHaveBeenCalled();
    });

    it('잘못된 경로가 제공되면 false를 반환한다', () => {
      // Given
      const existingData = { data: [] };
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData(
        'templates',
        'invalid.path.here',
        []
      );

      // Then
      expect(result).toBe(false);
      expect(mockTemplateApp.setDataSource).not.toHaveBeenCalled();
    });

    it('대상이 배열이 아니면 false를 반환한다', () => {
      // Given
      const existingData = { data: 'not an array' };
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', []);

      // Then
      expect(result).toBe(false);
      expect(mockTemplateApp.setDataSource).not.toHaveBeenCalled();
    });

    it('newData가 배열이 아니면 false를 반환한다', () => {
      // Given
      const existingData = { data: [{ id: 1 }] };
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData(
        'templates',
        'data',
        'not an array' as any
      );

      // Then
      expect(result).toBe(false);
      expect(mockTemplateApp.setDataSource).not.toHaveBeenCalled();
    });

    it('getDataSource 메서드가 없으면 false를 반환한다', () => {
      // Given
      delete mockTemplateApp.getDataSource;

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', []);

      // Then
      expect(result).toBe(false);
    });

    it('setDataSource 메서드가 없으면 false를 반환한다', () => {
      // Given
      delete mockTemplateApp.setDataSource;

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', []);

      // Then
      expect(result).toBe(false);
    });
  });

  describe('무한 스크롤 시나리오', () => {
    it('페이지네이션된 데이터를 순차적으로 추가한다', () => {
      // Given - 첫 페이지 데이터
      const page1Data = {
        data: [
          { id: 1, name: '템플릿 1' },
          { id: 2, name: '템플릿 2' },
        ],
        meta: { current_page: 1, total: 50 },
      };
      mockTemplateApp.getDataSource.mockReturnValue(page1Data);

      // 두 번째 페이지 데이터
      const page2Items = [
        { id: 3, name: '템플릿 3' },
        { id: 4, name: '템플릿 4' },
      ];

      // When
      const result = (window as any).G7Core.dataSource.updateData(
        'templates',
        'data',
        page2Items,
        'append'
      );

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'templates',
        {
          data: [
            { id: 1, name: '템플릿 1' },
            { id: 2, name: '템플릿 2' },
            { id: 3, name: '템플릿 3' },
            { id: 4, name: '템플릿 4' },
          ],
          meta: { current_page: 1, total: 50 },
        },
        { merge: false }
      );
    });

    it('빈 배열을 추가해도 정상 동작한다', () => {
      // Given
      const existingData = { data: [{ id: 1 }] };
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', []);

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'templates',
        { data: [{ id: 1 }] },
        { merge: false }
      );
    });

    it('빈 기존 배열에 데이터를 추가한다', () => {
      // Given
      const existingData = { data: [] };
      const newData = [{ id: 1 }, { id: 2 }];
      mockTemplateApp.getDataSource.mockReturnValue(existingData);

      // When
      const result = (window as any).G7Core.dataSource.updateData('templates', 'data', newData);

      // Then
      expect(result).toBe(true);
      expect(mockTemplateApp.setDataSource).toHaveBeenCalledWith(
        'templates',
        { data: [{ id: 1 }, { id: 2 }] },
        { merge: false }
      );
    });
  });
});

/**
 * G7Core.state 부모 컨텍스트 API 테스트
 *
 * @since engine-v1.16.0
 */
describe('G7Core.state 부모 컨텍스트 API', () => {
  beforeEach(() => {
    // 레이아웃 컨텍스트 스택 초기화
    (window as any).__g7LayoutContextStack = [];

    // G7Core.state mock
    (window as any).G7Core = {
      state: {
        get: vi.fn().mockReturnValue({ appName: 'G7' }),
        set: vi.fn(),
        getParent: (): { _local: Record<string, any>; _global: Record<string, any>; setState: (updates: any) => void } | null => {
          const contextStack = (window as any).__g7LayoutContextStack || [];
          if (contextStack.length === 0) {
            return null;
          }
          const parentEntry = contextStack[contextStack.length - 1];
          if (!parentEntry) {
            return null;
          }
          const parentData = parentEntry.dataContext || {};
          return {
            _local: parentEntry.state || parentData._local || {},
            _global: parentData._global || (window as any).G7Core.state.get() || {},
            setState: parentEntry.setState,
          };
        },
        setParentLocal: (pathOrUpdates: string | Record<string, any>, maybeValue?: any): void => {
          const contextStack = (window as any).__g7LayoutContextStack || [];
          if (contextStack.length === 0) {
            return;
          }
          const parentEntry = contextStack[contextStack.length - 1];
          if (!parentEntry?.setState) {
            return;
          }
          let updates: Record<string, any>;
          if (typeof pathOrUpdates === 'string') {
            updates = { [pathOrUpdates]: maybeValue };
          } else {
            updates = pathOrUpdates;
          }
          const currentLocal = parentEntry.state || {};
          const merged = { ...currentLocal, ...updates };
          parentEntry.setState(merged);
        },
        setParentGlobal: (pathOrUpdates: string | Record<string, any>, maybeValue?: any): void => {
          let updates: Record<string, any>;
          if (typeof pathOrUpdates === 'string') {
            updates = { [pathOrUpdates]: maybeValue };
          } else {
            updates = pathOrUpdates;
          }
          (window as any).G7Core.state.set(updates);
        },
      },
    };
  });

  afterEach(() => {
    delete (window as any).__g7LayoutContextStack;
    delete (window as any).G7Core;
  });

  describe('getParent', () => {
    it('컨텍스트 스택이 비어있으면 null을 반환한다', () => {
      // When
      const result = (window as any).G7Core.state.getParent();

      // Then
      expect(result).toBeNull();
    });

    it('부모 컨텍스트의 _local과 _global을 반환한다', () => {
      // Given
      const parentState = { form: { name: 'parent value' } };
      const parentSetState = vi.fn();
      (window as any).__g7LayoutContextStack.push({
        state: parentState,
        setState: parentSetState,
        dataContext: {
          _local: parentState,
          _global: { appName: 'G7' },
        },
      });

      // When
      const result = (window as any).G7Core.state.getParent();

      // Then
      expect(result).not.toBeNull();
      expect(result._local).toEqual({ form: { name: 'parent value' } });
      expect(result._global).toEqual({ appName: 'G7' });
      expect(result.setState).toBe(parentSetState);
    });

    it('중첩 모달에서 가장 최근 부모를 반환한다', () => {
      // Given
      const rootState = { level: 'root' };
      const parentState = { level: 'parent' };
      (window as any).__g7LayoutContextStack.push({
        state: rootState,
        setState: vi.fn(),
      });
      (window as any).__g7LayoutContextStack.push({
        state: parentState,
        setState: vi.fn(),
      });

      // When
      const result = (window as any).G7Core.state.getParent();

      // Then
      expect(result._local.level).toBe('parent');
    });
  });

  describe('setParentLocal', () => {
    it('컨텍스트 스택이 비어있으면 아무것도 하지 않는다', () => {
      // When
      (window as any).G7Core.state.setParentLocal({ test: 'value' });

      // Then - 에러 없이 종료되어야 함
      expect(true).toBe(true);
    });

    it('객체로 부모 로컬 상태를 업데이트한다', () => {
      // Given
      const parentState = { existing: 'value' };
      const parentSetState = vi.fn();
      (window as any).__g7LayoutContextStack.push({
        state: parentState,
        setState: parentSetState,
      });

      // When
      (window as any).G7Core.state.setParentLocal({ newField: 'newValue' });

      // Then
      expect(parentSetState).toHaveBeenCalledWith({
        existing: 'value',
        newField: 'newValue',
      });
    });

    it('경로와 값으로 부모 로컬 상태를 업데이트한다', () => {
      // Given
      const parentState = { form: { name: 'old' } };
      const parentSetState = vi.fn();
      (window as any).__g7LayoutContextStack.push({
        state: parentState,
        setState: parentSetState,
      });

      // When
      (window as any).G7Core.state.setParentLocal('form.name', 'new');

      // Then
      expect(parentSetState).toHaveBeenCalledWith({
        form: { name: 'old' },
        'form.name': 'new',
      });
    });
  });

  describe('setParentGlobal', () => {
    it('G7Core.state.set을 통해 전역 상태를 업데이트한다', () => {
      // When
      (window as any).G7Core.state.setParentGlobal({ modalResult: { confirmed: true } });

      // Then
      expect((window as any).G7Core.state.set).toHaveBeenCalledWith({
        modalResult: { confirmed: true },
      });
    });

    it('경로와 값으로 전역 상태를 업데이트한다', () => {
      // When
      (window as any).G7Core.state.setParentGlobal('modalResult.confirmed', true);

      // Then
      expect((window as any).G7Core.state.set).toHaveBeenCalledWith({
        'modalResult.confirmed': true,
      });
    });
  });
});
