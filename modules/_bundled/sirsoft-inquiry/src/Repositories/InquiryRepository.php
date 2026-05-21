<?php

namespace Modules\Sirsoft\Inquiry\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Inquiry\Exceptions\InquiryNotFoundException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface;

class InquiryRepository implements InquiryRepositoryInterface
{
    public function findByUuidOrFail(string $uuid): Inquiry
    {
        return Inquiry::where('uuid', $uuid)->firstOr(fn () => throw new InquiryNotFoundException($uuid));
    }

    public function create(array $data): Inquiry
    {
        return Inquiry::create($data);
    }

    public function update(Inquiry $inquiry, array $data): Inquiry
    {
        $inquiry->fill($data)->save();
        return $inquiry;
    }

    public function listByUser(int $userId, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        return Inquiry::query()
            ->where('user_id', $userId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('received_at')
            ->paginate($perPage);
    }

    public function listForAdmin(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        return Inquiry::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByDesc('received_at')
            ->paginate($perPage);
    }
}
