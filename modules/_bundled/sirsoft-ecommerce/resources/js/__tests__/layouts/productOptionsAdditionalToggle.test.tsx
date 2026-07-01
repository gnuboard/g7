/**
 * @file productOptionsAdditionalToggle.test.tsx
 * @description A23 — 추가옵션 토글 파생화 + 확인 모달 트리거 회귀 (구조 검증)
 *
 * 명령형 has_additional_options boolean 제거 → additional_options.length 파생식으로 전환되었는지,
 * "사용"/"미사용" 토글이 파생식 active 와 확인 모달 트리거를 사용하는지,
 * init 기본값과 직렬화에 has_additional_options 가 남아있지 않은지 검증한다.
 *
 * §B-FAIL-1 회귀: 루트 modals 배열의 모달은 Modal 컴포넌트가 `show` prop 을 받지 않고
 * 엔진(template-engine.ts)이 `_global.modalStack`(openModal/closeModal) 기반으로 isOpen 을
 * 강제 주입하므로, `"show":"{{_local.ui.X}}"` + setState 플래그 방식으로는 열리지 않는다.
 * 반드시 openModal/closeModal 핸들러로 여닫아야 한다.
 *
 * §13-D-FAIL 회귀: Modal 컴포넌트(Modal.tsx)는 `slots`/`slots.footer` 를 렌더하지 않고
 * `{children}` 만 렌더한다(modal-usage.md "잘못된 패턴"). 따라서 취소/확인 버튼을 `slots.footer`
 * 에 두면 라이브에서 버튼이 미렌더되어 "비우기" 플로우를 완결할 수 없다. footer 버튼은 반드시
 * `children` 말미의 `flex justify-end` Div 안에 두어야 한다(정상 형제 _modal_label_uncheck_confirm 패턴).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import productOptionsPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.json';
import productForm from '../../../layouts/admin/admin_ecommerce_product_form.json';
import clearModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_additional_options_clear.json';
import saveTemplateModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_save_template.json';

const serialize = (obj: unknown) => JSON.stringify(obj);

/** children 트리를 DFS 로 순회하며 조건에 맞는 첫 노드를 찾는다 (slots 는 순회하지 않음 — 미렌더이므로). */
const findInChildren = (root: any, pred: (n: any) => boolean): any => {
    const stack: any[] = [root];
    while (stack.length) {
        const node = stack.shift();
        if (node && typeof node === 'object') {
            if (pred(node)) return node;
            if (Array.isArray(node.children)) stack.push(...node.children);
        }
    }
    return null;
};

/** Button 노드를 children 트리에서 텍스트로 찾는다. */
const findButtonByText = (root: any, text: string): any =>
    findInChildren(root, (n) => n.name === 'Button' && n.text === text);

describe('A23 — 추가옵션 토글 파생화', () => {
    it('명령형 has_additional_options 가 옵션 partial 직렬화에서 0회 등장', () => {
        expect(serialize(productOptionsPartial)).not.toContain('has_additional_options');
    });

    it('루트 폼 init 기본값에서 has_additional_options 가 제거됨', () => {
        expect(serialize(productForm)).not.toContain('has_additional_options');
    });

    it('섹션 if 가 additional_options.length 파생식을 사용', () => {
        const flat = serialize(productOptionsPartial);
        expect(flat).toContain('(_local.form.additional_options ?? []).length > 0');
    });

    it('"사용" 버튼은 addAdditionalOption 을 condition(length===0) 으로 호출', () => {
        const flat = serialize(productOptionsPartial);
        expect(flat).toContain('sirsoft-ecommerce.addAdditionalOption');
        expect(flat).toContain('(_local.form.additional_options ?? []).length === 0');
    });
});

describe('A23/§B-FAIL-1 — 추가옵션 확인 모달은 openModal/closeModal 로 여닫는다', () => {
    const findNotUseButton = (): any => {
        // "미사용" 버튼: not_use 텍스트를 가진 Button
        const stack: any[] = [productOptionsPartial as any];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (node.text === '$t:sirsoft-ecommerce.common.not_use') return node;
                for (const v of Object.values(node)) {
                    if (Array.isArray(v)) stack.push(...v);
                    else if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return null;
    };

    it('"미사용" 버튼은 행이 있을 때 openModal(additional_options_clear_modal) 을 호출', () => {
        const btn = findNotUseButton();
        expect(btn).not.toBeNull();
        const openAction = btn.actions.find((a: any) => a.handler === 'openModal');
        expect(openAction).toBeDefined();
        expect(openAction.target).toBe('additional_options_clear_modal');
        // 행이 있을 때만
        expect(openAction.condition).toContain('(_local.form.additional_options ?? []).length > 0');
    });

    it('"미사용" 버튼은 setState ui.showAdditionalOptionsClearModal 플래그를 더이상 쓰지 않는다', () => {
        const flat = serialize(productOptionsPartial);
        expect(flat).not.toContain('showAdditionalOptionsClearModal');
    });

    it('확인 모달은 `show` prop 을 갖지 않는다 (엔진이 isOpen=modalStack 으로 제어)', () => {
        expect((clearModal as any).props.show).toBeUndefined();
    });

    it('확인 모달은 slots/slots.footer 를 쓰지 않는다 (Modal 컴포넌트 미렌더 — §13-D-FAIL)', () => {
        expect((clearModal as any).slots).toBeUndefined();
    });

    it('확인 모달 footer 버튼은 children 말미 flex-justify-end Div 안에 있다', () => {
        const footerDiv = findInChildren(
            clearModal,
            (n) => n.name === 'Div' && typeof n.props?.className === 'string' && /flex/.test(n.props.className) && /justify-end/.test(n.props.className),
        );
        expect(footerDiv).not.toBeNull();
        const btnTexts = (footerDiv.children ?? []).filter((c: any) => c.name === 'Button').map((b: any) => b.text);
        expect(btnTexts).toContain('$t:sirsoft-ecommerce.common.cancel');
        expect(btnTexts).toContain('$t:sirsoft-ecommerce.common.confirm');
    });

    it('확인 모달 취소 버튼은 closeModal 로 닫는다 (children 트리)', () => {
        const cancelBtn = findButtonByText(clearModal, '$t:sirsoft-ecommerce.common.cancel');
        expect(cancelBtn).not.toBeNull();
        const handlers = cancelBtn.actions.map((a: any) => a.handler);
        expect(handlers).toContain('closeModal');
        expect(handlers).not.toContain('setState');
    });

    it('확인 모달 확정 버튼은 clearAdditionalOptions 핸들러로 비우고 closeModal 로 닫는다 (children 트리)', () => {
        const confirmBtn = findButtonByText(clearModal, '$t:sirsoft-ecommerce.common.confirm');
        expect(confirmBtn).not.toBeNull();
        const handlers = confirmBtn.actions.map((a: any) => a.handler);
        // dot-path 인라인 setState 는 form 참조를 안 바꿔 토글 className 리렌더 누락 → 전용 핸들러 사용
        expect(handlers).toContain('sirsoft-ecommerce.clearAdditionalOptions');
        expect(handlers).not.toContain('setState');
        // 닫기는 closeModal
        expect(handlers).toContain('closeModal');
    });

    it('확인 모달은 showAdditionalOptionsClearModal 플래그를 더이상 쓰지 않는다', () => {
        expect(serialize(clearModal)).not.toContain('showAdditionalOptionsClearModal');
    });

    it('루트 폼 modals 에 clear 모달이 등록됨', () => {
        const modals = (productForm as any).modals;
        const registered = modals.some((m: any) =>
            typeof m.partial === 'string' && m.partial.includes('_modal_additional_options_clear'),
        );
        expect(registered).toBe(true);
    });

    it('루트 폼 init 에서 showAdditionalOptionsClearModal seed 가 제거됨', () => {
        expect(serialize(productForm)).not.toContain('showAdditionalOptionsClearModal');
    });
});

describe('추가옵션명 입력 디바운스 + 필수 토글 UI', () => {
    /** 추가옵션 sortable 영역(additional_options_sortable)에서 노드를 찾는다. */
    const findAdditionalOptionNode = (pred: (n: any) => boolean): any => {
        const stack: any[] = [productOptionsPartial as any];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (pred(node)) return node;
                for (const v of Object.values(node)) {
                    if (Array.isArray(v)) stack.push(...v);
                    else if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return null;
    };

    it('추가옵션명 MultilingualInput 의 change 액션에 debounce(300) 가 적용됨 (타이핑 지연 방지)', () => {
        // additional_options_{{addIdx}}_name 입력
        const nameInput = findAdditionalOptionNode(
            (n) => n.name === 'MultilingualInput' && typeof n.props?.name === 'string' && n.props.name.includes('_name') && /additional_options/.test(n.props.name),
        );
        expect(nameInput).not.toBeNull();
        const changeAction = (nameInput.actions ?? []).find(
            (a: any) => a.type === 'change' && a.handler === 'sirsoft-ecommerce.updateAdditionalOption' && a.params?.field === 'name',
        );
        expect(changeAction).toBeDefined();
        expect(changeAction.debounce).toBe(300);
    });

    it('추가옵션 필수 여부는 raw checkbox Input 이 아니라 Toggle 컴포넌트를 사용', () => {
        // is_required 를 다루는 change 액션을 가진 노드
        const requiredControl = findAdditionalOptionNode(
            (n) =>
                Array.isArray(n.actions) &&
                n.actions.some(
                    (a: any) => a.handler === 'sirsoft-ecommerce.updateAdditionalOption' && a.params?.field === 'is_required',
                ),
        );
        expect(requiredControl).not.toBeNull();
        // Toggle 컴포넌트여야 함 (raw checkbox Input 금지)
        expect(requiredControl.name).toBe('Toggle');
        expect(requiredControl.props?.type).not.toBe('checkbox');
        // $event.target.checked 로 boolean 전달
        const changeAction = requiredControl.actions.find(
            (a: any) => a.params?.field === 'is_required',
        );
        expect(changeAction.params.value).toContain('$event.target.checked');
    });

    it('추가옵션 영역에 raw "type":"checkbox" Input 이 남아있지 않다', () => {
        // additional_options_sortable 하위에서 checkbox Input 부재 확인
        const sortable = findAdditionalOptionNode((n) => n.id === 'additional_options_sortable');
        expect(sortable).not.toBeNull();
        const rawCheckbox = (() => {
            const stack: any[] = [sortable];
            while (stack.length) {
                const node = stack.shift();
                if (node && typeof node === 'object') {
                    if (node.name === 'Input' && node.props?.type === 'checkbox') return node;
                    for (const v of Object.values(node)) {
                        if (Array.isArray(v)) stack.push(...v);
                        else if (v && typeof v === 'object') stack.push(v);
                    }
                }
            }
            return null;
        })();
        expect(rawCheckbox).toBeNull();
    });
});

describe('추가옵션 선택지(values) 리스트 UI — 세션 B', () => {
    /** 추가옵션 partial 트리에서 조건에 맞는 노드를 모두 찾는다. */
    const findAll = (root: any, pred: (n: any) => boolean): any[] => {
        const found: any[] = [];
        const stack: any[] = [root];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (pred(node)) found.push(node);
                for (const v of Object.values(node)) {
                    if (Array.isArray(v)) stack.push(...v);
                    else if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return found;
    };

    const flat = serialize(productOptionsPartial);

    it('그룹 itemTemplate 안에 values iteration(addVal/addValIdx)이 존재한다', () => {
        const iter = findAll(productOptionsPartial, (n) =>
            n.iteration && n.iteration.item_var === 'addVal' && n.iteration.index_var === 'addValIdx',
        );
        expect(iter.length).toBeGreaterThan(0);
        // source 는 그룹의 values
        expect(iter[0].iteration.source).toContain('addOpt.values');
    });

    it('선택지명은 MultilingualInput + updateAdditionalOptionValue(field=name, debounce 300)', () => {
        const nameInputs = findAll(productOptionsPartial, (n) =>
            n.name === 'MultilingualInput' &&
            typeof n.props?.name === 'string' &&
            n.props.name.includes('_values_') && n.props.name.includes('_name'),
        );
        expect(nameInputs.length).toBeGreaterThan(0);
        const changeAction = (nameInputs[0].actions ?? []).find(
            (a: any) => a.handler === 'sirsoft-ecommerce.updateAdditionalOptionValue' && a.params?.field === 'name',
        );
        expect(changeAction).toBeDefined();
        expect(changeAction.debounce).toBe(300);
        expect(changeAction.params.groupIndex).toBe('{{addIdx}}');
        expect(changeAction.params.valueIndex).toBe('{{addValIdx}}');
    });

    it('추가금 입력은 number Input + min:0 + updateAdditionalOptionValue(field=price_adjustment)', () => {
        const priceInputs = findAll(productOptionsPartial, (n) =>
            n.name === 'Input' && n.props?.type === 'number' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.updateAdditionalOptionValue' && a.params?.field === 'price_adjustment'),
        );
        expect(priceInputs.length).toBeGreaterThan(0);
        expect(priceInputs[0].props.min).toBe('0');
    });

    it('기본 선택지는 radio Input (그룹당 1개) — field=is_default', () => {
        const radios = findAll(productOptionsPartial, (n) =>
            n.name === 'Input' && n.props?.type === 'radio' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.updateAdditionalOptionValue' && a.params?.field === 'is_default'),
        );
        expect(radios.length).toBeGreaterThan(0);
        // 그룹 단위 radio name (그룹당 1개 선택 보장)
        expect(radios[0].props.name).toContain('_default');
    });

    it('활성 토글은 Toggle 컴포넌트 — field=is_active', () => {
        const toggles = findAll(productOptionsPartial, (n) =>
            n.name === 'Toggle' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.updateAdditionalOptionValue' && a.params?.field === 'is_active'),
        );
        expect(toggles.length).toBeGreaterThan(0);
    });

    it('선택지 추가 버튼은 addAdditionalOptionValue(groupIndex) — max 20 가드', () => {
        expect(flat).toContain('sirsoft-ecommerce.addAdditionalOptionValue');
        const addBtns = findAll(productOptionsPartial, (n) =>
            n.name === 'Button' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.addAdditionalOptionValue'),
        );
        expect(addBtns.length).toBeGreaterThan(0);
        expect(addBtns[0].if).toContain('< 20');
    });

    it('선택지 삭제 버튼은 removeAdditionalOptionValue(groupIndex, valueIndex)', () => {
        const delBtns = findAll(productOptionsPartial, (n) =>
            n.name === 'Button' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.removeAdditionalOptionValue'),
        );
        expect(delBtns.length).toBeGreaterThan(0);
        const action = delBtns[0].actions.find((a: any) => a.handler === 'sirsoft-ecommerce.removeAdditionalOptionValue');
        expect(action.params.groupIndex).toBe('{{addIdx}}');
        expect(action.params.valueIndex).toBe('{{addValIdx}}');
    });

    it('다통화 환산 표시는 추가금 0원(초기 상태)에서도 환율통화만 있으면 항상 노출(일반 옵션 패턴 일치, 레이아웃 정렬 보존)', () => {
        // 회귀: 과거엔 (addVal.price_adjustment ?? 0) > 0 가드로 0원이면 환산행이 사라져
        // row 높이가 들쭉날쭉 → sm:items-end 정렬이 어긋났다. 일반 상품옵션(currencyColumns.length>0)처럼
        // 가격과 무관하게 환율통화 유무로만 노출하도록 가드를 제거한다.
        const valueRow = findAll(productOptionsPartial, (n) =>
            n.iteration && n.iteration.item_var === 'addVal',
        )[0];
        expect(valueRow).toBeDefined();
        const currencyPreview = findAll(valueRow, (n) =>
            typeof n.if === 'string' &&
            n.if.includes("exchange_rate") &&
            n.if.includes('.length > 0') &&
            Array.isArray(n.children) &&
            n.children.some((c: any) => c.iteration && c.iteration.item_var === 'addValCur'),
        )[0];
        expect(currencyPreview).toBeDefined();
        // price_adjustment > 0 가드가 제거되었는지 (해당 미리보기 Div if 한정)
        expect(currencyPreview.if).not.toContain('(addVal.price_adjustment ?? 0) > 0');
        expect(currencyPreview.if).not.toContain('price_adjustment ?? 0) >');
        // 환산 텍스트는 0 fallback 으로 NaN 방지
        const span = currencyPreview.children.find((c: any) => c.iteration && c.iteration.item_var === 'addValCur');
        expect(span.text).toContain('(addVal.price_adjustment ?? 0)');
    });

    it('선택지 row 컨테이너는 sm:items-start 로 인풋박스를 상단(라벨+인풋 baseline) 정렬한다', () => {
        // 회귀: sm:items-end 는 추가금 블럭(다통화 줄)이 길어지면 인풋을 하단으로 밀어
        // 선택지명 인풋과 세로 위치가 어긋났다. 상단 정렬로 인풋이 같은 라인에 나란히 오게 한다.
        const valueRow = findAll(productOptionsPartial, (n) =>
            n.iteration && n.iteration.item_var === 'addVal' &&
            typeof n.props?.className === 'string' && n.props.className.includes('sm:flex-row'),
        )[0];
        expect(valueRow).toBeDefined();
        expect(valueRow.props.className).toContain('sm:items-start');
        expect(valueRow.props.className).not.toContain('sm:items-end');
    });

    it('라벨 없는 컨트롤(기본/활성/삭제)은 sm:mt-6 으로 인풋 baseline 에 맞춰진다', () => {
        const valueRow = findAll(productOptionsPartial, (n) =>
            n.iteration && n.iteration.item_var === 'addVal' &&
            typeof n.props?.className === 'string' && n.props.className.includes('sm:flex-row'),
        )[0];
        expect(valueRow).toBeDefined();
        // 기본(radio is_default) 컨트롤 래퍼에 sm:mt-6
        const defaultRadio = findAll(valueRow, (n) =>
            n.name === 'Input' && n.props?.type === 'radio',
        )[0];
        // radio 의 부모 Div 를 찾는다
        const defaultWrapper = findAll(valueRow, (n) =>
            Array.isArray(n.children) && n.children.includes(defaultRadio),
        )[0];
        expect(defaultWrapper.props.className).toContain('sm:mt-6');
        // 활성 Toggle 래퍼에 sm:mt-6
        const activeToggle = findAll(valueRow, (n) =>
            n.name === 'Toggle' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.params?.field === 'is_active'),
        )[0];
        const activeWrapper = findAll(valueRow, (n) =>
            Array.isArray(n.children) && n.children.includes(activeToggle),
        )[0];
        expect(activeWrapper.props.className).toContain('sm:mt-6');
        // 삭제 버튼에 sm:mt-6
        const delBtn = findAll(valueRow, (n) =>
            n.name === 'Button' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.removeAdditionalOptionValue'),
        )[0];
        expect(delBtn.props.className).toContain('sm:mt-6');
    });
});

describe('저장 sequence — emitEvent 후 form.images 갱신', () => {
    const findSaveButton = (): any => {
        const stack: any[] = [productForm as any];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (node.id === 'footer_save_button') return node;
                for (const v of Object.values(node)) {
                    if (Array.isArray(v)) stack.push(...v);
                    else if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return null;
    };

    it('저장 sequence 가 emitEvent → setState(form.images) → apiCall 순서를 갖는다', () => {
        const saveBtn = findSaveButton();
        expect(saveBtn).not.toBeNull();
        const seq = saveBtn.actions.find((a: any) => a.handler === 'sequence');
        expect(seq).toBeDefined();
        const actions = seq.params.actions;
        const emitIdx = actions.findIndex((a: any) => a.handler === 'emitEvent' && a.params?.event === 'upload:product_images');
        const apiIdx = actions.findIndex((a: any) => a.handler === 'apiCall');
        const imgSetStateIdx = actions.findIndex(
            (a: any) => a.handler === 'setState' && typeof a.params?.['form.images'] === 'string' && a.params['form.images'].includes('_eventResult'),
        );
        expect(emitIdx).toBeGreaterThanOrEqual(0);
        expect(imgSetStateIdx).toBeGreaterThan(emitIdx);
        expect(apiIdx).toBeGreaterThan(imgSetStateIdx);
    });

    it('form.images setState 는 수정 모드에서만(if route.itemCode) allFiles 를 반영한다', () => {
        const saveBtn = findSaveButton();
        const seq = saveBtn.actions.find((a: any) => a.handler === 'sequence');
        const imgSetState = seq.params.actions.find(
            (a: any) => a.handler === 'setState' && typeof a.params?.['form.images'] === 'string' && a.params['form.images'].includes('_eventResult'),
        );
        expect(imgSetState.if).toContain('route.itemCode');
        expect(imgSetState.if).toContain('allFiles');
    });
});

describe('§13-D-FAIL — save_template 모달도 slots.footer 미사용 (children 배치)', () => {
    it('save_template 모달은 slots/slots.footer 를 쓰지 않는다', () => {
        expect((saveTemplateModal as any).slots).toBeUndefined();
    });

    it('save_template footer 버튼은 children 말미 flex-justify-end Div 안에 있다', () => {
        const footerDiv = findInChildren(
            saveTemplateModal,
            (n) => n.name === 'Div' && typeof n.props?.className === 'string' && /flex/.test(n.props.className) && /justify-end/.test(n.props.className),
        );
        expect(footerDiv).not.toBeNull();
        const btnTexts = (footerDiv.children ?? []).filter((c: any) => c.name === 'Button').map((b: any) => b.text);
        expect(btnTexts).toContain('$t:sirsoft-ecommerce.common.cancel');
        expect(btnTexts).toContain('$t:sirsoft-ecommerce.admin.product.modal.save_template.save_button');
    });

    it('save_template 취소 버튼은 closeModal, 저장 버튼은 confirmSaveNoticeTemplate 핸들러를 호출', () => {
        const cancelBtn = findButtonByText(saveTemplateModal, '$t:sirsoft-ecommerce.common.cancel');
        const saveBtn = findButtonByText(saveTemplateModal, '$t:sirsoft-ecommerce.admin.product.modal.save_template.save_button');
        expect(cancelBtn).not.toBeNull();
        expect(saveBtn).not.toBeNull();
        expect(cancelBtn.actions.map((a: any) => a.handler)).toContain('closeModal');
        expect(saveBtn.actions.map((a: any) => a.handler)).toContain('sirsoft-ecommerce.confirmSaveNoticeTemplate');
    });
});

describe('회귀: 상품 생성/저장 후 navigate 는 id 기반 (product_code URL → show 라우트 405 회피)', () => {
    /** 임의 깊이 트리에서 조건 노드를 DFS 로 모두 수집 (children + 일반 객체 순회). */
    const collectAll = (root: any, pred: (n: any) => boolean): any[] => {
        const out: any[] = [];
        const stack: any[] = [root];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (pred(node)) out.push(node);
                for (const v of Object.values(node)) {
                    if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return out;
    };

    it('저장 onSuccess 의 생성모드 navigate path 는 result.data.id 를 사용한다', () => {
        const navs = collectAll(
            productForm,
            (n) => n.handler === 'navigate' && typeof n.params?.path === 'string' && /\/admin\/ecommerce\/products\//.test(n.params.path) && /\/edit/.test(n.params.path),
        );
        // 생성모드 redirect 노드 (condition: !route.itemCode) 존재
        const createNav = navs => navs.find((n: any) => typeof n.condition === 'string' && /!route\.itemCode/.test(n.condition));
        const node = createNav(navs);
        expect(node).toBeTruthy();
        expect(node.params.path).toContain('result.data.id');
        expect(node.params.path).not.toContain('product_code');
    });
});

describe('직접입력(allow_custom_text) 토글 — 선택지 단위', () => {
    /** 임의 깊이 트리에서 조건 노드를 DFS 로 모두 수집. */
    const collectAll = (root: any, pred: (n: any) => boolean): any[] => {
        const out: any[] = [];
        const stack: any[] = [root];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (pred(node)) out.push(node);
                for (const v of Object.values(node)) {
                    if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return out;
    };

    it('선택지 카드에 allow_custom_text Toggle 이 존재하고 updateAdditionalOptionValue 를 호출한다', () => {
        const toggles = collectAll(
            productOptionsPartial,
            (n) => n.name === 'Toggle' && typeof n.props?.name === 'string' && /allow_custom_text/.test(n.props.name),
        );
        expect(toggles.length).toBeGreaterThan(0);

        const toggle = toggles[0];
        // checked 바인딩이 addVal.allow_custom_text 파생식
        expect(toggle.props.checked).toContain('allow_custom_text');

        const changeAction = (toggle.actions ?? []).find((a: any) => a.type === 'change');
        expect(changeAction).toBeTruthy();
        expect(changeAction.handler).toBe('sirsoft-ecommerce.updateAdditionalOptionValue');
        expect(changeAction.params.field).toBe('allow_custom_text');
    });

    it('직접입력 토글에 i18n 라벨이 붙는다 (키 노출 0)', () => {
        const labels = collectAll(
            productOptionsPartial,
            (n) => n.name === 'Label' && n.text === '$t:sirsoft-ecommerce.admin.product.options.additional_value_allow_custom_text',
        );
        expect(labels.length).toBeGreaterThan(0);
    });
});

describe('빈 선택지 그룹 에러 — 강조 + 영역 하단 메시지', () => {
    const collectAll = (root: any, pred: (n: any) => boolean): any[] => {
        const out: any[] = [];
        const stack: any[] = [root];
        while (stack.length) {
            const node = stack.shift();
            if (node && typeof node === 'object') {
                if (pred(node)) out.push(node);
                for (const v of Object.values(node)) {
                    if (v && typeof v === 'object') stack.push(v);
                }
            }
        }
        return out;
    };

    const serializeAll = JSON.stringify(productOptionsPartial);

    it('그룹 카드 className 이 values 에러일 때 강조 테두리(border-red)를 적용한다', () => {
        // 그룹 카드 루트의 className 표현식에 errors[additional_options.N.values] 분기 + border-red
        const cards = collectAll(
            productOptionsPartial,
            (n) =>
                typeof n.props?.className === 'string' &&
                n.props.className.includes("'.values'") &&
                /border-red/.test(n.props.className),
        );
        expect(cards.length).toBeGreaterThan(0);
    });

    it('선택지 영역 하단에 values 에러 인라인 메시지 노드가 있다', () => {
        const errNodes = collectAll(
            productOptionsPartial,
            (n) =>
                typeof n.if === 'string' &&
                /additional_options.*\.values'\]/.test(n.if) &&
                typeof n.text === 'string' &&
                /additional_options.*\.values'\]/.test(n.text),
        );
        expect(errNodes.length).toBeGreaterThan(0);
    });
});
