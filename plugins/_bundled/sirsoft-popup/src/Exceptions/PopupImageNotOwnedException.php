<?php

namespace Plugins\Sirsoft\Popup\Exceptions;

use RuntimeException;

/**
 * 다른 관리자가 올린 임시 이미지를 임시 이미지 삭제로 지우려 할 때의 예외.
 *
 * 임시 이미지는 그 관리자가 아직 저장하지 않은 등록·수정 화면의 것이다 — 지우면 그 화면의 저장이 이미지 없이 실패한다.
 * 끝내 연결되지 않은 임시 이미지는 정리 스케줄이 지운다.
 */
class PopupImageNotOwnedException extends RuntimeException
{
    /**
     * 다국어 메시지 키
     */
    private const MESSAGE_KEY = 'sirsoft-popup::messages.image.not_owned';

    /**
     * PopupImageNotOwnedException 생성자
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
