/**
 * 페이지 제목 판정 — 4단 폴백.
 *
 * G7 은 SPA 라 `document.title` 을 쓰는 코드가 없고 제목이 라우트 메타에 번역 키로
 * 들어 있다. 그래서 제목을 얻는 순서가 다른 사이트와 다르고, 순서가 틀리면 통계의
 * 「페이지 제목」 목록이 번역 키(`$t:...`)로 채워진다.
 *
 * @scenario source=beacon
 * @effects page_title_falls_back_through_meta_document_and_path
 */

import { afterEach, describe, expect, it } from 'vitest';

import { resolvePageTitle } from '../pageTitle';

describe('resolvePageTitle', () => {
  afterEach(() => {
    document.title = '';
  });

  // @scenario source=beacon
  // @effects page_title_falls_back_through_meta_document_and_path
  it('번역 키를 번역한 문구로 바꾼다', () => {
    const title = resolvePageTitle(
      { path: '/board', meta: { title: '$t:user.board.title' } },
      (key) => (key === 'user.board.title' ? '자유게시판' : key),
    );

    expect(title).toBe('자유게시판');
  });

  // @scenario source=beacon
  // @effects page_title_falls_back_through_meta_document_and_path
  it('번역이 없으면 키를 그대로 보여 주지 않는다', () => {
    document.title = '문서 제목';

    // 번역기는 못 찾은 키를 그대로 돌려준다 — 그것을 제목으로 쓰면 화면에 `$t:` 가 뜬다.
    const title = resolvePageTitle(
      { path: '/board', meta: { title: '$t:missing.key' } },
      (key) => key,
    );

    expect(title).toBe('문서 제목');
  });

  // @scenario source=beacon
  // @effects page_title_falls_back_through_meta_document_and_path
  it('메타 제목이 평문이면 그대로 쓴다', () => {
    expect(resolvePageTitle({ path: '/x', meta: { title: '공지사항' } })).toBe('공지사항');
  });

  // @scenario source=beacon
  // @effects page_title_falls_back_through_meta_document_and_path
  it('메타가 없으면 document.title 로 물러선다', () => {
    document.title = '기본 제목';

    expect(resolvePageTitle({ path: '/x' })).toBe('기본 제목');
  });

  // @scenario source=beacon
  // @effects page_title_falls_back_through_meta_document_and_path
  it('아무것도 없으면 경로를 쓴다', () => {
    // 빈 문자열을 남기면 화면에서 그 행이 무엇을 가리키는지 알 수 없다.
    expect(resolvePageTitle({ path: '/board/free' })).toBe('/board/free');
  });

  // @scenario source=beacon
  // @effects page_title_falls_back_through_meta_document_and_path
  it('라우트가 아예 없어도 판정한다', () => {
    expect(typeof resolvePageTitle(null)).toBe('string');
    expect(typeof resolvePageTitle(undefined)).toBe('string');
  });
});
