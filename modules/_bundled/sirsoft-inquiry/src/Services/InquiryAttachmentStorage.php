<?php

namespace Modules\Sirsoft\Inquiry\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Models\InquiryAttachment;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;

class InquiryAttachmentStorage
{
    public function __construct(
        private readonly InquiryAttachmentRepositoryInterface $attachments,
    ) {}

    /**
     * @param 'inquiry'|'message' $context
     */
    public function store(Inquiry $inquiry, int $uploaderUserId, UploadedFile $file, string $context = 'message'): InquiryAttachment
    {
        $mime = $file->getMimeType() ?? $file->getClientMimeType();
        $allowed = config('inquiry.attachment.allowed_mimes', []);
        if (! in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException("Disallowed mime: {$mime}");
        }

        $maxKey = $context === 'inquiry' ? 'max_size_inquiry' : 'max_size_message';
        $maxBytes = (int) config("inquiry.attachment.{$maxKey}");
        if ($file->getSize() > $maxBytes) {
            throw new InvalidArgumentException("File too large: {$file->getSize()} > {$maxBytes}");
        }

        $disk = config('inquiry.attachment.disk', 'local');
        $path = $file->store("inquiries/{$inquiry->uuid}", $disk);

        return $this->attachments->create([
            'inquiry_id' => $inquiry->id,
            'message_id' => null,
            'uploader_user_id' => $uploaderUserId,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
        ]);
    }
}
