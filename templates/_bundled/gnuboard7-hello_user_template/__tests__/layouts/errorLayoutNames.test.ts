/**
 * Hello 사용자 템플릿 — 오류 화면 레이아웃 이름.
 *
 * 7.1.0 전수 실측 M3: 이 템플릿을 켜고 없는 주소를 열면 `GET /api/layouts/gnuboard7-hello_user_template/errors/404 → 404`
 * 로 오류 화면을 찾지 못해 엔진의 「페이지 로딩 실패」 화면과 스택 추적이 그대로 보였다. 템플릿 설정
 * (`template.json` 의 `error_config.layouts`)은 `errors/404` 를 가리키는데 오류 레이아웃 6개의 이름은 `404` 처럼
 * 접두가 없어, 설치 때 그 이름으로 등록됐다(관리자 Hello 템플릿과 sirsoft-basic 은 `errors/404`).
 *
 * @scenario-manifest tests/scenarios/error-layout-names.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../..');
const templateJson = JSON.parse(fs.readFileSync(path.join(root, 'template.json'), 'utf8'));
const errorLayouts: Record<string, string> = templateJson.error_config.layouts;

describe('[case:error-layout-names] Hello 사용자 템플릿 오류 화면 이름', () => {
  /**
   * @scenario code=all
   * @effects error_layout_name_matches_error_config
   */
  it.each(Object.entries(errorLayouts))('%s 오류 화면의 이름이 error_config 의 %s 와 같다', (code, expectedName) => {
    const file = path.join(root, 'layouts', 'errors', `${code}`);
    const json = JSON.parse(fs.readFileSync(`${file}.json`, 'utf8'));
    const markup = fs.readFileSync(`${file}.g7.html`, 'utf8');

    expect(json.layout_name).toBe(expectedName);
    expect(markup).toContain(`<layout name="${expectedName}"`);
  });
});
