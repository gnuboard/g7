/**
 * 편집 화면의 서식을 CSSOM 으로 입힙니다 — 엄격한 스타일 정책에서도 정렬·색·표·이미지 크기가 보이게 한다
 *
 * 운영자가 `style-src 'self'; style-src-attr 'none'` 을 걸면 `setAttribute('style', …)` 가 막힌다. CKEditor 는
 * 편집 화면 요소의 서식을 그 방식으로 쓰므로, 정책을 건 사이트에서는 가운데 정렬·글자색·표 테두리·이미지 크기가
 * 편집 화면에 보이지 않았다(저장되는 내용은 맞다). `el.style.cssText = …` 는 정책이 막지 않는다.
 *
 * CKEditor 는 편집 화면 DOM 의 속성을 변환기의 `setDomElementAttribute` 한 곳으로 쓴다. 편집 화면용 변환기
 * (`editor.editing.view.domConverter`)의 그 메서드만 인스턴스 단위로 덮어쓴다 — 저장용 변환기는 별개 인스턴스이고
 * 프로토타입도 건드리지 않으므로 저장되는 HTML 은 한 글자도 달라지지 않는다.
 *
 * 동봉 판(43.3.1)의 내부 메서드에 기대는 코드다. 판을 올릴 때 이 메서드의 이름·인자·안전 판정이 그대로인지 본다.
 */

/** CKEditor 가 안전하지 않다고 본 속성을 돌려 쓸 때 붙이는 접두어 */
const UNSAFE_ATTRIBUTE_PREFIX = 'data-ck-unsafe-attribute-';

/**
 * 편집 화면 변환기의 `style` 쓰기를 CSSOM 대입으로 바꾸는 CKEditor 함수 플러그인입니다.
 *
 * 변환기나 메서드가 없으면(다른 CKEditor 판) 아무것도 하지 않는다. `style` 이 아닌 속성과, CKEditor 가
 * 안전하지 않다고 본 `style` 값은 원본에 그대로 넘긴다.
 *
 * @param editor 만들고 있는 편집기
 */
export function EditingViewCssomStyle(editor: any): void {
    const converter = editor?.editing?.view?.domConverter;
    if (!converter || typeof converter.setDomElementAttribute !== 'function') {
        return;
    }

    const original = converter.setDomElementAttribute.bind(converter);

    converter.setDomElementAttribute = (domElement: HTMLElement, key: string, value: string, relatedViewElement?: any): void => {
        if (key !== 'style' || !shouldRenderStyle(converter, domElement, value, relatedViewElement)) {
            original(domElement, key, value, relatedViewElement);

            return;
        }

        domElement.removeAttribute(`${UNSAFE_ATTRIBUTE_PREFIX}style`);
        if (value === '' || value === null || value === undefined) {
            domElement.removeAttribute('style');

            return;
        }

        domElement.style.cssText = String(value);
    };
}

/**
 * CKEditor 원본과 같은 기준으로 `style` 을 그려도 되는지 판정합니다.
 *
 * 판정 함수가 없으면 원본에 맡긴다(거짓).
 *
 * @param converter 편집 화면 변환기
 * @param domElement 대상 요소
 * @param value style 값
 * @param relatedViewElement 대응하는 뷰 요소 (없을 수 있다)
 * @return 그려도 되면 true
 */
function shouldRenderStyle(converter: any, domElement: HTMLElement, value: string, relatedViewElement?: any): boolean {
    if (!domElement?.style || typeof converter.shouldRenderAttribute !== 'function') {
        return false;
    }

    if (converter.shouldRenderAttribute('style', value, String(domElement.tagName ?? '').toLowerCase())) {
        return true;
    }

    return relatedViewElement?.shouldRenderUnsafeAttribute?.('style') === true;
}
