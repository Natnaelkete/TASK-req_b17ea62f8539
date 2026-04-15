<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Define role-based gates
        Gate::define('system_admin', fn ($user) => $user->role === 'system_admin');
        Gate::define('compliance_reviewer', fn ($user) => in_array($user->role, ['system_admin', 'compliance_reviewer']));
        Gate::define('employer_manager', fn ($user) => in_array($user->role, ['system_admin', 'employer_manager']));
        Gate::define('inspector', fn ($user) => in_array($user->role, ['system_admin', 'inspector']));

        Gate::define('approve-employer', fn ($user) => in_array($user->role, ['system_admin', 'compliance_reviewer']));
        Gate::define('publish-results', fn ($user) => in_array($user->role, ['system_admin', 'compliance_reviewer']));
        Gate::define('manage-workflows', fn ($user) => in_array($user->role, ['system_admin', 'compliance_reviewer']));
        Gate::define('manage-inspections', fn ($user) => in_array($user->role, ['system_admin', 'inspector']));
    }
}
