/**
 * @file board-report-modal-feedback.test.tsx
 * @description 신고 모달 - 제출 후 피드백/상태 반영 검증 (이슈 #413-60)
 *
 * 검수 결과(김은혜, 오류확정):
 *  - 증상2: 신고 성공 후 버튼이 "신고됨"으로 즉시 안 바뀜 (새로고침해야 전환)
 *  - 증상1: 신고 실패(409 등) 시 spinner만 잠깐, 모달 안 닫힘 + 에러 토스트 미노출 → "진행 불가"
 *
 * 근본 원인 / 수정:
 *  - 증상2: onSuccess 에 post 데이터소스 refetch 누락 → refetchDataSource(post) 추가
 *  - 증상1: onError 에 closeModal 누락 → "모달 닫고 토스트"(설계 결정) 로 보강
 *
 * 신고 모달은 게시글/댓글 공용 1파일이므로 이 단언이 양쪽을 모두 커버한다.
 *
 * 7.1.1: 실패 시 창 유지로 바뀌었다(증상1 묶음의 마지막 단언). 렌더 판정은 board-report-modal-fail-keeps-open.test.tsx.
 *
 * @scenario-manifest tests/scenarios/board-report-modal-fail-keeps-open.yaml
 */

import { describe, it, expect } from 'vitest';
import reportModal from '../../../layouts/partials/board/show/modals/_modal_report.json';

/**
 * 신고 모달의 "신고하기" 제출 버튼 액션을 찾는다.
 * 버튼 영역 → conditions 핸들러 → 검증 통과(then 배열) 안의 apiCall 을 반환.
 */
function findSubmitApiCall(layout: any): any {
  const found: any[] = [];

  function walk(node: any) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
      node.forEach(walk);
      return;
    }
    if (node.handler === 'apiCall') {
      found.push(node);
    }
    for (const key of Object.keys(node)) {
      walk(node[key]);
    }
  }

  walk(layout);
  // 신고 제출 apiCall: target 이 reportModal.apiEndpoint 인 것
  return found.find(
    (a) => typeof a.target === 'string' && a.target.includes('reportModal')
  ) ?? found[0] ?? null;
}

function asArray(x: any): any[] {
  if (!x) return [];
  return Array.isArray(x) ? x : [x];
}

describe('신고 모달 제출 피드백 (이슈 #413-60)', () => {
  const apiCall = findSubmitApiCall(reportModal);

  // @scenario result=succeeded
  // @effects report_modal_closes_and_resets_on_success
  it('신고 제출 apiCall 액션을 찾을 수 있어야 한다', () => {
    expect(apiCall).not.toBeNull();
    expect(apiCall.handler).toBe('apiCall');
    expect(apiCall.params?.method).toBe('POST');
  });

  describe('증상2 — onSuccess 에 post 데이터소스 refetch 가 포함되어야 한다', () => {
    const onSuccess = asArray(apiCall?.onSuccess);

    // @scenario result=succeeded
    // @effects report_modal_closes_and_resets_on_success
    it('onSuccess 에 refetchDataSource 핸들러가 있어야 한다', () => {
      const refetch = onSuccess.find((a) => a.handler === 'refetchDataSource');
      expect(refetch).toBeDefined();
    });

    // @scenario result=succeeded
    // @effects report_modal_closes_and_resets_on_success
    it('refetchDataSource 가 post 데이터소스를 대상으로 해야 한다 (params.dataSourceId)', () => {
      const refetch = onSuccess.find((a) => a.handler === 'refetchDataSource');
      expect(refetch?.params?.dataSourceId).toBe('post');
    });

    // @scenario result=succeeded
    // @effects report_modal_closes_and_resets_on_success
    it('onSuccess 에 성공 토스트가 유지되어야 한다', () => {
      const toast = onSuccess.find((a) => a.handler === 'toast');
      expect(toast?.params?.type).toBe('success');
    });

    // @scenario result=succeeded
    // @effects report_modal_closes_and_resets_on_success
    it('onSuccess 에 closeModal 이 유지되어야 한다', () => {
      const close = onSuccess.find((a) => a.handler === 'closeModal');
      expect(close).toBeDefined();
    });
  });

  describe('증상1 — onError 에 잠금 해제 + 에러 토스트가 포함되어야 한다 (7.1.1 부터 창은 유지)', () => {
    const onError = asArray(apiCall?.onError);

    // @scenario result=validation_failed
    // @effects report_modal_stays_open_on_failure
    it('onError 에 reportSubmitting 리셋(setState)이 있어야 한다', () => {
      const reset = onError.find(
        (a) => a.handler === 'setState' && 'reportSubmitting' in (a.params ?? {})
      );
      expect(reset).toBeDefined();
      expect(reset?.params?.reportSubmitting).toBe(false);
    });

    // @scenario result=validation_failed
    // @effects report_modal_stays_open_on_failure
    it('onError 에 에러 토스트가 있어야 한다', () => {
      const toast = onError.find((a) => a.handler === 'toast');
      expect(toast).toBeDefined();
      expect(toast?.params?.type).toBe('error');
    });

    // 7.1.1 에서 바뀐 설계: 실패해도 창과 적은 사유를 유지하고(관리자 신고 창과 같은 동작) 서버 칸별 오류를 담는다.
    // 종전 「실패 시 모달 닫고 토스트」 는 422(검증 오류)에 적은 사유까지 지웠다. 증상1(진행 불가)은 잠금 해제·토스트가 해소한다.
    // @scenario result=validation_failed
    // @effects report_modal_stays_open_on_failure
    it('onError 에 closeModal 이 없고 서버 칸별 오류를 담는다 (7.1.1: 실패 시 창 유지)', () => {
      const close = onError.find((a) => a.handler === 'closeModal');
      expect(close).toBeUndefined();
      const reset = onError.find((a) => a.handler === 'setState' && 'reportErrors' in (a.params ?? {}));
      expect(String(reset?.params?.reportErrors)).toContain('error.errors');
    });
  });
});
