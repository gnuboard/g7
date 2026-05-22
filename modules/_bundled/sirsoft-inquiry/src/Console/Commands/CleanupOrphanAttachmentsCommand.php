<?php

namespace Modules\Sirsoft\Inquiry\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;

class CleanupOrphanAttachmentsCommand extends Command
{
    protected $signature = 'inquiry:cleanup-orphan-attachments';
    protected $description = 'Remove orphan inquiry attachments (uploaded but not linked to a message within the cutoff window).';

    public function handle(InquiryAttachmentRepositoryInterface $attachments): int
    {
        $minutes = (int) config('inquiry.attachment.orphan_cleanup_after_minutes', 30);
        $orphans = $attachments->listOrphansOlderThanMinutes($minutes);

        $count = 0;
        foreach ($orphans as $att) {
            try {
                Storage::disk($att->disk)->delete($att->path);
            } catch (\Throwable $e) {
                $this->warn("Failed to delete file for attachment #{$att->id}: {$e->getMessage()}");
            }
            $attachments->delete($att);
            $count++;
        }

        $this->info("Cleaned up {$count} orphan attachment(s).");
        return self::SUCCESS;
    }
}
