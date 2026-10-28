<?php

namespace Plugins\Sirsoft\Popup\Exceptions;

use RuntimeException;

/**
 * 팝업에 붙은 이미지를 임시 이미지 삭제로 지우려 할 때의 예외.
 *
 * 붙은 이미지는 팝업 등록·수정 저장만 바꾼다 — 여기서 지우면 저장한 팝업의 이미지가 사용자 화면에서 깨진다.
 */
class PopupImageAttachedException extends RuntimeException
{
    /**
     * 다국어 메시지 키
     */
    private const MESSAGE_KEY = 'sirsoft-popup::messages.image.attached_not_deletable';

    /**
     * PopupImageAttachedException 생성자
     *
     * @param  int  $imageId  대상 이미지 ID
     */
    public function __construct(public readonly int $imageId)
    {
        parent::__construct(__(self::MESSAGE_KEY));
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
