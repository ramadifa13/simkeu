<?php
/** Display the visible logo bounds without changing the uploaded source image. */
function brandLogo($filename, $prefix='') {
    $root=dirname(__DIR__);$relative='';
    foreach(['uploads/logo/','assets/uploads/logo/','admin/uploads/logo/','admin/assets/uploads/logo/'] as $dir) {
        if($filename && is_file($root.'/'.$dir.basename($filename))){$relative=$dir.basename($filename);break;}
    }
    if(!$relative)return '<span class="brand-logo" aria-hidden="true"><i class="fas fa-hand-holding-usd"></i></span>';
    static $bounds=[];$file=$root.'/'.$relative;
    if(!isset($bounds[$file])) {
        $bounds[$file]=[100,100,0,0];
        $size=@getimagesize($file);
        if($size && $size[0]<=5000 && $size[1]<=5000 && function_exists('imagecreatefrompng') && $size[2]===IMAGETYPE_PNG) {
            $im=@imagecreatefrompng($file);
            if($im){$w=$size[0];$h=$size[1];$x1=$w;$y1=$h;$x2=0;$y2=0;$step=max(1,(int)(max($w,$h)/400));
                for($y=0;$y<$h;$y+=$step)for($x=0;$x<$w;$x+=$step){$c=imagecolorat($im,$x,$y);$a=($c>>24)&127;$r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255;if($a<110 && min($r,$g,$b)<235){$x1=min($x1,$x);$x2=max($x2,$x);$y1=min($y1,$y);$y2=max($y2,$y);}}
                imagedestroy($im);
                if($x2>$x1 && $y2>$y1){$side=max($x2-$x1,$y2-$y1)*1.14;$bounds[$file]=[$w/$side*100,$h/$side*100,50-(($x1+$x2)/2)/$side*100,50-(($y1+$y2)/2)/$side*100];}
            }
        }
    }
    [$width,$height,$left,$top]=$bounds[$file];
    return '<span class="brand-logo"><img src="'.htmlspecialchars($prefix.$relative,ENT_QUOTES,'UTF-8').'" alt="Logo koperasi" style="width:'.$width.'% !important;height:'.$height.'% !important;left:'.$left.'%;top:'.$top.'%"></span>';
}
