<?php

namespace Modules\Gnuboard7\HelloModule\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Gnuboard7\HelloModule\Models\Memo;
use Modules\Gnuboard7\HelloModule\Repositories\Contracts\MemoRepositoryInterface;

/**
 * 메모 서비스
 *
 * 메모 생성/수정/삭제 비즈니스 로직 및 훅 발행을 담당합니다.
 */
class MemoService
{
    /**
     * MemoService 생성자
     *
     * @param  MemoRepositoryInterface  $memoRepository  메모 Repository
     */
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
    ) {}

    /**
     * 메모 목록을 조회합니다.
     *
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 메모 목록
     */
    public function getMemos(int $perPage = 10): LengthAwarePaginator
    {
        return $this->memoRepository->paginate($perPage);
    }

    /**
     * ID 로 메모를 조회합니다.
     *
     * @param  int  $id  메모 ID
     * @return Memo 메모 모델
     *
     * @throws ModelNotFoundException
     */
    public function getMemo(int $id): Memo
    {
        return $this->memoRepository->findOrFail($id);
    }

    /**
     * 메모를 생성합니다.
     *
     * 저장 전에 제목을 `gnuboard7-hello_module.memo.title.filter` 필터에 통과시키고,
     * 생성 성공 시 `gnuboard7-hello_module.memo.created` 훅을 발행합니다.
     *
     * @param  array  $data  메모 생성 데이터 (title, content)
     * @return Memo 생성된 메모 모델
     */
    public function createMemo(array $data): Memo
    {
        // Filter 훅 시연 — 다른 확장(예: Hello 플러그인)이 제목을 가공할 수 있다
        if (array_key_exists('title', $data)) {
            $data['title'] = HookManager::applyFilters('gnuboard7-hello_module.memo.title.filter', $data['title']);
        }

        $memo = $this->memoRepository->create($data);

        HookManager::doAction('gnuboard7-hello_module.memo.created', $memo);

        return $memo;
    }

    /**
     * 메모를 수정합니다.
     *
     * @param  Memo  $memo  메모 모델
     * @param  array  $data  수정할 데이터
     * @return Memo 수정된 메모 모델
     */
    public function updateMemo(Memo $memo, array $data): Memo
    {
        return $this->memoRepository->update($memo, $data);
    }

    /**
     * 메모를 삭제합니다.
     *
     * @param  Memo  $memo  메모 모델
     * @return bool 삭제 성공 여부
     */
    public function deleteMemo(Memo $memo): bool
    {
        return $this->memoRepository->delete($memo);
    }
}
