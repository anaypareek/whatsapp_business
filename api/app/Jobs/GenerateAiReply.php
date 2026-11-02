<?php
namespace App\Jobs;

use App\Models\Message;
use App\Services\AiReplyService;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/** Dispatched with dispatchAfterResponse() from the webhook (no queue worker on shared hosting), so Meta gets its 200 before the AI call. */
class GenerateAiReply
{
    use Dispatchable;

    public function __construct(public int $messageId) {}

    public function handle(AiReplyService $ai): void
    {
        try {
            $m = Message::with('phoneNumber')->find($this->messageId);
            if ($m?->phoneNumber) $ai->reply($m->phoneNumber, $m);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AI reply failed: '.class_basename($e));
        }
    }
}
