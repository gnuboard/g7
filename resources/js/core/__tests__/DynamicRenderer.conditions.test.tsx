/**
 * DynamicRenderer conditions 기능 테스트
 *
 * 컴포넌트의 conditions 속성을 통한 조건부 렌더링 테스트
 *
 * @since engine-v1.10.0
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { render, screen } from '@testing-library/react';
import { evaluateRenderCondition } from '../template-engine/helpers/RenderHelpers';
import { DataBindingEngine } from '../template-engine/DataBindingEngine';

// Mock 컴포넌트들
const MockDiv = ({ children, ...props }: any) => <div {...props}>{children}</div>;
const MockSpan = ({ children, ...props }: any) => <span {...props}>{children}</span>;

// DataBindingEngine 인스턴스
let bindingEngine: DataBindingEngine;

beforeEach(() => {
  bindingEngine = new DataBindingEngine();
});

describe('evaluateRenderCondition', () => {
  describe('기존 if 속성 (하위 호환)', () => {
    it('if 속성이 없으면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {},
        { _local: {} },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('if 속성이 truthy 표현식이면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        { if: '{{user.isAdmin}}' },
        { user: { isAdmin: true } },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('if 속성이 falsy 표현식이면 false를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        { if: '{{user.isAdmin}}' },
        { user: { isAdmin: false } },
        bindingEngine
      );
      expect(result).toBe(false);
    });

    it('if 속성의 비교 연산이 올바르게 동작해야 함', () => {
      const result = evaluateRenderCondition(
        { if: "{{status === 'active'}}" },
        { status: 'active' },
        bindingEngine
      );
      expect(result).toBe(true);

      const result2 = evaluateRenderCondition(
        { if: "{{status === 'active'}}" },
        { status: 'inactive' },
        bindingEngine
      );
      expect(result2).toBe(false);
    });
  });

  describe('conditions - 단순 문자열', () => {
    it('단순 문자열 조건이 truthy면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        { conditions: '{{user.isLoggedIn}}' },
        { user: { isLoggedIn: true } },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('단순 문자열 조건이 falsy면 false를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        { conditions: '{{user.isLoggedIn}}' },
        { user: { isLoggedIn: false } },
        bindingEngine
      );
      expect(result).toBe(false);
    });
  });

  describe('conditions - AND 그룹', () => {
    it('모든 조건이 true면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: {
            and: ['{{route.id}}', '{{form_data?.data?.author}}'],
          },
        },
        {
          route: { id: '123' },
          form_data: { data: { author: 'John' } },
        },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('하나라도 false면 false를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: {
            and: ['{{route.id}}', '{{form_data?.data?.author}}'],
          },
        },
        {
          route: { id: '123' },
          form_data: { data: { author: null } },
        },
        bindingEngine
      );
      expect(result).toBe(false);
    });

    it('빈 배열이면 true를 반환해야 함 (vacuous truth)', () => {
      const result = evaluateRenderCondition(
        {
          conditions: { and: [] },
        },
        {},
        bindingEngine
      );
      expect(result).toBe(true);
    });
  });

  describe('conditions - OR 그룹', () => {
    it('하나라도 true면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: {
            or: ['{{user.isAdmin}}', '{{user.isManager}}'],
          },
        },
        {
          user: { isAdmin: false, isManager: true },
        },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('모든 조건이 false면 false를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: {
            or: ['{{user.isAdmin}}', '{{user.isManager}}'],
          },
        },
        {
          user: { isAdmin: false, isManager: false },
        },
        bindingEngine
      );
      expect(result).toBe(false);
    });

    it('빈 배열이면 false를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: { or: [] },
        },
        {},
        bindingEngine
      );
      expect(result).toBe(false);
    });
  });

  describe('conditions - 중첩 AND/OR', () => {
    it('OR 안에 AND가 중첩된 조건을 평가할 수 있어야 함', () => {
      // user.isSuperAdmin OR (user.isAdmin AND user.department === 'sales')
      const result = evaluateRenderCondition(
        {
          conditions: {
            or: [
              '{{user.isSuperAdmin}}',
              {
                and: [
                  '{{user.isAdmin}}',
                  "{{user.department === 'sales'}}",
                ],
              },
            ],
          },
        },
        {
          user: { isSuperAdmin: false, isAdmin: true, department: 'sales' },
        },
        bindingEngine
      );
      expect(result).toBe(true);

      // 중첩 AND 조건 실패
      const result2 = evaluateRenderCondition(
        {
          conditions: {
            or: [
              '{{user.isSuperAdmin}}',
              {
                and: [
                  '{{user.isAdmin}}',
                  "{{user.department === 'sales'}}",
                ],
              },
            ],
          },
        },
        {
          user: { isSuperAdmin: false, isAdmin: true, department: 'marketing' },
        },
        bindingEngine
      );
      expect(result2).toBe(false);
    });
  });

  describe('conditions - if/else 체인 (ConditionBranch[])', () => {
    it('첫 번째 조건이 true면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: [
            { if: "{{user.role === 'admin'}}" },
            { if: "{{user.role === 'manager'}}" },
          ],
        },
        { user: { role: 'admin' } },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('두 번째 조건이 true면 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: [
            { if: "{{user.role === 'admin'}}" },
            { if: "{{user.role === 'manager'}}" },
          ],
        },
        { user: { role: 'manager' } },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('모든 조건이 false면 false를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: [
            { if: "{{user.role === 'admin'}}" },
            { if: "{{user.role === 'manager'}}" },
          ],
        },
        { user: { role: 'user' } },
        bindingEngine
      );
      expect(result).toBe(false);
    });

    it('else 브랜치가 있으면 항상 true를 반환해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: [
            { if: "{{user.role === 'admin'}}" },
            { if: "{{user.role === 'manager'}}" },
            {}, // else 브랜치
          ],
        },
        { user: { role: 'user' } },
        bindingEngine
      );
      expect(result).toBe(true);
    });
  });

  describe('우선순위: if vs conditions', () => {
    it('if와 conditions가 둘 다 있으면 if가 우선되어야 함', () => {
      // if: false, conditions: true → false 반환 (if 우선)
      const result = evaluateRenderCondition(
        {
          if: '{{ifValue}}',
          conditions: '{{condValue}}',
        },
        { ifValue: false, condValue: true },
        bindingEngine
      );
      expect(result).toBe(false);

      // if: true, conditions: false → true 반환 (if 우선)
      const result2 = evaluateRenderCondition(
        {
          if: '{{ifValue}}',
          conditions: '{{condValue}}',
        },
        { ifValue: true, condValue: false },
        bindingEngine
      );
      expect(result2).toBe(true);
    });
  });

  describe('표현식 호환성', () => {
    it('Optional Chaining을 지원해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: '{{form_data?.data?.author}}',
        },
        { form_data: null },
        bindingEngine
      );
      expect(result).toBe(false);

      const result2 = evaluateRenderCondition(
        {
          conditions: '{{form_data?.data?.author}}',
        },
        { form_data: { data: { author: 'John' } } },
        bindingEngine
      );
      expect(result2).toBe(true);
    });

    it('논리 연산자를 지원해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: '{{a && b}}',
        },
        { a: true, b: true },
        bindingEngine
      );
      expect(result).toBe(true);

      const result2 = evaluateRenderCondition(
        {
          conditions: '{{a || b}}',
        },
        { a: false, b: true },
        bindingEngine
      );
      expect(result2).toBe(true);
    });

    it('삼항 연산자를 지원해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: '{{isActive ? true : false}}',
        },
        { isActive: true },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('Nullish Coalescing을 지원해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: '{{value ?? defaultValue}}',
        },
        { value: null, defaultValue: true },
        bindingEngine
      );
      expect(result).toBe(true);
    });

    it('함수 호출을 지원해야 함', () => {
      const result = evaluateRenderCondition(
        {
          conditions: '{{_global.hasPermission("edit")}}',
        },
        {
          _global: {
            hasPermission: (perm: string) => perm === 'edit',
          },
        },
        bindingEngine
      );
      expect(result).toBe(true);
    });
  });
});

describe('실제 사용 사례', () => {
  describe('관리자 전용 영역 렌더링', () => {
    it('admin 또는 manager 역할일 때만 렌더링해야 함', () => {
      const conditions = {
        conditions: {
          or: [
            "{{user.role === 'admin'}}",
            "{{user.role === 'manager'}}",
          ],
        },
      };

      // admin 역할
      expect(
        evaluateRenderCondition(conditions, { user: { role: 'admin' } }, bindingEngine)
      ).toBe(true);

      // manager 역할
      expect(
        evaluateRenderCondition(conditions, { user: { role: 'manager' } }, bindingEngine)
      ).toBe(true);

      // user 역할
      expect(
        evaluateRenderCondition(conditions, { user: { role: 'user' } }, bindingEngine)
      ).toBe(false);
    });
  });

  describe('저자 정보 영역 렌더링', () => {
    it('route.id와 form_data.data.author가 둘 다 있을 때만 렌더링해야 함', () => {
      const conditions = {
        conditions: {
          and: ['{{route.id}}', '{{form_data?.data?.author}}'],
        },
      };

      // 둘 다 있음
      expect(
        evaluateRenderCondition(
          conditions,
          { route: { id: '123' }, form_data: { data: { author: 'John' } } },
          bindingEngine
        )
      ).toBe(true);

      // route.id 없음
      expect(
        evaluateRenderCondition(
          conditions,
          { route: {}, form_data: { data: { author: 'John' } } },
          bindingEngine
        )
      ).toBe(false);

      // author 없음
      expect(
        evaluateRenderCondition(
          conditions,
          { route: { id: '123' }, form_data: { data: {} } },
          bindingEngine
        )
      ).toBe(false);
    });
  });

  describe('복합 권한 체크', () => {
    it('superAdmin이거나 (admin + sales 부서)일 때 렌더링해야 함', () => {
      const conditions = {
        conditions: {
          or: [
            '{{user.isSuperAdmin}}',
            {
              and: [
                '{{user.isAdmin}}',
                "{{user.department === 'sales'}}",
              ],
            },
          ],
        },
      };

      // superAdmin
      expect(
        evaluateRenderCondition(
          conditions,
          { user: { isSuperAdmin: true, isAdmin: false, department: 'hr' } },
          bindingEngine
        )
      ).toBe(true);

      // admin + sales
      expect(
        evaluateRenderCondition(
          conditions,
          { user: { isSuperAdmin: false, isAdmin: true, department: 'sales' } },
          bindingEngine
        )
      ).toBe(true);

      // admin + hr (부서 불일치)
      expect(
        evaluateRenderCondition(
          conditions,
          { user: { isSuperAdmin: false, isAdmin: true, department: 'hr' } },
          bindingEngine
        )
      ).toBe(false);

      // 일반 사용자
      expect(
        evaluateRenderCondition(
          conditions,
          { user: { isSuperAdmin: false, isAdmin: false, department: 'sales' } },
          bindingEngine
        )
      ).toBe(false);
    });
  });
});
