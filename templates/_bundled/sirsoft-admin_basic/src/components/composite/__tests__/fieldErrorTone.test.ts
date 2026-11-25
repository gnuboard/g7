/**
 * @file fieldErrorTone.test.ts
 * @description 입력값 오류 문구 한 색 (1.2.1 「입력값 오류 문구의 색과 배치를 통일」, 7.2.1 출시 전 실측 W1-002)
 *
 * 레이아웃의 오류 문구는 `.form-error`(red-700 / 다크 red-300, 입력칸 아래 줄)로 맞췄는데, 부품이 직접 그리는
 * 칸 오류 문구가 `text-red-600 dark:text-red-400` 를 써서 같은 화면에 빨강이 두 가지로 보였다(메뉴 관리 폼 — 슬러그 오류와 아이콘 칸 오류).
 * 칸 아래 오류 문구를 그리는 부품은 `.form-error` 를 쓴다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const dir = path.resolve(__dirname, '..');
const files = [
  'MenuIconField.tsx',
  'FormField.tsx',
  'DynamicFieldList/DynamicFieldListItem.tsx',
  'MultilingualInput.tsx',
  'MultilingualTagInput.tsx',
  'RadioGroup.tsx',
];

describe('칸 오류 문구 색 (W1-002)', () => {
  // @scenario case=field_error_tone
  // @effects field_error_uses_form_error_class
  it.each(files)('%s 의 칸 오류 문구는 form-error 를 쓰고 옛 빨강(text-xs text-red-600)을 쓰지 않는다', (file) => {
    const src = fs.readFileSync(path.join(dir, file), 'utf-8');
    expect(src).not.toMatch(/text-xs text-red-600 dark:text-red-400/);
    expect(src).not.toMatch(/mt-1 text-xs text-red-600/);
    expect(src).toMatch(/className="form-error[" ]/);
  });
});
