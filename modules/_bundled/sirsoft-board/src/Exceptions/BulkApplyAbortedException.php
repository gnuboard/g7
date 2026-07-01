<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 일괄 적용 중단 예외 클래스
 *
 * 게시판 환경설정 일괄 적용 중 한 지점이라도 실패하면 발생합니다.
 * 이 예외가 던져지면 전체 변경이 롤백(원자적 처리)되며,
 * 중단 지점 정보(실패 게시판·순번·총 대상 수)를 담아 사용자 안내와
 * 활동 이력 기록에 활용합니다.
 *
 * - forBoard(): 권한 적용 루프에서 특정 게시판 실패 (board 정보 포함)
 * - forColumns(): 컬럼 일괄 업데이트 실패 (단일 쿼리라 게시판 특정 불가, board=null)
 */
class BulkApplyAbortedException extends Exception
{
    /**
     * 일괄 적용 중단 예외 생성자
     *
     * 메시지는 사용자에게 직접 노출되지 않는다 (화면 안내는 Controller 가 별도
     * 다국어 키로 처리, 본 예외는 Log::warning·활동이력 추적에만 사용). 따라서
     * 추적 식별자만 담고, 실패 게시판/순번/총수 등 상세는 프로퍼티로 노출한다.
     *
     * @param  Board|null  $board  실패한 게시판 (컬럼 업데이트 실패 시 null)
     * @param  int|null  $failedAt  실패 순번 (1-base, 컬럼 업데이트 실패 시 null)
     * @param  int  $total  일괄 적용 대상 게시판 총 수
     * @param  \Throwable|null  $previous  원인 예외
     */
    public function __construct(
        public readonly ?Board $board,
        public readonly ?int $failedAt,
        public readonly int $total,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('bulk_apply_aborted', 0, $previous);
    }

    /**
     * 권한 적용 루프 실패용 인스턴스를 생성합니다.
     *
     * @param  Board  $board  실패한 게시판
     * @param  int  $index  실패 인덱스 (0-base)
     * @param  int  $total  일괄 적용 대상 게시판 총 수
     * @param  \Throwable|null  $previous  원인 예외
     */
    public static function forBoard(Board $board, int $index, int $total, ?\Throwable $previous = null): self
    {
        return new self($board, $index + 1, $total, $previous);
    }

    /**
     * 컬럼 일괄 업데이트 실패용 인스턴스를 생성합니다.
     *
     * @param  int  $total  일괄 적용 대상 게시판 총 수
     * @param  \Throwable|null  $previous  원인 예외
     */
    public static function forColumns(int $total, ?\Throwable $previous = null): self
    {
        return new self(null, null, $total, $previous);
    }

    /**
     * 실패 게시판 정보를 배열로 반환합니다 (응답·활동이력용).
     *
     * @return array{board_id: int, slug: string, name: string}|null 게시판 정보 (없으면 null)
     */
    public function boardInfo(): ?array
    {
        if ($this->board === null) {
            return null;
        }

        return [
            'board_id' => $this->board->id,
            'slug' => $this->board->slug,
            'name' => $this->board->getLocalizedName(),
        ];
    }

    /**
     * 중단 정보를 직렬화 안전한 배열로 반환합니다.
     *
     * 훅 인자는 큐 직렬화(HookArgumentSerializer)를 거쳐 Exception 객체는
     * 보존되지 않으므로(null 대체), 활동이력 리스너에는 이 배열을 전달한다.
     *
     * @return array{failed_board: array{board_id: int, slug: string, name: string}|null, failed_at: int|null, total: int, failure_reason: string|null}
     */
    public function toLogContext(): array
    {
        return [
            'failed_board' => $this->boardInfo(),
            'failed_at' => $this->failedAt,
            'total' => $this->total,
            'failure_reason' => $this->getPrevious()?->getMessage(),
        ];
    }
}
