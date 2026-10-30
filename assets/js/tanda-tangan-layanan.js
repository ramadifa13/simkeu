/**
 * Signature pad bersama SIMKEU.
 * Semua TTD diekspor JPEG (bukan PNG) agar storage tidak membengkak; max 1 MB.
 */
(function () {
    'use strict';
    var LINE = 2.4;
    var MAX_BYTES = 1024 * 1024;
    var MAX_EXPORT_W = 700;
    var JPEG_QUALITY = 0.82;
    var pads = new WeakMap();

    function exportCanvas(canvas, type, quality) {
        var w = canvas.width;
        var h = canvas.height;
        var src = canvas;
        if (w > MAX_EXPORT_W) {
            var ratio = MAX_EXPORT_W / w;
            src = document.createElement('canvas');
            src.width = Math.max(1, Math.round(w * ratio));
            src.height = Math.max(1, Math.round(h * ratio));
            src.getContext('2d').drawImage(canvas, 0, 0, src.width, src.height);
        }
        // Latar putih: JPEG tidak punya alpha; goresan hitam tetap terbaca.
        var out = document.createElement('canvas');
        out.width = src.width;
        out.height = src.height;
        var ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(src, 0, 0);
        return { canvas: out, type: type || 'image/jpeg', quality: quality == null ? JPEG_QUALITY : quality };
    }

    function toJpegDataURL(canvas, quality) {
        var ex = exportCanvas(canvas, 'image/jpeg', quality);
        var q = ex.quality;
        var url = ex.canvas.toDataURL('image/jpeg', q);
        // Turunkan kualitas bila masih > 1 MB (jarang untuk canvas 700px).
        while (url.length * 0.75 > MAX_BYTES && q > 0.4) {
            q -= 0.08;
            url = ex.canvas.toDataURL('image/jpeg', q);
        }
        return url;
    }

    function toJpegBlob(canvas, quality) {
        return new Promise(function (resolve) {
            var ex = exportCanvas(canvas, 'image/jpeg', quality);
            var q = ex.quality;
            function tryBlob(qq) {
                ex.canvas.toBlob(function (blob) {
                    if (blob && blob.size > MAX_BYTES && qq > 0.4) {
                        tryBlob(Math.max(0.4, qq - 0.08));
                        return;
                    }
                    resolve(blob);
                }, 'image/jpeg', qq);
            }
            tryBlob(q);
        });
    }

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
        var before = state && state.dirty ? toJpegDataURL(canvas) : null;
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
                field.value = toJpegDataURL(canvas);
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
                // Re-encode ke JPEG ringkas saat preload (PNG lama → JPEG baru).
                if (field) {
                    field.value = toJpegDataURL(canvas);
                }
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
                // Pastikan semua pad di form ini tersimpan sebagai JPEG ringkas sebelum POST.
                form.querySelectorAll('canvas.signature:not(.signature-pad), canvas.signature-pad').forEach(function (c) {
                    var st = pads.get(c);
                    if (st && st.dirty && st.field) {
                        st.field.value = toJpegDataURL(c);
                    }
                });
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

    /**
     * Tempel blob JPEG ke input file tersembunyi (untuk upload multipart).
     */
    async function attachJpegFile(form, canvas, fieldName) {
        var blob = await toJpegBlob(canvas);
        if (!blob) {
            throw new Error('Tanda tangan gagal dibuat.');
        }
        if (blob.size > MAX_BYTES) {
            throw new Error('Tanda tangan melebihi 1 MB.');
        }
        var input = form.querySelector('input[name="' + fieldName + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'file';
            input.name = fieldName;
            input.className = 'd-none';
            input.accept = 'image/jpeg';
            form.appendChild(input);
        }
        var dt = new DataTransfer();
        dt.items.add(new File([blob], fieldName.replace(/[^\w.-]+/g, '_') + '.jpg', { type: 'image/jpeg' }));
        input.files = dt.files;
        return blob;
    }

    window.SimkeuSignaturePad = {
        MAX_BYTES: MAX_BYTES,
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
            return toJpegDataURL(canvas);
        },
        toJpegDataURL: toJpegDataURL,
        toJpegBlob: toJpegBlob,
        attachJpegFile: attachJpegFile
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
