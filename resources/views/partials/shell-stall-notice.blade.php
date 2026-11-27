{{--
    셸 JS 미도착 안내 (공개 #158) — 스크립트 없이 CSS 만으로 지연 표시한다.

    부트스트랩이 인라인일 때는 "재시도 로직이 HTML 과 함께 도착"이 보장됐다. 정적 파일
    (`g7-shell-boot.js`)로 옮기면서 그 파일 자체를 못 받는 실패 창이 새로 생겼다 — 그 경우
    재시도·폴백 UI 를 그릴 코드가 없으므로 영구 백지가 된다. 그래서 JS 에 의존하지 않는
    안내를 둔다: 20초 뒤 CSS 애니메이션으로 보이게 하고, 셸이 도착하면 `<html>` 에 붙이는
    `g7-shell-ok` 클래스로 숨긴다. 새로고침은 `<a href="">`(현재 주소 재요청)라 스크립트가 필요 없다.

    규칙(`#g7-shell-stall …`)은 정적 파일 `g7-core.css` 에 있다. 이 뷰에 스타일 블록을 두면
    엄격한 스타일 정책에서 막혀, 숨김 규칙이 사라지고 안내가 정상 화면 아래에 늘 보인다(공개 #163).
--}}
        <div id="g7-shell-stall" role="alert">
            <strong>{{ __('errors.bootstrap.title') }}</strong>
            {{ __('errors.bootstrap.message') }}
            <a href="">{{ __('errors.bootstrap.reload') }}</a>
        </div>
