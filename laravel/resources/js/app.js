import { animateCounters, animateCharts, animateValue, bootDashboardMotion, revealDashboard } from './dashboard-motion';

window.SimkeuDashboard = { animateCounters, animateCharts, animateValue, bootDashboardMotion, revealDashboard };

const shell = document.querySelector('[data-portal-shell]');
const mobileNavigation = document.querySelector('[data-mobile-nav]');
const mobileBackdrop = document.querySelector('[data-mobile-nav-backdrop]');

// Tombol kembali mempertahankan konteks halaman sebelumnya (filter, halaman
// tabel, atau tab) ketika pengguna datang dari portal. Bila halaman dibuka
// langsung, href tetap menjadi fallback yang aman dan terprediksi.
document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-safe-back], a.ui-form-cancel');

    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    try {
        const referrer = document.referrer ? new URL(document.referrer) : null;
        if (referrer?.origin === window.location.origin && window.history.length > 1) {
            event.preventDefault();
            window.history.back();
        }
    } catch {
        // URL referrer tidak valid: gunakan href fallback dari tombol.
    }
});

const SIDEBAR_KEY = 'simkeu-sidebar-collapsed';
const sidebarGroups = () => document.querySelectorAll('[data-sidebar] details[data-nav-group]');

const syncSidebarToggles = (collapsed) => {
    document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar');
    });
};

const restoreActiveNavGroups = () => {
    sidebarGroups().forEach((group) => {
        group.open = group.hasAttribute('data-nav-active')
            || Boolean(group.querySelector('.is-current, .sidebar-sublink.is-current'));
    });
};

const setSidebarCollapsed = (collapsed, { restoreGroups = true } = {}) => {
    shell?.classList.toggle('sidebar-collapsed', collapsed);
    localStorage.setItem(SIDEBAR_KEY, String(collapsed));
    syncSidebarToggles(collapsed);

    if (collapsed) {
        sidebarGroups().forEach((group) => {
            group.open = false;
        });
        return;
    }

    if (restoreGroups) {
        restoreActiveNavGroups();
    }
};

const initiallyCollapsed = localStorage.getItem(SIDEBAR_KEY) === 'true';
if (initiallyCollapsed) {
    shell?.classList.add('sidebar-collapsed');
    sidebarGroups().forEach((group) => {
        group.open = false;
    });
}
syncSidebarToggles(initiallyCollapsed);

document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        setSidebarCollapsed(!shell?.classList.contains('sidebar-collapsed'));
    });
});

document.querySelector('[data-sidebar]')?.addEventListener('click', (event) => {
    if (!shell?.classList.contains('sidebar-collapsed')) {
        return;
    }

    const summary = event.target.closest('summary');
    const link = event.target.closest('a');
    if (!summary && !link) {
        return;
    }

    if (summary) {
        event.preventDefault();
        const group = summary.closest('details[data-nav-group]');
        setSidebarCollapsed(false, { restoreGroups: false });
        if (group) {
            group.open = true;
        } else {
            restoreActiveNavGroups();
        }
        return;
    }

    setSidebarCollapsed(false);
});

const toggleMobileNavigation = (forceClose = false) => {
    const shouldOpen = forceClose
        ? false
        : !mobileNavigation?.classList.contains('translate-x-0');

    mobileNavigation?.classList.toggle('translate-x-0', shouldOpen);
    mobileNavigation?.classList.toggle('-translate-x-full', !shouldOpen);
    mobileBackdrop?.classList.toggle('hidden', !shouldOpen);
    document.body.classList.toggle('overflow-hidden', shouldOpen);
    document.querySelectorAll('[data-mobile-nav-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', String(shouldOpen));
        if (toggle.closest('[data-mobile-nav]')) {
            return;
        }
        toggle.setAttribute('aria-label', shouldOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi');
    });
};

document.querySelectorAll('[data-mobile-nav-toggle]').forEach((button) => {
    button.addEventListener('click', () => toggleMobileNavigation());
});
mobileBackdrop?.addEventListener('click', () => toggleMobileNavigation(true));

mobileNavigation?.addEventListener('click', (event) => {
    if (event.target.closest('a')) {
        toggleMobileNavigation(true);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobileNavigation?.classList.contains('translate-x-0')) {
        toggleMobileNavigation(true);
    }
});

const positionActionMenu = (trigger, popover) => {
    const bounds = trigger.getBoundingClientRect();
    const width = popover.offsetWidth;
    const height = popover.offsetHeight;
    popover.style.left = `${Math.max(12, Math.min(window.innerWidth - width - 12, bounds.right - width))}px`;
    popover.style.top = `${bounds.bottom + height + 8 > window.innerHeight ? Math.max(12, bounds.top - height - 8) : bounds.bottom + 8}px`;
};

const openActionPopovers = () => document.querySelectorAll('[data-action-popover]:popover-open');
const openDismissibleMenus = () => document.querySelectorAll('[data-dismissible-menu][open]');

const closeDismissibleMenus = (except = null) => {
    openDismissibleMenus().forEach((menu) => {
        if (menu !== except) {
            menu.removeAttribute('open');
        }
    });
};

// Menu header (notifikasi/profil) memakai <details> yang tidak menutup sendiri
// saat klik di luar; tangani di sini tanpa mengganggu <details> navigasi sidebar.
document.addEventListener('pointerdown', (event) => {
    const menu = event.target.closest('[data-dismissible-menu]');
    if (menu) {
        closeDismissibleMenus(menu);
        return;
    }

    closeDismissibleMenus();
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    closeDismissibleMenus();
});

const syncPortalNotifState = (menu) => {
    if (!menu?.matches?.('[data-portal-notif]')) {
        return;
    }

    const isOpen = menu.hasAttribute('open');
    const trigger = menu.querySelector('[data-portal-notif-trigger]');
    const scrim = menu.querySelector('[data-portal-notif-scrim]');

    trigger?.setAttribute('aria-expanded', String(isOpen));

    if (scrim) {
        scrim.hidden = !isOpen;
        scrim.setAttribute('aria-hidden', String(!isOpen));
    }
};

document.addEventListener('toggle', (event) => {
    if (event.target.matches?.('[data-portal-notif]')) {
        syncPortalNotifState(event.target);
    }
});

document.addEventListener('pointerdown', (event) => {
    if (event.target.closest('[data-portal-notif-scrim]')) {
        closeDismissibleMenus();
    }
});

// popovertarget menangani buka/tutup + light-dismiss native; JS hanya
// memosisikan panel dan menyelaraskan aria-expanded.
document.addEventListener('toggle', (event) => {
    if (!event.target.matches?.('[data-action-popover]')) {
        return;
    }

    const popover = event.target;
    const trigger = popover.parentElement?.querySelector('[data-action-trigger]');
    const isOpen = popover.matches(':popover-open');

    trigger?.setAttribute('aria-expanded', String(isOpen));

    if (isOpen && trigger) {
        closeDismissibleMenus();
        positionActionMenu(trigger, popover);
    }
}, true);

const repositionActionPopovers = () => openActionPopovers().forEach((popover) => {
    const trigger = popover.parentElement.querySelector('[data-action-trigger]');
    if (trigger) {
        positionActionMenu(trigger, popover);
    }
});
window.addEventListener('resize', repositionActionPopovers);
window.addEventListener('scroll', repositionActionPopovers, true);

const SOURCE_MAX_BYTES = 40 * 1024 * 1024;
const STORED_MAX_BYTES = 1024 * 1024;

const compressImage = async (file, { preserveAlpha = false } = {}) => {
    if (file.size > SOURCE_MAX_BYTES) {
        throw new Error('Gambar melebihi 40 MB. Pilih foto yang lebih kecil.');
    }

    // Logo PNG/WebP kecil disimpan apa adanya agar transparansi tidak hilang.
    if (preserveAlpha && ['image/png', 'image/webp'].includes(file.type) && file.size <= STORED_MAX_BYTES) {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file);
        let scale = Math.min(1, 2200 / Math.max(bitmap.width, bitmap.height));
        let quality = 0.86;
        let result;
        const outputType = preserveAlpha ? 'image/png' : 'image/webp';
        do {
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            const context = canvas.getContext('2d', { alpha: true });
            context.clearRect(0, 0, canvas.width, canvas.height);
            context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            result = await new Promise((resolve) => canvas.toBlob(resolve, outputType, preserveAlpha ? undefined : quality));
            if (result && result.size <= STORED_MAX_BYTES) break;
            if (!preserveAlpha && quality > 0.48) quality -= 0.12;
            else scale *= 0.75;
        } while (scale > 0.08);
        bitmap.close();
        if (!result || result.size > STORED_MAX_BYTES) {
            return file;
        }
        const extension = outputType === 'image/png' ? 'png' : 'webp';
        return new File([result], `${file.name.replace(/\.[^.]+$/, '')}.${extension}`, { type: outputType });
    } catch (error) {
        if (String(error?.message || '').includes('40 MB')) {
            throw error;
        }
        return file;
    }
};

document.querySelectorAll('[data-image-upload]').forEach((wrapper) => {
    const input = wrapper.querySelector('input[type="file"]:not([data-camera-input])');
    const camera = wrapper.querySelector('[data-camera-input]');
    const message = wrapper.querySelector('[data-upload-message]');
    const allowMultiple = wrapper.hasAttribute('data-multiple') || Boolean(input?.multiple);
    wrapper.querySelector('[data-gallery-button]')?.addEventListener('click', () => input?.click());
    wrapper.querySelector('[data-camera-button]')?.addEventListener('click', () => camera?.click());
    camera?.addEventListener('change', () => {
        if (!camera.files?.length || !input) return;
        const transfer = new DataTransfer();
        transfer.items.add(camera.files[0]);
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    input?.addEventListener('change', () => {
        const files = [...(input.files || [])].filter((file) => file.type.startsWith('image/'));
        if (!files.length) return;
        const preserveAlpha = wrapper.hasAttribute('data-preserve-alpha');
        if (message) {
            message.textContent = preserveAlpha
                ? 'Menyiapkan logo…'
                : (files.length > 1 ? `Mengoptimalkan ${files.length} gambar…` : 'Mengoptimalkan gambar…');
        }
        wrapper.optimizing = (async () => {
            try {
                const optimizedFiles = [];
                for (const file of (allowMultiple ? files : files.slice(0, 1))) {
                    optimizedFiles.push(await compressImage(file, { preserveAlpha }));
                }
                const transfer = new DataTransfer();
                optimizedFiles.forEach((file) => transfer.items.add(file));
                input.files = transfer.files;
                const passthrough = (file) => file.size > STORED_MAX_BYTES;
                if (message) {
                    if (preserveAlpha && optimizedFiles[0] && !passthrough(optimizedFiles[0])) {
                        message.textContent = `${optimizedFiles[0].name} · ${(optimizedFiles[0].size / 1024).toFixed(0)} KB, transparansi dipertahankan`;
                    } else if (optimizedFiles.some(passthrough)) {
                        const totalMb = optimizedFiles.reduce((sum, file) => sum + file.size, 0) / (1024 * 1024);
                        const label = optimizedFiles.length > 1
                            ? `${optimizedFiles.length} gambar (${totalMb.toFixed(1)} MB)`
                            : `Gambar ${totalMb.toFixed(1)} MB`;
                        message.textContent = `${label} akan dikecilkan di server. Hasil simpan maksimal 1 MB.`;
                    } else if (optimizedFiles.length > 1) {
                        const totalKb = optimizedFiles.reduce((sum, file) => sum + file.size, 0) / 1024;
                        message.textContent = `${optimizedFiles.length} file siap · ${totalKb.toFixed(0)} KB total`;
                    } else if (optimizedFiles[0]) {
                        message.textContent = `${optimizedFiles[0].name} · ${(optimizedFiles[0].size / 1024).toFixed(0)} KB, siap disimpan`;
                    }
                }
            } catch (error) {
                input.value = '';
                if (message) message.textContent = error.message;
            } finally {
                wrapper.optimizing = null;
            }
        })();
    });
});

document.querySelectorAll('form:has([data-image-upload]), form:has([data-signature])').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        const pending = [
            ...[...form.querySelectorAll('[data-image-upload]')].map((wrapper) => wrapper.optimizing),
            ...[...form.querySelectorAll('[data-signature]')].map((wrapper) => wrapper.pending),
        ].filter(Boolean);
        if (!pending.length) return;
        event.preventDefault();
        await Promise.allSettled(pending);
        form.requestSubmit(event.submitter || undefined);
    });
});

// Aturan form anggota diwariskan dari PHP native: anggota jaminan (AGTJ)
// tidak memakai peran/ketua SHU dan hanya membutuhkan foto KTP; ketua kelompok
// tidak boleh memilih ketua lain sebagai pengajak.
document.querySelectorAll('[data-member-form]').forEach((form) => {
    const category = form.querySelector('[name="kategori_anggota"]');
    const shuRole = form.querySelector('[name="kategori_shu"]');
    const groupLeader = form.querySelector('[name="ketua_kelompok_id"]');
    const code = form.dataset.memberCode || '';
    const fieldFor = (input) => input?.closest('ui-field') || input?.parentElement;
    const documentFor = (name) => form.querySelector(`[name="${name}"]`)?.closest('[data-image-upload]');

    const syncMemberForm = () => {
        const isGuarantee = category ? category.value === 'jaminan' : code.startsWith('AGTJ');
        const isLeader = shuRole?.value === 'ketua_kelompok';
        const roleField = fieldFor(shuRole);
        const leaderField = fieldFor(groupLeader);

        if (isGuarantee && shuRole) shuRole.value = 'anggota';
        if ((isGuarantee || isLeader) && groupLeader) groupLeader.value = '';
        if (roleField) roleField.hidden = isGuarantee;
        if (leaderField) leaderField.hidden = isGuarantee || isLeader;

        ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'].forEach((name) => {
            const wrapper = documentFor(name);
            const input = wrapper?.querySelector('input[type="file"]');
            const mark = wrapper?.querySelector('[data-required-mark]');
            const visible = !isGuarantee || name === 'foto_ktp';
            const needsRequired = !form.dataset.memberCode && visible;
            if (wrapper) wrapper.hidden = !visible;
            if (input) input.required = needsRequired;
            if (mark) mark.hidden = !needsRequired;
        });
    };

    category?.addEventListener('change', syncMemberForm);
    shuRole?.addEventListener('change', syncMemberForm);
    syncMemberForm();
});

document.querySelectorAll('[data-signature]').forEach((wrapper) => {
    const canvas = wrapper.querySelector('canvas');
    const input = wrapper.querySelector('input[type="file"]');
    const message = wrapper.querySelector('[data-signature-message]');
    const preview = wrapper.querySelector('[data-signature-preview]');
    const context = canvas.getContext('2d');
    let drawing = false;
    const hasSavedSignature = Boolean(preview);
    const idleMessage = hasSavedSignature
        ? 'Gambar baru akan menggantikan dan menghapus tanda tangan lama.'
        : 'Belum ada tanda tangan';
    const resize = () => {
        const bounds = canvas.getBoundingClientRect();
        canvas.width = Math.round(bounds.width * devicePixelRatio);
        canvas.height = Math.round(bounds.height * devicePixelRatio);
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.scale(devicePixelRatio, devicePixelRatio);
        context.strokeStyle = '#17383b';
        context.lineWidth = 2.5;
        context.lineCap = 'round';
        context.lineJoin = 'round';
    };
    const commitSignature = () => {
        if (!drawing) return;
        drawing = false;
        wrapper.pending = (async () => {
            try {
                if (message) message.textContent = 'Menyiapkan tanda tangan…';
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.8));
                if (!blob || !input) throw new Error('Tanda tangan belum siap. Gambar lagi lalu simpan.');
                const file = await compressImage(new File([blob], 'tanda-tangan.webp', { type: 'image/webp' }));
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
                preview?.classList.add('hidden');
                if (message) message.textContent = `Tanda tangan baru siap · ${(file.size / 1024).toFixed(0)} KB`;
            } catch (error) {
                if (input) input.value = '';
                if (message) message.textContent = error.message || 'Tanda tangan gagal disiapkan.';
            } finally {
                wrapper.pending = null;
            }
        })();
    };
    resize();
    canvas.addEventListener('pointerdown', (event) => {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        context.beginPath();
        context.moveTo(event.offsetX, event.offsetY);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) return;
        context.lineTo(event.offsetX, event.offsetY);
        context.stroke();
    });
    canvas.addEventListener('pointerup', commitSignature);
    canvas.addEventListener('pointercancel', commitSignature);
    wrapper.querySelector('[data-signature-clear]')?.addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        if (input) input.value = '';
        preview?.classList.remove('hidden');
        if (message) message.textContent = idleMessage;
    });
});

// Native date/time pickers: Flux `appearance-none` can hide the calendar affordance.
// Wrap remaining bare inputs and open the OS picker via showPicker() on interaction.
const DATE_INPUT_SELECTOR = 'input[type="date"], input[type="datetime-local"], input[type="month"], input[type="time"], input[type="week"]';

const openNativeDatePicker = (input) => {
    if (!(input instanceof HTMLInputElement) || input.disabled || input.readOnly) {
        return;
    }

    input.focus({ preventScroll: true });

    if (typeof input.showPicker === 'function') {
        try {
            input.showPicker();
        } catch {
            // Browser may reject showPicker outside a trusted gesture or when hidden.
        }
    }
};

const calendarButtonSvg = `
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true">
        <path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z" clip-rule="evenodd" />
    </svg>
`;

const enhanceDateInput = (input) => {
    if (!(input instanceof HTMLInputElement) || input.dataset.uiDateReady === '1') {
        return;
    }

    input.dataset.uiDateReady = '1';
    input.classList.add('ui-date-input');

    let wrap = input.closest('.ui-date-picker');
    if (! wrap) {
        wrap = document.createElement('div');
        wrap.className = 'ui-date-picker';
        input.parentNode?.insertBefore(wrap, input);
        wrap.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ui-date-picker-btn';
        button.setAttribute('aria-label', 'Buka kalender');
        button.tabIndex = -1;
        button.innerHTML = calendarButtonSvg;
        button.addEventListener('click', (event) => {
            event.preventDefault();
            openNativeDatePicker(input);
        });
        wrap.appendChild(button);
    }

    input.addEventListener('click', () => openNativeDatePicker(input));
};

const scanDateInputs = (root = document) => {
    root.querySelectorAll?.(DATE_INPUT_SELECTOR)?.forEach(enhanceDateInput);
};

scanDateInputs();

document.addEventListener('focusin', (event) => {
    const input = event.target?.closest?.(DATE_INPUT_SELECTOR);
    if (input) {
        enhanceDateInput(input);
    }
});

const dateObserver = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType !== Node.ELEMENT_NODE) {
                return;
            }
            if (node.matches?.(DATE_INPUT_SELECTOR)) {
                enhanceDateInput(node);
            }
            scanDateInputs(node);
        });
    }
});

if (document.body) {
    dateObserver.observe(document.body, { childList: true, subtree: true });
}

document.querySelectorAll('[data-dashboard-root]').forEach((root) => bootDashboardMotion(root));
