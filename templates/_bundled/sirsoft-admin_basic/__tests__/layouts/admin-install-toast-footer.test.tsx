/**
 * @file admin-install-toast-footer.test.tsx
 * @description 설치·업데이트 창 고정 하단(props.footer) 단추의 성공 토스트가 응답 값을 번역 인자로 채운다 
 *
 * 브라우저 실측에서 서명 ZIP 설치 성공 토스트가 「패키지 서명을 확인했습니다 (서명자: ).」로 서명자 자리가 비었다.
 * 응답에는 `data.integrity_key_id` 가 있었고, 토스트 핸들러가 받은 문구는 이미 번역된 글자였다. 설치 단추는 Modal 의
 * 고정 하단(`props.footer`, 컴포넌트 정의 배열)에 있고, 렌더러는 props 를 ① 바인딩 ② 번역 순서로 미리 푼다. ①은 액션 정의를
 * 건너뛰지만 ②는 그 안까지 내려가 `$t:…|signer={{response…}}` 를 응답이 오기 전 렌더 문맥(response 없음)으로 번역했다.
 * 종전 시험은 footer 를 본문 자식으로 펼쳐 그려(노드 수준 액션) 이 경로를 밟지 않아 초록이었다.
 *
 * 그래서 진짜 Modal 과 엔진으로 그리고, 고정 하단의 설치 단추를 실제로 누른다. 하네스 디스패처에는 번역 엔진이 없어
 * 토스트 문구를 번역 전 문자열로 쌓으므로, 운영 handleToast 와 같은 번역을 쌓인 문구에 적용해 화면 글자를 비교한다
 * (이미 번역된 글자에는 효과가 없다 — 결함이면 빈 서명자가 그대로 남는다).
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { createLayoutTest, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import fs from 'fs';
import path from 'path';
import { makeHarnessModal } from './harnessModal';
import adminKo from '../../lang/partial/ko/admin.json';

// 엔진(코어)은 저장소 루트의 react 를, 진짜 Modal 은 이 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 — 이 파일에서만
// 템플릿 쪽 react 를 루트 사본으로 바꿔 낀다(admin-settings-core-update-result-footer.test.tsx 와 같은 방식)
vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});

const Plain: React.FC<any> = ({ className, children, text }) => <div className={className}>{children ?? text}</div>;
const TestButton: React.FC<any> = ({ onClick, disabled, className, children, text }) => (
  <button type="button" onClick={onClick} disabled={disabled} className={className}>
    {children ?? text}
  </button>
);
const TestFileInput: React.FC<any> = ({ placeholder }) => <div>{placeholder}</div>;
const TestTabNavigation: React.FC<any> = () => <div />;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });
  const names = ['Div', 'P', 'Span', 'Icon', 'Label', 'Code', 'A', 'Img', 'Ul', 'Li', 'H3', 'H4', 'Input', 'Textarea'];
  (registry as any).registry = {
    ...Object.fromEntries(names.map((n) => [n, basic(n, Plain)])),
    Button: basic('Button', TestButton),
    FileInput: basic('FileInput', TestFileInput),
    TabNavigation: { component: TestTabNavigation, metadata: { name: 'TabNavigation', type: 'composite' } },
    Modal: { component: makeHarnessModal(), metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const LAYOUTS_DIR = path.resolve(__dirname, '../../layouts');

/** 조각 참조(`partial`)를 디스크의 짝 JSON 으로 펼친다(설치가 하는 인라인과 같은 결과) */
function inlinePartials(node: any): any {
  if (Array.isArray(node)) return node.map(inlinePartials);
  if (node && typeof node === 'object') {
    if (typeof node.partial === 'string' && Object.keys(node).length === 1) {
      const { meta: _meta, ...rest } = JSON.parse(fs.readFileSync(path.join(LAYOUTS_DIR, node.partial), 'utf-8'));
      return inlinePartials(rest);
    }
    return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, inlinePartials(v)]));
  }
  return node;
}

const loadModal = (relativePath: string) => inlinePartials(JSON.parse(fs.readFileSync(path.join(LAYOUTS_DIR, relativePath), 'utf-8')));

/** 정해 둔 응답 하나를 돌려주는 fetch 모의(CSRF 쿠키 요청은 204) */
function installFetch(body: any, status = 201): void {
  (globalThis as any).fetch = vi.fn(async (url: string) => {
    const reply = String(url).includes('csrf-cookie') ? { status: 204, body: null } : { status, body };
    return {
      ok: reply.status >= 200 && reply.status < 300,
      status: reply.status,
      statusText: '',
      json: async () => reply.body,
      text: async () => JSON.stringify(reply.body),
      headers: new Headers({ 'Content-Type': 'application/json' }),
    } as Response;
  });
}

const T = adminKo.package_integrity;
const CTX = { templateId: 'test-template', locale: 'ko' } as any;
const VERIFIED = { success: true, message: 'ok', data: { identifier: 'glitter-board', integrity_status: 'verified', integrity_key_id: 'glitter:93a4eed863537167' } };

const CASES = [
  { name: '모듈 수동 설치', file: 'partials/admin_module_list/_modal_manual_install.json', modalId: 'module_manual_install_modal', globals: { moduleInstallTab: 'file_upload', moduleUploadFile: new File(['zip'], 'a.zip') }, install: adminKo.modules.actions.install },
  { name: '플러그인 수동 설치', file: 'partials/admin_plugin_list/_modal_manual_install.json', modalId: 'plugin_manual_install_modal', globals: { pluginInstallTab: 'file_upload', pluginUploadFile: new File(['zip'], 'a.zip') }, install: adminKo.plugins.actions.install },
  { name: '템플릿 수동 설치', file: 'partials/admin_template_list/_modal_manual_install.json', modalId: 'template_manual_install_modal', globals: { templateInstallTab: 'file_upload', templateUploadFile: new File(['zip'], 'a.zip') }, install: adminKo.templates.actions.install },
  { name: '언어팩 설치', file: 'partials/admin_language_pack_list/_modal_install.json', modalId: 'language_pack_install_modal', globals: { languagePackInstallTab: 'file_upload', languagePackUploadFile: new File(['zip'], 'ja.zip') }, install: adminKo.language_packs.actions.install },
] as const;

describe('설치 창 고정 하단 단추의 성공 토스트 — 응답 값 번역 인자 ', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;
  });

  /**
   * 커버 축(마커 아님 — 평문): 창 module·plugin·template 수동 설치, 언어팩 설치 / 단추 위치 props.footer / response 201_verified
   * @scenario case=manual_install_verified_toast
   * @effects verified_toast_names_signer
   */
  it.each(CASES)('$name — 서명 확인 토스트에 서명자(key_id 콜론 앞)를 채운다', async ({ file, modalId, globals, install }) => {
    const modal = loadModal(file);
    expect(modal.props.footer.some((n: any) => n.name === 'Button'), '설치 단추가 고정 하단에 있어야 이 경로를 잰다').toBe(true);

    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: `install_toast_footer_${modalId}`,
        data_sources: [],
        initGlobal: { packageIntegrity: null, packageIntegrityMessage: null, ...globals },
        modals: [modal],
        components: [{ id: 'main', type: 'basic', name: 'Div', children: [] }],
      } as any,
      { componentRegistry: setupRegistry(), includeModals: true, translations: { admin: adminKo }, locale: 'ko' },
    );
    await testUtils.render();
    await testUtils.triggerAction({ type: 'click', handler: 'openModal', target: modalId } as any);
    await testUtils.rerender();
    installFetch(VERIFIED);

    const bar = document.querySelector('.modal-footer-bar') as HTMLElement;
    const button = [...bar.querySelectorAll('button')].find((b) => b.textContent?.includes(install));
    expect(button, `고정 하단에 「${install}」 단추가 없다`).toBeTruthy();
    button!.click();

    const engine = TranslationEngine.getInstance();
    const shown = () => testUtils!.getToasts().map((t) => engine.resolveTranslations(t.message, CTX, {}));
    const prefix = T.result_verified.split('{{')[0];
    await waitFor(() => expect(shown().some((m) => m.startsWith(prefix))).toBe(true));
    expect(shown().find((m) => m.startsWith(prefix))).toBe(T.result_verified.replace('{{signer}}', 'glitter'));
  });
});
