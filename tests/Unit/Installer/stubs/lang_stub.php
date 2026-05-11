<?php

/**
 * 인스톨러 단위 테스트용 lang() 글로벌 스텁
 *
 * public/install/includes/functions.php 의 lang() 헬퍼 대체.
 * 메시지 키를 그대로 반환해 단위 테스트에서 검증 가능한 형태로 노출.
 */
if (! function_exists('lang')) {
    function lang(string $key, array $params = []): string
    {
        unset($params);
        return $key;
    }
}
