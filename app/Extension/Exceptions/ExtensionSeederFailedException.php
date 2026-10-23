<?php

namespace App\Extension\Exceptions;

/**
 * 확장 설치 중 시더가 실패했을 때 던지는 예외.
 *
 * 예전에는 시더 예외를 로그만 남기고 삼켜 설치가 「성공」으로 끝났고, 운영자는 기본 데이터가 0건인
 * 사이트를 받았다. 이제 설치를 실패로 끝내고 이번 설치가 만든 행·테이블·설정·디렉터리를 되돌린다.
 * 메시지는 설치 화면·CLI 에 그대로 보이는 실패 사유다.
 */
class ExtensionSeederFailedException extends \RuntimeException
{
    /**
     * @param  string  $seeder  실패한 시더 클래스(FQCN)
     * @param  string  $reason  원인 메시지
     * @param  \Throwable|null  $previous  원인 예외
     */
    public function __construct(
        public readonly string $seeder,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            __('extensions.errors.seeder_failed', ['seeder' => $seeder, 'message' => $reason]),
            0,
            $previous,
        );
    }
}
