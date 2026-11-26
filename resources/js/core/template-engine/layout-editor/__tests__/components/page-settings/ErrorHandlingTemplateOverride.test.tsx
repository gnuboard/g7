/**
 * ErrorHandlingTemplateOverride.test.tsx — 오류 처리 [덮기] 단추와 행 목록
 *
 * 설계 의도(lang `layout_editor.error_rows.override_template`): 「이 동작은 템플릿 전체에 설정돼 있습니다. 여기서 바꾸면
 * 이 페이지에만 적용되어 템플릿 설정을 덮습니다」. 템플릿 설정(`/api/templates/{id}/config` 의 errorHandling, 런타임에
 * TemplateApp 이 ErrorHandlingResolver.setTemplateConfig 로 등록하는 값)에 있는 코드인데 이 페이지에 동작이 없으면
 * 행에 〔템플릿〕 출처와 [덮기] 가 나와야 한다.
 *
 * 결함: 셸(PageSettingsModal)이 templateCodes 로 병합본 키를 넘겨, 병합본에 있는 코드는 먼저 〔상속〕
 * 으로 판정되고 병합본에 없는 코드는 템플릿 코드로도 잡히지 않아 [덮기] 가 나올 수 없었다. 또 셸은 레이아웃이 따로
 * 선언한 코드(예 418)가 templateCodes 경로로 행이 된다고 보았지만 행 목록은 표준 코드로만 만들어져 그 코드를 편집기에서
 * 볼 수 없었다. 셸 배선은 PageSettingsModal.test.tsx 의 D-N 시험이 잰다.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ErrorHandlingForm } from '../../../components/page-settings/ErrorHandlingForm';

const t = (k: string) => k;
const CODES = ['401', '403', '404', '500', '503'];
const RECIPES = {
  toast: { label: '$t:안내', params: [{ key: 'message' }], build: { handler: 'toast', params: { message: '{{message}}' } } },
};

beforeEach(() => cleanup());

describe('오류 처리 — 템플릿 코드의 [덮기]와 레이아웃 선언 코드의 행', () => {
  /**
   * @scenario subject=error_handling_template_override
   * @effects error_template_code_without_page_action_offers_override_here
   */
  it('템플릿 설정 코드(500)인데 이 페이지에 동작이 없으면 〔템플릿〕 출처와 [덮기] 가 나오고, 누르면 그 코드로 덮기를 시작한다', () => {
    const onChange = vi.fn();
    render(
      <ErrorHandlingForm value={{}} onChange={onChange} t={t} errorConfigCodes={CODES} templateCodes={['500']} recipes={RECIPES} />,
    );
    expect(screen.getByTestId('g7le-error-rows-source-500').textContent).toContain('source_template');
    fireEvent.click(screen.getByTestId('g7le-error-rows-override-here-500'));
    expect(onChange).toHaveBeenLastCalledWith({ '500': {} });
  });

  /**
   * @scenario subject=error_handling_template_override
   * @effects error_layout_declared_code_gets_row
   */
  it('레이아웃이 따로 선언한 코드(418)와 템플릿 설정에만 있는 코드(422)도 행으로 나온다', () => {
    render(
      <ErrorHandlingForm
        value={{ '418': { handler: 'toast' } }}
        onChange={vi.fn()}
        t={t}
        errorConfigCodes={CODES}
        templateCodes={['422']}
        recipes={RECIPES}
      />,
    );
    expect(screen.getByTestId('g7le-error-rows-row-418')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-422')).toBeInTheDocument();
    // 표준 코드와 default 행은 그대로 있고 maintenance 는 행이 아니다.
    expect(screen.getByTestId('g7le-error-rows-row-401')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-error-rows-row-default')).toBeInTheDocument();
  });
});
