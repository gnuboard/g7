<?php

/**
 * 인스톨러 단위 테스트용 lang() 글로벌 스텁
 *
 * public/install/includes/functions.php 의 lang() 헬퍼 대체.
 * 번역이 주입되지 않은 테스트에서는 메시지 키를 그대로 반환해 검증 가능한 형태로 노출한다.
 *
 * ## 왜 번역 주입을 존중하는가
 *
 * `lang()` 은 전역 함수이고 양쪽 정의 모두 `function_exists` 로 가드된다. 즉 같은
 * PHPUnit 프로세스에서 **먼저 로드된 쪽이 이기고** 그 승자가 뒤따르는 모든 테스트 클래스에
 * 적용된다. 이 스텁이 이기면, `$GLOBALS['translations']` 를 채워 실제 문구를 검증하는
 * 테스트(PrivilegedDbAccountGuardTest 등)가 키만 돌려받아 실패한다 — 클래스 단독 실행은
 * 통과하고 스위트 실행만 실패하는 전형적인 격리 결함이다.
 *
 * 그래서 스텁은 번역이 주입돼 있으면 실제 구현과 동일하게 동작하고, 없을 때만 키를
 * 반환한다. 두 사용처의 의도를 모두 만족시키므로 로드 순서가 결과를 바꾸지 않는다.
 */
if (! function_exists('lang')) {
    /**
     * 번역 키를 해석한다 (테스트 스텁).
     *
     * @param  string  $key  번역 키
     * @param  array  $params  치환할 플레이스홀더 (`:name` 형식)
     * @return string 번역 문자열 또는 키
     */
    function lang(string $key, array $params = []): string
    {
        global $translations;

        $message = (isset($translations) && is_array($translations))
            ? ($translations[$key] ?? $key)
            : $key;

        foreach ($params as $placeholder => $value) {
            $message = str_replace(":{$placeholder}", (string) $value, $message);
        }

        return $message;
    }
}
