<?php

namespace Modules\Sirsoft\Inquiry\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

interface InquiryRepositoryInterface
{
    public function findByUuidOrFail(string $uuid): Inquiry;

    public function create(array $data): Inquiry;

    public function update(Inquiry $inquiry, array $data): Inquiry;

    public function listByUser(int $userId, ?string $status = null, int $perPage = 20): LengthAwarePaginator;

    public function listForAdmin(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator;
}
