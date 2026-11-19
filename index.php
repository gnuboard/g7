<?php

/**
 * G7 루트 진입점 shim (배포 레이아웃 ② — 문서 루트 = 프로젝트 루트)
 *
 * 문서 루트를 바꿀 수 없는 호스팅에서 릴리즈 ZIP 을 문서 루트에 그대로 풀면
 * 이 파일과 루트 `.htaccess` 가 진입을 담당한다. 실제 프런트 컨트롤러는
 * `public/index.php` 하나이며(설치 게이트 · 확장 오토로드 블록 포함 — 그 블록을
 * 여기 복제하지 않는 것이 승계 규약이다), 이 파일은 위임만 한다.
 *
 * 위임 대상이 없으면(전송 중단·부분 압축 해제) PHP 의 fatal 안내가 프로젝트 루트의
 * 절대 경로를 그대로 화면에 싣는다 — 경로 없이 짧게 알리고 503 으로 끝낸다. 이 자리는
 * 애플리케이션 부팅 전이라 다국어 통로가 없다.
 *
 * 표준 배치(문서 루트 = public/)에서는 이 파일이 웹에 노출되지 않아 무해하다.
 * 이 파일은 코어 업데이트가 다른 코어 파일과 함께 갱신한다 — 내용을 수정하지 말 것.
 */
if (! is_file(__DIR__.'/public/index.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "public/index.php is missing - the deployment is incomplete. Re-upload the release package.\n";
    echo "public/index.php 가 없습니다 - 배포가 불완전합니다. 릴리즈 패키지를 다시 올려 주세요.\n";
    exit;
}

require __DIR__.'/public/index.php';
