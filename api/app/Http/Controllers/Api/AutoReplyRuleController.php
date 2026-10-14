<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutoReplyRule;
use Illuminate\Http\Request;

class AutoReplyRuleController extends Controller
{
    protected function rules(bool $partial = false): array
    {
        $s = $partial ? 'sometimes|' : 'required|';
        return ['phone_number_id' => 'nullable|exists:phone_numbers,id', 'match_type' => $s.'in:keyword,contains,exact',
            'trigger' => $s.'string|max:255', 'reply' => $s.'string|max:4096', 'enabled' => 'boolean', 'priority' => 'integer|min:0'];
    }
    public function index() { return AutoReplyRule::with('phoneNumber')->orderByDesc('priority')->orderBy('id')->get(); }
    public function store(Request $r) { return response()->json(AutoReplyRule::create($r->validate($this->rules())), 201); }
    public function update(Request $r, AutoReplyRule $autoReply) { $autoReply->update($r->validate($this->rules(true))); return $autoReply; }
    public function destroy(AutoReplyRule $autoReply) { $autoReply->delete(); return response()->noContent(); }
}
