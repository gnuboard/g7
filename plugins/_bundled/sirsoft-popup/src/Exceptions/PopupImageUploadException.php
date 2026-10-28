<?php

namespace Plugins\Sirsoft\Popup\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 올린 이미지를 저장하지 못했을 때의 예외 — 파일 쓰기가 실패했거나, 파일을 쓴 뒤 행 저장이 실패했을 때.
 *
 * 어느 쪽이든 이미지는 남지 않는다(행을 만들지 않거나, 쓴 파일을 지운다). 관리자에게는 다시 올리라고 안내한다.
 */
class PopupImageUploadException extends RuntimeException
{
    /**
     * 다국어 메시지 키
     */
    private const MESSAGE_KEY = 'sirsoft-popup::messages.image.upload_failed';

    /**
     * PopupImageUploadException 생성자
     *
     * @param  Throwable|null  $previous  원인 예외 (행 저장 실패)
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(__(self::MESSAGE_KEY), 0, $previous);
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 응답을 만들 때 이미 번역된 `getMessage()` 대신 이 키를 넘긴다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}
