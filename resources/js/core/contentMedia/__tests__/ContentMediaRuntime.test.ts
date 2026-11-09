/**
 * @file ContentMediaRuntime.test.ts
 * @description 본문 미디어 업로드 계약 런타임 (engine-v1.89.0, #883)
 *
 * 확장이 넘긴 계약을 편집기 플러그인이 쓰기 전에 검사·정규화하고, 업로드 전에 파일과 문서당 개수를 판정하며,
 * 어댑터를 골라 올린다. 다른 출처 주소·허용 밖 헤더를 받아 주면 편집기가 토큰을 외부로 실어 보낼 수 있다(KVE-2026-2350 계열).
 *
 * @scenario-manifest tests/scenarios/content-media-contract.yaml
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ContentMediaRuntime } from '../ContentMediaRuntime';
import type { ContentMediaUploadTarget } from '../types';

function rawTarget(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    version: 1,
    endpoint: '/api/modules/sirsoft-board/boards/free/content-media',
    fileField: 'file',
    fields: { temp_key: 'k-1' },
    headers: { 'X-Board-Post-Verify-Token': 'v', Authorization: 'Bearer stolen' },
    response: { urlPath: 'data.url', idPath: 'data.id', kindPath: 'data.kind' },
    mediaUrlPattern: '/api/modules/sirsoft-board/boards/free/content-media/(?<hash>[A-Za-z0-9]{12})',
    policy: {
      image: { enabled: true, maxSizeMb: 1, extensions: ['jpg', 'png', 'exe'], mimeTypes: ['image/jpeg', 'image/png'] },
      video: { enabled: false, maxSizeMb: 10, extensions: ['mp4'], mimeTypes: ['video/mp4'] },
      audio: { enabled: true, maxSizeMb: 2, extensions: [], mimeTypes: [] },
      maxPerDocument: 2,
    },
    ...overrides,
  };
}

describe('ContentMediaRuntime', () => {
  let post: ReturnType<typeof vi.fn>;
  let runtime: ContentMediaRuntime;

  beforeEach(() => {
    post = vi.fn();
    runtime = new ContentMediaRuntime({ getHttpClient: () => ({ post }) });
  });

  /**
   * @scenario case=normalize_valid
   * @effects target_normalized
   */
  it('정상 계약을 정규화하며 허용 밖 헤더·후보 밖 확장자를 떼고 확장자 없는 종류는 끈다', () => {
    const target = runtime.normalizeTarget(rawTarget())!;

    expect(target).not.toBeNull();
    expect(target.headers).toEqual({ 'X-Board-Post-Verify-Token': 'v' });
    expect(target.policy.image.extensions).toEqual(['jpg', 'png']);
    expect(target.policy.audio.enabled).toBe(false);
    expect(target.fields).toEqual({ temp_key: 'k-1' });
  });

  /**
   * @scenario case=normalize_rejects_foreign
   * @effects foreign_target_rejected
   */
  it.each([
    ['절대 주소', { endpoint: 'https://evil.example/upload' }],
    ['프로토콜 상대 주소', { endpoint: '//evil.example/upload' }],
    ['javascript 주소', { endpoint: 'javascript:alert(1)' }],
    ['버전 다름', { version: 2 }],
    ['응답 경로 없음', { response: {} }],
    ['정규식 오류', { mediaUrlPattern: '(' }],
    ['정책 없음', { policy: null }],
  ])('%s 이면 null', (_label, overrides) => {
    expect(runtime.normalizeTarget(rawTarget(overrides))).toBeNull();
  });

  /**
   * @scenario case=validate_file
   * @effects file_prevalidated
   */
  it('종류·확장자·크기를 업로드 전에 판정한다', () => {
    const target = runtime.normalizeTarget(rawTarget())!;

    expect(runtime.validateFile(target, { name: 'a.PNG', size: 100 })).toEqual({ ok: true, kind: 'image' });
    expect(runtime.validateFile(target, { name: 'a.gif', size: 100 })).toMatchObject({ ok: false, messageKey: 'core.content_media.client.extension_not_allowed' });
    expect(runtime.validateFile(target, { name: 'a.png', size: 2 * 1024 * 1024 })).toMatchObject({ ok: false, messageKey: 'core.content_media.client.file_too_large', params: { max: 1 } });
    expect(runtime.validateFile(target, { name: 'clip.mp4', size: 10 })).toMatchObject({ ok: false, kind: 'video', messageKey: 'core.content_media.client.kind_not_allowed' });
    expect(runtime.validateFile(target, { name: 'run.exe', size: 10 })).toMatchObject({ ok: false, kind: null });
  });

  /**
   * @scenario case=count_references
   * @effects per_document_limit_prechecked
   */
  it('본문이 참조하는 서로 다른 해시를 세고 상한 도달을 판정한다', () => {
    const target = runtime.normalizeTarget(rawTarget())!;
    const base = '/api/modules/sirsoft-board/boards/free/content-media/';
    const html = `<p><img src="${base}AAAAAAAAAAAA?expires=1&signature=x"><img src="${base}AAAAAAAAAAAA"></p><img src="/other/BBBBBBBBBBBB">`;

    expect(runtime.countReferences(html, target)).toBe(1);
    expect(runtime.isAtLimit(html, target)).toBe(false);
    expect(runtime.isAtLimit(`${html}<img src="${base}CCCCCCCCCCCC">`, target)).toBe(true);
    expect(runtime.countReferences('', target)).toBe(0);
  });

  /**
   * @scenario case=default_upload
   * @effects uploaded_via_api_client
   */
  it('기본 어댑터는 /api 를 떼고 FormData·허용 헤더로 올려 응답 경로에서 url·id·kind 를 꺼낸다', async () => {
    const target = runtime.normalizeTarget(rawTarget())!;
    post.mockResolvedValue({ success: true, data: { url: '/x/AAAAAAAAAAAA?s=1', id: 7, kind: 'image' } });
    const file = new File(['png'], 'a.png', { type: 'image/png' });

    const result = await runtime.upload(target, file);

    expect(result).toEqual({ url: '/x/AAAAAAAAAAAA?s=1', id: 7, kind: 'image' });
    const [url, form, config] = post.mock.calls[0];
    expect(url).toBe('/modules/sirsoft-board/boards/free/content-media');
    expect((form as FormData).get('temp_key')).toBe('k-1');
    expect((form as FormData).get('file')).toBe(file);
    expect(config.headers).toEqual({ 'X-Board-Post-Verify-Token': 'v', 'Content-Type': 'multipart/form-data' });
  });

  /**
   * @scenario case=anonymous_401_passthrough
   * @effects anonymous_401_rejected_to_caller
   */
  it('무토큰 401 거절은 그대로 호출자에게 전달된다(로그인 이동 판단은 ApiClient 몫)', async () => {
    const target = runtime.normalizeTarget(rawTarget())!;
    const error = Object.assign(new Error('401'), { response: { status: 401 }, g7Auth: { tokenSent: false } });
    post.mockRejectedValue(error);

    await expect(runtime.upload(target, new File(['x'], 'a.png'))).rejects.toBe(error);
  });

  /**
   * @scenario case=response_without_url
   * @effects upload_without_url_rejected
   */
  it('응답에 url 이 없으면 거절한다', async () => {
    const target = runtime.normalizeTarget(rawTarget())!;
    post.mockResolvedValue({ success: true, data: {} });

    await expect(runtime.upload(target, new File(['x'], 'a.png'))).rejects.toThrow('no url');
  });

  /**
   * @scenario case=adapter_priority
   * @effects adapter_selected_by_priority
   */
  it('우선순위가 높고 supports 가 참인 어댑터를 쓰고, 해제하면 기본 어댑터로 돌아간다', async () => {
    const target = runtime.normalizeTarget(rawTarget())!;
    post.mockResolvedValue({ data: { url: '/core' } });
    const custom = vi.fn().mockResolvedValue({ url: '/custom', kind: 'image' });
    const off = runtime.registerAdapter({ id: 's3', priority: 10, supports: (_t: ContentMediaUploadTarget, f: File) => f.name.endsWith('.png'), upload: custom });

    expect(runtime.listAdapters().map((a) => a.id)).toEqual(['s3', 'core-api']);
    expect((await runtime.upload(target, new File(['x'], 'a.png'))).url).toBe('/custom');
    expect((await runtime.upload(target, new File(['x'], 'a.jpg'))).url).toBe('/core');

    off();
    expect((await runtime.upload(target, new File(['x'], 'a.png'))).url).toBe('/core');
    expect(runtime.registerAdapter({} as any)).toBeTypeOf('function');
  });
});
