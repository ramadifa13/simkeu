/* Fit the visible mark when an uploaded PNG contains a large empty canvas.
   Only layout styles change; the uploaded image is never rewritten. */
(function(){
    function fit(img){
        if(!img.complete || !img.naturalWidth)return;
        try{
            const scale=Math.min(1,400/Math.max(img.naturalWidth,img.naturalHeight));
            const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(img.naturalWidth*scale));canvas.height=Math.max(1,Math.round(img.naturalHeight*scale));
            const ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(img,0,0,canvas.width,canvas.height);const data=ctx.getImageData(0,0,canvas.width,canvas.height).data;
            let x1=canvas.width,y1=canvas.height,x2=-1,y2=-1;
            for(let y=0;y<canvas.height;y++)for(let x=0;x<canvas.width;x++){const i=(y*canvas.width+x)*4;if(data[i+3]>35 && Math.min(data[i],data[i+1],data[i+2])<235){x1=Math.min(x1,x);y1=Math.min(y1,y);x2=Math.max(x2,x);y2=Math.max(y2,y);}}
            if(x2>x1 && y2>y1){const side=Math.max(x2-x1+1,y2-y1+1)*1.14;img.style.setProperty('width',canvas.width/side*100+'%','important');img.style.setProperty('height',canvas.height/side*100+'%','important');img.style.left=(50-(x1+x2+1)/2/side*100)+'%';img.style.top=(50-(y1+y2+1)/2/side*100)+'%';}
        }catch(e){/* Original image remains visible if a source cannot be sampled. */}
    }
    function init(){document.querySelectorAll('.brand-logo img').forEach(img=>{if(img.complete)fit(img);else img.addEventListener('load',()=>fit(img),{once:true});});}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
