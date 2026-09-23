/**
 * @file adminPageAttachmentUploaderProps.test.ts
 * @description 페이지 작성 화면 첨부 업로더 — 서버 공개 한도를 그대로 넘긴다 (#599 validate P7)
 *
 * 서버 `publicLimits()` 는 개수 상한이 없으면(설정 0) `max_count: null` 을 낸다 — "제한 없음" 이라는
 * 뜻이고, 화면은 컴포넌트 기본값을 쓰기로 했다. 그런데 레이아웃이 `?? 5` 로 받아 null 이 5개 상한으로
 * 바뀌었다. 허용 형식이 비면 `accept: ''` 가 오는데 `??` 는 빈 문자열을 통과시켜 파일 선택창이
 * 모든 형식을 열었다. 레이아웃 표현식을 엔진에 그대로 넣어 해석 결과를 본다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import pageForm from '../../../layouts/admin/admin_page_form.json';

type Json = Record<string, any>;

function findNode(node: unknown, pred: (n: Json) => boolean): Json | undefined {
  if (Array.isArray(node)) {
    for (const item of node) {
      const hit = findNode(item, pred);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) return obj;
    for (const v of Object.values(obj)) {
      const hit = findNode(v, pred);
      if (hit) return hit;
    }
  }
  return undefined;
}

const uploader = findNode(pageForm, (n) => n.name === 'FileUploader')!;

/** 업로더 props 중 한도 관련 3개만 엔진으로 해석한다. */
function limits(data: Json | undefined): Json {
  const { maxFiles, maxSize, accept } = uploader.props;

  return dataBindingEngine.resolveObject({ maxFiles, maxSize, accept }, { attachmentSettings: { data } } as any);
}

describe('페이지 첨부 업로더 한도 props', () => {
  /**
   * 개수 상한이 없으면(null) maxFiles 를 넘기지 않아 컴포넌트 기본값이 적용된다.
   *
   * @scenario case=max_count_null
   * @effects page_attachment_unlimited_count_uses_component_default
   */
  it('max_count 가 null 이면 maxFiles 를 비운다(undefined)', () => {
    expect(limits({ max_count: null, max_size_mb: 10, accept: '.pdf' }).maxFiles).toBeUndefined();
  });

  /**
   * 개수 상한이 있으면 그 값을 그대로 넘긴다.
   *
   * @scenario case=max_count_set
   * @effects page_attachment_limits_follow_server
   */
  it('max_count 가 있으면 그대로 넘긴다', () => {
    expect(limits({ max_count: 3, max_size_mb: 10, accept: '.pdf' }).maxFiles).toBe(3);
  });

  /**
   * 허용 형식이 빈 문자열이면 기본 형식 목록으로 떨어진다(빈 accept 는 모든 형식을 연다).
   *
   * @scenario case=accept_empty
   * @effects page_attachment_empty_accept_falls_back_to_default
   */
  it('accept 가 빈 문자열이면 기본 형식 목록을 쓴다', () => {
    expect(limits({ max_count: null, max_size_mb: 10, accept: '' }).accept).toBe('.jpg,.jpeg,.png,.gif,.webp,.pdf,.zip');
    expect(limits({ max_count: null, max_size_mb: 10, accept: '.pdf,.zip' }).accept).toBe('.pdf,.zip');
  });

  /**
   * FileUploader 에 없는 `compact` prop 을 넘기지 않는다(아무 효과가 없는 선언).
   *
   * @scenario case=noop_prop_compact
   * @effects page_attachment_uploader_has_no_noop_props
   */
  it('없는 prop(compact)을 넘기지 않는다', () => {
    expect(uploader.props).not.toHaveProperty('compact');
  });
});
