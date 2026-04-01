<?php

namespace App\Http\Resources;

use App\Http\Resources\Traits\HasRowNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 메일 발송 이력 컬렉션 리소스
 *
 * 발송 이력 목록을 페이지네이션과 함께 반환합니다.
 */
class MailSendLogCollection extends ResourceCollection
{
    use HasRowNumber;

    /**
     * 메일 발송 이력 컬렉션을 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->mapWithRowNumber(function ($mailSendLog) {
                return (new MailSendLogResource($mailSendLog))->toArray(request());
            }),
            'pagination' => $this->resource instanceof LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
        ];
    }
}
