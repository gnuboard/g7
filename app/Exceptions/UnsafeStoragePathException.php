<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * 스토리지 드라이버가 안전하지 않은 경로를 거부할 때 던지는 예외
 *
 * 경로 순회(`..`)·절대경로·인코딩 변형 같은 조각, 기준 디렉터리를 벗어나는 결합 결과,
 * 디스크·확장·카테고리 루트를 겨냥한 파괴 작업(deleteDirectory/deleteAll)이 대상이다.
 * 정상 흐름에서는 발생하지 않으므로 호출부는 잡지 않는다(서버 결함으로 5xx 처리).
 */
class UnsafeStoragePathException extends InvalidArgumentException
{
    /**
     * UnsafeStoragePathException 생성자
     *
     * @param  string  $reason  거부 사유 코드 (null_byte, backslash, absolute, encoded,
     *                          empty_segment, dot_segment, empty_category, outside_base, destructive_on_root)
     * @param  string  $messageKey  번역 키 (exceptions.storage.*)
     */
    public function __construct(
        private string $reason,
        private string $messageKey = 'exceptions.storage.unsafe_path'
    ) {
        parent::__construct(__($messageKey));
    }

    /**
     * 거부 사유 코드를 반환합니다.
     *
     * @return string 거부 사유 코드
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 메시지 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터 (없음)
     */
    public function getMessageParams(): array
    {
        return [];
    }
}
