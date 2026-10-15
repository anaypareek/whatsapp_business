<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flow extends Model
{
    public const MAX_NODES = 10; // steps per flow (the trigger is not counted)
    public const TRIGGER_TYPES = ['contains', 'exact', 'starts_with'];
    public const NODE_TYPES = ['message', 'question', 'condition', 'webhook'];

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'keywords' => 'array', 'nodes' => 'array', 'edges' => 'array'];

    public function phoneNumber() { return $this->belongsTo(PhoneNumber::class); }
    public function client() { return $this->belongsTo(Client::class); }
    public function sessions() { return $this->hasMany(FlowSession::class); }

    /** Plain array consumed by FlowRunner (also used for unsaved drafts in the simulator). */
    public function definition(): array
    {
        return ['id' => $this->id, 'trigger_type' => $this->trigger_type, 'keywords' => $this->keywords ?? [], 'nodes' => $this->nodes ?? [], 'edges' => $this->edges ?? []];
    }
}
