<?php

namespace App\Providers;

use App\Discovery\Search\FallbackQueryParser;
use App\Discovery\Search\QueryParser;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureReaderAccount;
use App\Http\Middleware\RequirePassword;
use App\Models\Creator;
use App\Models\Demographic;
use App\Models\ExternalReview;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Review;
use App\Models\Theme;
use App\Models\Trope;
use App\Models\User;
use App\Support\PreResolveHosts;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\Livewire;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QueryParser::class, FallbackQueryParser::class);

        // Registered here, not in boot, so the guard is on Livewire's list before any of its own hooks.
        $this->guardReaderComponentsFromAdmins();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Herd's PHP-FPM on macOS aborts a worker whose curl looks a host up itself (a 502 on any page that calls out); see PreResolveHosts.
        if (PHP_OS_FAMILY === 'Darwin' && PHP_SAPI === 'fpm-fcgi') {
            Http::globalMiddleware(new PreResolveHosts);
        }

        // What a preference points at (user_preferences.preferable_type). A plain map, not an enforced one: notifications and the rest keep their class names.
        Relation::morphMap([
            'genre' => Genre::class,
            'theme' => Theme::class,
            'trope' => Trope::class,
            'demographic' => Demographic::class,
            'media_type' => MediaType::class,
            'creator' => Creator::class,
            // What a report points at (reports.reportable_type).
            'review' => Review::class,
            'external_review' => ExternalReview::class,
        ]);

        // Livewire re-runs only auth and can: on /livewire/update; these four are replayed too, so an update is refused as the page was.
        Livewire::addPersistentMiddleware([
            EnsureEmailIsVerified::class,
            EnsureReaderAccount::class,
            EnsureOnboarded::class,
            RequirePassword::class,
        ]);

        Gate::define('access-admin', fn (User $user): bool => $user->is_admin);

        // An admin account runs the catalog and has no reader side (see EnsureReaderAccount); the same line guards the reader writes an admin could still call straight through Livewire.
        Gate::define('use-reader-side', fn (User $user): bool => ! $user->is_admin);

        RateLimiter::for('reviews', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.reviews_per_hour'))->by($user->id));
        RateLimiter::for('collection-clones', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.collection_clones_per_hour'))->by($user->id));
        RateLimiter::for('reports', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.reports_per_hour'))->by($user->id));

        // The language model: per reader (the assistant per address for a guest), and one global ceiling for summaries.
        RateLimiter::for('ai-intent', fn (User $user): Limit => Limit::perHour(30)->by($user->id));
        RateLimiter::for('ai-assistant', fn (Request $request): Limit => Limit::perHour(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('ai-explanations', fn (User $user): Limit => Limit::perHour(60)->by($user->id));
        RateLimiter::for('ai-summaries', fn (): Limit => Limit::perMinute(30));

        // pdf.js reads a file in many small ranges, so a reader gets a roomy allowance (per person, not per address).
        // One save every couple of seconds while reading, plus the beacons; a person, not an address.
        RateLimiter::for('reader-progress', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('reader-stream', fn (Request $request): Limit => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));

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
     * An admin cannot open a reader page, but a Livewire request does not pass through the route's middleware: the library listener sits in the shared layout,
     * and a reader page left open from before the account was promoted keeps its snapshot. So every request from an admin to a component outside the admin
     * pages and the account pages is refused as the component is restored, before a method call, a property update or a render can read or write reader data.
     */
    private function guardReaderComponentsFromAdmins(): void
    {
        $adminSide = ['pages::admin.', 'pages::settings.'];
        $readerSettings = ['pages::settings.preferences', 'pages::settings.privacy'];

        on('hydrate', function (Component $component) use ($adminSide, $readerSettings): void {
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
