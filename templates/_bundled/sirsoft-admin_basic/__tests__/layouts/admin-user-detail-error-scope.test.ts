/**
 * @file admin-user-detail-error-scope.test.ts
 * @description 회원 상세 — 403·404 화면 전환을 회원 조회에만 묶는다 (#736 S2 결정)
 *
 * 레이아웃 단위 `errorHandling` 은 상속한 화면 틀(`_admin_base`)의 데이터 소스(알림·메뉴 등)에도 걸린다. 회원 읽기
 * 권한만 있는 관리자는 알림(`core.notifications.read`)·메뉴(`core.menus.read`) 조회가 403 이라, 볼 수 있는 회원 상세가
 * 통째로 403 화면으로 바뀌었다(2026-10-04 실측). 다른 관리자 화면처럼 자기 데이터 소스에만 건다.
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import detail from '../../layouts/admin_user_detail.json';

describe('회원 상세 — 오류 화면 전환 범위', () => {
  /**
   * @scenario failing_source=shell
   * @effects shell_403_does_not_replace_detail
   */
  it('레이아웃 단위 403·404 처리기를 두지 않는다(화면 틀 데이터 소스의 403 이 상세를 덮지 않게)', () => {
    expect((detail as any).errorHandling).toBeUndefined();
  });

  /**
   * @scenario failing_source=user
   * @effects user_source_403_404_still_show_error_page
   */
  it('회원 조회(user)의 403·404 는 종전대로 본문을 오류 화면으로 바꾼다', () => {
    const user = asArray<any>((detail as any).data_sources).find((s) => s.id === 'user');
    for (const code of ['403', '404']) {
      const handler = asArray<any>(user?.errorHandling?.[code]);
      expect(handler.some((a) => a.handler === 'showErrorPage' && a.params?.target === 'content'), `user ${code}`).toBe(true);
    }
  });
});
