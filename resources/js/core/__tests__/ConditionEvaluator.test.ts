/**
 * ConditionEvaluator 단위 테스트
 *
 * conditions 통합 조건 시스템의 핵심 함수들을 테스트합니다.
 * - evaluateStringCondition: 문자열 조건 평가
 * - evaluateConditionExpression: AND/OR 그룹 평가
 * - evaluateConditionBranches: if/else 체인 평가
 * - evaluateConditions: 통합 평가
 *
 * @since engine-v1.10.0
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  evaluateStringCondition,
  evaluateConditionExpression,
  evaluateConditionBranches,
  evaluateConditions,
  isConditionExpression,
  isConditionBranches,
  type ConditionExpression,
  type ConditionBranch,
  type ConditionsProperty,
} from '../template-engine/helpers/ConditionEvaluator';
import { evaluateIfCondition } from '../template-engine/helpers/RenderHelpers';
import { DataBindingEngine } from '../template-engine/DataBindingEngine';

describe('ConditionEvaluator', () => {
  let bindingEngine: DataBindingEngine;

  beforeEach(() => {
    bindingEngine = new DataBindingEngine();
  });

  // ==========================================================================
  // evaluateStringCondition 테스트
  // ==========================================================================
  describe('evaluateStringCondition', () => {
    describe('JavaScript 리터럴 표현식', () => {
      it('{{true}}는 true 반환', () => {
        expect(evaluateStringCondition('{{true}}', {}, bindingEngine)).toBe(true);
      });

      it('{{false}}는 false 반환', () => {
        expect(evaluateStringCondition('{{false}}', {}, bindingEngine)).toBe(false);
      });

      it('{{null}}은 false 반환', () => {
        expect(evaluateStringCondition('{{null}}', {}, bindingEngine)).toBe(false);
      });

      it('{{undefined}}은 false 반환', () => {
        expect(evaluateStringCondition('{{undefined}}', {}, bindingEngine)).toBe(false);
      });

      it('컨텍스트에 true 키가 있어도 리터럴 우선', () => {
        const context = { true: false };
        expect(evaluateStringCondition('{{true}}', context, bindingEngine)).toBe(true);
      });
    });

    describe('단순 바인딩 표현식', () => {
      it('truthy 값은 true 반환', () => {
        const context = { user: { isAdmin: true } };
        expect(evaluateStringCondition('{{user.isAdmin}}', context, bindingEngine)).toBe(true);
      });

      it('falsy 값(false)은 false 반환', () => {
        const context = { user: { isAdmin: false } };
        expect(evaluateStringCondition('{{user.isAdmin}}', context, bindingEngine)).toBe(false);
      });

      it('falsy 값(0)은 false 반환', () => {
        const context = { count: 0 };
        expect(evaluateStringCondition('{{count}}', context, bindingEngine)).toBe(false);
      });

      it('falsy 값(null)은 false 반환', () => {
        const context = { value: null };
        expect(evaluateStringCondition('{{value}}', context, bindingEngine)).toBe(false);
      });

      it('falsy 값(undefined)은 false 반환', () => {
        const context = { value: undefined };
        expect(evaluateStringCondition('{{value}}', context, bindingEngine)).toBe(false);
      });

      it('빈 문자열은 false 반환', () => {
        const context = { value: '' };
        expect(evaluateStringCondition('{{value}}', context, bindingEngine)).toBe(false);
      });

      it('문자열 "false"는 false 반환', () => {
        const context = { value: 'false' };
        expect(evaluateStringCondition('{{value}}', context, bindingEngine)).toBe(false);
      });

      it('존재하지 않는 경로는 false 반환', () => {
        const context = { user: {} };
        expect(evaluateStringCondition('{{user.notExist}}', context, bindingEngine)).toBe(false);
      });
    });

    describe('비교 연산자', () => {
      it('=== 동등 비교', () => {
        const context = { status: 'active' };
        expect(evaluateStringCondition("{{status === 'active'}}", context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition("{{status === 'inactive'}}", context, bindingEngine)).toBe(false);
      });

      it('!== 부등 비교', () => {
        const context = { status: 'active' };
        expect(evaluateStringCondition("{{status !== 'inactive'}}", context, bindingEngine)).toBe(true);
      });

      it('숫자 비교 (<, >, <=, >=)', () => {
        const context = { count: 10 };
        expect(evaluateStringCondition('{{count > 5}}', context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition('{{count < 5}}', context, bindingEngine)).toBe(false);
        expect(evaluateStringCondition('{{count >= 10}}', context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition('{{count <= 10}}', context, bindingEngine)).toBe(true);
      });
    });

    describe('논리 연산자', () => {
      it('&& AND 연산', () => {
        const context = { a: true, b: true, c: false };
        expect(evaluateStringCondition('{{a && b}}', context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition('{{a && c}}', context, bindingEngine)).toBe(false);
      });

      it('|| OR 연산', () => {
        const context = { a: false, b: true, c: false };
        expect(evaluateStringCondition('{{a || b}}', context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition('{{a || c}}', context, bindingEngine)).toBe(false);
      });

      it('! NOT 연산', () => {
        const context = { value: false };
        expect(evaluateStringCondition('{{!value}}', context, bindingEngine)).toBe(true);
      });
    });

    describe('Optional Chaining', () => {
      it('존재하는 중첩 경로', () => {
        const context = { user: { profile: { name: 'John' } } };
        expect(evaluateStringCondition('{{user?.profile?.name}}', context, bindingEngine)).toBe(true);
      });

      it('중간에 null인 경로', () => {
        const context = { user: { profile: null } };
        expect(evaluateStringCondition('{{user?.profile?.name}}', context, bindingEngine)).toBe(false);
      });

      it('최상위가 존재하지 않는 경로', () => {
        const context = {};
        expect(evaluateStringCondition('{{user?.profile?.name}}', context, bindingEngine)).toBe(false);
      });
    });

    describe('삼항 연산자', () => {
      it('조건 true일 때', () => {
        const context = { isEdit: true };
        expect(evaluateStringCondition("{{isEdit ? 'yes' : 'no'}}", context, bindingEngine)).toBe(true); // 'yes' → truthy
      });

      it('조건 false일 때 빈 문자열 반환', () => {
        const context = { isEdit: false };
        expect(evaluateStringCondition("{{isEdit ? 'yes' : ''}}", context, bindingEngine)).toBe(false); // '' → falsy
      });
    });

    describe('함수 호출', () => {
      it('_global.hasPermission 같은 함수 호출', () => {
        const context = {
          _global: {
            hasPermission: (perm: string) => perm === 'admin',
          },
        };
        expect(evaluateStringCondition("{{_global.hasPermission('admin')}}", context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition("{{_global.hasPermission('guest')}}", context, bindingEngine)).toBe(false);
      });

      it('배열 메서드 호출', () => {
        const context = {
          items: [1, 2, 3],
        };
        expect(evaluateStringCondition('{{items.length > 0}}', context, bindingEngine)).toBe(true);
        expect(evaluateStringCondition('{{items.includes(2)}}', context, bindingEngine)).toBe(true);
      });
    });

    describe('빈 조건', () => {
      it('빈 문자열 조건은 true 반환', () => {
        expect(evaluateStringCondition('', {}, bindingEngine)).toBe(true);
      });

      it('undefined 조건은 true 반환', () => {
        expect(evaluateStringCondition(undefined as any, {}, bindingEngine)).toBe(true);
      });
    });
  });

  // ==========================================================================
  // evaluateConditionExpression 테스트
  // ==========================================================================
  describe('evaluateConditionExpression', () => {
    describe('문자열 조건', () => {
      it('문자열은 evaluateStringCondition으로 평가', () => {
        const context = { user: { isAdmin: true } };
        expect(evaluateConditionExpression('{{user.isAdmin}}', context, bindingEngine)).toBe(true);
      });
    });

    describe('AND 그룹', () => {
      it('모든 조건 true → true', () => {
        const context = { a: true, b: true, c: true };
        const condition: ConditionExpression = {
          and: ['{{a}}', '{{b}}', '{{c}}'],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(true);
      });

      it('하나라도 false → false', () => {
        const context = { a: true, b: false, c: true };
        const condition: ConditionExpression = {
          and: ['{{a}}', '{{b}}', '{{c}}'],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(false);
      });

      it('빈 AND 그룹은 true 반환', () => {
        const condition: ConditionExpression = { and: [] };
        expect(evaluateConditionExpression(condition, {}, bindingEngine)).toBe(true);
      });

      it('단락 평가: 첫 false에서 중단', () => {
        const evalOrder: string[] = [];
        const context = {
          get first() {
            evalOrder.push('first');
            return false;
          },
          get second() {
            evalOrder.push('second');
            return true;
          },
        };
        const condition: ConditionExpression = {
          and: ['{{first}}', '{{second}}'],
        };
        evaluateConditionExpression(condition, context, bindingEngine);
        expect(evalOrder).toEqual(['first']); // second는 평가되지 않음
      });
    });

    describe('OR 그룹', () => {
      it('하나라도 true → true', () => {
        const context = { a: false, b: true, c: false };
        const condition: ConditionExpression = {
          or: ['{{a}}', '{{b}}', '{{c}}'],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(true);
      });

      it('모든 조건 false → false', () => {
        const context = { a: false, b: false, c: false };
        const condition: ConditionExpression = {
          or: ['{{a}}', '{{b}}', '{{c}}'],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(false);
      });

      it('빈 OR 그룹은 false 반환', () => {
        const condition: ConditionExpression = { or: [] };
        expect(evaluateConditionExpression(condition, {}, bindingEngine)).toBe(false);
      });

      it('단락 평가: 첫 true에서 중단', () => {
        const evalOrder: string[] = [];
        const context = {
          get first() {
            evalOrder.push('first');
            return true;
          },
          get second() {
            evalOrder.push('second');
            return false;
          },
        };
        const condition: ConditionExpression = {
          or: ['{{first}}', '{{second}}'],
        };
        evaluateConditionExpression(condition, context, bindingEngine);
        expect(evalOrder).toEqual(['first']); // second는 평가되지 않음
      });
    });

    describe('중첩 AND/OR', () => {
      it('OR 안에 AND', () => {
        const context = { isAdmin: false, isManager: true, department: 'sales' };
        // isAdmin이거나 (isManager이면서 sales 부서)
        const condition: ConditionExpression = {
          or: [
            '{{isAdmin}}',
            {
              and: ['{{isManager}}', "{{department === 'sales'}}"],
            },
          ],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(true);
      });

      it('AND 안에 OR', () => {
        const context = { isLoggedIn: true, hasBasicPlan: false, hasPremiumPlan: true };
        // 로그인하고 (기본 플랜 또는 프리미엄 플랜)
        const condition: ConditionExpression = {
          and: [
            '{{isLoggedIn}}',
            {
              or: ['{{hasBasicPlan}}', '{{hasPremiumPlan}}'],
            },
          ],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(true);
      });

      it('복잡한 3단 중첩', () => {
        const context = {
          user: { role: 'editor', department: 'content' },
          feature: { enabled: true },
        };
        // (editor이면서 content 부서) 또는 feature enabled
        const condition: ConditionExpression = {
          or: [
            {
              and: [
                "{{user.role === 'editor'}}",
                "{{user.department === 'content'}}",
              ],
            },
            '{{feature.enabled}}',
          ],
        };
        expect(evaluateConditionExpression(condition, context, bindingEngine)).toBe(true);
      });
    });
  });

  // ==========================================================================
  // evaluateConditionBranches 테스트
  // ==========================================================================
  describe('evaluateConditionBranches', () => {
    describe('if/else if/else 체인', () => {
      it('첫 번째 조건 매칭', () => {
        const context = { mode: 'edit' };
        const branches: ConditionBranch[] = [
          { if: "{{mode === 'edit'}}", then: { handler: 'save' } },
          { if: "{{mode === 'view'}}", then: { handler: 'view' } },
          { then: { handler: 'default' } },
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(true);
        expect(result.branchIndex).toBe(0);
      });

      it('두 번째 조건 매칭', () => {
        const context = { mode: 'view' };
        const branches: ConditionBranch[] = [
          { if: "{{mode === 'edit'}}", then: { handler: 'save' } },
          { if: "{{mode === 'view'}}", then: { handler: 'view' } },
          { then: { handler: 'default' } },
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(true);
        expect(result.branchIndex).toBe(1);
      });

      it('else 브랜치 매칭', () => {
        const context = { mode: 'other' };
        const branches: ConditionBranch[] = [
          { if: "{{mode === 'edit'}}", then: { handler: 'save' } },
          { if: "{{mode === 'view'}}", then: { handler: 'view' } },
          { then: { handler: 'default' } }, // else
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(true);
        expect(result.branchIndex).toBe(2);
      });

      it('매칭 없음 (else 없이)', () => {
        const context = { mode: 'other' };
        const branches: ConditionBranch[] = [
          { if: "{{mode === 'edit'}}", then: { handler: 'save' } },
          { if: "{{mode === 'view'}}", then: { handler: 'view' } },
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(false);
        expect(result.branchIndex).toBe(-1);
      });
    });

    describe('AND/OR 조건 브랜치', () => {
      it('AND 그룹 조건', () => {
        const context = { user: { isLoggedIn: true, hasPermission: true } };
        const branches: ConditionBranch[] = [
          {
            if: { and: ['{{user.isLoggedIn}}', '{{user.hasPermission}}'] },
            then: { handler: 'proceed' },
          },
          { then: { handler: 'deny' } },
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(true);
        expect(result.branchIndex).toBe(0);
      });

      it('OR 그룹 조건', () => {
        const context = { user: { isAdmin: false, isModerator: true } };
        const branches: ConditionBranch[] = [
          {
            if: { or: ['{{user.isAdmin}}', '{{user.isModerator}}'] },
            then: { handler: 'moderate' },
          },
          { then: { handler: 'view' } },
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(true);
        expect(result.branchIndex).toBe(0);
      });
    });

    describe('엣지 케이스', () => {
      it('빈 배열은 매칭 없음', () => {
        const result = evaluateConditionBranches([], {}, bindingEngine);
        expect(result.matched).toBe(false);
        expect(result.branchIndex).toBe(-1);
      });

      it('then 없는 브랜치도 정상 처리', () => {
        const context = { show: true };
        const branches: ConditionBranch[] = [
          { if: '{{show}}' }, // then 없음 (컴포넌트용)
        ];
        const result = evaluateConditionBranches(branches, context, bindingEngine);
        expect(result.matched).toBe(true);
        expect(result.branchIndex).toBe(0);
      });
    });
  });

  // ==========================================================================
  // evaluateConditions 테스트
  // ==========================================================================
  describe('evaluateConditions', () => {
    describe('단일 ConditionExpression', () => {
      it('문자열 조건', () => {
        const context = { show: true };
        expect(evaluateConditions('{{show}}', context, bindingEngine)).toBe(true);
      });

      it('AND 그룹', () => {
        const context = { a: true, b: true };
        const cond: ConditionsProperty = { and: ['{{a}}', '{{b}}'] };
        expect(evaluateConditions(cond, context, bindingEngine)).toBe(true);
      });

      it('OR 그룹', () => {
        const context = { a: false, b: true };
        const cond: ConditionsProperty = { or: ['{{a}}', '{{b}}'] };
        expect(evaluateConditions(cond, context, bindingEngine)).toBe(true);
      });
    });

    describe('ConditionBranch[] (if/else 체인)', () => {
      it('하나라도 매칭되면 true', () => {
        const context = { role: 'admin' };
        const cond: ConditionsProperty = [
          { if: "{{role === 'admin'}}" },
          { if: "{{role === 'manager'}}" },
        ];
        expect(evaluateConditions(cond, context, bindingEngine)).toBe(true);
      });

      it('모든 조건 false면 false (else 없이)', () => {
        const context = { role: 'guest' };
        const cond: ConditionsProperty = [
          { if: "{{role === 'admin'}}" },
          { if: "{{role === 'manager'}}" },
        ];
        expect(evaluateConditions(cond, context, bindingEngine)).toBe(false);
      });

      it('else 브랜치 있으면 항상 true', () => {
        const context = { role: 'guest' };
        const cond: ConditionsProperty = [
          { if: "{{role === 'admin'}}" },
          {}, // else 브랜치
        ];
        expect(evaluateConditions(cond, context, bindingEngine)).toBe(true);
      });
    });
  });

  // ==========================================================================
  // 타입 가드 테스트
  // ==========================================================================
  describe('타입 가드', () => {
    describe('isConditionExpression', () => {
      it('문자열은 true', () => {
        expect(isConditionExpression('{{user.isAdmin}}')).toBe(true);
      });

      it('AND 객체는 true', () => {
        expect(isConditionExpression({ and: ['{{a}}', '{{b}}'] })).toBe(true);
      });

      it('OR 객체는 true', () => {
        expect(isConditionExpression({ or: ['{{a}}', '{{b}}'] })).toBe(true);
      });

      it('ConditionBranch 배열은 false', () => {
        expect(isConditionExpression([{ if: '{{a}}' }])).toBe(false);
      });

      it('null/undefined는 false', () => {
        expect(isConditionExpression(null)).toBe(false);
        expect(isConditionExpression(undefined)).toBe(false);
      });
    });

    describe('isConditionBranches', () => {
      it('ConditionBranch 배열은 true', () => {
        expect(isConditionBranches([{ if: '{{a}}' }])).toBe(true);
        expect(isConditionBranches([{ then: { handler: 'test' } }])).toBe(true);
        expect(isConditionBranches([{ if: '{{a}}', then: { handler: 'test' } }])).toBe(true);
      });

      it('빈 배열은 false', () => {
        expect(isConditionBranches([])).toBe(false);
      });

      it('문자열 배열은 false', () => {
        expect(isConditionBranches(['{{a}}', '{{b}}'])).toBe(false);
      });

      it('문자열은 false', () => {
        expect(isConditionBranches('{{a}}')).toBe(false);
      });
    });
  });

  // ==========================================================================
  // 실제 사용 시나리오 테스트
  // ==========================================================================
  describe('실제 사용 시나리오', () => {
    it('컴포넌트 조건부 렌더링 - route.id와 form_data 둘 다 필요', () => {
      const context1 = { route: { id: '123' }, form_data: { data: { author: 'John' } } };
      const context2 = { route: { id: '123' }, form_data: null };
      const context3 = { route: {}, form_data: { data: { author: 'John' } } };

      const condition: ConditionExpression = {
        and: ['{{route.id}}', '{{form_data?.data?.author}}'],
      };

      expect(evaluateConditionExpression(condition, context1, bindingEngine)).toBe(true);
      expect(evaluateConditionExpression(condition, context2, bindingEngine)).toBe(false);
      expect(evaluateConditionExpression(condition, context3, bindingEngine)).toBe(false);
    });

    it('액션 분기 처리 - 행 액션별 다른 핸들러', () => {
      const branches: ConditionBranch[] = [
        {
          if: "{{$args[0] === 'edit'}}",
          then: { handler: 'navigate', params: { path: '/edit' } },
        },
        {
          if: "{{$args[0] === 'delete'}}",
          then: { handler: 'openModal', params: { id: 'confirm' } },
        },
        {
          then: { handler: 'toast', params: { message: '알 수 없는 액션' } },
        },
      ];

      // edit 액션
      const result1 = evaluateConditionBranches(branches, { $args: ['edit'] }, bindingEngine);
      expect(result1.branchIndex).toBe(0);

      // delete 액션
      const result2 = evaluateConditionBranches(branches, { $args: ['delete'] }, bindingEngine);
      expect(result2.branchIndex).toBe(1);

      // 알 수 없는 액션
      const result3 = evaluateConditionBranches(branches, { $args: ['unknown'] }, bindingEngine);
      expect(result3.branchIndex).toBe(2);
    });

    it('데이터 소스 조건부 fetch - 권한 및 route 조건', () => {
      const condition: ConditionExpression = {
        and: [
          '{{!!route.itemCode}}',
          "{{_global.hasPermission('view_product')}}",
        ],
      };

      // 권한 있고 itemCode 있음
      const context1 = {
        route: { itemCode: 'P001' },
        _global: { hasPermission: (p: string) => p === 'view_product' },
      };
      expect(evaluateConditionExpression(condition, context1, bindingEngine)).toBe(true);

      // 권한 없음
      const context2 = {
        route: { itemCode: 'P001' },
        _global: { hasPermission: () => false },
      };
      expect(evaluateConditionExpression(condition, context2, bindingEngine)).toBe(false);

      // itemCode 없음
      const context3 = {
        route: {},
        _global: { hasPermission: () => true },
      };
      expect(evaluateConditionExpression(condition, context3, bindingEngine)).toBe(false);
    });

    it('스크립트 조건부 로드 - 플러그인 활성화 또는 관리자', () => {
      const condition: ConditionExpression = {
        or: [
          "{{_global.installedPlugins?.find(p => p.identifier === 'analytics' && p.status === 'active')}}",
          "{{_global.user?.role === 'admin'}}",
        ],
      };

      // 플러그인 활성화됨
      const context1 = {
        _global: {
          installedPlugins: [{ identifier: 'analytics', status: 'active' }],
          user: { role: 'user' },
        },
      };
      expect(evaluateConditionExpression(condition, context1, bindingEngine)).toBe(true);

      // 관리자
      const context2 = {
        _global: {
          installedPlugins: [],
          user: { role: 'admin' },
        },
      };
      expect(evaluateConditionExpression(condition, context2, bindingEngine)).toBe(true);

      // 둘 다 아님
      const context3 = {
        _global: {
          installedPlugins: [],
          user: { role: 'user' },
        },
      };
      expect(evaluateConditionExpression(condition, context3, bindingEngine)).toBe(false);
    });
  });

  // ==========================================================================
  // evaluateIfCondition (RenderHelpers) 리터럴 테스트
  // ==========================================================================
  describe('evaluateIfCondition - JavaScript 리터럴', () => {
    it('{{true}}는 true 반환', () => {
      expect(evaluateIfCondition('{{true}}', {}, bindingEngine)).toBe(true);
    });

    it('{{false}}는 false 반환', () => {
      expect(evaluateIfCondition('{{false}}', {}, bindingEngine)).toBe(false);
    });

    it('{{null}}은 false 반환', () => {
      expect(evaluateIfCondition('{{null}}', {}, bindingEngine)).toBe(false);
    });

    it('{{undefined}}은 false 반환', () => {
      expect(evaluateIfCondition('{{undefined}}', {}, bindingEngine)).toBe(false);
    });

    it('컨텍스트에 true 키가 있어도 리터럴 우선', () => {
      const context = { true: false };
      expect(evaluateIfCondition('{{true}}', context, bindingEngine)).toBe(true);
    });

    it('일반 경로 표현식은 기존대로 동작', () => {
      const context = { user: { isAdmin: true } };
      expect(evaluateIfCondition('{{user.isAdmin}}', context, bindingEngine)).toBe(true);
    });
  });
});
