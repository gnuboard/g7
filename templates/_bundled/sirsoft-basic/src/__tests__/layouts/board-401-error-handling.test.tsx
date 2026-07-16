/**
 * @file board-401-error-handling.test.tsx
 * @description 게시판 목록/상세 - 비로그인 접근 시 401 errorHandling 검증 (이슈 #228 B-5, #413 item 28)
 *
 * 검증 항목:
 * 1. show.json / index.json 데이터소스에 401 errorHandling이 정의되어 있는가
 * 2. 401 핸들러가 sequence(toast → redirectToLoginWithReturn) 방식인가
 * 3. 두 번째 action 이 redirectToLoginWithReturn 핸들러인가 (custom+name 형식 금지)
 * 4. auth_required가 아닌 auth_mode: "optional"이 사용되는가
 * 5. 기존 403/404 핸들러가 유지되는가
 *
 * @scenario entry_point:board_list_401,board_show_401
 * @effects redirect_preserves_pathname,login_returns_to_original_path
 */

import { describe, it, expect } from 'vitest';
import showLayout from '../../../layouts/board/show.json';
import indexLayout from '../../../layouts/board/index.json';

function getDataSource(layout: any, dataSourceId: string) {
  return (layout.data_sources ?? []).find((s: any) => s.id === dataSourceId) ?? null;
}

function getDataSourceErrorHandling(layout: any, dataSourceId: string) {
  const ds = getDataSource(layout, dataSourceId);
  return ds?.errorHandling ?? null;
}

describe('게시판 401 errorHandling — sequence + redirect 방식 (이슈 #228 B-5)', () => {
  describe('show.json — auth_mode 및 데이터소스 401', () => {
    // redirect: /board/{{route.slug}}/posts/{{route.id}}
    const ds = getDataSource(showLayout, 'post');
    const dsErrorHandling = ds?.errorHandling ?? null;

    it('auth_mode가 optional이어야 한다 (auth_required 사용 금지)', () => {
      expect(ds?.auth_mode).toBe('optional');
      expect(ds?.auth_required).toBeUndefined();
    });

    it('데이터소스 errorHandling에 401이 정의되어야 한다', () => {
      expect(dsErrorHandling?.['401']).toBeDefined();
    });

    it('401 핸들러가 sequence 타입이어야 한다', () => {
      expect(dsErrorHandling?.['401']?.handler).toBe('sequence');
    });

    it('sequence actions 배열이 존재해야 한다', () => {
      expect(Array.isArray(dsErrorHandling?.['401']?.actions)).toBe(true);
      expect(dsErrorHandling?.['401']?.actions.length).toBeGreaterThanOrEqual(2);
    });

    it('첫 번째 action이 toast 핸들러이어야 한다', () => {
      const toastAction = dsErrorHandling?.['401']?.actions[0];
      expect(toastAction?.handler).toBe('toast');
    });

    it('두 번째 action이 redirectToLoginWithReturn 핸들러이어야 한다', () => {
      // 등록 키를 handler 에 직접 지정 (custom+name 형식은 디스패치가 handler 값으로만
      // 조회하므로 throw 됨 — feedback_verify_full_path_not_just_evaluator).
      const redirectAction = dsErrorHandling?.['401']?.actions[1];
      expect(redirectAction?.handler).toBe('redirectToLoginWithReturn');
    });

    it('두 번째 action에 name 필드 단언이 없어야 한다 (custom+name 거짓 통과 방지)', () => {
      const redirectAction = dsErrorHandling?.['401']?.actions[1];
      expect(redirectAction?.handler).not.toBe('custom');
      expect((redirectAction as any)?.name).toBeUndefined();
    });

    it('redirect 는 핸들러가 window.location 으로 캡처하므로 navigate params 가 불필요하다', () => {
      // 레이아웃에 정적 redirect 표현식을 두지 않는다 (핸들러가 런타임에 캡처).
      const redirectAction = dsErrorHandling?.['401']?.actions[1];
      expect(redirectAction?.params).toBeUndefined();
    });

    it('기존 403 핸들러가 유지되어야 한다', () => {
      expect(dsErrorHandling?.['403']).toBeDefined();
      expect(dsErrorHandling?.['403']?.handler).toBe('showErrorPage');
    });

    it('기존 404 핸들러가 유지되어야 한다', () => {
      expect(dsErrorHandling?.['404']).toBeDefined();
      expect(dsErrorHandling?.['404']?.handler).toBe('showErrorPage');
    });

    it('레이아웃 최상위에 errorHandling이 없어야 한다', () => {
      expect((showLayout as any).errorHandling).toBeUndefined();
    });
  });

  describe('index.json — auth_mode 및 데이터소스 401', () => {
    const ds = getDataSource(indexLayout, 'posts');
    const dsErrorHandling = ds?.errorHandling ?? null;

    it('auth_mode가 optional이어야 한다 (auth_required 사용 금지)', () => {
      expect(ds?.auth_mode).toBe('optional');
      expect(ds?.auth_required).toBeUndefined();
    });

    it('데이터소스 errorHandling에 401이 정의되어야 한다', () => {
      expect(dsErrorHandling?.['401']).toBeDefined();
    });

    it('401 핸들러가 sequence 타입이어야 한다', () => {
      expect(dsErrorHandling?.['401']?.handler).toBe('sequence');
    });

    it('sequence actions 배열이 존재해야 한다', () => {
      expect(Array.isArray(dsErrorHandling?.['401']?.actions)).toBe(true);
      expect(dsErrorHandling?.['401']?.actions.length).toBeGreaterThanOrEqual(2);
    });

    it('첫 번째 action이 toast 핸들러이어야 한다', () => {
      const toastAction = dsErrorHandling?.['401']?.actions[0];
      expect(toastAction?.handler).toBe('toast');
    });

    it('두 번째 action이 redirectToLoginWithReturn 핸들러이어야 한다', () => {
      const redirectAction = dsErrorHandling?.['401']?.actions[1];
      expect(redirectAction?.handler).toBe('redirectToLoginWithReturn');
    });

    it('두 번째 action에 name 필드 단언이 없어야 한다 (custom+name 거짓 통과 방지)', () => {
      const redirectAction = dsErrorHandling?.['401']?.actions[1];
      expect(redirectAction?.handler).not.toBe('custom');
      expect((redirectAction as any)?.name).toBeUndefined();
    });

    it('redirect 는 핸들러가 window.location 으로 캡처하므로 navigate params 가 불필요하다', () => {
      const redirectAction = dsErrorHandling?.['401']?.actions[1];
      expect(redirectAction?.params).toBeUndefined();
    });

    it('기존 403 핸들러가 유지되어야 한다', () => {
      expect(dsErrorHandling?.['403']).toBeDefined();
      expect(dsErrorHandling?.['403']?.handler).toBe('showErrorPage');
    });

    it('레이아웃 최상위에 errorHandling이 없어야 한다', () => {
      expect((indexLayout as any).errorHandling).toBeUndefined();
    });
  });
});
