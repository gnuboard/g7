{{--
    코어 정적 스타일시트 링크 (공개 #163).

    엄격한 스타일 정책(`style-src 'self'; style-src-attr 'none'`)에서는 뷰 안의 `<style>` 블록이
    막히므로, 코어가 그리는 화면의 스타일은 `public/assets/core/css/` 의 정적 파일로 싣는다.
    빌드 없이 git 으로 추적하는 파일이고 코어 업데이트가 `public` 을 통째 복사하므로 자동 배포된다.

    @param $sheet 'core'(사용자·관리자 셸 — 템플릿 CSS 와 함께 실린다, 전역 선택자 없음)
                | 'standalone'(오류·점검·DB 계정 오류·템플릿 없음 화면 — 전역 리셋 포함)
                | 'layers'(사용자 셸 — 확장 CSS 의미 칸 `@layer g7-extension;` 순서 선언, 템플릿 CSS 링크 **뒤**)
                | 'layers-admin'(관리자 셸 — `@layer g7-preview, g7-extension;` 순서 선언, 템플릿 CSS 링크 **뒤**)
                  층 순서는 그 층 이름이 문서에 처음 나온 자리로 정해지므로, 순서 선언만 담은 파일을 템플릿 CSS
                  `<link>` 뒤에 두면 종전 인라인 `<style>` 선언과 같은 자리를 차지한다(공개 #163).
--}}
@php
    $g7CoreSheet = in_array($sheet ?? 'core', ['standalone', 'layers', 'layers-admin'], true) ? $sheet : 'core';
    $g7CoreSheetFile = 'assets/core/css/g7-'.$g7CoreSheet.'.css';
    $g7CoreSheetPath = public_path($g7CoreSheetFile);
@endphp
        <link rel="stylesheet" href="{{ asset($g7CoreSheetFile) }}?v={{ file_exists($g7CoreSheetPath) ? filemtime($g7CoreSheetPath) : 0 }}" data-g7-core-css="{{ $g7CoreSheet }}">
