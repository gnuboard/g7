<?php

namespace Modules\Sirsoft\Board\Services\Concerns;

use App\Contracts\Extension\StorageInterface;

/**
 * 첨부 행 disk 기준 스토리지 해석 trait (첨부·게시판 서비스 공용)
 *
 * 디스크를 전환한 뒤에도 전환 이전 행(구 disk)의 서빙·이동·삭제가 그 행의 실제 저장 위치를
 * 향하도록, 행에 기록된 disk 의 스토리지를 돌려줍니다. 사용 서비스는 `StorageInterface $storage`
 * 주입(모듈 서비스 프로바이더의 `$storageServices`)을 전제로 합니다.
 *
 * 형제 이커머스 `Services\Concerns\ResolvesRowStorage` 와 같은 해석이다 — 종전에는 첨부 서비스의
 * 사본(private 메서드)만 있어 게시판 삭제(`BoardService::deleteAttachmentFiles()`)가 주입 디스크
 * 하나만 지웠다.
 */
trait ResolvesRowStorage
{
    /**
     * 첨부 행에 기록된 disk 기준 스토리지를 반환합니다.
     *
     * 미등록 disk(그 디스크를 제공하던 확장이 비활성화된 경우)는 주입 스토리지로 폴백합니다 —
     * withDisk 로 미등록 disk 인스턴스를 만들면 이후 exists/response/delete 가 예외를 던져
     * 서빙·삭제가 500 이 됩니다(파일은 도달 불가이므로 404 가 정상 degradation).
     *
     * @param  string|null  $disk  행의 disk 컬럼 값 (비어 있으면 주입 스토리지)
     * @return StorageInterface 행 disk 의 스토리지
     */
    protected function storageForRow(?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || $disk === $this->storage->getDisk()) {
            return $this->storage;
        }

        if (config("filesystems.disks.{$disk}") === null) {
            return $this->storage;
        }

        return $this->storage->withDisk($disk);
    }
}
