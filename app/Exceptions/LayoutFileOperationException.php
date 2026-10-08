<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 레이아웃 파일 생성·삭제·이름변경 흐름에서 발생하는 운영 오류 예외.
 *
 * 다국어 키 + 파라미터를 보존해 컨트롤러가 원본 키로 응답할 수 있게 한다 — 이미 번역된
 * 문장을 응답의 메시지 **키** 자리에 넘기면 키 해석에 실패해 원문이 그대로 화면에 나간다
 * ({@see CustomAssetOperationException} 과 같은 이유·같은 형태).
 *
 * 차단 사유가 "무엇이 이 파일을 붙들고 있는가" 인 경우(부모 참조·partial 참조)에는
 * {@see $references} 에 그 목록을 실어 보낸다. 화면이 "삭제할 수 없습니다" 만 보여 주면
 * 사용자는 무엇을 정리해야 하는지 알 수 없고, 결국 DB 를 직접 여는 수밖에 없다.
 */
class LayoutFileOperationException extends RuntimeException
{
    /**
     * @param  string  $errorKey  다국어 키 (예: 'layouts.errors.delete_blocked_by_reference')
     * @param  array<string, mixed>  $params  메시지 파라미터
     * @param  array<int, array{name: string, kind: string, line?: int}>  $references  차단 근거가 된 참조 목록
     * @param  int  $status  HTTP 상태 코드 (기본 422)
     * @param  Throwable|null  $previous  원인 예외
     */
    public function __construct(
        public readonly string $errorKey,
        public readonly array $params = [],
        public readonly array $references = [],
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct(trans_count($errorKey, $params), 0, $previous);
    }
}
