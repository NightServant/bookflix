<?php

namespace App\Providers;

use App\Discovery\Search\QueryParser;
use App\Discovery\Search\RuleBasedQueryParser;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QueryParser::class, RuleBasedQueryParser::class);

        // Registered here, not in boot: Livewire runs its own `call` hooks in registration order, and an event listener executes inside one of them (SupportEvents),
        // so the guard has to be on the list before Livewire boots.
        $this->guardReaderComponentsFromAdmins();
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

        // An admin account runs the catalog and has no reader side (see EnsureReaderAccount); the same line guards the reader writes an admin could still call straight through Livewire.
        Gate::define('use-reader-side', fn (User $user): bool => ! $user->is_admin);

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

    /**
     * An admin cannot open a reader page, but a Livewire call does not pass through the route's middleware: the library listener sits in the shared layout,
     * and a reader page left open from before the account was promoted keeps its snapshot. So every call from an admin to a component outside the admin
     * pages, the account pages and the search palette is refused here, before it can write reader data.
     */
    private function guardReaderComponentsFromAdmins(): void
    {
        $adminSide = ['pages::admin.', 'pages::settings.', 'command-palette-results'];
        $readerSettings = ['pages::settings.preferences', 'pages::settings.privacy'];

        on('call', function (Component $component) use ($adminSide, $readerSettings): void {
            $user = auth()->user();

            if ($user === null || Gate::forUser($user)->allows('use-reader-side')) {
                return;
            }

            $name = $component->getName();

            $allowed = Str::startsWith($name, $adminSide) && ! Str::startsWith($name, $readerSettings);

            abort_unless($allowed, 403);
        });
    }
}
