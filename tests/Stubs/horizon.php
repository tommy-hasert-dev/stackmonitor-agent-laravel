<?php

// The two repositories of laravel/horizon the agent reads, for tests without
// the package; only declared where Horizon isn't installed.

namespace Laravel\Horizon\Contracts;

if (! interface_exists(MasterSupervisorRepository::class)) {
    interface MasterSupervisorRepository
    {
        public function all();
    }
}

if (! interface_exists(SupervisorRepository::class)) {
    interface SupervisorRepository
    {
        public function all();
    }
}
