{{--
    자산 URL 모드 자가 복구 — 공용 헬퍼 (이슈 #486 §5)

    실행 코드는 `public/assets/core/js/g7-shell-head.js` 로 옮겼다(#158 — strict CSP
    `script-src 'self'` 에서 인라인 `<script>` 가 실행되지 않아 전 화면이 백지가 됐다).
    그 파일은 `partials/template-externals-head` 가 externals·템플릿 CSS `<link>` 보다 먼저
    싣는다(CSS 링크의 error 를 캡처 리스너로 받기 때문). 불변식(L1·L3·L5·L7)과 정적 게시
    역변환 규칙 설명도 그 파일에 있다.

    include 지점(admin·app 블레이드)은 유지한다 — 이 위치에 인라인 스크립트를 다시 넣지 않는다.
--}}
