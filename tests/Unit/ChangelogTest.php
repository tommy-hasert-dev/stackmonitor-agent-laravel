<?php

use StackMonitor\Agent\ReportBuilder;

it('has the reported version as the newest changelog entry', function () {
    $changelog = (string) file_get_contents(__DIR__.'/../../CHANGELOG.md');

    preg_match_all('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', $changelog, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and($matches[1][0])->toBe(ReportBuilder::AGENT_VERSION)
        ->and($matches[1])->toBe(collect($matches[1])->sort(fn ($a, $b) => version_compare($b, $a))->values()->all());
});
