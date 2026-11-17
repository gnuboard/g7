<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 실행 역할과 맞지 않는 레이아웃 테이블에 쓰려 할 때 던집니다 — stage 는 운영 테이블, live 는 스테이지 테이블에 쓰지 않는다.
 *
 * @since 7.2.0
 */
class StageLayoutWriteException extends RuntimeException
{
    /** 메시지 키 */
    public const MESSAGE_KEY = 'exceptions.stage_layout_write';

    /**
     * @param  string  $table  쓰려던 테이블
     */
    public function __construct(public readonly string $table)
    {
        parent::__construct((string) __(self::MESSAGE_KEY, $this->getMessageParams()));
    }

    /**
     * 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array{table: string} 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['table' => $this->table];
    }
}
