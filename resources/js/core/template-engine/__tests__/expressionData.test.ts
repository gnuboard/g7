/**
 * 표현식 컨텍스트 DTO 변환기 (helpers/expressionData) 테스트 (KVE-2026-2350)
 *
 * `$event`·`error`·`$args` 에 원본 DOM 이벤트·Error·콜백 인자가 그대로 실리면 표현식이
 * `target.ownerDocument.defaultView` 로 Window 에 닿는다. 변환기는 표현식이 실제로 읽는
 * 필드만 plain 사본으로 싣는다 — 번들 레이아웃 전수 집계에서 나온 경로가 모두 살아 있어야 한다.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect } from 'vitest';
import {
    toExpressionEvent,
    toExpressionArgs,
    toExpressionData,
    toExpressionError,
} from '../helpers/expressionData';

describe('expressionData — 표현식 DTO', () => {
    describe('toExpressionEvent', () => {
        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('input change 이벤트: value·checked·name·type·tagName·dataset 을 싣고 DOM 참조는 싣지 않는다', () => {
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'agree';
            input.checked = true;
            input.dataset.id = '7';
            const event = new Event('change');
            input.dispatchEvent(event);

            const dto = toExpressionEvent(event) as any;

            expect(dto.type).toBe('change');
            expect(dto.target).toMatchObject({ name: 'agree', checked: true, type: 'checkbox', tagName: 'INPUT' });
            expect(dto.target.dataset).toEqual({ id: '7' });
            expect(dto.target.ownerDocument).toBeUndefined();
            expect(dto.target instanceof Element).toBe(false);
            expect(dto.view).toBeUndefined();
        });

        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('form submit: 이름 있는 컨트롤을 target.<name>.value 로 읽을 수 있다 (게시판 검색)', () => {
            const form = document.createElement('form');
            const search = document.createElement('input');
            search.name = 'search';
            search.value = '공지';
            form.appendChild(search);
            const event = new Event('submit');
            form.dispatchEvent(event);

            const dto = toExpressionEvent(event) as any;

            expect(dto.target.tagName).toBe('FORM');
            expect(dto.target.search.value).toBe('공지');
        });

        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('select: selectedOptions[0].dataset.url 을 읽을 수 있다 (메뉴 편집)', () => {
            const select = document.createElement('select');
            const option = document.createElement('option');
            option.value = 'k';
            option.dataset.url = '/admin/x';
            select.appendChild(option);
            option.selected = true;
            const event = new Event('change');
            select.dispatchEvent(event);

            const dto = toExpressionEvent(event) as any;

            expect(dto.target.value).toBe('k');
            expect(dto.target.selectedOptions[0].dataset.url).toBe('/admin/x');
        });

        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('scroll 이벤트만 스크롤 필드를 싣는다 (기존 extractEventData 계약)', () => {
            const div = document.createElement('div');
            const scroll = new Event('scroll');
            div.dispatchEvent(scroll);
            const click = new Event('click');
            div.dispatchEvent(click);

            expect(toExpressionEvent(scroll)).toMatchObject({ target: { scrollTop: 0, scrollHeight: 0, clientHeight: 0 } });
            expect((toExpressionEvent(click) as any).target.scrollTop).toBeUndefined();
        });

        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('keyboard 이벤트 스칼라(key·ctrlKey) 를 싣는다', () => {
            const event = new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true });
            document.body.dispatchEvent(event);

            expect(toExpressionEvent(event)).toMatchObject({ type: 'keydown', key: 'Enter', ctrlKey: true });
        });

        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('합성 컴포넌트 데이터({ target: { name, value } }·주소검색 결과)는 그대로 복사된다', () => {
            expect(toExpressionEvent({ target: { name: 'title', value: { ko: '가', en: 'a' } } })).toEqual({
                target: { name: 'title', value: { ko: '가', en: 'a' } },
            });
            expect(toExpressionEvent({ zipcode: '13479', address: '성남시' })).toEqual({
                zipcode: '13479',
                address: '성남시',
            });
            expect(toExpressionEvent({ target: { checked: true } })).toEqual({ target: { checked: true } });
        });

        /**
         * @effects event_dto_preserves_bound_fields
         */
        it('file input: files 는 File 배열로 싣는다 (업로드 값 유지)', () => {
            const input = document.createElement('input');
            input.type = 'file';
            const file = new File(['x'], 'a.png', { type: 'image/png' });
            Object.defineProperty(input, 'files', { value: [file] });
            const event = new Event('change');
            input.dispatchEvent(event);

            const dto = toExpressionEvent(event) as any;
            expect(dto.target.files).toEqual([file]);
            expect(dto.target.files[0]).toBe(file);
        });
    });

    describe('toExpressionArgs / toExpressionData', () => {
        /**
         * @effects args_dto_strips_host_objects
         */
        it('인자 속 DOM 이벤트는 DTO 로, 호스트 객체는 null 로, 행 데이터는 그대로 복사한다', () => {
            const button = document.createElement('button');
            const event = new MouseEvent('click');
            button.dispatchEvent(event);
            const row = { id: 3, member: { uuid: 'u' }, tags: ['a'] };

            const args = toExpressionArgs([event, row, window, document.body, localStorage]) as any[];

            expect(args[0]).toMatchObject({ type: 'click', target: { tagName: 'BUTTON' } });
            expect(args[1]).toEqual(row);
            expect(args[1]).not.toBe(row);
            expect(args[2]).toBeNull();
            expect(args[3]).toMatchObject({ tagName: 'BODY' });
            expect(args[3].ownerDocument).toBeUndefined();
            expect(args[4]).toBeNull();
        });

        /**
         * @effects args_dto_strips_host_objects
         */
        it('순환 참조·공유 참조를 보존하고 __proto__ 키로 프로토타입을 바꾸지 않는다', () => {
            const shared = { v: 1 };
            const cyclic: any = { a: shared, b: shared };
            cyclic.self = cyclic;
            const polluted = JSON.parse('{"__proto__": {"polluted": true}, "ok": 1}');

            const copy = toExpressionData(cyclic) as any;
            expect(copy.a).toBe(copy.b);
            expect(copy.self).toBe(copy);

            const safe = toExpressionData(polluted) as any;
            expect(safe.ok).toBe(1);
            expect(safe.polluted).toBeUndefined();
            expect(({} as any).polluted).toBeUndefined();
        });

        /**
         * @effects args_dto_strips_host_objects
         */
        it('허용목록 밖 네이티브 함수는 null, JS 함수는 유지된다', () => {
            const jsFn = () => 1;
            const copy = toExpressionData({ reflect: Object.getOwnPropertyDescriptor, call: Function.prototype.call, cb: jsFn }) as any;

            expect(copy.reflect).toBeNull();
            expect(copy.call).toBeNull();
            expect(copy.cb).toBe(jsFn);
        });
    });

    describe('toExpressionError', () => {
        /**
         * @effects error_dto_excludes_stack_and_hosts
         */
        it('API 오류: message·name·status·response·errors·code 를 싣고 stack 은 싣지 않는다', () => {
            const err: any = new Error('검증 실패');
            err.status = 422;
            err.code = 'E_VALIDATION';
            err.response = { message: '검증 실패', errors: { email: ['필수'] } };
            err.errors = { email: ['필수'] };

            const dto = toExpressionError(err) as any;

            expect(dto).toMatchObject({
                message: '검증 실패',
                name: 'Error',
                status: 422,
                code: 'E_VALIDATION',
                errors: { email: ['필수'] },
                response: { message: '검증 실패', errors: { email: ['필수'] } },
            });
            expect(dto.stack).toBeUndefined();
            expect(dto instanceof Error).toBe(false);
        });

        /**
         * @effects error_dto_excludes_stack_and_hosts
         */
        it('오류에 매달린 호스트 객체는 null, plain 오류의 message 부재는 그대로 둔다', () => {
            const err: any = new Error('x');
            err.request = new XMLHttpRequest();
            const dto = toExpressionError(err) as any;
            expect(dto.request).toBeNull();

            expect(toExpressionError({ errors: { a: 1 } })).toEqual({ errors: { a: 1 } });
            expect(toExpressionError('plain')).toBe('plain');
        });
    });
});
