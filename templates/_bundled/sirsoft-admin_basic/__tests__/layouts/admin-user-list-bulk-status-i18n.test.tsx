/**
 * @file admin-user-list-bulk-status-i18n.test.tsx
 * @description 회원 목록 일괄 작업 바 "상태 변경" 라벨 i18n 키화 검증 (공개#45)
 *
 * 테스트 대상:
 * - templates/.../layouts/admin_user_list.json (status_change_label Span)
 *
 * 검증 항목:
 * - 하드코딩된 "상태 변경" 문자열이 레이아웃에 남아 있지 않음
 * - status_change_label 노드가 $t:admin.users.bulk_status_change 키를 사용
 * - ko/en partial 에 bulk_status_change 키가 정의됨 (미해석 키 0)
 * - TranslationEngine 이 키를 ko/en 으로 해석
 */

import { describe, it, expect } from 'vitest';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import userListLayout from '../../layouts/admin_user_list.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

/** 레이아웃 트리에서 id 로 노드를 찾습니다. */
function findById(node: unknown, id: string): any {
  if (!node || typeof node !== 'object') return undefined;
  const value = node as { id?: string; [key: string]: unknown };
  if (value.id === id) return value;
  for (const child of Object.values(value)) {
    if (Array.isArray(child)) {
      for (const item of child) {
        const found = findById(item, id);
        if (found) return found;
      }
      continue;
    }
    const found = findById(child, id);
    if (found) return found;
  }
  return undefined;
}

describe('회원 목록 일괄 작업 "상태 변경" 라벨 i18n (공개#45)', () => {
  it('레이아웃에 하드코딩된 "상태 변경" 문자열이 남아 있지 않아야 한다', () => {
    const layoutStr = JSON.stringify(userListLayout);
    expect(layoutStr).not.toContain('"text":"상태 변경"');
    expect(layoutStr).not.toContain('"text": "상태 변경"');
  });

  it('status_change_label 노드가 $t:admin.users.bulk_status_change 키를 사용해야 한다', () => {
    const node = findById(userListLayout, 'status_change_label');
    expect(node).toBeDefined();
    expect(node.text).toBe('$t:admin.users.bulk_status_change');
  });

  it('ko/en partial 에 users.bulk_status_change 키가 정의되어야 한다', () => {
    expect((adminKo as any).users.bulk_status_change).toBe('상태 변경');
    expect((adminEn as any).users.bulk_status_change).toBe('Status Change');
  });

  it('TranslationEngine 이 키를 ko/en 으로 해석해야 한다', () => {
    const engine = TranslationEngine.getInstance();
    const templateId = 'sirsoft-admin_basic';

    (engine as any).translations.set(`${templateId}:ko`, { admin: adminKo });
    (engine as any).translations.set(`${templateId}:en`, { admin: adminEn });

    expect(
      engine.translate('admin.users.bulk_status_change', { templateId, locale: 'ko' }),
    ).toBe('상태 변경');
    expect(
      engine.translate('admin.users.bulk_status_change', { templateId, locale: 'en' }),
    ).toBe('Status Change');
  });
});
