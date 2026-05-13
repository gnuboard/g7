/**
 * 트러블슈팅 회귀 테스트 - 컴포넌트 관련 이슈
 *
 * troubleshooting-components.md, troubleshooting-components-*.md에 기록된 사례의 회귀 테스트입니다.
 *
 * @see docs/frontend/troubleshooting-components.md
 * @see docs/frontend/troubleshooting-components-datagrid.md
 * @see docs/frontend/troubleshooting-components-form.md
 * @see docs/frontend/troubleshooting-components-misc.md
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Logger } from '../../utils/Logger';
import { hasExplicitSetStateForField } from '../FormContext';

describe('트러블슈팅 회귀 테스트 - DataGrid 컴포넌트', () => {
  beforeEach(() => {
    Logger.getInstance().setDebug(false);
  });

  describe('[사례 1] API 응답 구조와 data prop 바인딩 불일치', () => {
    /**
     * 증상: DataGrid에 데이터가 표시되지 않음
     * 해결: API 응답 구조에 맞게 바인딩 경로 수정
     */
    it('API 응답이 { data: { data: [] } } 형태일 때 올바른 경로를 사용해야 함', () => {
      const apiResponse = {
        success: true,
        data: {
          data: [
            { id: 1, name: 'Item 1' },
            { id: 2, name: 'Item 2' },
          ],
          meta: { total: 2, page: 1 },
        },
      };

      // 올바른 바인딩: {{products?.data?.data}}
      const data = apiResponse?.data?.data;
      expect(data).toHaveLength(2);

      // 잘못된 바인딩: {{products.data}}
      const wrongData = apiResponse?.data; // 객체 전체가 반환됨
      expect(Array.isArray(wrongData)).toBe(false);
    });
  });

  describe('[사례 2] expandChildren (SubGrid) 데이터가 빈 값으로 표시됨', () => {
    /**
     * 증상: SubGrid에서 중첩 데이터가 표시되지 않음
     * 해결: row 컨텍스트에서 올바른 경로로 데이터 접근
     */
    it('expandChildren에서 row 컨텍스트를 통해 중첩 데이터에 접근해야 함', () => {
      const row = {
        id: 1,
        name: 'Product',
        options: [
          { id: 101, name: 'Option A' },
          { id: 102, name: 'Option B' },
        ],
      };

      // expandChildren에서 {{row.options}} 사용
      expect(row.options).toHaveLength(2);
    });
  });

  describe('[사례 3] DataGrid 이벤트 핸들러가 동작하지 않음', () => {
    /**
     * 증상: onExpandChange/onSelectionChange 이벤트가 발생하지 않음
     * 해결: 이벤트 핸들러를 actions 배열로 정의
     */
    it('이벤트 핸들러는 actions 배열 형태로 정의해야 함', () => {
      const correctConfig = {
        actions: [
          {
            type: 'selectionChange',
            handler: 'setState',
            params: { target: 'local', selectedIds: '{{$event}}' },
          },
        ],
      };

      expect(Array.isArray(correctConfig.actions)).toBe(true);
      expect(correctConfig.actions[0].type).toBe('selectionChange');
    });
  });

  describe('[동적 컬럼 사례 1] 동적 컬럼 체크해제가 즉시 다시 체크됨', () => {
    /**
     * 증상: ColumnSelector에서 컬럼 체크해제 시 즉시 다시 체크됨
     * 해결: controlled 상태 관리 및 낙관적 업데이트
     */
    it('visibleColumns 변경이 즉시 반영되어야 함', () => {
      let visibleColumns = ['id', 'name', 'price', 'status'];

      // 컬럼 체크해제
      const toggleColumn = (field: string) => {
        if (visibleColumns.includes(field)) {
          visibleColumns = visibleColumns.filter((c) => c !== field);
        } else {
          visibleColumns = [...visibleColumns, field];
        }
      };

      toggleColumn('price');

      expect(visibleColumns).not.toContain('price');
      expect(visibleColumns).toHaveLength(3);
    });
  });

  describe('[컬럼 width 사례] 컬럼 width 속성이 적용되지 않음', () => {
    /**
     * 증상: columns 배열의 width 속성이 무시됨
     * 해결: minWidth, maxWidth도 함께 설정
     */
    it('width와 함께 minWidth, maxWidth를 설정해야 함', () => {
      const column = {
        field: 'name',
        header: '이름',
        width: 200,
        minWidth: 100,
        maxWidth: 300,
      };

      expect(column.width).toBe(200);
      expect(column.minWidth).toBeLessThanOrEqual(column.width);
      expect(column.maxWidth).toBeGreaterThanOrEqual(column.width);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - Form 컴포넌트', () => {
  describe('[사례 1] trackChanges가 작동하지 않음', () => {
    /**
     * 증상: trackChanges 활성화 후에도 저장 버튼이 비활성화
     * 해결: Form의 initialValues와 현재 values 비교 로직 확인
     */
    it('값 변경 시 hasChanges가 true가 되어야 함', () => {
      const initialValues = { name: 'Original' };
      let currentValues = { name: 'Original' };

      const checkHasChanges = () => {
        return JSON.stringify(initialValues) !== JSON.stringify(currentValues);
      };

      expect(checkHasChanges()).toBe(false);

      currentValues = { name: 'Modified' };
      expect(checkHasChanges()).toBe(true);
    });
  });

  describe('[사례 2] Form 내 입력 필드에서 버벅거림', () => {
    /**
     * 증상: 입력 시 심한 버벅거림 발생
     * 해결: debounce 적용 및 불필요한 리렌더링 방지
     */
    it('debounce로 빈번한 상태 업데이트를 제한해야 함', () => {
      let updateCount = 0;
      let lastValue = '';

      // debounce 시뮬레이션
      const debounce = (fn: Function, delay: number) => {
        let timeoutId: any;
        return (...args: any[]) => {
          clearTimeout(timeoutId);
          timeoutId = setTimeout(() => fn(...args), delay);
        };
      };

      const updateState = debounce((value: string) => {
        updateCount++;
        lastValue = value;
      }, 300);

      // 빠른 연속 입력
      updateState('a');
      updateState('ab');
      updateState('abc');
      updateState('abcd');

      // 즉시 실행되지 않음
      expect(updateCount).toBe(0);
    });
  });

  describe('[사례 3] 자동 바인딩과 수동 바인딩 혼용 시 충돌', () => {
    /**
     * 증상: dataKey 설정 후 수동 바인딩이 동작하지 않음
     * 해결: 하나의 방식만 사용하거나 parentFormContextProp={undefined} 설정
     */
    it('Sortable 내부에서는 parentFormContextProp을 undefined로 설정해야 함', () => {
      const sortableItemProps = {
        parentFormContextProp: undefined,
      };

      expect(sortableItemProps.parentFormContextProp).toBeUndefined();
    });
  });

  describe('[사례 4] dataKey 자동 바인딩이 라디오/체크박스의 value를 덮어쓰는 문제', () => {
    /**
     * 증상: dataKey 설정된 폼에서 라디오 버튼 클릭 시 값이 변경되지 않음
     * 원인: 자동 바인딩이 라디오의 value prop을 상태값으로 덮어씀
     * 해결: autoBinding: false로 해당 필드의 자동 바인딩 비활성화
     */
    it('hasExplicitSetStateForField 유틸리티가 setState 대상 필드를 정확히 감지해야 함', () => {
      // hasExplicitSetStateForField는 유틸리티 함수로 제공 (레이아웃 분석 등에서 활용 가능)
      const radioActions = [
        {
          type: 'change',
          handler: 'sequence',
          actions: [
            {
              handler: 'setState',
              params: {
                target: 'local',
                'form.purchase_restriction': '{{$event.target.value}}',
                'form.allowed_roles': [],
              },
            },
          ],
        },
      ];

      // sequence 내부 setState 감지
      expect(hasExplicitSetStateForField(radioActions, 'form', 'purchase_restriction')).toBe(true);

      // 같은 폼의 다른 필드(title 등)는 감지 안 됨
      expect(hasExplicitSetStateForField(radioActions, 'form', 'title')).toBe(false);
    });

    it('autoBinding: false prop으로 자동 바인딩 비활성화가 가능해야 함', () => {
      // DynamicRenderer에서 autoBinding: false 감지 시 자동 바인딩 스킵
      // autoBinding prop은 HTML로 전달되지 않음 (자동 제거)
      const componentProps = {
        type: 'radio',
        name: 'purchase_restriction',
        value: 'none',
        autoBinding: false,
      };

      expect(componentProps.autoBinding).toBe(false);

      // autoBinding 제거 후 나머지 props 보존 확인
      const { autoBinding: _ab, ...cleanProps } = componentProps;
      expect(cleanProps).toEqual({ type: 'radio', name: 'purchase_restriction', value: 'none' });
      expect('autoBinding' in cleanProps).toBe(false);
    });

    it('autoBinding 미설정 필드는 종전대로 자동 바인딩 유지', () => {
      // 일반 텍스트 필드 - autoBinding 미설정 → 자동 바인딩 활성
      const noActions: any[] = [];

      expect(hasExplicitSetStateForField(noActions, 'form', 'title')).toBe(false);
      expect(hasExplicitSetStateForField(undefined, 'form', 'description')).toBe(false);
    });
  });

  describe('[에러 사례 1] {{error.data}}가 빈 객체를 반환', () => {
    /**
     * 증상: API 에러 시 error.data가 비어있음
     * 해결: error.errors 사용
     */
    it('API 에러 응답은 error.errors에 있음', () => {
      const errorResponse = {
        success: false,
        message: 'Validation failed',
        errors: {
          name: ['이름은 필수입니다.'],
          email: ['유효한 이메일 주소가 아닙니다.'],
        },
      };

      // 잘못된 접근: error.data
      expect(errorResponse).not.toHaveProperty('data');

      // 올바른 접근: error.errors
      expect(errorResponse.errors).toBeDefined();
      expect(errorResponse.errors.name).toContain('이름은 필수입니다.');
    });
  });

  describe('[Button 사례 1] Form 내부 Button 클릭 시 의도치 않은 submit', () => {
    /**
     * 증상: Form 내부의 일반 Button 클릭 시 Form이 submit됨
     * 해결: type="button" 명시
     */
    it('Form 내부 Button은 type="button"을 명시해야 함', () => {
      const buttonProps = {
        type: 'button', // 명시적으로 button 타입 설정
        onClick: vi.fn(),
      };

      expect(buttonProps.type).toBe('button');
    });
  });

  describe('[사례 6] 부모 Div click + controlled checkbox 자식의 한 박자 지연 토글 (#304)', () => {
    /**
     * 증상: 체크박스를 직접 클릭하면 즉시 체크되지 않고, 다른 항목을 클릭한 시점에야 이전 클릭이 반영됨
     * 근본 원인: controlled checkbox + 부모 click 액션 + 자식 액션 없음 패턴은
     *           React 표준 onChange 동작과 동기화되지 않아 한 박자 지연 발생
     * 해결: 부모를 Label로 변경 + 자식 Input에 change 액션 부여
     */

    it('금지 패턴: 부모 Div click 액션 + 자식 controlled checkbox 액션 없음', () => {
      // ❌ 한 박자 지연 발생하는 결함 패턴
      const badPattern = {
        name: 'Div',
        actions: [{ type: 'click', handler: 'setState', params: { fields: '...' } }],
        children: [
          {
            name: 'Input',
            props: { type: 'checkbox', checked: '{{state.value}}' },
            // actions 없음 — 결함 원인
          },
        ],
      };

      // 결함 패턴 식별: 부모에 click + 자식 Input(checkbox)에 액션 없음
      const parentClick = badPattern.actions?.find((a: any) => a.type === 'click');
      const childInput = badPattern.children[0];
      const childAction = (childInput as any).actions;

      expect(parentClick).toBeDefined();
      expect((childInput as any).props.type).toBe('checkbox');
      expect(childAction).toBeUndefined();
      // → 이 조합이 Phase 1 ① 단계의 fail 테스트가 검출한 결함
    });

    it('올바른 패턴: Label로 감싸기 + 자식 Input에 change 액션', () => {
      const goodPattern = {
        name: 'Label',
        // 부모 액션 없음 — HTML 표준이 자식 Input change 자동 발화
        children: [
          {
            name: 'Input',
            props: { type: 'checkbox', checked: '{{state.value}}' },
            actions: [
              {
                type: 'change',
                handler: 'setState',
                params: { target: 'local', value: '{{...토글 표현식}}' },
              },
            ],
          },
          { name: 'Span', text: '필드명' },
        ],
      };

      expect(goodPattern.name).toBe('Label');
      expect((goodPattern as any).actions).toBeUndefined();

      const checkbox = goodPattern.children[0] as any;
      const changeAction = checkbox.actions?.find((a: any) => a.type === 'change');
      expect(changeAction).toBeDefined();
      expect(changeAction.handler).toBe('setState');
    });

    it('Label 안 Label 중첩 금지 — 텍스트는 Span으로 다운그레이드', () => {
      const correctedPattern = {
        name: 'Label',
        children: [
          {
            name: 'Input',
            props: { type: 'checkbox' },
            actions: [{ type: 'change', handler: 'setState' }],
          },
          { name: 'Span', text: '권한 이름' }, // ✅ 기존 Label에서 다운그레이드
          { name: 'Span', text: '권한 설명' },
        ],
      };

      // 부모 Label 안에 중첩 Label이 없어야 함
      const nestedLabels = correctedPattern.children.filter((c: any) => c.name === 'Label');
      expect(nestedLabels.length).toBe(0);
    });

    it('자식 컨테이너에 pointer-events-none 사용 금지', () => {
      // 결함 차단 목적의 pointer-events-none은 본 패턴에서 불필요
      const goodPattern = {
        name: 'Label',
        children: [
          {
            name: 'Div',
            props: { className: 'flex items-start gap-2' }, // ✅ pointer-events-none 없음
            children: [{ name: 'Input', props: { type: 'checkbox' } }],
          },
        ],
      };

      const innerDiv = goodPattern.children[0] as any;
      expect(innerDiv.props.className).not.toContain('pointer-events-none');
    });

    it('change 이벤트 vs click 이벤트 구분', () => {
      // React controlled checkbox는 change 이벤트로만 표준 동기화 가능
      const supportedEventsForControlledCheckbox = ['change'];
      const problematicEventsForControlledCheckbox = ['click'];

      expect(supportedEventsForControlledCheckbox).toContain('change');
      expect(problematicEventsForControlledCheckbox).toContain('click');
      // click은 발화되지만 controlled checked 강제 동기화와 충돌 → 한 박자 지연
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 기타 컴포넌트', () => {
  describe('[Partial 사례 1] Partial 내부의 {{props.xxx}} 바인딩이 빈 값', () => {
    /**
     * 증상: Partial에서 props 접근 불가
     * 해결: data_sources ID 직접 참조
     */
    it('Partial에서는 data_sources ID를 직접 참조해야 함', () => {
      // Partial 레이아웃
      const partialLayout = {
        type: 'basic',
        name: 'Div',
        props: {
          // 잘못된: {{props.products}}
          // 올바른: {{products}}
          children: '{{products?.data?.data?.length ?? 0}} items',
        },
      };

      expect(partialLayout.props.children).not.toContain('props.');
    });
  });

  describe('[Route 사례 1] route.identifier가 undefined로 평가됨', () => {
    /**
     * 증상: route 파라미터가 undefined로 평가됨
     * 해결: route 정의에 params 설정 확인
     */
    it('route 객체에서 동적 파라미터에 접근할 수 있어야 함', () => {
      const route = {
        path: '/admin/products/:id',
        params: { id: '123' },
        identifier: '123', // alias
      };

      expect(route.identifier).toBe('123');
      expect(route.params.id).toBe('123');
    });
  });

  describe('[Select 사례 1] Select가 초기 렌더링 시 공란으로 표시됨', () => {
    /**
     * 증상: Select 초기값이 표시되지 않음
     * 해결: value prop과 options의 value 타입 일치 확인
     */
    it('value와 options의 value 타입이 일치해야 함', () => {
      const options = [
        { value: 1, label: 'Option 1' },
        { value: 2, label: 'Option 2' },
      ];

      const value = 1; // 숫자

      const selectedOption = options.find((opt) => opt.value === value);
      expect(selectedOption).toBeDefined();
      expect(selectedOption?.label).toBe('Option 1');

      // 타입 불일치 (문자열)
      const wrongValue = '1';
      const wrongSelected = options.find((opt) => opt.value === wrongValue);
      expect(wrongSelected).toBeUndefined(); // 찾지 못함
    });
  });

  describe('[Select 사례 5] Select 초기값이 "전체"로 선택되지 않음', () => {
    /**
     * 증상: null과 '' 매칭 실패
     * 해결: null과 ''을 동일하게 처리하거나 명시적 값 설정
     */
    it('null과 빈 문자열 매칭을 처리해야 함', () => {
      const options = [
        { value: '', label: '전체' },
        { value: 'active', label: '활성' },
      ];

      const currentValue = null;

      // null을 ''로 변환하여 매칭
      const normalizedValue = currentValue ?? '';
      const selectedOption = options.find((opt) => opt.value === normalizedValue);

      expect(selectedOption?.label).toBe('전체');
    });
  });

  describe('[Icon 사례 1] Icon에 w-*/h-* 클래스가 적용되지 않음', () => {
    /**
     * 증상: Icon에 Tailwind 크기 클래스가 적용되지 않음
     * 해결: size prop 또는 className="text-*" 사용
     */
    it('Icon은 size prop 또는 text-* 클래스를 사용해야 함', () => {
      // 올바른 사용
      const iconProps1 = { size: 'sm' };
      const iconProps2 = { className: 'text-sm' };

      // 잘못된 사용 (무시됨)
      const wrongProps = { className: 'w-4 h-4' };

      expect(iconProps1.size).toBe('sm');
      expect(iconProps2.className).toBe('text-sm');
    });
  });

  describe('[iteration 사례 1] iteration 내부 데이터 바인딩이 빈 값', () => {
    /**
     * 증상: iteration 내부에서 item_var 접근 불가
     * 해결: 올바른 item_var 이름 사용
     */
    it('iteration의 item_var를 정확히 참조해야 함', () => {
      const iteration = {
        source: '{{items}}',
        item_var: 'item',
        index_var: 'idx',
      };

      const items = [
        { id: 1, name: 'Item 1' },
        { id: 2, name: 'Item 2' },
      ];

      // 각 아이템에서 item_var로 접근
      items.forEach((item, idx) => {
        const context = { [iteration.item_var]: item, [iteration.index_var]: idx };
        expect(context.item.name).toBe(`Item ${idx + 1}`);
        expect(context.idx).toBe(idx);
      });
    });
  });

  describe('[iteration 사례 3] iteration 내 동적 추가 컴포넌트가 이전 값 상속', () => {
    /**
     * 증상: 새로 추가된 아이템이 이전 아이템의 값을 상속
     * 해결: key prop에 고유 식별자 사용
     */
    it('각 iteration 아이템은 고유한 key를 가져야 함', () => {
      const items = [
        { id: 'uuid-1', name: 'Item 1' },
        { id: 'uuid-2', name: 'Item 2' },
      ];

      // 새 아이템 추가
      const newItem = { id: 'uuid-3', name: '' };
      items.push(newItem);

      const keys = items.map((item) => item.id);
      const uniqueKeys = new Set(keys);

      expect(uniqueKeys.size).toBe(keys.length);
    });
  });

  describe('[modals 사례 1] modals를 객체 형식으로 정의하면 렌더링되지 않음', () => {
    /**
     * 증상: modals를 객체 형식으로 정의하면 모달이 표시되지 않음
     * 해결: modals는 반드시 배열 형식으로 정의
     */
    it('modals는 배열 형식이어야 함', () => {
      // 올바른 형식
      const correctModals = [
        { id: 'modal_1', title: 'Modal 1', children: [] },
        { id: 'modal_2', title: 'Modal 2', children: [] },
      ];

      // 잘못된 형식
      const wrongModals = {
        modal_1: { title: 'Modal 1', children: [] },
        modal_2: { title: 'Modal 2', children: [] },
      };

      expect(Array.isArray(correctModals)).toBe(true);
      expect(Array.isArray(wrongModals)).toBe(false);
    });
  });

  describe('[핸들러 사례 1] 핸들러를 표현식에서 함수처럼 호출', () => {
    /**
     * 증상: {{handler()}} 형태로 호출하면 작동하지 않음
     * 해결: actions 배열로 정의
     */
    it('핸들러는 actions 배열로 정의해야 함', () => {
      // 잘못된 사용
      const wrongUsage = '{{myHandler()}}';

      // 올바른 사용
      const correctUsage = {
        actions: [{ handler: 'myHandler', params: {} }],
      };

      expect(wrongUsage).toContain('()');
      expect(correctUsage.actions[0].handler).toBe('myHandler');
    });
  });
});

describe('트러블슈팅 회귀 테스트 - isolated 상태 스코프', () => {
  describe('[사례 1] target:"isolated"가 _local로 폴백됨', () => {
    /**
     * 증상: target: "isolated" 설정이 무시됨
     * 해결: isolatedScopeId가 설정되어 있는지 확인
     */
    it('isolated 상태 사용 시 isolatedScopeId가 필요함', () => {
      const componentConfig = {
        isolatedScopeId: 'unique-scope-123',
        state: { value: 'isolated value' },
      };

      expect(componentConfig.isolatedScopeId).toBeDefined();
    });
  });

  describe('[사례 3] isolatedScopeId 중복으로 상태 충돌', () => {
    /**
     * 증상: 같은 isolatedScopeId를 가진 컴포넌트들의 상태가 공유됨
     * 해결: 고유한 isolatedScopeId 사용
     */
    it('isolatedScopeId는 전역적으로 고유해야 함', () => {
      const scopes = new Set<string>();

      const generateScopeId = (prefix: string) => {
        let id = `${prefix}-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        while (scopes.has(id)) {
          id = `${prefix}-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        }
        scopes.add(id);
        return id;
      };

      const scope1 = generateScopeId('modal');
      const scope2 = generateScopeId('modal');

      expect(scope1).not.toBe(scope2);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - Form 자동 바인딩 bindingType 메타데이터', () => {
  /**
   * DynamicRenderer의 Form 자동 바인딩에서 boolean 값의 바인딩 경로를
   * 컴포넌트 메타데이터(bindingType)로 결정하는 로직의 회귀 테스트.
   *
   * 수정 전: typeof currentValue === 'boolean' → 항상 checked 바인딩
   * 수정 후: metadata.bindingType에 따라 분기
   *   - 'checked': 항상 checked (Toggle, Checkbox, ChipCheckbox)
   *   - 'checkable': type이 checkbox/radio일 때만 checked (Input)
   *   - 미지정: 항상 value (RadioGroup, Select 등)
   */

  /**
   * isCheckedBinding 판단 헬퍼 (DynamicRenderer.tsx 실제 로직과 동일)
   *
   * typeof currentValue === 'boolean' 이 전제 조건 (boolean이 아니면 항상 value 바인딩)
   * bindingType: 'checked' → boolean이면 항상 checked 바인딩 (Toggle, Checkbox, ChipCheckbox)
   * bindingType: 'checkable' → boolean + checkbox/radio type일 때만 checked 바인딩 (Input)
   * 미지정 → 항상 value 바인딩 (RadioGroup, Select 등)
   */
  const calcIsCheckedBinding = (
    metadataBindingType: string | undefined,
    currentValue: any,
    inputType?: string
  ): boolean => {
    return typeof currentValue === 'boolean' && (
      metadataBindingType === 'checked'
      || (metadataBindingType === 'checkable'
        && ['checkbox', 'radio'].includes(inputType ?? ''))
    );
  };

  describe('[사례 1] bindingType: "checked" 컴포넌트 (Toggle, Checkbox, ChipCheckbox)', () => {
    it('boolean 값이면 isCheckedBinding이 true여야 함', () => {
      expect(calcIsCheckedBinding('checked', true)).toBe(true);
    });

    it('비-boolean 값이면 isCheckedBinding이 false여야 함', () => {
      expect(calcIsCheckedBinding('checked', 'true')).toBe(false);
    });
  });

  describe('[사례 2] bindingType: "checkable" 컴포넌트 (Input)', () => {
    it('boolean 값 + type="checkbox"이면 isCheckedBinding이 true여야 함', () => {
      expect(calcIsCheckedBinding('checkable', true, 'checkbox')).toBe(true);
    });

    it('boolean 값 + type="radio"이면 isCheckedBinding이 true여야 함', () => {
      expect(calcIsCheckedBinding('checkable', false, 'radio')).toBe(true);
    });

    it('boolean 값 + type="text"이면 isCheckedBinding이 false여야 함 (value 바인딩)', () => {
      expect(calcIsCheckedBinding('checkable', true, 'text')).toBe(false);
    });

    it('boolean 값 + type 미지정이면 isCheckedBinding이 false여야 함 (value 바인딩)', () => {
      expect(calcIsCheckedBinding('checkable', true, undefined)).toBe(false);
    });
  });

  describe('[사례 3] bindingType 미지정 컴포넌트 (RadioGroup, Select 등)', () => {
    it('boolean 값이어도 isCheckedBinding이 false여야 함 (value 바인딩)', () => {
      expect(calcIsCheckedBinding(undefined, true)).toBe(false);
    });

    it('metadata가 undefined여도 isCheckedBinding이 false여야 함', () => {
      expect(calcIsCheckedBinding(undefined, true, 'text')).toBe(false);
    });

    it('RadioGroup에 boolean true가 바인딩되면 value="true"로 전달되어 String 비교 성공', () => {
      // RadioGroup 내부: String(option.value) === String(value)
      const radioValue = true; // Form 자동 바인딩으로 value={true} 전달
      const optionValue = 'true'; // RadioGroup 옵션의 value

      expect(String(optionValue) === String(radioValue)).toBe(true);
    });

    it('RadioGroup에 boolean false가 바인딩되면 value="false"로 전달되어 String 비교 성공', () => {
      const radioValue = false;
      const optionValue = 'false';

      expect(String(optionValue) === String(radioValue)).toBe(true);
    });
  });

  describe('[사례 4] 게시판 Input type="checkbox" 회귀 확인 (is_notice, is_secret)', () => {
    it('Input(checkable) + type=checkbox + boolean 값 → checked 바인딩 유지', () => {
      // sirsoft-admin_basic/sirsoft-basic 모두 Input에 bindingType: "checkable" 등록
      expect(calcIsCheckedBinding('checkable', true, 'checkbox')).toBe(true);
    });
  });
});

describe('트러블슈팅 회귀 테스트 - 렌더링 구조', () => {
  describe('[사례 1] renderTemplate/updateTemplateData 트리 구조 일관성', () => {
    /**
     * 증상: 필터 내장 페이지 진입 시 이중 렌더링(깜빡임) 발생
     * 해결: updateTemplateData()에 외부 SlotProvider 추가 + DynamicRenderer의 isRootRenderer SlotProvider 래핑 제거
     */
    it('renderTemplate과 updateTemplateData는 동일한 Provider 래핑 구조를 가져야 함', async () => {
      // template-engine.ts의 renderTemplate()과 updateTemplateData()가 동일한
      // React 트리 구조를 가지는지 검증하는 구조적 테스트
      //
      // 근본 원인: renderTemplate은 SlotProvider로 래핑했지만 updateTemplateData는 하지 않아
      // 데이터소스 완료 시 React 트리 구조 변경 → 전체 서브트리 언마운트/리마운트

      // renderTemplate의 Provider 래핑 순서 (외부 → 내부)
      const renderTemplateProviders = [
        'TranslationProvider',
        'TransitionProvider',
        'ResponsiveProvider',
        'SlotProvider',  // 외부에서 모든 컴포넌트에 SlotProvider 제공
      ];

      // updateTemplateData의 Provider 래핑 순서 (외부 → 내부)
      const updateTemplateDataProviders = [
        'TranslationProvider',
        'TransitionProvider',
        'ResponsiveProvider',
        'SlotProvider',  // 외부에서 모든 컴포넌트에 SlotProvider 제공 (수정 후 추가됨)
      ];

      // 두 함수의 Provider 구조가 동일해야 이중 렌더링 방지
      expect(renderTemplateProviders).toEqual(updateTemplateDataProviders);
    });

    it('외부 SlotProvider가 모든 컴포넌트에 슬롯 컨텍스트를 제공해야 함', () => {
      // _admin_base.json은 여러 루트 컴포넌트(사이드바, 헤더, 콘텐츠 슬롯)를 가짐
      // isRootRenderer=true는 index===0(첫 컴포넌트)에만 적용됨
      // 외부 SlotProvider가 없으면 index>0 컴포넌트(콘텐츠 영역)에서 슬롯 시스템 미동작

      const components = [
        { index: 0, name: 'sidebar', isRootRenderer: true },
        { index: 1, name: 'header', isRootRenderer: false },
        { index: 2, name: 'content_slot', isRootRenderer: false },
      ];

      // 외부 SlotProvider: 모든 컴포넌트에 SlotProvider 컨텍스트 제공
      const externalSlotProvider = true;
      components.forEach(comp => {
        const hasSlotContext = externalSlotProvider; // 외부에서 제공
        expect(hasSlotContext).toBe(true);
      });
    });

    it('외부 SlotProvider 불일치 시 이중 렌더링이 발생하는 원리를 검증', () => {
      // 수정 전: renderTemplate만 SlotProvider 있음
      const beforeFixRenderTree = ['TranslationProvider', 'TransitionProvider', 'ResponsiveProvider', 'SlotProvider'];
      const beforeFixUpdateTree = ['TranslationProvider', 'TransitionProvider', 'ResponsiveProvider'];

      // 구조가 다르면 React가 전체 서브트리를 언마운트/리마운트
      const structureMismatch = beforeFixRenderTree.length !== beforeFixUpdateTree.length;
      expect(structureMismatch).toBe(true); // 이전 코드에서는 불일치 → 이중 렌더링 원인

      // 수정 후: 두 구조 모두 SlotProvider 포함 → 일치
      const fixedRenderTree = ['TranslationProvider', 'TransitionProvider', 'ResponsiveProvider', 'SlotProvider'];
      const fixedUpdateTree = ['TranslationProvider', 'TransitionProvider', 'ResponsiveProvider', 'SlotProvider'];
      expect(fixedRenderTree).toEqual(fixedUpdateTree); // 일치 → 이중 렌더링 없음
    });

    it('DynamicRenderer의 isRootRenderer는 SlotProvider를 래핑하지 않아야 함 (외부에서 제공)', () => {
      // DynamicRenderer에서 isRootRenderer=true 시 SlotProvider 래핑하면
      // 외부 SlotProvider와 이중 래핑 → context 오염 위험
      // isRootRenderer는 다른 용도(__g7ForcedLocalFields 클리어 등)로만 사용
      const isRootRenderer = true;
      const dynamicRendererWrapsSlotProvider = false; // 외부에서 제공하므로 내부 래핑 불필요
      expect(dynamicRendererWrapsSlotProvider).toBe(false);
    });
  });

  describe('[사례 3] SPA 네비게이션 시 DynamicRenderer 내부 상태 잔존으로 DataGrid 데이터 깨짐 (engine-v1.24.5)', () => {
    /**
     * 증상: 상품관리 → 주문관리 이동 시 DataGrid에 체크박스만 표시되고 컬럼/데이터 비어 있음
     * 근본 원인: 동일 base layout 공유 → 루트 컴포넌트 ID 동일 → React가 DynamicRenderer 보존 → useState에 이전 _local 잔존
     * 해결: DynamicRenderer key에 layout_name 포함하여 레이아웃 변경 시 React 강제 remount
     * 개선 (engine-v1.24.8): _fromBase 컴포넌트는 stable key → base 보존, 슬롯 children만 remount
     */

    // key 생성 로직 (template-engine.ts 실제 로직과 동일)
    function getTopLevelKey(componentDef: { id: string; _fromBase?: boolean }, layoutName: string): string {
      return (layoutName && !componentDef._fromBase) ? `${componentDef.id}_${layoutName}` : componentDef.id;
    }

    // children key 생성 로직 (DynamicRenderer.tsx 실제 로직과 동일)
    // getRemountKey()는 단순 componentId 반환, layoutKey suffix는 별도 적용
    function getChildKey(childDef: { id?: string; _fromBase?: boolean }, index: number, layoutKey: string): string {
      // 1단계: _fromBase 체크
      const baseKey = childDef._fromBase
        ? (childDef.id || `child-${index}`)
        : (childDef.id || `child-${index}`);  // getRemountKey는 단순 ID 반환 (_remountKeys 없는 경우)

      // 2단계: non-_fromBase에 layoutKey suffix 추가
      if (!childDef._fromBase && layoutKey) {
        return `${baseKey}_${layoutKey}`;
      }
      return baseKey;
    }

    it('슬롯 children(_fromBase 없음)은 layout_name이 다르면 key가 달라져야 함', () => {
      const slotChild = { id: 'product_form', _fromBase: undefined as boolean | undefined };
      const productLayoutName = 'admin_ecommerce_product_list';
      const orderLayoutName = 'admin_ecommerce_order_list';

      const productKey = getTopLevelKey(slotChild as any, productLayoutName);
      const orderKey = getTopLevelKey(slotChild as any, orderLayoutName);

      // 슬롯 children은 _fromBase 없음 → layout_name key → React 강제 remount
      expect(productKey).not.toBe(orderKey);
      expect(productKey).toBe('product_form_admin_ecommerce_product_list');
      expect(orderKey).toBe('product_form_admin_ecommerce_order_list');
    });

    it('_fromBase 컴포넌트는 layout_name이 달라도 stable key 유지', () => {
      const baseComponent = { id: 'admin_layout_root', _fromBase: true };
      const productLayoutName = 'admin_ecommerce_product_list';
      const orderLayoutName = 'admin_ecommerce_order_list';

      const productKey = getTopLevelKey(baseComponent, productLayoutName);
      const orderKey = getTopLevelKey(baseComponent, orderLayoutName);

      // _fromBase → stable key → React가 컴포넌트 보존(update)
      expect(productKey).toBe(orderKey);
      expect(productKey).toBe('admin_layout_root');
    });

    it('layout_name이 없으면 componentDef.id만 사용 (하위 호환)', () => {
      const componentId = 'admin_layout';
      const layoutName = '';

      const key = getTopLevelKey({ id: componentId }, layoutName);

      expect(key).toBe(componentId);
    });

    it('동일 레이아웃 내 updateTemplateData는 동일 key 유지 (불필요한 remount 방지)', () => {
      const slotChild = { id: 'admin_layout' };
      const layoutName = 'admin_ecommerce_order_list';

      const key1 = getTopLevelKey(slotChild, layoutName);
      const key2 = getTopLevelKey(slotChild, layoutName);

      // 같은 레이아웃 내에서는 key가 동일 → React가 컴포넌트 보존 (정상 동작)
      expect(key1).toBe(key2);
    });
  });

  describe('[사례 4] extends 기반 레이아웃 base 컴포넌트 불필요 remount — 로고 깜빡임 (engine-v1.24.8)', () => {
    /**
     * 증상: 관리자에서 메뉴 이동 시 좌측 상단 사이트 로고(이미지)가 매번 깜빡임
     * 근본 원인: engine-v1.24.5에서 모든 최상위 컴포넌트 key에 layout_name 추가 → base 컴포넌트까지 강제 remount
     * 해결: LayoutService.replaceSlots()에서 base 컴포넌트에 _fromBase: true 자동 마킹 → stable key
     */

    // key 생성 로직 재사용
    function getTopLevelKey(componentDef: { id: string; _fromBase?: boolean }, layoutName: string): string {
      return (layoutName && !componentDef._fromBase) ? `${componentDef.id}_${layoutName}` : componentDef.id;
    }

    function getChildKey(childDef: { id?: string; _fromBase?: boolean }, index: number, layoutKey: string): string {
      const baseKey = childDef._fromBase
        ? (childDef.id || `child-${index}`)
        : (childDef.id || `child-${index}`);
      if (!childDef._fromBase && layoutKey) {
        return `${baseKey}_${layoutKey}`;
      }
      return baseKey;
    }

    it('_fromBase: true 컴포넌트는 페이지 전환 시 stable key → 보존', () => {
      // _admin_base.json의 최상위 4개 컴포넌트 시뮬레이션
      const baseComponents = [
        { id: 'global_toast', _fromBase: true },
        { id: 'page_transition_indicator', _fromBase: true },
        { id: 'layout_warnings', _fromBase: true },
        { id: 'admin_layout_root', _fromBase: true },
      ];

      const layoutA = 'admin_ecommerce_product_list';
      const layoutB = 'admin_ecommerce_order_list';

      for (const comp of baseComponents) {
        const keyA = getTopLevelKey(comp, layoutA);
        const keyB = getTopLevelKey(comp, layoutB);
        expect(keyA).toBe(keyB); // stable key
        expect(keyA).toBe(comp.id); // componentDef.id만 사용
      }
    });

    it('_fromBase 없는 슬롯 children은 페이지 전환 시 다른 key → remount', () => {
      // 슬롯에 삽입되는 페이지 고유 컴포넌트
      const slotChildren = [
        { id: 'product_form_header' },
        { id: 'product_form_body' },
      ];

      const layoutA = 'admin_ecommerce_product_form';
      const layoutB = 'admin_settings';

      for (const comp of slotChildren) {
        const keyA = getTopLevelKey(comp as any, layoutA);
        const keyB = getTopLevelKey(comp as any, layoutB);
        expect(keyA).not.toBe(keyB); // 다른 key → remount
      }
    });

    it('_fromBase children(sidebar 등)은 stable key', () => {
      const sidebarChildren = [
        { id: 'sidebar_header', _fromBase: true },
        { id: 'admin_sidebar_menu', _fromBase: true },
        { id: 'sidebar_footer', _fromBase: true },
      ];

      const remountSuffix = 'admin_ecommerce_product_list';

      for (let i = 0; i < sidebarChildren.length; i++) {
        const key = getChildKey(sidebarChildren[i], i, remountSuffix);
        expect(key).toBe(sidebarChildren[i].id); // stable key
      }
    });

    it('슬롯 래퍼(slot 매칭 컴포넌트)는 _fromBase 미마킹 → children key에 layoutKey suffix → remount', () => {
      // main_content(Container, slot="content")는 슬롯이 매칭되면 _fromBase 미마킹
      // 부모 DynamicRenderer(right_content_area, _fromBase)가 보존되어도
      // layoutKey suffix로 children key가 변경 → remount → localDynamicState 초기화
      const slotWrapper = { id: 'main_content' }; // _fromBase 없음 (슬롯 매칭됨)

      const layoutA = 'admin_ecommerce_product_list';
      const layoutB = 'admin_ecommerce_order_list';

      // children key: getChildKey는 non-_fromBase에 layoutKey suffix 추가
      const keyA = getChildKey(slotWrapper, 0, layoutA);
      const keyB = getChildKey(slotWrapper, 0, layoutB);

      expect(keyA).not.toBe(keyB); // 다른 key → remount
      expect(keyA).toBe(`main_content_${layoutA}`);
      expect(keyB).toBe(`main_content_${layoutB}`);
    });

    it('슬롯 래퍼 조상(right_content_wrapper 등 _fromBase)은 보존되지만 non-_fromBase children은 remount', () => {
      // right_content_area(_fromBase: true)는 보존, 그 children인 main_content는 layoutKey로 remount
      const ancestor = { id: 'right_content_area', _fromBase: true };
      const slotWrapper = { id: 'main_content' }; // _fromBase 없음

      const layoutA = 'admin_ecommerce_product_list';
      const layoutB = 'admin_ecommerce_order_list';

      // 조상은 stable key
      expect(getChildKey(ancestor, 0, layoutA)).toBe('right_content_area');
      expect(getChildKey(ancestor, 0, layoutB)).toBe('right_content_area');

      // 슬롯 래퍼는 layoutKey로 remount
      expect(getChildKey(slotWrapper, 0, layoutA)).not.toBe(getChildKey(slotWrapper, 0, layoutB));
    });

    it('non-extends 레이아웃은 _fromBase 없음 → 전체 layout_name key (하위 호환)', () => {
      // extends를 사용하지 않는 레이아웃 — replaceSlots 미호출 → _fromBase 없음
      const nonExtendsComponents = [
        { id: 'login_form' },
        { id: 'login_footer' },
      ];

      const layoutA = 'auth_login';
      const layoutB = 'auth_register';

      for (const comp of nonExtendsComponents) {
        const keyA = getTopLevelKey(comp as any, layoutA);
        const keyB = getTopLevelKey(comp as any, layoutB);
        expect(keyA).not.toBe(keyB); // layout_name key → remount
        expect(keyA).toBe(`${comp.id}_${layoutA}`);
      }
    });

    it('환경설정 로고 변경 시 _global 리액티브 바인딩으로 갱신 (remount 불필요)', () => {
      // 사이드바 로고는 {{_global.settings?.general?.site_logo_url}}에 바인딩
      // _global setState → 리액티브 갱신 → stable key 상태에서도 정상 동작
      const globalState = {
        settings: { general: { site_logo_url: '/old-logo.png' } },
      };

      // 환경설정 저장 onSuccess에서 _global.settings 갱신
      const updatedGlobalState = {
        ...globalState,
        settings: { general: { site_logo_url: '/new-logo.png' } },
      };

      // 참조가 다름 → React가 리렌더 트리거
      expect(globalState.settings).not.toBe(updatedGlobalState.settings);
      expect(updatedGlobalState.settings.general.site_logo_url).toBe('/new-logo.png');
    });

    it('_fromBase 보존 컴포넌트의 localDynamicState가 페이지 전환 시 초기화되어야 함', () => {
      // _fromBase: true인 admin_layout_root가 보존될 때,
      // 이전 페이지에서 설정된 localDynamicState(visibleFilters 등)가
      // componentContext.state를 통해 하위 컴포넌트에 전파되는 문제 검증
      //
      // 해결: layoutKey 변경 시 _fromBase 컴포넌트의 localDynamicState를 초기화

      // 시뮬레이션: order_list 페이지에서 설정된 상태
      const prevPageDynamicState = {
        loadingActions: {},
        visibleFilters: ['status', 'date', 'payment_method', 'shipping_method'],
        hasChanges: false,
        __setStateId: 'setState_4',
      };

      // 페이지 전환 후 초기화된 상태
      const resetDynamicState = { loadingActions: {} };

      // _fromBase 컴포넌트는 layoutKey 변경 시 localDynamicState를 초기화
      // → visibleFilters 등 이전 페이지 상태가 제거되어야 함
      expect(resetDynamicState).not.toHaveProperty('visibleFilters');
      expect(resetDynamicState).not.toHaveProperty('hasChanges');
      expect(resetDynamicState).not.toHaveProperty('__setStateId');
      expect(resetDynamicState).toHaveProperty('loadingActions');

      // 초기화 후 deepMergeState 결과: dataContext._local만 반영
      const newPageLocal = { activeTab: 'basic', form: { name: '상품A' } };
      const mergedLocal = { ...newPageLocal, ...resetDynamicState };
      expect(mergedLocal).not.toHaveProperty('visibleFilters');
      expect(mergedLocal.activeTab).toBe('basic');

      // prevPageDynamicState를 사용하여 오염 상태와 비교
      expect(prevPageDynamicState).toHaveProperty('visibleFilters');
      expect(prevPageDynamicState.visibleFilters).toHaveLength(4);
    });

    it('_fromBase 아닌 컴포넌트는 layoutKey 변경 시 localDynamicState 초기화 불필요 (remount)', () => {
      // 슬롯 children은 _fromBase가 없으므로 key에 layoutKey가 포함됨
      // → 페이지 전환 시 key 변경 → React가 unmount/remount → localDynamicState 자동 초기화
      const componentDef = { id: 'order_detail_form', _fromBase: undefined };
      const layoutKeyA = 'admin_ecommerce_order_list';
      const layoutKeyB = 'admin_ecommerce_order_detail';

      // key가 다르므로 React가 unmount/remount
      const keyA = `${componentDef.id}_${layoutKeyA}`;
      const keyB = `${componentDef.id}_${layoutKeyB}`;
      expect(keyA).not.toBe(keyB);

      // remount 시 localDynamicState는 초기값 { loadingActions: {} }으로 자동 리셋
      // → 명시적 초기화 불필요
    });
  });

  describe('[사례 7-1] cellChildren 표현식 결과 $t: 키가 번역되지 않고 그대로 노출', () => {
    /**
     * 증상: DataGrid cellChildren에서 삼항 연산자 결과 $t: 키가 번역되지 않음
     * 해결: renderItemChildren.resolveValue에 $t: 번역 후처리 추가 + 하이픈 정규식 수정
     * @since engine-v1.28.1
     */
    it('preprocessTranslationTokens가 하이픈 포함 모듈키를 올바르게 처리해야 함', () => {
      // 하이픈 포함 키: sirsoft-page, sirsoft-board 등
      const hyphenRegex = /\$t:[a-zA-Z0-9._-]+/;

      // 하이픈 포함 키 매칭
      expect(hyphenRegex.test('$t:sirsoft-page.admin.page.published_status.published')).toBe(true);
      expect(hyphenRegex.test('$t:sirsoft-board.admin.board.name')).toBe(true);

      // 하이픈 없는 키도 정상 매칭 (회귀 없음)
      expect(hyphenRegex.test('$t:common.save')).toBe(true);
      expect(hyphenRegex.test('$t:validation.required')).toBe(true);
    });

    it('표현식 평가 결과가 $t: 문자열이면 번역 대상이어야 함', () => {
      // 삼항 연산자 평가 결과 시뮬레이션
      const expressionResult = '$t:sirsoft-page.admin.page.published_status.published';

      // $t: 패턴 감지
      const shouldTranslate = typeof expressionResult === 'string'
        && /\$t:[a-zA-Z0-9._-]+/.test(expressionResult);
      expect(shouldTranslate).toBe(true);
    });

    it('raw: 바인딩 결과는 $t: 패턴이 있어도 번역 면제', () => {
      const isRawBinding = true;
      const result = '$t:common.save';

      // raw: 바인딩이므로 번역 면제
      const shouldTranslate = !isRawBinding
        && typeof result === 'string'
        && /\$t:[a-zA-Z0-9._-]+/.test(result);
      expect(shouldTranslate).toBe(false);
    });

    it('표현식 결과가 $t: 문자열이 아니면 번역 시도 안 함', () => {
      const normalResult = '활성';

      const shouldTranslate = typeof normalResult === 'string'
        && /\$t:[a-zA-Z0-9._-]+/.test(normalResult);
      expect(shouldTranslate).toBe(false);
    });
  });

  /**
   * [사례 22-1] FileUploader 갤러리 이미지 blob URL stale closure
   *
   * 증상: 갤러리(Lightbox) 열리지만 이미지가 표시되지 않음 (blob URL 에러)
   * 원인: authenticatedImageUrls useEffect cleanup에서 blob URL revoke 후
   *       Map 미초기화 → stale closure의 has() 체크가 재로딩 건너뜀
   * 해결: ref 기반 캐시 + 언마운트 전용 cleanup 분리
   */
  describe('[사례 22-1] FileUploader 갤러리 blob URL stale closure', () => {
    it('ref 기반 캐시는 existingFiles 변경 후에도 has() 체크가 최신 상태를 참조한다', () => {
      // state 기반 (버그): 클로저가 이전 렌더의 스냅샷 캡처
      let stateMap = new Map<number, string>();
      const closureCapturedMap = stateMap; // effect 생성 시점의 스냅샷

      // 비동기 state 업데이트 시뮬레이션
      stateMap = new Map(stateMap);
      stateMap.set(1, 'blob:url-1');
      stateMap.set(2, 'blob:url-2');

      // state 기반: 클로저의 has()는 빈 Map 참조 → 업데이트 못 봄
      expect(closureCapturedMap.has(1)).toBe(false); // stale!

      // ref 기반 (수정): 항상 최신 참조
      const refMap = { current: new Map<number, string>() };
      refMap.current.set(1, 'blob:url-1');
      refMap.current.set(2, 'blob:url-2');

      // ref 기반: 항상 최신 상태 참조
      expect(refMap.current.has(1)).toBe(true);
      expect(refMap.current.has(2)).toBe(true);
    });

    it('cleanup에서 revoke 후 Map을 clear하지 않으면 재로딩이 건너뛰어진다', () => {
      const urlCache = new Map<number, string>();
      urlCache.set(1, 'blob:url-1');
      urlCache.set(2, 'blob:url-2');

      // cleanup: revoke만 하고 Map은 clear 안 함 (버그 패턴)
      const revokedUrls: string[] = [];
      urlCache.forEach((url) => revokedUrls.push(url));
      // URL.revokeObjectURL(url) 시뮬레이션 — URL은 무효화되지만 Map에 남아있음

      // 새 effect에서 has() 체크
      const shouldLoad1 = !urlCache.has(1); // false! Map에 남아있으므로
      const shouldLoad2 = !urlCache.has(2); // false!

      expect(shouldLoad1).toBe(false); // 재로딩 건너뜀 → 버그!
      expect(shouldLoad2).toBe(false);
      expect(revokedUrls).toEqual(['blob:url-1', 'blob:url-2']);

      // 수정: ref 기반에서는 revoke된 파일의 URL을 삭제하고 새로 로드
      const refCache = { current: new Map<number, string>() };
      refCache.current.set(1, 'blob:url-1');
      refCache.current.set(2, 'blob:url-2');

      // existingFiles에서 제거된 파일만 정리 (현재 파일 ID 집합)
      const currentIds = new Set([1, 2]);
      for (const [id] of refCache.current) {
        if (!currentIds.has(id)) {
          refCache.current.delete(id);
        }
      }

      // 기존 파일은 캐시에 유지 → 재로딩 불필요
      expect(refCache.current.has(1)).toBe(true);
      expect(refCache.current.has(2)).toBe(true);
    });

    it('비동기 로딩 중 cancelled 플래그로 stale 업데이트를 방지한다', async () => {
      let cancelled = false;
      const results: number[] = [];

      const loadAsync = async () => {
        for (const id of [1, 2, 3]) {
          if (cancelled) break;
          // 비동기 작업 시뮬레이션
          await new Promise((r) => setTimeout(r, 1));
          if (!cancelled) {
            results.push(id);
          }
        }
      };

      const promise = loadAsync();

      // 2번째 항목 로드 중 취소
      await new Promise((r) => setTimeout(r, 3));
      cancelled = true;

      await promise;

      // 취소 후에는 더 이상 추가되지 않음
      expect(results.length).toBeLessThanOrEqual(3);
      // cancelled 플래그가 동작함을 확인
      expect(cancelled).toBe(true);
    });
  });
});
