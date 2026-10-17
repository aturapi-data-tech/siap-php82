const defaultTheme = require("tailwindcss/defaultTheme");
const forms = require("@tailwindcss/forms");

/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: "class",
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
        // Kelas warna yang dirakit di PHP (Jadwal::WARNA, RoleBadge, dll.)
        "./app/Support/**/*.php",
    ],
    theme: {
        extend: {
            colors: {
                // Brand inti
                brand: {
                    DEFAULT: "rgb(var(--fb-brand) / <alpha-value>)",
                    // ── semantik Flowbite v4 (nilai di app.css --fb-*) ──
                    strong: "rgb(var(--fb-brand-strong) / <alpha-value>)",
                    medium: "rgb(var(--fb-brand-medium) / <alpha-value>)",
                    soft: "rgb(var(--fb-brand-soft) / <alpha-value>)",
                    softer: "rgb(var(--fb-brand-softer) / <alpha-value>)",
                    subtle: "rgb(var(--fb-brand-subtle) / <alpha-value>)",
                    green: "#157547",
                    lime: "#A1CD3A",
                    "green-active": "#0f5634", // hover/press hijau
                    "green-soft": "#4f9e6a", // indikator/aksen hijau-soft
                    "green-pale": "#cfe0d3", // disabled hijau
                },
                // ── Token semantik Flowbite v4 (dipetakan ke brand; nilai & mode gelap di app.css --fb-*) ──
                heading: "rgb(var(--fb-heading) / <alpha-value>)",
                fg: {
                    brand: "rgb(var(--fb-fg-brand) / <alpha-value>)",
                    "brand-strong": "rgb(var(--fb-fg-brand-strong) / <alpha-value>)",
                    "danger-strong": "rgb(var(--fb-fg-danger-strong) / <alpha-value>)",
                    "success-strong": "rgb(var(--fb-fg-success-strong) / <alpha-value>)",
                    warning: "rgb(var(--fb-fg-warning) / <alpha-value>)",
                    disabled: "rgb(var(--fb-fg-disabled) / <alpha-value>)",
                },
                neutral: {
                    primary: "rgb(var(--fb-neutral-primary) / <alpha-value>)",
                    "primary-soft": "rgb(var(--fb-neutral-primary-soft) / <alpha-value>)",
                    "primary-medium": "rgb(var(--fb-neutral-primary-medium) / <alpha-value>)",
                    "secondary-soft": "rgb(var(--fb-neutral-secondary-soft) / <alpha-value>)",
                    "secondary-medium": "rgb(var(--fb-neutral-secondary-medium) / <alpha-value>)",
                    "tertiary-soft": "rgb(var(--fb-neutral-tertiary-soft) / <alpha-value>)",
                    tertiary: "rgb(var(--fb-neutral-tertiary) / <alpha-value>)",
                    "tertiary-medium": "rgb(var(--fb-neutral-tertiary-medium) / <alpha-value>)",
                    quaternary: "rgb(var(--fb-neutral-quaternary) / <alpha-value>)",
                },
                default: {
                    DEFAULT: "rgb(var(--fb-default) / <alpha-value>)",
                    medium: "rgb(var(--fb-default-medium) / <alpha-value>)",
                    light: "rgb(var(--fb-default-light) / <alpha-value>)",
                    strong: "rgb(var(--fb-default-strong) / <alpha-value>)", // garis input aktif — sage bernuansa brand, ≥3:1
                },
                danger: {
                    DEFAULT: "rgb(var(--fb-danger) / <alpha-value>)",
                    strong: "rgb(var(--fb-danger-strong) / <alpha-value>)",
                    medium: "rgb(var(--fb-danger-medium) / <alpha-value>)",
                    soft: "rgb(var(--fb-danger-soft) / <alpha-value>)",
                    subtle: "rgb(var(--fb-danger-subtle) / <alpha-value>)",
                },
                // Teks di atas permukaan gelap
                on: {
                    dark: "#f6f8f5",
                    "dark-soft": "#9aa89e",
                },
                // Abu-abu Tailwind diganti netral bernuansa hijau hutan (opsi A "Netral Klinis"):
                // semua dark:bg-gray-900/800, border-gray-700, text-gray-500 … ikut brand tanpa mengubah tiap file.
                gray: {
                    50: "#f6f8f6",
                    100: "#eef1ee",
                    200: "#dfe5e0",
                    300: "#c4cdc6",
                    400: "#9aa59d",
                    500: "#66716a",
                    600: "#4b554e",
                    700: "#26322b",
                    800: "#161e1a",
                    900: "#0f1512",
                    950: "#0a0f0c",
                },
                // Semantic (v2: DEFAULT + -tint utk badge/alert bg + -deep utk teks) — hex eksak dari style guide v2
                success: { DEFAULT: "#3fae6a", tint: "#e2f3e8", deep: "#23744a", strong: "rgb(var(--fb-success-strong) / <alpha-value>)", soft: "rgb(var(--fb-success-soft) / <alpha-value>)", subtle: "rgb(var(--fb-success-subtle) / <alpha-value>)" },
                warning: { DEFAULT: "#d4a017", tint: "#faf1d9", deep: "#8a6a0e", strong: "rgb(var(--fb-warning-strong) / <alpha-value>)", soft: "rgb(var(--fb-warning-soft) / <alpha-value>)", subtle: "rgb(var(--fb-warning-subtle) / <alpha-value>)" },
                error: { DEFAULT: "#c64545", tint: "#fae7e7", deep: "#9c3232" },
                info: { DEFAULT: "#2e7da6", tint: "#e3eff5", deep: "#205d7d" },
                // ===== Design system tokens (acuan /standarisasi-ui) =====
                // Pakai sebagai utility: bg-canvas, text-ink, border-hairline, dst.
                canvas: "#ffffff", // kartu, tabel, topbar & sidebar (opsi A "Netral Klinis": kartu putih di atas latar netral)
                surface: {
                    soft: "#f4f6f4", // latar halaman (wrapper page) & band — netral, sedikit hijau
                    elevated: "#ffffff", // kartu data / modal (v2: di atas canvas)
                    card: "#eef1ee", // kartu fitur / blok sekunder
                    strong: "#e3f0e6", // tab / menu terpilih — tint hijau Madinah
                    dark: "#14201a", // panel data / footer (forest)
                    "dark-elevated": "#1e2b23", // kartu di atas dark
                    "dark-soft": "#18241d", // blok kode di dalam dark
                },
                ink: "#13201a", // judul & teks utama
                body: {
                    DEFAULT: "#3c463f", // teks berjalan
                    strong: "#233029", // paragraf lead
                },
                muted: {
                    DEFAULT: "#5f6961", // sub-judul — 5,4:1 di latar halaman, 5,7:1 di kartu putih
                    soft: "#646d66", // caption / fine-print — ≥4,8:1 di latar halaman & kartu (WCAG AA).
                    // Dulu #8b948c = 3.13:1, di bawah ambang 4.5:1 sehingga teks
                    // sekunder terbaca buram. Dipakai ~382 file lewat text-muted-soft.
                },
                hairline: {
                    DEFAULT: "#e2e6e3", // garis 1px di permukaan terang
                    soft: "#ecefec", // divider sangat halus
                },
            },
            borderRadius: {
                base: "0.5rem", // rounded-base Flowbite v4
            },
            boxShadow: {
                xs: "0 1px 2px 0 rgb(0 0 0 / 0.05)", // shadow-xs Flowbite v4
            },
            fontFamily: {
                // v2: Source Sans 3 (x-height tinggi, mudah dibaca lansia); Inter fallback
                sans: ['"Source Sans 3"', "Inter", ...defaultTheme.fontFamily.sans],
                // Headline editorial (substitusi Copernicus/Tiempos)
                serif: [
                    '"Cormorant Garamond"',
                    '"Tiempos Headline"',
                    "Garamond",
                    '"Times New Roman"',
                    "serif",
                ],
            },
            // Skala display serif (ukuran + line-height + tracking sudah dibundel)
            fontSize: {
                // HANYA ukuran yang TIDAK ada di Tailwind default.
                // (30→text-3xl, 24→text-2xl, 20→text-xl, 18→text-lg, 16→text-base,
                //  14→text-sm, 12→text-xs sudah native — tidak perlu dibuat.)
                "display-xl": ["52px", { lineHeight: "1.1", letterSpacing: "0.2px" }],  // antara 5xl/6xl
                "display-lg": ["38px", { lineHeight: "1.15", letterSpacing: "0.2px" }], // ~4xl tapi beda
                "title-sm": ["15px", { lineHeight: "1.45" }],                            // antara sm/base
                "caption": ["13px", { lineHeight: "1.4" }],                              // antara xs/sm
                "caption-up": ["11.5px", { lineHeight: "1.4", letterSpacing: "1px" }],   // < xs
            },
            spacing: {
                section: "96px", // jarak antar band (py-section)
            },
            backgroundImage: {
                // Garis dash pola panjang-pendek (----------- --) — lime, utk pemisah header sidebar
                "dash-lime":
                    "repeating-linear-gradient(to right, #A1CD3A 0 44px, transparent 44px 52px, #A1CD3A 52px 62px, transparent 62px 70px)",
            },
        },
    },
    plugins: [forms],
};
