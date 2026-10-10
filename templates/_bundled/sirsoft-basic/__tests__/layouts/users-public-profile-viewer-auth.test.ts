/**
 * @file users-public-profile-viewer-auth.test.ts
 * @description 공개 프로필·작성글 화면의 글 목록·건수는 «보는 사람 권한» 기준이다 (7.1.0 실측 O-K4-7)
 *
 * 서버(`/users/{uuid}/posts`, `/users/{uuid}/posts/stats`)는 optional.sanctum 이라 토큰이 실리면
 * 그 회원이 읽을 수 있는 게시판(회원전용 등)의 글까지 세고, 토큰이 없으면 비로그인 기준으로 센다.
 * 그런데 화면 데이터 소스가 `auth_required:false`(= auth_mode none)라 로그인해도 토큰을 싣지 않아,
 * 회원에게도 비로그인 기준 목록·건수가 보였다(2026-10-07 측정: 회원 m2 화면 「10 Posts」, 같은 API 를
 * m2 토큰으로 부르면 11).
 *
 * auth_mode optional — 토큰이 있으면 싣고, 없으면 그대로 비로그인 요청을 낸다(로그인 화면으로 튕기지 않는다).
 * required 로 두면 비로그인 방문자에게는 요청 자체를 건너뛰어 공개 프로필이 비어 버린다.
 *
 * @scenario viewer=member
 * @effects public_profile_post_sources_send_viewer_token
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';

function load(rel: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../', rel), 'utf-8'));
}

function source(layout: any, id: string): any {
  const found = (layout.data_sources ?? []).find((s: any) => s.id === id);
  expect(found, `${layout.layout_name} 에 데이터 소스 ${id} 가 없다`).toBeTruthy();

  return found;
}

describe('공개 프로필 글 목록·건수 데이터 소스는 보는 사람 토큰을 싣는다 (O-K4-7)', () => {
  const cases: Array<[string, string]> = [
    ['layouts/users/show.json', 'postStats'],
    ['layouts/users/show.json', 'recentPosts'],
    ['layouts/users/posts.json', 'userPosts'],
  ];

  // @scenario viewer=member
  // @effects public_profile_post_sources_send_viewer_token
  it.each(cases)('%s 의 %s 는 auth_mode optional 이다', (rel, id) => {
    const ds = source(load(rel), id);

    expect(ds.auth_mode).toBe('optional');
    // auth_required:false 가 남으면 auth_mode 와 뜻이 엇갈린다(엔진은 auth_mode 우선이지만 편집기·문서가 헷갈린다)
    expect(ds.auth_required).toBeUndefined();
  });

  // @scenario viewer=member
  // @effects public_profile_post_sources_send_viewer_token
  it('비로그인 방문자에게도 요청이 나가도록 required 로 두지 않는다', () => {
    for (const [rel, id] of cases) {
      expect(source(load(rel), id).auth_mode).not.toBe('required');
    }
  });

  // @scenario viewer=member
  // @effects public_profile_post_sources_send_viewer_token
  it('짝 .g7.html 소스도 같은 선언이다(굽기 산출만 고친 회귀 방지)', () => {
    const show = fs.readFileSync(path.resolve(__dirname, '../../layouts/users/show.g7.html'), 'utf-8');
    const posts = fs.readFileSync(path.resolve(__dirname, '../../layouts/users/posts.g7.html'), 'utf-8');

    for (const [html, id] of [[show, 'postStats'], [show, 'recentPosts'], [posts, 'userPosts']] as const) {
      const tag = html.match(new RegExp(`<data-source id="${id}"[^>]*>`))?.[0] ?? '';
      expect(tag, `${id} 태그가 없다`).not.toBe('');
      expect(tag).toContain('auth_mode="optional"');
      expect(tag).not.toContain('auth_required');
    }
  });
});
