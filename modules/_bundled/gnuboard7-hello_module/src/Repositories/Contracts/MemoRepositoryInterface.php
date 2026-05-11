<?php

namespace Modules\Gnuboard7\HelloModule\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Gnuboard7\HelloModule\Models\Memo;

/**
 * 메모 Repository 인터페이스
 */
interface MemoRepositoryInterface
{
    /**
     * 메모 목록을 페이지네이션하여 조회합니다.
     *
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 메모 목록
     */
    public function paginate(int $perPage = 10): LengthAwarePaginator;

    /**
     * ID로 메모를 조회합니다.
     *
     * @param  int  $id  메모 ID
     * @return Memo|null 메모 모델 또는 null
     */
    public function findById(int $id): ?Memo;

    /**
     * ID로 메모를 조회하며, 없으면 예외를 발생시킵니다.
     *
     * @param  int  $id  메모 ID
     * @return Memo 메모 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id): Memo;

    /**
     * 메모를 생성합니다.
     *
     * @param  array  $data  메모 생성 데이터
     * @return Memo 생성된 메모 모델
     */
    public function create(array $data): Memo;

    /**
     * 메모를 수정합니다.
     *
     * @param  Memo  $memo  메모 모델
     * @param  array  $data  수정할 데이터
     * @return Memo 수정된 메모 모델
     */
    public function update(Memo $memo, array $data): Memo;

    /**
     * 메모를 삭제합니다.
     *
     * @param  Memo  $memo  메모 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Memo $memo): bool;
}
