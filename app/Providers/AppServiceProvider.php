<?php

namespace App\Providers;

use App\Http\Middleware\CekAksesMenu;
use App\Services\AppMenu;
use App\Support\AksesHalaman;
use App\Support\HakAkses;
use App\Support\ProgramAkses;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // Hak akses = permission Spatie yang dicentang per role di Role Control; definisinya di berkas program
        // app/Akses/Program/<Kelompok>/*.php (App\Support\ProgramAkses). Spatie mendaftarkan tiap permission ke Gate.
        // Role super (Admin) selalu lolos supaya tidak ada yang bisa mengunci semua admin keluar.
        Gate::before(fn ($user) => $user->hasRole(HakAkses::PERAN_SUPER) ? true : null);

        // Aksi khusus berkas program (gaji.kelola, absensi.kelola, …): Gate cadangan bila permission-nya belum dibuat
        // (sebelum akses:sinkron pertama) → centang awal definisi. Sesudah dibuat, centang Role Control yang berlaku.
        // Nama Gate sama dengan sebelumnya, jadi pemanggil @can / ->can() tidak berubah.
        foreach (array_keys(ProgramAkses::aksiKhusus()) as $namaAksi) {
            Gate::define($namaAksi, fn ($user) => AksesHalaman::bolehAksi($user, $namaAksi));
        }

        // Middleware izin menu juga dijalankan ulang di setiap aksi Livewire (/livewire/update), tidak hanya saat
        // halaman dibuka — hak akses yang dicabut di Role Control langsung berlaku untuk tab yang masih terbuka.
        Livewire::addPersistentMiddleware([CekAksesMenu::class]);

        // Blade directive untuk render path TTD user (dua format kolom
        // myuser_ttd_image: filename saja vs legacy 'UserTtd/abc.png') —
        // logikanya terpusat di App\Support\TtdUser.
        // Pemakaian: <img src="@ttdSrc($user->myuser_ttd_image)" />
        Blade::directive('ttdSrc', function ($expression) {
            return "<?php echo \\App\\Support\\TtdUser::pathWeb($expression); ?>";
        });

        // Share $sidebarMenus (grouped + filtered by hak akses user) ke sidebar layout.
        // Tidak query DB di guest pages — guard via auth check.
        View::composer('layouts.app-sidebar', function ($view) {
            $view->with('sidebarMenus', AppMenu::grouped(auth()->user()));
        });
    }
}
