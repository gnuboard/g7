/**
 * 비밀글 열람 확인 토큰 헤더 헬퍼 + 직접 호출 경로 배선 검증
 *
 * globalHeaders 는 데이터소스와 apiCall 핸들러에만 적용된다. 코어 ApiClient
 * (G7Core.api)를 직접 호출하는 경로는 그 배선을 타지 않아, 비밀번호로 원문을 연
 * 사용자에게 화면이 버튼·썸네일을 내주고도 서버가 403 으로 거부한다. 예외도 콘솔
 * 오류도 남지 않고 그 자리만 조용히 비는 결함이라 구조로 잠근다.
 *
 * @scenario surface=direct_api_client
 *
 * @effects secret_view_token_header_helper,direct_api_call_sites_carry_secret_header
 */

import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { secretContentHeaders, SECRET_VIEW_TOKEN_HEADER } from '../secretContentHeaders';

const SRC = resolve(__dirname, '../..');

afterEach(() => {
  delete (window as any).G7Core;
});

describe('secretContentHeaders', () => {
  it('전역 상태에 토큰이 있으면 헤더 객체를 만든다', () => {
    (window as any).G7Core = {
      state: { get: (k: string) => (k === '_global' ? { secretViewToken: 'tok-40' } : undefined) },
    };

    expect(secretContentHeaders()).toEqual({ [SECRET_VIEW_TOKEN_HEADER]: 'tok-40' });
  });

  it('토큰이 없거나 빈 문자열이면 빈 객체를 돌려준다 (빈 헤더 전송 금지)', () => {
    (window as any).G7Core = { state: { get: () => ({ secretViewToken: '' }) } };
    expect(secretContentHeaders()).toEqual({});

    (window as any).G7Core = { state: { get: () => ({}) } };
    expect(secretContentHeaders()).toEqual({});
  });

  it('G7Core 가 아직 없어도 예외를 던지지 않는다', () => {
    expect(secretContentHeaders()).toEqual({});
  });
});

describe('직접 호출 경로 배선', () => {
  const CALL_SITES = [
    'handlers/downloadAttachment.ts',
    'components/composite/ImageGallery.tsx',
    'components/composite/FileUploader/SortableThumbnailItem.tsx',
    'components/composite/FileUploader/useFileUploader.ts',
  ];

  it.each(CALL_SITES)('%s 가 blob 요청에 열람 토큰 헤더를 싣는다', (relative) => {
    const source = readFileSync(resolve(SRC, relative), 'utf-8');

    expect(
      source.includes('secretContentHeaders'),
      `${relative} 가 열람 토큰 헤더를 싣지 않습니다 — 비밀글 첨부에서 서버가 403 으로 거부하는데 화면에는 오류가 남지 않습니다.`
    ).toBe(true);

    const blobCalls = source.split("responseType: 'blob'");
    expect(
      blobCalls.length - 1,
      `${relative} 에 blob 요청이 없습니다 — 테스트가 실제 경로를 보고 있는지 확인이 필요합니다.`
    ).toBeGreaterThan(0);

    blobCalls.slice(1).forEach((chunk, index) => {
      expect(
        chunk.slice(0, 200).includes('secretContentHeaders()'),
        `${relative} 의 ${index + 1}번째 blob 요청에 열람 토큰 헤더가 빠졌습니다.`
      ).toBe(true);
    });
  });
});
