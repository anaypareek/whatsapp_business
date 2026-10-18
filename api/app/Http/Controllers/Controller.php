<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /** JSON error for a caught failure (usually a Meta API error): {message, error_code, log_id, meta?}. */
    protected function fail(\Throwable $e, int $status = 502, array $extra = []): \Illuminate\Http\JsonResponse
    {
        $id = \App\Services\ErrorLogger::alreadyLogged($e) ?? \App\Services\ErrorLogger::exception($e, 'error', ['status_code' => $status]);
        $meta = $e instanceof \App\Services\MetaApiException;
        return response()->json(['message' => $e->getMessage(), 'error_code' => \App\Services\ErrorLogger::codeFor($e), 'log_id' => $id]
            + ($meta ? ['meta' => array_intersect_key($e->details(), array_flip(['http_status', 'meta_code', 'meta_subcode', 'error_user_title', 'error_user_msg', 'error_details', 'hint', 'fbtrace_id']))] : []) + $extra, $status);
    }

    //
}
