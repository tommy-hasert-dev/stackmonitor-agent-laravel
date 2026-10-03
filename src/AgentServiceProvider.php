<?php

namespace StackMonitor\Agent;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stackmonitor-agent.php', 'stackmonitor-agent');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stackmonitor-agent.php' => $this->app->configPath('stackmonitor-agent.php'),
        ], 'stackmonitor-agent-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/agent.php');

        // Route::any() only covers Laravel's own verbs; for any other method
        // (PROPFIND, TRACE, made-up ones) the router answers 405 before
        // VerifyMonitorSignature runs, which reveals the endpoint exists (#18).
        // Render the same 404 an unknown path gets instead.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (MethodNotAllowedHttpException $e, Request $request) use ($handler) {
                if ($request->path() !== Route::getRoutes()->getByName('stackmonitor-agent.status')?->uri()) {
                    return null;
                }

                return $handler->render($request, new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path())));
            });
        });

        // When the scheduler last ran (#60): Laravel doesn't record it.
        Event::listen(function (CommandStarting $event) {
            if ($event->command === 'schedule:run') {
                $this->app->make(SchedulerHeartbeat::class)->record();
                $this->app->make(ScheduledTasks::class)->recordSchedule();
            }
        });

        // The last run of every scheduled task (#138).
        Event::listen(
            [ScheduledTaskStarting::class, ScheduledTaskFinished::class, ScheduledTaskFailed::class, ScheduledTaskSkipped::class, ScheduledBackgroundTaskFinished::class],
            fn (object $event) => $this->app->make(ScheduledTasks::class)->record($event),
        );
    }
}
