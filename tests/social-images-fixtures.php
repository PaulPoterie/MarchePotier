<?php
if ( PHP_SAPI !== 'cli' || ! defined( 'MARCPO_IMAGE_REVIEW' ) ) { exit( 1 ); }
$workspace = realpath( $argv[1] ?? '' );
if ( ! $workspace || ! extension_loaded( 'gd' ) ) { exit( "Use GD and an existing disposable workspace.\n" ); }
$dir = $workspace . '/fixtures';
if ( is_dir( $dir ) ) { exit( "Fixtures already exist; refusing to overwrite them.\n" ); }
mkdir( $dir );
foreach ( array( 'landscape'=>array(1600,800), 'portrait'=>array(800,1600), 'small'=>array(300,200), 'webp'=>array(800,600), 'exif'=>array(600,400) ) as $name=>$size ) {
    $im=imagecreatetruecolor(...$size);
    $colors=array(array(220,30,30),array(30,190,30),array(30,30,220),array(220,190,30));
    foreach($colors as $i=>$rgb) { imagefilledrectangle($im,($i%2)*($size[0]/2),(intdiv($i,2))*($size[1]/2),($i%2+1)*($size[0]/2)-1,(intdiv($i,2)+1)*($size[1]/2)-1,imagecolorallocate($im,...$rgb)); }
    if('webp'===$name) { imagewebp($im,"$dir/webp.webp",100); }
    elseif('exif'===$name) {
        ob_start(); imagejpeg($im,null,100); $jpeg=ob_get_clean();
        for($o=1;$o<=8;$o++) {
            $exif="Exif\0\0II".pack('vV',42,8).pack('v',1).pack('vvV',0x112,3,1).pack('v',$o)."\0\0".pack('V',0);
            file_put_contents("$dir/exif-$o.jpg",substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
        }
    } else { imagepng($im,"$dir/$name.png"); }
    imagedestroy($im);
}
$im=imagecreatetruecolor(400,200); imagealphablending($im,false); imagesavealpha($im,true);
imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));
imagefilledrectangle($im,100,50,299,149,imagecolorallocatealpha($im,220,30,30,0));
imagepng($im,"$dir/transparent.png"); imagedestroy($im);
file_put_contents("$dir/invalid.png",'not an image');
echo "Fixtures generated.\n";
