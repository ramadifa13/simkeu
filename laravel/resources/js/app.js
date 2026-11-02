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
