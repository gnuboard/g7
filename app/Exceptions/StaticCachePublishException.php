<?php

namespace App\Exceptions;

/**
 * 부트스트랩 리소스 정적 게시(bake) 실패 예외.
 *
 * 사용자 대면 예외가 아니다 — `ExtensionStaticCacheService::publishVersion()` 의
 * 자체 catch 가 즉시 삼켜 Log::warning 진단으로만 남기고, 사이트는 API 폴백으로
 * 정상 동작한다. 따라서 메시지는 다국어 키가 아니라 운영자 로그용 진단 문자열이다.
 */
class StaticCachePublishException extends \RuntimeException {}
