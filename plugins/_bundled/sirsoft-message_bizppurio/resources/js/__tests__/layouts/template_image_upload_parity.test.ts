/**
 * @file template_image_upload_parity.test.ts
 * @description 알림톡 템플릿 이미지 업로드 위젯의 **복제 패리티**와 제약 드리프트 검사
 *
 * 이 위젯은 두 화면에 복제돼 있다(플러그인 설정 화면 · 알림 템플릿 폼 주입 조각).
 * 확장 JSON 은 `partial` 로 펼쳐지는 단계가 없고 두 화면의 호스트도 달라 파일을 합칠 수
 * 없다 — 그래서 **복제를 남기되 갈라지는 것을 테스트로 막는다.**
 *
 * 상한·최소 가로는 kapi 의 고정 제약이고 SSoT 는 PHP FormRequest 의 상수다.
 * 안내 문구가 숫자를 문장에 박으면 3중(문구·화면·서버)이 되므로 `$t` 파라미터로 뺐고,
 * 남은 2중(화면 파라미터 ↔ PHP 상수)을 이 테스트가 맞춘다.
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import settingsLayout from '../../../layouts/admin/plugin_settings.json';
import formSections from '../../../extensions/notification_template_form_sections.json';

import { type AnyNode } from './helpers';

const PLUGIN_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');

/**
 * 조건에 맞는 첫 노드를 찾는다 (children/slots/modals/배열 전부 훑는다).
 *
 * @param node 탐색 시작 노드
 * @param match 판정 함수
 * @returns 찾은 노드 또는 null
 */
function findByPredicate(node: unknown, match: (n: AnyNode) => boolean): AnyNode | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findByPredicate(child, match);
      if (found) return found;
    }

    return null;
  }

  if (!node || typeof node !== 'object') return null;

  const current = node as AnyNode;
  if (match(current)) return current;

  for (const value of Object.values(current)) {
    const found = findByPredicate(value, match);
    if (found) return found;
  }

  return null;
}

/** PHP FormRequest 가 선언한 kapi 제약을 읽는다 (SSoT). */
function phpConstraints(): { max: number; width: number } {
  const source = fs.readFileSync(
    path.join(PLUGIN_ROOT, 'src/Http/Requests/BizppurioTemplateImageRequest.php'),
    'utf8',
  );

  const max = /public const MAX_KILOBYTES\s*=\s*(\d+)/.exec(source);
  const width = /public const MIN_WIDTH\s*=\s*(\d+)/.exec(source);

  expect(max, 'MAX_KILOBYTES 상수를 찾지 못했습니다').not.toBeNull();
  expect(width, 'MIN_WIDTH 상수를 찾지 못했습니다').not.toBeNull();

  return { max: Number(max![1]), width: Number(width![1]) };
}

/** 두 화면에서 이미지 업로드 안내 문구 노드를 찾는다. */
function hintNodes(): Array<[string, AnyNode]> {
  const found: Array<[string, AnyNode]> = [];

  for (const [label, tree] of [
    ['plugin_settings', settingsLayout as AnyNode],
    ['notification_template_form_sections', formSections as AnyNode],
  ] as Array<[string, AnyNode]>) {
    const node = findByPredicate(tree, (n) => typeof n.text === 'string' && n.text.includes('image_hint'));
    expect(node, `${label}: 이미지 안내 문구 노드를 찾지 못했습니다`).not.toBeNull();
    found.push([label, node as AnyNode]);
  }

  return found;
}

/** 두 화면에서 파일 입력 노드를 찾는다. */
function fileInputNodes(): Array<[string, AnyNode]> {
  const found: Array<[string, AnyNode]> = [];

  for (const [label, tree] of [
    ['plugin_settings', settingsLayout as AnyNode],
    ['notification_template_form_sections', formSections as AnyNode],
  ] as Array<[string, AnyNode]>) {
    const node = findByPredicate(
      tree,
      (n) => n.name === 'Input' && (n.props as Record<string, unknown> | undefined)?.type === 'file',
    );
    expect(node, `${label}: 파일 입력 노드를 찾지 못했습니다`).not.toBeNull();
    found.push([label, node as AnyNode]);
  }

  return found;
}

describe('알림톡 템플릿 이미지 업로드 — 복제 패리티', () => {
  /**
   * 안내 문구의 숫자가 서버 제약과 같다.
   *
   * @effects template_image_hint_matches_the_server_constraint
   */
  it('안내 문구의 상한·최소 가로가 PHP 상수와 같다', () => {
    const { max, width } = phpConstraints();

    for (const [label, node] of hintNodes()) {
      const text = String(node.text);
      expect(text, `${label}: max 파라미터 누락`).toContain(`max=${max}`);
      expect(text, `${label}: width 파라미터 누락`).toContain(`width=${width}`);
    }
  });

  /**
   * 두 복제본의 `accept` 와 업로드 파라미터가 서로 같다.
   *
   * 한쪽만 고치면 같은 기능이 화면마다 다르게 동작하고, 그 차이는 그 화면을 여는
   * 운영자에게만 보인다.
   *
   * @effects template_image_widget_copies_stay_identical
   */
  it('두 복제본의 accept 와 업로드 배선이 같다', () => {
    const [[, a], [, b]] = fileInputNodes();

    const acceptOf = (n: AnyNode) => (n.props as Record<string, unknown>).accept;
    const uploadActionOf = (n: AnyNode) =>
      (n.actions ?? []).find((action) => String((action as AnyNode).handler ?? '').includes('uploadTemplateImage'));

    expect(acceptOf(a)).toBe(acceptOf(b));
    expect(String(acceptOf(a))).toBe('.jpg,.jpeg,.png');

    const actionA = uploadActionOf(a) as AnyNode | undefined;
    const actionB = uploadActionOf(b) as AnyNode | undefined;

    expect(actionA, 'plugin_settings: 업로드 액션 누락').toBeTruthy();
    expect(actionB, 'notification_template_form_sections: 업로드 액션 누락').toBeTruthy();
    expect(Object.keys((actionA!.params ?? {}) as Record<string, unknown>).sort()).toEqual(
      Object.keys((actionB!.params ?? {}) as Record<string, unknown>).sort(),
    );
  });

  /**
   * 안내 문구가 숫자를 문장에 박지 않는다 — 박으면 상한이 세 곳이 된다.
   *
   * @effects template_image_hint_keeps_numbers_out_of_the_sentence
   */
  /**
   * 안내 문구가 파라미터를 **엔진이 받는 형식**으로 넘긴다.
   *
   * `{{$t('key', { … })}}` 는 표현식 헬퍼가 키 하나만 받아(`$t = (key: string)`) 두 번째
   * 인자를 조용히 버린다. 파라미터를 넘기는 형식은 `$t:key|k=v&k2=v2` 하나뿐이다.
   *
   * @effects template_image_hint_passes_params_in_the_engine_form
   */
  it('안내 문구가 $t:key|param= 형식으로 값을 넘긴다', () => {
    for (const [label, node] of hintNodes()) {
      const text = String(node.text);

      expect(text, `${label}: 파라미터가 버려지는 호출 형식입니다`).not.toMatch(/\$t\(/);
      expect(text, `${label}: max 파라미터가 없습니다`).toMatch(/\$t:[^|]+\|[^"]*max=/);
      expect(text, `${label}: width 파라미터가 없습니다`).toMatch(/width=/);
    }
  });

  it('번역 문장에 숫자가 박혀 있지 않다', () => {
    for (const locale of ['ko', 'en']) {
      const messages = JSON.parse(
        fs.readFileSync(path.join(PLUGIN_ROOT, `resources/lang/${locale}.json`), 'utf8'),
      );
      const hint = String(messages.template.form.image_hint);

      expect(hint, `${locale}: 숫자 리터럴이 남아 있습니다`).not.toMatch(/\d{3}/);

      // 자리표시자는 **엔진이 치환하는 표기**여야 한다. `:max` 같은 PHP 표기는 프런트
      // 번역기가 건드리지 않아(`TranslationEngine.replaceParams` 는 `{{k}}`·`{k}` 만 본다)
      // 화면에 글자 그대로 남는다 — 실측에서 실제로 그렇게 노출됐다.
      expect(hint, `${locale}: PHP 표기 자리표시자가 남아 있습니다`).not.toMatch(/:[a-z_]+/);
      expect(hint).toContain('{{max}}');
      expect(hint).toContain('{{width}}');
    }
  });
});
