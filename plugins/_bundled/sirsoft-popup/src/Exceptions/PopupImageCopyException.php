<?php

namespace Plugins\Sirsoft\Popup\Exceptions;

use RuntimeException;

/**
 * 팝업 이미지 파일 복제 실패 예외 — 일괄 복사가 원본 파일을 읽지 못했거나 새 파일을 쓰지 못했을 때.
 *
 * 일괄 복사는 이 예외를 포함한 모든 실패를 건별로 잡아 그 팝업만 되돌리고 다음 건을 계속한다.
 */
class PopupImageCopyException extends RuntimeException
{
    /**
     * PopupImageCopyException 생성자
     *
     * @param  string  $messageKey  다국어 메시지 키
     * @param  array<string, mixed>  $messageParams  메시지 치환 파라미터
     */
    private function __construct(
        private string $messageKey,
        private array $messageParams,
    ) {
        parent::__construct(__($messageKey, $messageParams));
    }

    /**
     * 원본 파일이 저장소에 없을 때의 예외를 만듭니다.
     *
     * @param  int  $imageId  원본 이미지 ID
     * @return self 예외
     */
    public static function sourceMissing(int $imageId): self
    {
        return new self('sirsoft-popup::exceptions.image_copy.source_missing', ['id' => $imageId]);
    }

    /**
     * 복제 파일을 쓰지 못했을 때의 예외를 만듭니다.
     *
     * @param  int  $imageId  원본 이미지 ID
     * @return self 예외
     */
    public static function writeFailed(int $imageId): self
    {
        return new self('sirsoft-popup::exceptions.image_copy.write_failed', ['id' => $imageId]);
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 응답·로그를 만들 때 이미 번역된 `getMessage()` 대신 이 키를 넘긴다 — 번역문을 키로 해석하면 원문이 그대로 나간다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->messageParams;
    }
}
