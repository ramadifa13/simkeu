document.querySelectorAll('canvas.signature').forEach(function (canvas) {
    const ctx = canvas.getContext('2d');
    const field = canvas.closest('form').querySelector('[name="ttd"]');
    let drawing = false, marked = false;
    ctx.lineWidth = 2; ctx.lineCap = 'round';
    function point(e) { const r=canvas.getBoundingClientRect(); return [(e.clientX-r.left)*canvas.width/r.width,(e.clientY-r.top)*canvas.height/r.height]; }
    canvas.addEventListener('pointerdown',function(e){drawing=true; canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p[0],p[1]);});
    canvas.addEventListener('pointermove',function(e){if(!drawing)return; const p=point(e);ctx.lineTo(p[0],p[1]);ctx.stroke();marked=true;});
    function stop(){drawing=false;if(marked)field.value=canvas.toDataURL('image/png');}
    canvas.addEventListener('pointerup',stop);canvas.addEventListener('pointercancel',stop);
    canvas.closest('form').querySelector('.clear-sign').addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);field.value='';marked=false;});
    canvas.closest('form').addEventListener('submit',function(e){if(!field.value){e.preventDefault();alert('Tanda tangan nasabah wajib diisi.');}});
});
