{{--
    코어 엔진 + 템플릿 컴포넌트 번들 부트스트랩 (재시도 + 폴백 UI)

    두 번들은 `<script src>` 로 로드되므로 엔진의 fetch 재시도 래퍼가 닿지 않는다.
    코어 번들이 없으면 재시도할 JS 자체가 없다(닭-달걀). 따라서 재시도를 의존성 없는
    손작성 ES5 정적 파일 `public/assets/core/js/g7-shell-boot.js` 로 구현한다. 종전에는
    인라인 `<script>`·`onload`/`onerror` 속성이었으나 strict CSP(`script-src 'self'`)에서
    실행되지 않아 전 화면이 백지가 됐다(#158) — 이 파샬에 인라인 실행 코드를 다시 넣지 않는다.
    서버 값은 `#g7-boot-data` JSON 블록으로, 번들 태그의 load/error 는 `data-g7-boot-bundle`
    표식 + 셸의 document 캡처 리스너로 받는다.

    로드 실패 시 종전에는 콘솔 에러만 남고 화면은 백지였다. 3회 시도 후에도 실패하면
    사용자에게 보이는 정적 폴백(새로고침 버튼)을 심는다.

    성능 계약 — `<script src>` 는 **정적 태그로 유지한다**:
    브라우저의 프리로드 스캐너는 HTML 파싱 중에 정적 `<script src>` 를 미리 발견해
    번들을 선행 로드한다. 이를 `document.createElement('script')` 로 바꾸면 스캐너가
    보지 못해 **셸 JS 가 실행된 뒤에야** 요청이 시작되고, 그만큼 렌더가 늦어진다
    (실측: HTML 응답 완료 314ms → 코어 번들 요청 시작 568ms, 약 250ms 손실).
    따라서 정상 경로는 정적 태그 그대로 두고, **실패했을 때만** error 캡처에서 동적
    재시도를 건다. 재시도는 예외 경로이므로 스캐너 이점을 잃어도 무방하다.

    'before-template' 외부 스크립트는 코어 뒤 · 템플릿 컴포넌트 앞이라는 순서 계약을
    갖는다. 정적 태그를 유지하므로 그 사이에 그대로 @include 하면 순서가 지켜진다.

    @param $templateType   'user' | 'admin'
    @param $coreEngineSrc  코어 엔진 번들 URL
    @param $componentsSrc  템플릿 컴포넌트 번들 URL
    @param $initConfig     initTemplateApp 에 넘길 설정 (JSON 직렬화 가능)
--}}
@php
    // 부트스트랩 셸(g7-shell-boot.js)이 읽는 서버 값 — 비실행 JSON 데이터 블록으로만 넘긴다(#158).
    $g7BootData = [
        'label' => $templateType === 'admin' ? '[Admin]' : '[User]',
        'coreEngineSrc' => $coreEngineSrc,
        'componentsSrc' => $componentsSrc,
        'i18n' => [
            'title' => __('errors.bootstrap.title'),
            'message' => __('errors.bootstrap.message'),
            'reload' => __('errors.bootstrap.reload'),
            'incompatible_title' => __('errors.bootstrap.incompatible_title'),
            'incompatible_message' => __('errors.bootstrap.incompatible_message'),
            'blocked_title' => __('errors.bootstrap.blocked_title'),
            'blocked_message' => __('errors.bootstrap.blocked_message'),
        ],
        'initConfig' => (object) $initConfig,
    ];
@endphp
<script type="application/json" id="g7-boot-data">@json($g7BootData)</script>

{{-- 부트스트랩 셸 — 코어 엔진 태그 바로 앞(번들 태그의 load/error 를 캡처 리스너로 받는다) --}}
<script src="{{ asset('assets/core/js/g7-shell-boot.js') }}?v={{ file_exists(public_path('assets/core/js/g7-shell-boot.js')) ? filemtime(public_path('assets/core/js/g7-shell-boot.js')) : 0 }}"></script>

{{-- 'before-core' 외부 스크립트 — G7Config 대입(셸) 뒤 · 코어 엔진 앞. 종전 인라인 대입 뒤에
     오던 순서를 그대로 지킨다(이 위치의 외부 스크립트가 window.G7Config 를 읽을 수 있다). --}}
@include('partials.template-externals-scripts', ['position' => 'before-core'])

{{-- 코어 렌더링 엔진 — 정적 태그 유지 (프리로드 스캐너가 선행 로드) --}}
<script src="{{ $coreEngineSrc }}" data-g7-boot-bundle="core"></script>

@include('partials.template-externals-scripts', ['position' => 'before-template'])

{{-- 템플릿 컴포넌트 번들 (IIFE) — 정적 태그 유지 --}}
<script src="{{ $componentsSrc }}" data-g7-boot-bundle="components"></script>
