import "./bootstrap";
import collapse from "@alpinejs/collapse";
import mask from "@alpinejs/mask";
import toastr from "toastr";
import "toastr/build/toastr.min.css";

window.toastr = toastr;

// TinyMCE — LAZY-LOADED (dynamic import) supaya bundle JS awal ringan.
// Editor (core + plugin + skin) baru di-fetch saat editor PERTAMA dibuka,
// lalu di-cache di window.tinymce. Self-hosted (npm), GPL, no API key.
let _tinymcePromise = null;
function loadTinymce() {
    if (window.tinymce) return Promise.resolve(window.tinymce);
    if (_tinymcePromise) return _tinymcePromise;
    _tinymcePromise = (async () => {
        const tinymce = (await import("tinymce")).default;
        // Plugin/tema/skin (side-effect import) — daftar ke singleton tinymce.
        await Promise.all([
            import("tinymce/icons/default"),
            import("tinymce/themes/silver"),
            import("tinymce/models/dom"),
            import("tinymce/plugins/lists"),
            import("tinymce/plugins/link"),
            import("tinymce/plugins/table"),
            import("tinymce/plugins/autolink"),
            import("tinymce/plugins/code"),
            import("tinymce/plugins/charmap"),
            import("tinymce/plugins/fullscreen"),
            import("tinymce/skins/ui/oxide/skin.min.css"),
        ]);
        window.__tinymceContentCss = (await import("tinymce/skins/content/default/content.min.css?inline")).default;
        window.__tinymceContentUiCss = (await import("tinymce/skins/ui/oxide/content.min.css?inline")).default;
        window.tinymce = tinymce;
        return tinymce;
    })();
    return _tinymcePromise;
}

// Alpine.data factory untuk <x-tinymce-editor>
document.addEventListener("alpine:init", () => {
    window.Alpine.data(
        "tinymceEditor",
        ({
            propName,
            placeholder = "Tulis di sini…",
            modalEvent = null,
            flushEvent = null,
            reloadEvent = null,
            height = 480,
            // CSS tambahan utk content editor (WYSIWYG) — di-append ke content_style.
            // Dipakai mis. supaya tampilan editor mirror tema cetak (font/border/warna).
            contentStyle = "",
        }) => ({
            editor: null,
            init() {
                if (modalEvent) {
                    window.addEventListener("open-modal", (e) => {
                        if (e.detail?.name === modalEvent) {
                            setTimeout(() => this.bootEditor(), 120);
                        }
                    });
                    window.addEventListener("close-modal", (e) => {
                        if (e.detail?.name === modalEvent) {
                            this.cleanupEditor();
                        }
                    });
                } else {
                    this.$nextTick(() => this.bootEditor());
                }
                if (flushEvent) {
                    window.addEventListener(flushEvent, () => this.flush());
                }
                if (reloadEvent) {
                    window.addEventListener(reloadEvent, () => this.reload());
                }
            },
            reload() {
                if (!this.editor) return;
                const fresh = this.$wire.get(propName) || "";
                this.editor.setContent(fresh);
            },
            cleanupEditor() {
                const tinymce = window.tinymce;
                if (!tinymce) return; // belum pernah dimuat → tak ada yang dibersihkan
                // tinymce.remove() = canonical cleanup di TinyMCE 8 (destroy + unbind global).
                if (this.editor) {
                    try { tinymce.remove(this.editor); } catch (e) {
                        console.warn("[tinymceEditor] remove failed:", e);
                    }
                    this.editor = null;
                }
                // Defensive: hapus orphan instance yang masih nempel ke host
                const host = this.$refs?.host;
                if (host && host.id) {
                    try {
                        const orphan = tinymce.get(host.id);
                        if (orphan) tinymce.remove(orphan);
                    } catch (e) {}
                }
            },
            async bootEditor() {
                const tinymce = await loadTinymce();
                this.cleanupEditor();
                const host = this.$refs.host;
                if (!host) {
                    console.warn("[tinymceEditor] x-ref host not found");
                    return;
                }

                // Workaround bug TinyMCE 8: `purgeDestroyedEditor` crash kalau ada
                // null entry di `tinymce.editors` global registry (dari destroy/remove
                // sebelumnya yang tidak bersih). Force-filter null sebelum init.
                try {
                    if (Array.isArray(tinymce.editors)) {
                        tinymce.editors = tinymce.editors.filter((e) => e != null);
                    }
                } catch (e) {
                    console.warn("[tinymceEditor] could not purge null editors:", e);
                }

                // Reset textarea state — sapu bersih artifact dari destroy sebelumnya
                host.style.cssText = "";
                host.className = "";
                host.value = "";

                // Assign unique ID + pakai selector (lebih reliable daripada target)
                host.id = "tinymce-host-" + Math.random().toString(36).slice(2);

                const initial = this.$wire.get(propName) || "";
                console.log("[tinymceEditor] init start", {
                    propName,
                    hostId: host.id,
                    initialLen: initial.length,
                });
                tinymce
                    .init({
                        selector: "#" + host.id,
                        // TinyMCE 6.8+ / 7+ / 8+ wajib explicit license key.
                        // 'gpl' = pakai versi open-source (GPLv2), tidak butuh API key Tiny Cloud.
                        license_key: "gpl",
                        height: height,
                        menubar: false,
                        branding: false,
                        promotion: false,
                        plugins: "lists link table autolink code charmap fullscreen",
                        toolbar:
                            "undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | " +
                            "alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | " +
                            "table | link charmap | removeformat code | fullscreen",
                        placeholder: placeholder,
                        skin: false,
                        content_css: false,
                        content_style:
                            window.__tinymceContentCss +
                            "\n" +
                            window.__tinymceContentUiCss +
                            "\nbody { font-size: 14px; }" +
                            (contentStyle ? "\n" + contentStyle : ""),
                        setup: (ed) => {
                            this.editor = ed;
                            ed.on("init", () => {
                                console.log("[tinymceEditor] editor init complete");
                                if (initial) ed.setContent(initial);
                            });
                            ed.on(
                                "input change keyup blur SetContent",
                                () => this.flush()
                            );
                        },
                    })
                    .then((editors) => {
                        console.log("[tinymceEditor] init resolved", editors?.length);
                    })
                    .catch((err) => {
                        console.error("[tinymceEditor] init failed:", err);
                    });
            },
            flush() {
                if (!this.editor) return;
                const html = this.editor.getContent();
                this.$wire.set(propName, html, false);
            },
        })
    );
});

// ✅ Plugin didaftarkan via event, Alpine-nya dari Livewire
document.addEventListener("alpine:init", () => {
    window.Alpine.plugin(collapse);
    window.Alpine.plugin(mask);

    // x-enter-chain: Enter di input/select → fokus field berikutnya (entry cepat).
    // Textarea/checkbox/radio/disabled/hidden dilewati. Pasang di wrapper form (mis. body modal).
    window.Alpine.directive("enter-chain", (el) => {
        const SEL =
            'input:not([type=checkbox]):not([type=radio]):not([type=hidden]), select';
        el.addEventListener("keydown", (e) => {
            if (e.key !== "Enter") return;
            const t = e.target;
            if (!t.matches || !t.matches(SEL)) return; // textarea/tombol → biarkan
            e.preventDefault();
            const els = [...el.querySelectorAll(SEL + ", textarea")].filter(
                (x) => !x.disabled && !x.readOnly && x.offsetParent !== null
            );
            const i = els.indexOf(t);
            if (i > -1 && i < els.length - 1) els[i + 1].focus();
        });
    });
});
