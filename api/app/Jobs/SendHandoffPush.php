<?php
namespace App\Jobs;

use App\Models\AiContactState;
use App\Models\PhoneNumber;
use App\Services\ErrorLogger;
use App\Services\FcmService;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Push "Needs human" to the owning client's phones and staff (Master app) when a chat first becomes needs_human.
 * One collapse tag per conversation so repeats within the cooldown do not spam.
 */
class SendHandoffPush
{
    use Dispatchable;

    public function __construct(
        public int $phoneNumberId,
        public string $contactWaId,
        public string $reason,
        public ?string $contactName = null,
    ) {}

    public function handle(FcmService $fcm): void
    {
        try {
            $phone = PhoneNumber::query()->find($this->phoneNumberId);
            if (! $phone) return;
            // only while still flagged (human may have cleared it already)
            $st = AiContactState::where('phone_number_id', $phone->id)->where('contact_wa_id', $this->contactWaId)->where('needs_human', true)->first();
            if (! $st) return;
            $who = $this->contactName ?: ('+'.$this->contactWaId);
            $title = 'Needs human: '.$who;
            $body = trim($this->reason) !== '' ? mb_substr($this->reason, 0, 240) : 'Customer needs a team member';
            $data = [
                'type' => 'needs_human',
                'phone_number_id' => (string) $phone->id,
                'contact' => $this->contactWaId,
                'contact_name' => (string) ($this->contactName ?? ''),
                'reason' => mb_substr($this->reason, 0, 240),
            ];
            $tag = 'handoff_'.$phone->id.'_'.$this->contactWaId;
            if ($phone->client_id) $fcm->sendToClient((int) $phone->client_id, $title, $body, $data, $tag);
            $fcm->sendToStaff($title.' · '.($phone->display_number ?? ''), $body, $data, $tag);
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'warning');
        }
    }
}
