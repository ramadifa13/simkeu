const shell = document.querySelector('[data-portal-shell]');
const mobileNavigation = document.querySelector('[data-mobile-nav]');
const mobileBackdrop = document.querySelector('[data-mobile-nav-backdrop]');

document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const isCollapsed = shell?.classList.toggle('sidebar-collapsed');

        if (isCollapsed) {
            document.querySelectorAll('[data-sidebar] details').forEach((group) => {
                group.open = false;
            });
        }

        document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
            toggle.setAttribute('aria-expanded', String(!isCollapsed));
        });

        localStorage.setItem('simkeu-sidebar-collapsed', String(isCollapsed));
    });
});

if (localStorage.getItem('simkeu-sidebar-collapsed') === 'true') {
    shell?.classList.add('sidebar-collapsed');
    document.querySelectorAll('[data-sidebar] details').forEach((group) => {
        group.open = false;
    });
}

document.querySelector('[data-sidebar]')?.addEventListener('click', (event) => {
    if (!shell?.classList.contains('sidebar-collapsed')) {
        return;
    }

    if (!event.target.closest('a, summary')) {
        return;
    }

    shell.classList.remove('sidebar-collapsed');
    localStorage.setItem('simkeu-sidebar-collapsed', 'false');
    document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', 'true');
    });
});

const toggleMobileNavigation = () => {
    const isOpen = mobileNavigation?.classList.toggle('translate-x-0');
    mobileNavigation?.classList.toggle('-translate-x-full', !isOpen);
    mobileBackdrop?.classList.toggle('hidden', !isOpen);
    document.querySelectorAll('[data-mobile-nav-toggle]').forEach((toggle) => toggle.setAttribute('aria-expanded', String(isOpen)));
};

document.querySelectorAll('[data-mobile-nav-toggle]').forEach((button) => button.addEventListener('click', toggleMobileNavigation));
mobileBackdrop?.addEventListener('click', toggleMobileNavigation);

const positionActionMenu = (trigger, popover) => {
    const bounds = trigger.getBoundingClientRect();
    const width = popover.offsetWidth;
    const height = popover.offsetHeight;
    popover.style.left = `${Math.max(12, Math.min(window.innerWidth - width - 12, bounds.right - width))}px`;
    popover.style.top = `${bounds.bottom + height + 8 > window.innerHeight ? Math.max(12, bounds.top - height - 8) : bounds.bottom + 8}px`;
};

const openActionPopovers = () => document.querySelectorAll('[data-action-popover]:popover-open');

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-action-trigger]');
    if (!trigger) return;
    const popover = trigger.parentElement.querySelector('[data-action-popover]');
    if (!popover) return;
    if (popover.matches(':popover-open')) {
        popover.hidePopover();
    } else {
        popover.showPopover();
        positionActionMenu(trigger, popover);
    }
});

document.addEventListener('toggle', (event) => {
    if (!event.target.matches?.('[data-action-popover]')) return;
    const trigger = event.target.parentElement.querySelector('[data-action-trigger]');
    trigger?.setAttribute('aria-expanded', String(event.target.matches(':popover-open')));
}, true);

const repositionActionPopovers = () => openActionPopovers().forEach((popover) => {
    const trigger = popover.parentElement.querySelector('[data-action-trigger]');
    if (trigger) positionActionMenu(trigger, popover);
});
window.addEventListener('resize', repositionActionPopovers);
window.addEventListener('scroll', repositionActionPopovers, true);

const compressImage = async (file) => {
    const bitmap = await createImageBitmap(file);
    let scale = Math.min(1, 2200 / Math.max(bitmap.width, bitmap.height));
    let quality = 0.86;
    let result;
    do {
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        result = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', quality));
        if (result.size <= 1024 * 1024) break;
        if (quality > 0.48) quality -= 0.12;
        else scale *= 0.75;
    } while (scale > 0.08);
    bitmap.close();
    if (!result || result.size > 1024 * 1024) throw new Error('Gambar tidak dapat dipadatkan hingga 1 MB.');
    return new File([result], `${file.name.replace(/\.[^.]+$/, '')}.webp`, { type: 'image/webp' });
};

document.querySelectorAll('[data-image-upload]').forEach((wrapper) => {
    const input = wrapper.querySelector('input[type="file"]');
    const camera = wrapper.querySelector('[data-camera-input]');
    const message = wrapper.querySelector('[data-upload-message]');
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
        const file = input.files?.[0];
        if (!file || !file.type.startsWith('image/')) return;
        if (message) message.textContent = 'Mengoptimalkan gambar…';
        wrapper.optimizing = (async () => { try {
            const optimized = await compressImage(file);
            const transfer = new DataTransfer();
            transfer.items.add(optimized);
            input.files = transfer.files;
            if (message) message.textContent = `${optimized.name} · ${(optimized.size / 1024).toFixed(0)} KB, siap disimpan`;
        } catch (error) {
            input.value = '';
            if (message) message.textContent = error.message;
        } finally { wrapper.optimizing = null; } })();
    });
});

document.querySelectorAll('form:has([data-image-upload])').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        const pending = [...form.querySelectorAll('[data-image-upload]')].map((wrapper) => wrapper.optimizing).filter(Boolean);
        if (!pending.length) return;
        event.preventDefault();
        await Promise.allSettled(pending);
        form.requestSubmit(event.submitter || undefined);
    });
});

document.querySelectorAll('[data-signature]').forEach((wrapper) => {
    const canvas = wrapper.querySelector('canvas');
    const input = wrapper.querySelector('input[type="file"]');
    const context = canvas.getContext('2d');
    let drawing = false;
    const resize = () => {
        const bounds = canvas.getBoundingClientRect();
        canvas.width = Math.round(bounds.width * devicePixelRatio);
        canvas.height = Math.round(bounds.height * devicePixelRatio);
        context.scale(devicePixelRatio, devicePixelRatio);
        context.strokeStyle = '#17383b';
        context.lineWidth = 2.5;
        context.lineCap = 'round';
        context.lineJoin = 'round';
    };
    resize();
    canvas.addEventListener('pointerdown', (event) => { drawing = true; canvas.setPointerCapture(event.pointerId); context.beginPath(); context.moveTo(event.offsetX, event.offsetY); });
    canvas.addEventListener('pointermove', (event) => { if (drawing) { context.lineTo(event.offsetX, event.offsetY); context.stroke(); } });
    canvas.addEventListener('pointerup', async () => {
        if (!drawing) return;
        drawing = false;
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', .8));
        if (!blob || !input) return;
        const file = await compressImage(new File([blob], 'tanda-tangan.webp', { type: 'image/webp' }));
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        wrapper.querySelector('[data-signature-message]').textContent = `Tanda tangan siap · ${(file.size / 1024).toFixed(0)} KB`;
    });
    wrapper.querySelector('[data-signature-clear]')?.addEventListener('click', () => { context.clearRect(0, 0, canvas.width, canvas.height); if (input) input.value = ''; wrapper.querySelector('[data-signature-message]').textContent = 'Belum ada tanda tangan'; });
});
