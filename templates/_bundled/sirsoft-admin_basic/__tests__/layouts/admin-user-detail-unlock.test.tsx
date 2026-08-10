/**
 * @file admin-user-detail-unlock.test.tsx
 * @description 회원 상세 계정 잠금 상태 표시 + 관리자 잠금 해제 버튼 계약 검증 (C1)
 *
 * 테스트 대상:
 * - templates/.../layouts/admin_user_detail.json (header_unlock_button / field_lock_status)
 *
 * 배경: 보안 설정의 잠금 시간 0(무한대)으로 잠긴 계정은 성공 로그인 자체가 불가하므로
 * 관리자 수동 해제 경로가 없으면 복구 불가가 된다. 화면에 해제 수단이 실제로 존재하는지
 * (그리고 잠기지 않은 계정에는 노출되지 않는지) 정적으로 고정한다.
 */

import { describe, it, expect } from 'vitest';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import userDetailLayout from '../../layouts/admin_user_detail.json';
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

describe('회원 상세 계정 잠금 해제 (C1)', () => {
  it('잠금 해제 버튼이 잠긴 계정에서만 노출되어야 한다', () => {
    const button = findById(userDetailLayout, 'header_unlock_button');

    expect(button).toBeDefined();
    expect(button.if).toBe('{{user?.data?.is_locked === true}}');
    expect(button.props.disabled).toBe('{{user?.data?.abilities?.can_update !== true}}');
  });

  it('잠금 해제 버튼이 관리자 해제 엔드포인트를 POST 로 호출해야 한다', () => {
    const button = findById(userDetailLayout, 'header_unlock_button');
    const action = button.actions[0];

    expect(action.handler).toBe('apiCall');
    // target 은 액션 top-level 이어야 한다 (params 내부면 URL 이 undefined 로 깨진다)
    expect(action.target).toBe('/api/admin/users/{{route?.id}}/unlock');
    expect(action.params.method).toBe('POST');
    expect(action.auth_required).toBe(true);
  });

  it('해제 성공 시 상세 데이터를 다시 불러오고 성공 안내를 노출해야 한다', () => {
    const button = findById(userDetailLayout, 'header_unlock_button');
    const onSuccess = button.actions[0].onSuccess;

    const refetch = onSuccess.find((a: any) => a.handler === 'refetchDataSource');
    expect(refetch).toBeDefined();
    // params.id 가 아니라 params.dataSourceId 여야 인식된다
    expect(refetch.params.dataSourceId).toBe('user');

    const toast = onSuccess.find((a: any) => a.handler === 'toast');
    expect(toast).toBeDefined();
    expect(toast.params.message).toBe('$t:admin.users.detail.unlock_success');
  });

  it('잠금 상태 필드가 영구/기한부/정상 3분기를 모두 표시해야 한다', () => {
    const field = findById(userDetailLayout, 'field_lock_status');
    expect(field).toBeDefined();

    const conditions = field.children
      .filter((c: any) => typeof c.if === 'string')
      .map((c: any) => c.if);

    expect(conditions).toContain('{{user?.data?.locked_permanently === true}}');
    expect(conditions).toContain(
      '{{user?.data?.locked_permanently !== true && user?.data?.is_locked === true}}',
    );
    expect(conditions).toContain('{{user?.data?.is_locked !== true}}');
  });

  it('ko/en partial 에 잠금 해제 관련 키가 모두 정의되어야 한다', () => {
    for (const pack of [adminKo, adminEn] as any[]) {
      expect(pack.users.detail.unlock).toBeTruthy();
      expect(pack.users.detail.unlock_success).toBeTruthy();
      expect(pack.users.detail.locked_permanently_value).toBeTruthy();
      expect(pack.users.detail.locked_until_value).toBeTruthy();
      expect(pack.users.detail.not_locked_value).toBeTruthy();
      expect(pack.users.form.fields.lock_status).toBeTruthy();
    }
  });

  it('TranslationEngine 이 잠금 해제 키를 ko/en 으로 해석해야 한다', () => {
    const engine = TranslationEngine.getInstance();
    const templateId = 'sirsoft-admin_basic';

    (engine as any).translations.set(`${templateId}:ko`, { admin: adminKo });
    (engine as any).translations.set(`${templateId}:en`, { admin: adminEn });

    expect(engine.translate('admin.users.detail.unlock', { templateId, locale: 'ko' })).toBe(
      '잠금 해제',
    );
    expect(engine.translate('admin.users.detail.unlock', { templateId, locale: 'en' })).toBe(
      'Unlock',
    );
  });
});
