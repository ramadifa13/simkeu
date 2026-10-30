/**
 * Animasi & navigasi beranda publik SIMKEU.
 */
(function () {
    'use strict';

    var nav = document.querySelector('[data-lp-nav]');
    var toggle = document.querySelector('[data-lp-toggle]');
    var menu = document.querySelector('[data-lp-menu]');

    function closeMenu() {
        if (!menu || !toggle) return;
        menu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        var icon = toggle.querySelector('i');
        if (icon) icon.className = 'ti ti-menu-2';
    }

    if (toggle && menu) {
        toggle.addEventListener('click', function () {
            var open = menu.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            var icon = toggle.querySelector('i');
            if (icon) icon.className = open ? 'ti ti-x' : 'ti ti-menu-2';
        });
        menu.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', closeMenu);
        });
    }

    if (nav) {
        var onScroll = function () {
            nav.classList.toggle('is-scrolled', window.scrollY > 12);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // Hero stagger
    var heroItems = document.querySelectorAll('[data-lp-anim]');
    if (heroItems.length) {
        window.requestAnimationFrame(function () {
            heroItems.forEach(function (el, i) {
                window.setTimeout(function () {
                    el.classList.add('is-in');
                }, 90 + i * 110);
            });
        });
    }

    // Scroll reveal
    var reveals = document.querySelectorAll('.lp-reveal');
    if (!reveals.length) return;
    if (!('IntersectionObserver' in window)) {
        reveals.forEach(function (el) { el.classList.add('is-in'); });
        return;
    }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-in');
            io.unobserve(entry.target);
        });
    }, { threshold: 0.14, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach(function (el, i) {
        el.style.transitionDelay = (i % 4) * 70 + 'ms';
        io.observe(el);
    });
})();
