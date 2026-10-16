<?php

namespace App\Providers;

use App\Services\AppMenu;
use App\Support\AksiRole;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        if ($this->app->environment('production', 'staging')
            || request()->server('HTTP_X_FORWARDED_PROTO') === 'https'
            || request()->isSecure()) {
            URL::forceScheme('https');
        }

        // Gate aksi yang dibatasi — daftar role dari SATU sumber berklaster
        // (App\Support\AksiRole). Ubah role cukup di file itu; nama Gate di bawah
        // yang dipakai blade (@can) & server (->can()) tidak perlu ikut berubah.
        // Contoh:
        // Gate::define('dokumen.hapus', fn ($user) => $user->hasAnyRole(AksiRole::DOKUMEN_HAPUS));
        Gate::define('gaji.kelola', fn ($user) => $user->hasAnyRole(AksiRole::GAJI_KELOLA));

        // Blade directive untuk render path TTD user (dua format kolom
        // myuser_ttd_image: filename saja vs legacy 'UserTtd/abc.png') —
        // logikanya terpusat di App\Support\TtdUser.
        // Pemakaian: <img src="@ttdSrc($user->myuser_ttd_image)" />
        Blade::directive('ttdSrc', function ($expression) {
            return "<?php echo \\App\\Support\\TtdUser::pathWeb($expression); ?>";
        });

        // Share $sidebarMenus (grouped + filtered by user role) ke sidebar layout.
        // Tidak query DB di guest pages — guard via auth check.
        View::composer('layouts.app-sidebar', function ($view) {
            $roles = auth()->check()
                ? auth()->user()->getRoleNames()->map(fn($r) => trim(strtolower($r)))->values()->toArray()
                : [];

            $view->with('sidebarMenus', AppMenu::grouped($roles));
        });
    }
}
