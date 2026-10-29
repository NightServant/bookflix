<?php

namespace App\Providers;

use App\Enums\Visibility;
use App\Models\Creator;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Theme;
use App\Models\Trope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // What a preference points at (user_preferences.preferable_type). A plain map, not an enforced one: notifications and the rest keep their class names.
        Relation::morphMap([
            'genre' => Genre::class,
            'theme' => Theme::class,
            'trope' => Trope::class,
            'demographic' => Demographic::class,
            'media_type' => MediaType::class,
            'creator' => Creator::class,
        ]);

        Gate::define('access-admin', fn (User $user): bool => $user->is_admin);

        // A reader's library is theirs; others (guests too) may see it only when its owner made it public and is still active. Phase 10 builds the public page on this.
        Gate::define('view-library', fn (?User $viewer, User $owner): bool => $viewer?->is($owner)
            || ($owner->profile->library_visibility === Visibility::Public && ! $owner->isDeactivated()));

        foreach (config('bookflix.providers') as $key => $provider) {
            if ($provider['class'] === null) {
                continue;
            }

            RateLimiter::for("provider:{$key}", fn (): Limit => Limit::perMinute($provider['rate_per_minute']));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
