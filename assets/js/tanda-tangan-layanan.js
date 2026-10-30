(function () {
    var LINE = 2.4;
    var pads = new WeakMap();

    function syncSize(canvas, state) {
        var r = canvas.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;
        var cssW = Math.max(1, r.width);
        var cssH = Math.max(1, r.height);
        var w = Math.max(1, Math.round(cssW * dpr));
        var h = Math.max(1, Math.round(cssH * dpr));
        if (canvas.width === w && canvas.height === h) {
            return;
        }
        var before = state && state.dirty ? canvas.toDataURL('image/png') : null;
        canvas.width = w;
        canvas.height = h;
        var ctx = canvas.getContext('2d');
        ctx.lineWidth = LINE * dpr;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#183f49';
        if (state) {
            state.ctx = ctx;
        }
        if (before) {
            var image = new Image();
            image.onload = function () {
                ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
            };
            image.src = before;
        }
    }

    function pointer(canvas, e) {
        var r = canvas.getBoundingClientRect();
        var w = Math.max(1, r.width);
        var h = Math.max(1, r.height);
        return [
            (e.clientX - r.left) * canvas.width / w,
            (e.clientY - r.top) * canvas.height / h
        ];
    }

    function setReady(canvas, state, ready) {
        var field = state.field;
        var wrap = state.wrap;
        var status = state.status;
        if (ready) {
            if (field) {
                field.value = canvas.toDataURL('image/png');
            }
            wrap && wrap.classList.add('has-signature');
            if (status) {
                status.classList.add('is-ready');
                status.innerHTML = '<i class="ti ti-circle-check"></i> Siap disimpan';
            }
        } else {
            if (field) {
                field.value = '';
            }
            wrap && wrap.classList.remove('has-signature');
            if (status) {
                status.classList.remove('is-ready');
                status.innerHTML = '<i class="ti ti-signature"></i> Belum diisi';
            }
        }
    }

    function bindSignature(canvas) {
        if (!canvas) {
            return null;
        }
        var existing = pads.get(canvas);
        if (existing) {
            syncSize(canvas, existing);
            return existing;
        }

        var form = canvas.closest('form');
        var target = canvas.dataset.target || 'ttd';
        var field = form && form.querySelector('[name="' + target + '"]');
        if (!field && canvas.id) {
            field = document.getElementById(target) || document.querySelector('[name="' + target + '"]');
        }
        var wrap = canvas.closest('.signature-canvas-wrap');
        var status = canvas.closest('.signature-field') && canvas.closest('.signature-field').querySelector('.signature-status');
        var state = {
            canvas: canvas,
            field: field,
            wrap: wrap,
            status: status,
            drawing: false,
            dirty: !!(field && field.value),
            ctx: null
        };

        syncSize(canvas, state);
        pads.set(canvas, state);
        canvas.dataset.uiSignReady = '1';

        if (field && field.value && field.value.indexOf('data:image') === 0) {
            var preload = new Image();
            preload.onload = function () {
                if (!state.ctx) { syncSize(canvas, state); }
                if (state.ctx) { state.ctx.drawImage(preload, 0, 0, canvas.width, canvas.height); }
                state.dirty = true;
                wrap && wrap.classList.add('has-signature');
                if (status) {
                    status.classList.add('is-ready');
                    status.innerHTML = '<i class="ti ti-circle-check"></i> Siap disimpan';
                }
            };
            preload.src = field.value;
        }

        canvas.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            syncSize(canvas, state);
            state.drawing = true;
            canvas.setPointerCapture(e.pointerId);
            var p = pointer(canvas, e);
            state.ctx.beginPath();
            state.ctx.moveTo(p[0], p[1]);
        }, { passive: false });

        canvas.addEventListener('pointermove', function (e) {
            if (!state.drawing) {
                return;
            }
            e.preventDefault();
            var p = pointer(canvas, e);
            state.ctx.lineTo(p[0], p[1]);
            state.ctx.stroke();
            state.dirty = true;
        }, { passive: false });

        function stop(e) {
            if (!state.drawing) {
                return;
            }
            state.drawing = false;
            if (canvas.hasPointerCapture && canvas.hasPointerCapture(e.pointerId)) {
                canvas.releasePointerCapture(e.pointerId);
            }
            setReady(canvas, state, true);
        }
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        canvas.addEventListener('lostpointercapture', stop);

        var clearBtn = canvas.closest('.signature-field') && canvas.closest('.signature-field').querySelector('.clear-sign, .clear-signature, .signature-clear, .reset-ttd');
        if (clearBtn && !clearBtn.dataset.uiSignClear) {
            clearBtn.dataset.uiSignClear = '1';
            clearBtn.addEventListener('click', function () {
                state.ctx.clearRect(0, 0, canvas.width, canvas.height);
                state.dirty = false;
                setReady(canvas, state, false);
            });
        }

        if (form && canvas.dataset.required !== '0' && !form.dataset.uiSignSubmit) {
            form.dataset.uiSignSubmit = '1';
            form.addEventListener('submit', function (e) {
                var required = form.querySelectorAll('canvas.signature[data-required="1"], canvas.signature-pad[data-required="1"]');
                if (!required.length && field && canvas.classList.contains('signature')) {
                    if (!field.value) {
                        e.preventDefault();
                        alert('Tanda tangan nasabah wajib diisi.');
                    }
                    return;
                }
                for (var i = 0; i < required.length; i++) {
                    var st = pads.get(required[i]);
                    var f = st && st.field;
                    if (f && !f.value) {
                        e.preventDefault();
                        alert('Tanda tangan wajib diisi.');
                        return;
                    }
                }
            });
        }

        if (typeof ResizeObserver !== 'undefined') {
            var ro = new ResizeObserver(function () {
                if (!state.drawing) {
                    syncSize(canvas, state);
                }
            });
            ro.observe(canvas);
        }

        return state;
    }

    function bindAll(root) {
        // .signature-pad di pencairan punya handler sendiri — jangan double-bind.
        (root || document).querySelectorAll('canvas.signature:not(.signature-pad)').forEach(bindSignature);
    }

    function resizeAll(root) {
        (root || document).querySelectorAll('canvas.signature:not(.signature-pad)').forEach(function (c) {
            var st = pads.get(c) || bindSignature(c);
            if (st) {
                syncSize(c, st);
            }
        });
    }

    window.SimkeuSignaturePad = {
        bind: bindSignature,
        bindAll: bindAll,
        resize: resizeAll,
        clear: function (canvas) {
            var st = pads.get(canvas);
            if (!st) {
                return;
            }
            st.ctx.clearRect(0, 0, canvas.width, canvas.height);
            st.dirty = false;
            setReady(canvas, st, false);
        },
        isDirty: function (canvas) {
            var st = pads.get(canvas);
            return !!(st && st.dirty);
        },
        toDataURL: function (canvas) {
            return canvas.toDataURL('image/png');
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        bindAll(document);
    });
    document.addEventListener('shown.bs.modal', function (e) {
        resizeAll(e.target);
        bindAll(e.target);
    });
    window.addEventListener('resize', function () {
        resizeAll(document);
    });
    window.addEventListener('orientationchange', function () {
        setTimeout(function () {
            resizeAll(document);
        }, 200);
    });
})();
