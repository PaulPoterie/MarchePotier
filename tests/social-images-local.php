<?php
/** Real WordPress image tests. Requires a disposable MARCPO_IMAGE_REVIEW database and prepared colour/EXIF fixtures. */
if ( PHP_SAPI !== 'cli' || ! defined( 'MARCPO_IMAGE_REVIEW' ) || count( $argv ) !== 4 ) { exit( "Use the isolated image review bootstrap, WP root, fixture workspace and engine.\n" ); }
$repo = dirname( __DIR__ ); $workspace = realpath( $argv[2] ); $engine = $argv[3];
if ( ! $workspace || ! in_array( $engine, array( 'gd', 'imagick', 'both', 'none' ), true ) ) { exit( 1 ); }
define( 'WP_PLUGIN_DIR', $repo ); define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST'] = 'marche-potier-test.local'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $argv[1] . '/wp-load.php';
if ( 'marcpo_img261002_' !== $GLOBALS['wpdb']->prefix ) { throw new RuntimeException( 'Wrong database.' ); }
require $repo . '/marche-potier.php';
use MarchePotier\{SocialImages,MediaLibrary,PrivateFiles,Records,Editions};
Editions::register(); Records::register();
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'upload_dir', static function ( $u ) use ( $workspace, $engine ) {
    $u['basedir'] = wp_normalize_path( "$workspace/output/$engine" ); $u['baseurl'] = 'http://example.invalid/images';
    $u['path'] = $u['basedir'] . $u['subdir']; $u['url'] = $u['baseurl'] . $u['subdir']; $u['error'] = false; return $u;
} );
$count = 0; $created = array(); $application = 0;
$before = get_posts( array( 'post_type'=>'attachment', 'post_status'=>'any', 'posts_per_page'=>-1, 'fields'=>'ids' ) );
function si_check( bool $ok, string $message ): void { global $count; if ( ! $ok ) { throw new RuntimeException( $message ); } echo 'OK IMAGE ' . ++$count . ': ' . $message . "\n"; }
function si_pixel( string $path, int $x, int $y ): array {
    if ( extension_loaded( 'gd' ) ) { $image=imagecreatefromjpeg($path); $p=imagecolorsforindex($image,imagecolorat($image,$x,$y)); imagedestroy($image); return array($p['red'],$p['green'],$p['blue']); }
    $image=new Imagick($path); $p=$image->getImagePixelColor($x,$y)->getColor(); $image->clear(); return array($p['r'],$p['g'],$p['b']);
}
function si_color( string $path, int $x, int $y, array $expected ): bool {
    $actual=si_pixel($path,$x,$y); foreach($expected as $i=>$v) { if(abs($v-$actual[$i])>25) { return false; } } return true;
}
try {
    si_check( extension_loaded('gd') === in_array($engine,array('gd','both'),true), 'GD availability matches this test process' );
    si_check( extension_loaded('imagick') === in_array($engine,array('imagick','both'),true), 'Imagick availability matches this test process' );
    if ( 'none' !== $engine ) {
        $cases=array('landscape.png'=>array(1080,540), 'portrait.png'=>array(675,1350), 'small.png'=>array(300,200), 'webp.webp'=>array(800,600));
        $orders=array(1=>array(0,1,2,3),2=>array(1,0,3,2),3=>array(3,2,1,0),4=>array(2,3,0,1),5=>array(0,2,1,3),6=>array(2,0,3,1),7=>array(3,1,2,0),8=>array(1,3,0,2));
        for($i=1;$i<=8;$i++) { $cases["exif-$i.jpg"]=$i>=5?array(400,600):array(600,400); }
        $colors=array(array(220,30,30),array(30,190,30),array(30,30,220),array(220,190,30));
        foreach($cases as $name=>$size) {
            $input="$workspace/fixtures/$name"; $hash=hash_file('sha256',$input);
            $result=SocialImages::create($input); $created[]=$result['attachment_id']; $path=PrivateFiles::path($result); $info=getimagesize($path);
            si_check($info[0]===1080 && $info[1]===1350 && $info['mime']==='image/jpeg',"JPEG 1080 × 1350: $name");
            si_check(hash_file('sha256',$input)===$hash,"Original unchanged: $name");
            $x=(int)floor((1080-$size[0])/2); $y=(int)floor((1350-$size[1])/2);
            $order=preg_match('/exif-(\d)/',$name,$m)?$orders[(int)$m[1]]:array(0,1,2,3);
            foreach($order as $i=>$color) { si_check(si_color($path,(int)($x+($i%2+.5)*$size[0]/2),(int)($y+(intdiv($i,2)+.5)*$size[1]/2),$colors[$color]),"Preserved quadrant $i: $name"); }
            si_check(si_color($path,0,0,array(255,255,255)),"White frame: $name");
            if($x>12) { si_check(si_color($path,$x-10,675,array(255,255,255)),"No horizontal enlargement: $name"); }
            if($y>12) { si_check(si_color($path,540,$y-10,array(255,255,255)),"No vertical enlargement: $name"); }
            copy($path,"$workspace/output/$engine-".pathinfo($name,PATHINFO_FILENAME).'.jpg');
            wp_delete_attachment($result['attachment_id'],true);
        }
        $result=SocialImages::create("$workspace/fixtures/transparent.png"); $created[]=$result['attachment_id']; $path=PrivateFiles::path($result);
        si_check(si_color($path,350,580,array(255,255,255)) && si_color($path,540,675,$colors[0]),'Transparency composited onto white');
        wp_delete_attachment($result['attachment_id'],true);
        $native=wp_get_image_editor("$workspace/fixtures/small.png");
        si_check(get_class($native) === ('gd'===$engine?'WP_Image_Editor_GD':'WP_Image_Editor_Imagick'),'Normal editor selection restored; Imagick preferred when both exist');
        unset($native);
        $convert=static fn($formats)=>array_merge($formats,array('image/jpeg'=>'image/webp'));
        add_filter('image_editor_output_format',$convert);
        try { $result=SocialImages::create("$workspace/fixtures/small.png"); $created[]=$result['attachment_id']; si_check(getimagesize(PrivateFiles::path($result))['mime']==='image/jpeg','Social export stays JPEG when site converts thumbnails to WebP'); wp_delete_attachment($result['attachment_id'],true); }
        finally { remove_filter('image_editor_output_format',$convert); }
        si_check(false===has_filter('wp_image_editors',array(SocialImages::class,'editors')) && false===has_filter('image_editor_output_format'),'No temporary filters left after success');
        $failed=false; try { SocialImages::create("$workspace/fixtures/invalid.png"); } catch(Throwable $error) { $failed=true; }
        si_check($failed && false===has_filter('wp_image_editors',array(SocialImages::class,'editors')),'Invalid image fails cleanly and restores editor filter');
        $files=glob(wp_upload_dir()['path'].'/*');
        $reject=static fn($empty,$post)=>'attachment'===($post['post_type']??'')?true:$empty;
        add_filter('wp_insert_post_empty_content',$reject,10,2); $failed=false;
        try { SocialImages::create("$workspace/fixtures/small.png"); } catch(Throwable $error) { $failed=true; }
        finally { remove_filter('wp_insert_post_empty_content',$reject); }
        si_check($failed && glob(wp_upload_dir()['path'].'/*')===$files,'Attachment failure removes generated file');
    }
    // Fail after the original has been saved: confirmation must survive missing image support.
    $u=wp_upload_bits('original.png',null,file_get_contents("$workspace/fixtures/small.png"));
    $id=MediaLibrary::register($u['file'],'image/png','Image review original'); si_check(!is_wp_error($id),'Original attachment registered'); $created[]=$id;
    $application=wp_insert_post(array('post_type'=>'marcpo_candidature','post_status'=>'publish','post_title'=>'[IMAGE TEST]'));
    $data=array('files'=>array('product1'=>array('attachment_id'=>$id,'mime'=>'image/png')),'identity'=>array('email'=>'image@example.invalid'));
    update_post_meta($application,Records::META,$data); $hash=hash_file('sha256',$u['file']);
    add_filter('wp_image_editors','__return_empty_array');
    try { MediaLibrary::finalize($application); } finally { remove_filter('wp_image_editors','__return_empty_array'); }
    $saved=Records::data($application);
    si_check(!empty($saved['files']['product1']['social_error']) && empty($saved['files']['product1']['social']),'Missing editor marks only the optional copy as failed');
    si_check(get_post($application) && wp_get_post_parent_id($id)===$application && hash_file('sha256',$u['file'])===$hash && !get_post_meta($id,'_marcpo_temporary_until',true),'Application and original remain confirmed and unchanged');
    if('none'!==$engine) {
        MediaLibrary::finalize($application); $saved=Records::data($application); $social=$saved['files']['product1']['social']['attachment_id']??0; if($social) { $created[]=$social; }
        si_check($social && empty($saved['files']['product1']['social_error']) && wp_get_post_parent_id($social)===$application,'Retry creates a confirmed copy and clears the failure');
        MediaLibrary::finalize($application);
        si_check(Records::data($application)['files']['product1']['social']['attachment_id']===$social,'Repeated finalization does not duplicate the copy');
    }
    si_check(false===has_filter('wp_image_editors',array(SocialImages::class,'editors')),'Editor filter restored after unavailable engine');
    echo "SUCCESS: $count image checks ($engine).\n";
} finally {
    if($application) { wp_delete_post($application,true); }
    foreach(array_unique($created) as $id) { wp_delete_attachment($id,true); }
    $after=get_posts(array('post_type'=>'attachment','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids'));
    sort($before); sort($after); if($before!==$after) { throw new RuntimeException('Attachment fixtures were not cleaned.'); }
}
