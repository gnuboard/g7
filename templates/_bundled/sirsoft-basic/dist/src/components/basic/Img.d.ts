import { default as React } from 'react';
export interface ImgProps extends React.ImgHTMLAttributes<HTMLImageElement> {
    /**
     * 썸네일 규격 이름(`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격). 지정하면 서빙 경로 이미지에 `size` 를 붙이고
     * `srcSet` 을 따로 주지 않았으면 1x·2x srcset 을 만든다. 외부·정적·`data:` 주소는 그대로다.
     * DOM 속성이 아니므로 `<img>` 로 내보내지 않는다.
     */
    thumbPreset?: string;
}
/**
 * 기본 이미지 컴포넌트
 *
 * `src` 가 사이트 내부 경로면 마운트 접두사를 붙여 내보낸다.
 *
 * `<img src>` 는 **브라우저가 라우터 없이 직접 받아오는 주소**라 접두사를 붙일 주체가 이
 * 컴포넌트뿐이다 — `A` 의 `href` 와 같은 축이다. 종전에는 `src` 를 그대로 흘려 하위
 * 폴더 배치(`https://host/g7/`)에서 레이아웃이 리터럴로 적은 `/images/…` 가 사이트
 * 밖을 가리켰다. 증상은 오류가 아니라 깨진 이미지뿐이다 (15차 G3 라운드 W4-2).
 *
 * `thumbPreset` 이 있으면 썸네일 주소와 srcset 을 만든다(판정은 코어 `G7Core.thumbnail`).
 *
 * `sitePath()` 는 멱등이며, 루트 상대(`/…`)가 아닌 값(절대 URL · `//` 프로토콜 상대 ·
 * `data:` · 상대 경로)은 그대로 돌려준다. 서버가 이미 접두사를 실어 준 값에 다시 붙지 않는다.
 */
export declare const Img: React.FC<ImgProps>;
