<?php

namespace StackMonitor\Agent\Http;

use Illuminate\Http\JsonResponse;
use StackMonitor\Agent\ReportBuilder;

final class StatusController
{
    public function __invoke(ReportBuilder $builder): JsonResponse
    {
        return new JsonResponse($builder->build(), 200, [], JSON_UNESCAPED_SLASHES);
    }
}
