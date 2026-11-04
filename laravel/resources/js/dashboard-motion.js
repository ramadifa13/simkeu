const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const easeOutCubic = (t) => 1 - (1 - t) ** 3;

export const parseMetric = (text) => {
    const raw = String(text ?? '').trim();
    if (raw === '' || raw === '—' || raw === '-') {
        return { kind: 'plain', display: raw || '—' };
    }

    if (raw.includes('%')) {
        const numeric = Number(raw.replace(/[^\d,-]/g, '').replace(',', '.'));
        return {
            kind: 'percent',
            target: Number.isFinite(numeric) ? numeric : 0,
            decimals: raw.includes(',') ? 1 : 0,
        };
    }

    if (/Rp\b/i.test(raw)) {
        const negative = /^-/.test(raw.trim());
        const digits = raw.replace(/[^\d]/g, '');
        const target = Number(digits || 0) * (negative ? -1 : 1);
        return { kind: 'money', target: Number.isFinite(target) ? target : 0, signed: /[+-]/.test(raw.trim()[0] || '') };
    }

    const cleaned = raw.replace(/\s/g, '');
    if (/^[+-]?\d[\d.]*$/.test(cleaned)) {
        const target = Number(cleaned.replace(/\./g, ''));
        return { kind: 'number', target: Number.isFinite(target) ? target : 0 };
    }

    return { kind: 'plain', display: raw };
};

export const formatMetric = (metric, value) => {
    if (metric.kind === 'plain') {
        return metric.display;
    }
    if (metric.kind === 'money') {
        const amount = `Rp ${Math.abs(Math.round(value)).toLocaleString('id-ID')}`;
        if (value < 0) return `-${amount}`;
        if (metric.signed && value >= 0) return `+${amount}`;
        return amount;
    }
    if (metric.kind === 'percent') {
        const decimals = metric.decimals ?? 1;
        return `${Number(value).toLocaleString('id-ID', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        })}%`;
    }
    return Math.round(value).toLocaleString('id-ID');
};

export const animateValue = (el, nextText, { duration = 1100 } = {}) => {
    if (!el) {
        return;
    }

    const metric = parseMetric(nextText);
    el.dataset.finalValue = nextText;

    if (prefersReducedMotion() || metric.kind === 'plain') {
        el.textContent = metric.kind === 'plain' ? metric.display : formatMetric(metric, metric.target);
        return;
    }

    const start = performance.now();
    const from = 0;
    const to = metric.target;

    const tick = (now) => {
        const progress = Math.min(1, (now - start) / duration);
        const current = from + (to - from) * easeOutCubic(progress);
        el.textContent = formatMetric(metric, current);
        if (progress < 1) {
            requestAnimationFrame(tick);
        } else {
            el.textContent = formatMetric(metric, to);
        }
    };

    requestAnimationFrame(tick);
};

export const animateCounters = (root = document) => {
    root.querySelectorAll('[data-dashboard-value], [data-dashboard-queue], [data-count-up]').forEach((el, index) => {
        const finalValue = el.dataset.finalValue || el.textContent;
        window.setTimeout(() => animateValue(el, finalValue, { duration: 900 + (index % 4) * 80 }), index * 45);
    });
};

export const animateCharts = (root = document) => {
    root.querySelectorAll('[data-chart-bar]').forEach((bar, index) => {
        const height = bar.dataset.height || '0%';
        if (prefersReducedMotion()) {
            bar.style.height = height;
            return;
        }
        bar.style.height = '0%';
        window.setTimeout(() => {
            bar.style.transition = 'height 780ms cubic-bezier(.16, 1, .3, 1)';
            bar.style.height = height;
        }, 120 + index * 70);
    });
};

export const revealDashboard = (root = document) => {
    if (prefersReducedMotion()) {
        root.querySelectorAll('[data-dash-reveal]').forEach((el) => el.classList.add('is-visible'));
        return;
    }
    root.querySelectorAll('[data-dash-reveal]').forEach((el, index) => {
        el.style.setProperty('--dash-delay', `${Math.min(index * 55, 420)}ms`);
        requestAnimationFrame(() => el.classList.add('is-visible'));
    });
};

export const bootDashboardMotion = (root) => {
    if (!root) {
        return;
    }
    revealDashboard(root);
    animateCounters(root);
    animateCharts(root);
};
