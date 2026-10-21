document.querySelectorAll('canvas.signature').forEach(function (canvas) {
    const form = canvas.closest('form'); const field = form && form.querySelector('[name="ttd"]'); const wrap = canvas.closest('.signature-canvas-wrap'); const status = canvas.closest('.signature-field')?.querySelector('.signature-status');
    if (!field) return; const ctx = canvas.getContext('2d'); let drawing = false;
    ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#183f49';
    function point(e) { const r=canvas.getBoundingClientRect(); return [(e.clientX-r.left)*canvas.width/r.width,(e.clientY-r.top)*canvas.height/r.height]; }
    function ready(){field.value=canvas.toDataURL('image/png');wrap?.classList.add('has-signature');if(status){status.classList.add('is-ready');status.innerHTML='<i class="fas fa-check-circle"></i> Siap disimpan';}}
    canvas.addEventListener('pointerdown',function(e){drawing=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p[0],p[1]);});
    canvas.addEventListener('pointermove',function(e){if(!drawing)return;const p=point(e);ctx.lineTo(p[0],p[1]);ctx.stroke();});
    function stop(){if(!drawing)return;drawing=false;ready();} canvas.addEventListener('pointerup',stop);canvas.addEventListener('pointercancel',stop);
    form.querySelector('.clear-sign')?.addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);field.value='';wrap?.classList.remove('has-signature');if(status){status.classList.remove('is-ready');status.innerHTML='<i class="fas fa-pen-nib"></i> Belum diisi';}});
    form.addEventListener('submit',function(e){if(!field.value){e.preventDefault();alert('Tanda tangan nasabah wajib diisi.');}});
});
