<button x-data="{
    init() {
            // apply theme saat pertama load
            const saved = localStorage.getItem('theme')
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
            const isDark = saved ? saved === 'dark' : prefersDark
            document.documentElement.classList.toggle('dark', isDark)
        },
        toggle() {
            const isDark = document.documentElement.classList.toggle('dark')
            localStorage.setItem('theme', isDark ? 'dark' : 'light')
        }
}" x-init="init()" @click="toggle()"
    class="w-9 h-9 flex items-center justify-center rounded-full border
           border-[#e3e3e0] dark:border-[#3E3E3A]
           bg-white dark:bg-[#161615]
           hover:bg-surface-soft dark:hover:bg-gray-700
           transition"
    aria-label="Toggle Theme" type="button">
    <!-- Sun -->
    <svg class="w-5 h-5 text-[#1b1b18] dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364-6.364-1.414 1.414M7.05 16.95l-1.414 1.414M16.95 16.95l1.414 1.414M7.05 7.05 5.636 5.636M12 8a4 4 0 100 8 4 4 0 000-8z" />
    </svg>

    <!-- Moon -->
    <svg class="w-5 h-5 text-[#EDEDEC] hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
    </svg>
</button>
