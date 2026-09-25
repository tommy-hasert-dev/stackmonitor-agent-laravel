<?php

use Illuminate\Support\Facades\Route;
use StackMonitor\Agent\Http\StatusController;
use StackMonitor\Agent\Http\VerifyMonitorSignature;

$path = trim((string) config('stackmonitor-agent.path'), '/');

if ($path === '') {
    $path = 'stackmonitor/status';
}

// Route::any (not ->get): a GET-only route makes Laravel answer other HTTP
// methods itself (405 with an Allow header, 200 on OPTIONS), which reveals the
// endpoint exists. VerifyMonitorSignature turns every non-GET/HEAD method into
// the same generic 404 as an unknown path (spec §7).
Route::any($path, StatusController::class)
    ->middleware(VerifyMonitorSignature::class)
    ->name('stackmonitor-agent.status');
