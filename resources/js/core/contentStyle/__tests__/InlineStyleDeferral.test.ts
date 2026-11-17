/**
 * 본문 서식 지연 장치 — style 속성을 data-g7-style 로 옮겼다가 CSSOM 대입으로 입힌다 (#163, engine-v1.91.0)
 *
 * [case:network-strict-style-csp-inline-style-blocked]
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */
import { describe, expect, it } from 'vitest';
// 코어에는 DOMPurify 의존성이 없다 — 템플릿이 실제로 쓰는 판으로 시험한다
// @ts-ignore - 템플릿 의존성 경로
import DOMPurify from '../../../../../templates/_bundled/sirsoft-basic/node_modules/dompurify';
import { apply, ATTR, defer, installHook, sanitize } from '../InlineStyleDeferral';

/**
 * 전용 인스턴스를 만듭니다.
 *
 * @returns DOMPurify 인스턴스
 */
const instance = (): any => DOMPurify(window);

describe('InlineStyleDeferral', () => {
    describe('defer — 정화 전에 style 속성 이름을 바꾼다(흐름 B)', () => {
        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_renamed_before_parse
         */
        it('태그 안의 style 만 1회용 이름으로 바꾸고 글자·다른 속성은 그대로 둔다', () => {
            const { html, token } = defer('<p style="color:red" title="a > b">style="x" 는 글자</p><span STYLE=\'font-size:20px\'>b</span><div data-style="k" class="style">c</div>');

            expect(token).toMatch(/^[a-z0-9]+$/);
            expect(html).toContain(`<p ${ATTR}-${token}="color:red" title="a > b">style="x" 는 글자</p>`);
            expect(html).toContain(`<span ${ATTR}-${token}='font-size:20px'>`);
            expect(html).toContain('<div data-style="k" class="style">');
            expect(html).not.toMatch(/<[^>]*\sstyle\s*=/i);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_renamed_before_parse
         */
        it('닫히지 않은 태그가 이어지는 긴 입력도 입력 길이에 비례하는 시간 안에 끝난다', () => {
            // 종전 정규식 판은 이런 입력에서 역추적이 길이의 제곱으로 늘었다(40KB 1.8초, 80KB 3.2초 실측)
            for (const body of ['style ' + '<a'.padEnd(200_000, 'a'), 'style ' + '<a '.repeat(70_000), 'style ' + '<a "'.repeat(50_000)]) {
                const started = performance.now();
                const out = defer(body).html;
                expect(performance.now() - started).toBeLessThan(500);
                expect(out.length).toBe(body.length);
            }
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_renamed_before_parse
         */
        it('구분자·값 모양이 달라도 속성 이름만 바꾼다', () => {
            const { html, token } = defer('<p class="a"style="x">1</p><b title=style lang= style>2</b><i\nSTYLE = \'y\'/>3<a href="?style=1" data-x=">" style=z>4</a>');
            const name = `${ATTR}-${token}`;

            expect(html).toContain(`<p class="a"${name}="x">`);
            // 값 자리의 style(따옴표 없는 값, = 뒤 공백 다음 값)은 이름이 아니다
            expect(html).toContain('<b title=style lang= style>');
            expect(html).toContain(`<i\n${name} = 'y'/>`);
            expect(html).toContain(`<a href="?style=1" data-x=">" ${name}=z>`);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_renamed_before_parse
         */
        it('style 이 없으면 원문 그대로다', () => {
            expect(defer('<p class="a">x</p>').html).toBe('<p class="a">x</p>');
            expect(defer('').html).toBe('');
        });
    });

    describe('sanitize + apply', () => {
        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_moved_to_data_attr, inline_style_applied_via_cssom
         */
        it('style 을 data-g7-style 로 옮기고, apply 가 cssText 로 입힌 뒤 속성을 지운다', () => {
            const out = sanitize(instance(), '<p style="color: rgb(255, 0, 0); text-align: center">a</p><span style="background-color: yellow">b</span>');

            expect(out).not.toMatch(/\sstyle=/);
            expect(out).toContain(`${ATTR}="color: rgb(255, 0, 0); text-align: center"`);
            // 1회용 이름이 남지 않는다
            expect(out).not.toMatch(new RegExp(`${ATTR}-[a-z0-9]`));

            const root = document.createElement('div');
            root.innerHTML = out;
            expect(apply(root)).toBe(2);
            expect(root.querySelectorAll(`[${ATTR}]`).length).toBe(0);
            expect((root.querySelector('p') as HTMLElement).style.color).toBe('rgb(255, 0, 0)');
            expect((root.querySelector('p') as HTMLElement).style.textAlign).toBe('center');
            expect((root.querySelector('span') as HTMLElement).style.backgroundColor).toBe('yellow');
            // 두 번 불러도 다시 적용하지 않는다
            expect(apply(root)).toBe(0);
            expect(apply(null)).toBe(0);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_source_data_attr_dropped
         */
        it('원문에 이미 적혀 있던 data-g7-style 은 버린다', () => {
            const out = sanitize(instance(), `<p ${ATTR}="position:fixed;inset:0">a</p><b ${ATTR}="color:red" style="color:blue">b</b>`);

            expect(out).toContain('<p>a</p>');
            expect(out).toContain(`<b ${ATTR}="color:blue">b</b>`);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_respects_forbid_config
         */
        it('정화 설정이 style 을 금지하면 올리지 않는다', () => {
            const forbidden = sanitize(instance(), '<p style="color:red">a</p>', { FORBID_ATTR: ['style'] });
            expect(forbidden).toBe('<p>a</p>');

            const notAllowed = sanitize(instance(), '<p style="color:red" class="k">a</p>', { ALLOWED_ATTR: ['class'] });
            expect(notAllowed).toBe('<p class="k">a</p>');

            const allowed = sanitize(instance(), '<p style="color:red" class="k">a</p>', { ALLOWED_ATTR: ['class', 'style'] });
            expect(allowed).toContain(`${ATTR}="color:red"`);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_moved_to_data_attr
         */
        it('data 속성을 끈 설정(ALLOW_DATA_ATTR=false)에서도 서식은 옮겨진다', () => {
            const out = sanitize(instance(), '<p style="color:red" data-x="1">a</p>', { ALLOW_DATA_ATTR: false });

            expect(out).toBe(`<p ${ATTR}="color:red">a</p>`);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_hook_does_not_leak
         */
        it('훅을 걸었다 푼다 — 같은 인스턴스의 다음 일반 정화에 새지 않는다', () => {
            const purify = instance();
            sanitize(purify, '<p style="color:red">a</p>');

            expect(purify.sanitize('<p style="color:red">a</p>')).toBe('<p style="color:red">a</p>');
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_sanitizer_still_strips_executables
         */
        it('DOMPurify 가 속성값에서 걸러 내는 모양의 서식 값은 버린다', () => {
            // 지연한 값은 DOMPurify 의 속성값 검사를 거치지 않으므로 같은 기준을 직접 적용한다
            const out = sanitize(instance(), '<p style="color:red;--><img src=x>">a</p><b style="x:</style><i>">b</b><u style="color:blue">c</u>');

            expect(out).not.toContain('<img');
            expect(out).not.toContain('</style');
            expect(out).toContain('<p>a</p>');
            expect(out).toContain(`<u ${ATTR}="color:blue">c</u>`);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_sanitizer_still_strips_executables
         */
        it('이벤트 속성·스크립트는 종전대로 지워진다', () => {
            const out = sanitize(instance(), '<p style="color:red" onclick="x()">a</p><script>y()</script>');

            expect(out).toBe(`<p ${ATTR}="color:red">a</p>`);
        });
    });

    describe('installHook — 이름을 바꾸지 않은 경우(토큰 없음)에도 남은 style 을 옮긴다', () => {
        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_moved_to_data_attr
         */
        it('정화 뒤 남은 style 속성을 data-g7-style 로 옮긴다', () => {
            const purify = instance();
            installHook(purify, {});

            expect(purify.sanitize('<p style="color:red">a</p>')).toBe(`<p ${ATTR}="color:red">a</p>`);
        });

        /**
         * @scenario case=inline_style_deferral
         * @effects inline_style_respects_forbid_config
         */
        it('요소별 허용 판정(allowStyle)이 false 면 버린다', () => {
            const purify = instance();
            const deferred = defer('<p style="color:red">a</p><b style="color:blue">b</b>');
            installHook(purify, {}, deferred.token, { allowStyle: (node) => node.nodeName !== 'B' });

            expect(purify.sanitize(deferred.html)).toBe(`<p ${ATTR}="color:red">a</p><b>b</b>`);
        });
    });
});
