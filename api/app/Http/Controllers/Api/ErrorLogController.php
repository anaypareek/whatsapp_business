<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;
use App\Services\ErrorLogger;
use Illuminate\Http\Request;

class ErrorLogController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['level' => 'nullable|string|max:20', 'source' => 'nullable|in:app,exception,meta,frontend', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'q' => 'nullable|string|max:200', 'status' => 'nullable|in:open,resolved,all', 'per_page' => 'nullable|integer|min:5|max:100']);
        $q = ErrorLog::query()->select(['id', 'level', 'source', 'message', 'error_code', 'status_code', 'file', 'line', 'url', 'method', 'user_label', 'ip', 'resolved_at', 'created_at'])->latest('id');
        if ($r->level) $q->where('level', $r->level);
        if ($r->source) $q->where('source', $r->source);
        if ($r->from) $q->where('created_at', '>=', $r->date('from')->startOfDay());
        if ($r->to) $q->where('created_at', '<=', $r->date('to')->endOfDay());
        if (($r->status ?? 'open') === 'open') $q->whereNull('resolved_at');
        elseif ($r->status === 'resolved') $q->whereNotNull('resolved_at');
        if ($s = trim((string) $r->q)) {
            $q->where(fn ($w) => $w->where('message', 'like', "%$s%")->orWhere('error_code', 'like', "%$s%")->orWhere('url', 'like', "%$s%")
                ->orWhere('file', 'like', "%$s%")->orWhere('user_label', 'like', "%$s%")->orWhere('id', ctype_digit($s) ? (int) $s : 0));
        }
        return $q->paginate($r->integer('per_page', 25));
    }

    public function show(ErrorLog $errorLog) { return $errorLog->load('resolver:id,name'); }

    public function resolve(Request $r, ErrorLog $errorLog)
    {
        $undo = $r->boolean('reopen');
        $errorLog->update(['resolved_at' => $undo ? null : now(), 'resolved_by' => $undo ? null : $r->user()->id]);
        return $errorLog->fresh()->load('resolver:id,name');
    }

    public function clear(Request $r)
    {
        $r->validate(['older_than_days' => 'required|integer|min:0|max:365', 'resolved_only' => 'nullable|boolean']);
        $q = ErrorLog::where('created_at', '<=', now()->subDays($r->integer('older_than_days')));
        if ($r->boolean('resolved_only')) $q->whereNotNull('resolved_at');
        return ['deleted' => $q->delete()];
    }

    public function count()
    {
        return ['open' => ErrorLog::whereNull('resolved_at')->count(),
            'last_24h' => ErrorLog::whereNull('resolved_at')->where('created_at', '>=', now()->subDay())->count()];
    }

    /** POST /api/client-errors — JS errors from the browser. */
    public function report(Request $r)
    {
        $d = $r->validate(['message' => 'required|string|max:2000', 'stack' => 'nullable|string|max:20000', 'url' => 'nullable|string|max:2048',
            'source' => 'nullable|string|max:512', 'line' => 'nullable|integer', 'column' => 'nullable|integer', 'app' => 'nullable|in:admin,portal',
            'kind' => 'nullable|string|max:40', 'user_agent' => 'nullable|string|max:512']);
        $id = ErrorLogger::record(['level' => 'error', 'source' => 'frontend', 'message' => '[JS] '.$d['message'], 'error_code' => 'JS_'.strtoupper($d['kind'] ?? 'ERROR'),
            'trace' => $d['stack'] ?? null, 'file' => $d['source'] ?? null, 'line' => $d['line'] ?? null, 'url' => $d['url'] ?? null,
            'context' => ['app' => $d['app'] ?? null, 'column' => $d['column'] ?? null, 'user_agent' => $d['user_agent'] ?? $r->userAgent()]]);
        return response()->json(['log_id' => $id], 201);
    }
}
