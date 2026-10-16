<?php

namespace StackMonitor\Agent\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StackMonitor\Agent\PhpCode;
use StackMonitor\Agent\ReportBuilder;
use StackMonitor\Agent\SuspiciousFiles;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StatusController
{
    /**
     * The report, or with X-Monitor-File the start of one suspicious file of
     * the last scan or the PHP files of one reported package as a ZIP
     * (#159), if the app allows it (STACKMONITOR_AGENT_FILE_CONTENTS). Both
     * are asked for by a SHA-256, the path's or the package's id.
     */
    public function __invoke(Request $request, ReportBuilder $builder, SuspiciousFiles $files, PhpCode $code): JsonResponse
    {
        $file = (string) $request->header('X-Monitor-File', '');

        if ($file === '') {
            return new JsonResponse($builder->build(), 200, [], JSON_UNESCAPED_SLASHES);
        }

        $content = filter_var(config('stackmonitor-agent.file_contents'), FILTER_VALIDATE_BOOL)
            ? ($files->content($file) ?? $code->archive($file))
            : null;

        if ($content === null) {
            // The same 404 as for a rejected request or an unknown route.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return new JsonResponse($content, 200, [], JSON_UNESCAPED_SLASHES);
    }
}
