<?php

namespace App\Exceptions;

/**
 * 기능이 쓰는 「선택」 PHP 확장이 서버에 없음.
 *
 * 선택 확장(zip 이 없는 설정 백업 등)을 가드 없이 부르면 `Class "ZipArchive" not found` 같은 치명 오류가
 * 500 으로 새고 원인을 알 수 없었다(#151). 기능 진입에서 확장을 확인해 이 예외를 던지고, 호출부는
 * 안내 메시지와 422 로 응답한다.
 *
 * @since 7.1.4
 */
class PhpExtensionUnavailableException extends \RuntimeException
{
    /**
     * @param  string  $extension  없는 PHP 확장 이름
     * @param  string  $messageKey  안내 메시지 번역 키 (응답에 키를 넘긴다)
     */
    public function __construct(
        public readonly string $extension,
        public readonly string $messageKey,
    ) {
        parent::__construct(__($messageKey, ['extension' => $extension]));
    }
}
