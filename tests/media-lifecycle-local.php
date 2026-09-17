<?php
/** Usage: php media-lifecycle-local.php <Local WP root> [--keep]. Start .tools/media-http-router.php on 8099 first. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || basename( dirname( dirname( $argv[1] ) ) ) !== 'marche-potier-test' ) { exit( "Local test site required.\n" ); }
$repo = dirname( __DIR__ ); $workspace = dirname( $repo );
define( 'WP_PLUGIN_DIR', $repo ); define( 'DISABLE_WP_CRON', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'WP_HOME', 'http://127.0.0.1:8099' ); define( 'WP_SITEURL', WP_HOME );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $argv[1] . '/wp-load.php'; require $repo . '/marche-potier.php';
use MarchePotier\{Plugin,Editions,Records,Blocks,PrivateFiles,MediaLibrary,UploadDrafts,SubmissionLock,PublicForm,CsvExport,Gallery};
Plugin::boot(); Editions::register(); Records::register(); Blocks::register();
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'upload_dir', static function ( $u ) use ( $workspace ) {
	$u['basedir'] = wp_normalize_path( $workspace . '/media-test-uploads' ); $u['baseurl'] = WP_HOME . '/media-test-uploads';
	$u['path'] = $u['basedir'] . $u['subdir']; $u['url'] = $u['baseurl'] . $u['subdir']; $u['error'] = false; return $u;
} );
$existing_media = get_posts( array( 'post_type'=>'attachment', 'post_status'=>array_values(get_post_stati()), 'posts_per_page'=>-1, 'fields'=>'ids' ) );
$run = substr( wp_generate_uuid4(), 0, 8 ); $posts = array(); $drafts = array(); $checks = 0; $user = 0; $passed = false; $keep = in_array( '--keep', $argv, true );
$temporary = $workspace . '/media-test-input-' . $run; wp_mkdir_p( $temporary );
$cookies = $temporary . '/cookies.txt';
function mc( bool $ok, string $message ): void { global $checks; if ( ! $ok ) { throw new RuntimeException( $message ); } echo 'OK MEDIA ' . ++$checks . ' : ' . $message . "\n"; }
function request_media( string $url, ?array $fields = null, bool $session = true ): array {
	global $cookies;
	$c = curl_init( $url ); curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>90 ) );
	if ( $session ) { curl_setopt_array( $c, array( CURLOPT_COOKIEFILE=>$cookies, CURLOPT_COOKIEJAR=>$cookies ) ); }
	if ( null !== $fields ) { curl_setopt( $c, CURLOPT_POSTFIELDS, $fields ); }
	$r = curl_exec( $c ); if ( false === $r ) { throw new RuntimeException( curl_error( $c ) ); }
	$n = curl_getinfo( $c, CURLINFO_HEADER_SIZE ); $code = curl_getinfo( $c, CURLINFO_RESPONSE_CODE ); wp_cache_flush();
	return array( $code, substr( $r, 0, $n ), substr( $r, $n ) );
}
function media_post( string $type, string $name ): int {
	global $posts, $run; $id = wp_insert_post( array( 'post_type'=>$type, 'post_title'=>'[TEST MEDIA ' . $run . '] ' . $name, 'post_status'=>'publish' ) ); $posts[] = $id; return $id;
}
function locked_media( callable $fn ): mixed { if ( ! SubmissionLock::acquire() ) { throw new RuntimeException( 'Lock' ); } try { return $fn(); } finally { SubmissionLock::release(); } }
function provisional( string $owner ): array {
	global $temporary; $u = wp_upload_bits( 'provisional.jpg', null, file_get_contents( $temporary . '/photo.jpg' ) );
	$id = MediaLibrary::register( $u['file'], 'image/jpeg', 'Test provisional', $owner );
	return array( 'attachment_id'=>$id, 'mime'=>'image/jpeg', 'original_name'=>'photo.jpg' );
}
try {
	foreach ( array( 'mp_rate_', 'mp_upload_rate_' ) as $prefix ) { delete_transient( $prefix . hash_hmac( 'sha256', '127.0.0.1', wp_salt() ) ); }
	$im = imagecreatetruecolor( 300, 200 ); imagefill( $im, 0, 0, imagecolorallocate( $im, 167, 94, 51 ) ); imagejpeg( $im, $temporary . '/photo.jpg' ); imagepng( $im, $temporary . '/photo.png' ); imagewebp( $im, $temporary . '/photo.webp' ); imagedestroy( $im );
	file_put_contents( $temporary . '/document.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" );
	file_put_contents( $temporary . '/fake.jpg', '<?php echo "not an image";' );
	$edition = media_post( 'mp_edition', 'Édition' );
	update_post_meta( $edition, '_mp_edition_settings', array( 'year'=>'2098', 'opens'=>'2020-01-01T00:00', 'closes'=>'2099-12-31T23:59', 'selection_public'=>false ) );
	$page = media_post( 'page', 'Formulaire' ); wp_update_post( array( 'ID'=>$page, 'post_content'=>wp_slash( '<!-- wp:' . Blocks::FORM . ' {"editionId":' . $edition . '} /-->' ) ) );
	$url = get_permalink( $page ); $endpoint = add_query_arg( 'mp_async', '1', $url );
	[$code, $headers, $html] = request_media( $url );
	file_put_contents( $workspace . '/media-test-page.html', $html );
	mc( 200 === $code && str_contains( $html, 'mp_signature' ), 'Formulaire public chargé dans WordPress' );
	$tokens = array(); foreach ( array( 'mp_edition','mp_issued','mp_random','mp_signature','mp_nonce' ) as $name ) { preg_match( '/name="' . $name . '" value="([^"]+)"/', $html, $m ); $tokens[$name] = html_entity_decode( $m[1] ?? '', ENT_QUOTES, 'UTF-8' ); }
	preg_match( '/mp_form_session=([a-f0-9]{64})/', $headers, $m ); $key = UploadDrafts::key( $m[1], $edition ); $drafts[] = $key;
	$send = static function ( $slot, $name ) use ( $tokens, $endpoint, $temporary ) { return request_media( $endpoint, $tokens + array( 'mp_upload_operation'=>'upload', 'mp_slot'=>$slot, $slot=>new CURLFile( $temporary . '/' . $name, mime_content_type( $temporary . '/' . $name ), $name ) ) ); };
	[$code,, $body] = $send( 'product1', 'fake.jpg' ); mc( 400 === $code, 'Faux JPEG refusé' );
	[$code,, $body] = $send( 'product1', 'document.pdf' ); mc( 400 === $code, 'PDF refusé dans un emplacement photo' );
	$revision = ''; $before = array();
	foreach ( array( 'product1'=>'photo.jpg', 'product2'=>'photo.png', 'product3'=>'photo.webp', 'stand'=>'photo.jpg', 'status'=>'document.pdf', 'insurance'=>'document.pdf' ) as $slot=>$name ) {
		[$code,, $body] = $send( $slot, $name ); $json = json_decode( $body, true );
		mc( 200 === $code && ! empty( $json['success'] ), 'Transfert HTTP accepté : ' . $slot );
		$revision = $json['data']['revision']; $draft = get_option( $key ); $file = $draft['files'][$slot]; $before[$slot] = $file['attachment_id'];
		mc( 'attachment' === get_post_type( $file['attachment_id'] ) && get_post_meta( $file['attachment_id'], '_mp_draft_owner', true ) === $key && empty( $file['social'] ), 'Média provisoire, propriétaire vérifié, aucune copie sociale prématurée : ' . $slot );
		[$status,, $bytes] = request_media( PrivateFiles::file_url( $file ), null, false ); mc( 200 === $status && strlen( $bytes ) > 0, 'URL du fichier accessible sans session : ' . $slot . ' (HTTP ' . $status . ', ' . PrivateFiles::file_url( $file ) . ')' );
	}
	request_media( $url ); [$code,, $body] = request_media( $endpoint, $tokens + array( 'mp_upload_operation'=>'status' ) );
	mc( count( json_decode( $body, true )['data']['files'] ) === 6, 'Rechargement : six pièces retrouvées' );
	$old = $before['product1']; $oldpath = get_attached_file( $old );
	[$code,, $body] = $send( 'product1', 'photo.png' ); $revision = json_decode( $body, true )['data']['revision']; $before['product1'] = get_option( $key )['files']['product1']['attachment_id'];
	mc( ! get_post( $old ) && ! file_exists( $oldpath ) && $before['product1'] !== $old, 'Remplacement provisoire : ancienne pièce supprimée après réception de la nouvelle' );
	$raw = array( 'identity'=>array( 'last_name'=>'Test ' . $run, 'first_name'=>'Média', 'address'=>'1 rue fictive', 'postcode'=>'00000', 'city'=>'Test', 'country'=>'France', 'phone'=>'0100000000', 'email'=>'media-' . $run . '@example.test' ), 'activity'=>array( 'presentation'=>'Essai de candidature et de médiathèque.', 'production'=>array('utilitaire'), 'technique'=>array('gres'), 'professional_status'=>'artisan', 'aaf_member'=>'no', 'association_member'=>'no', 'stand_length'=>'3' ) );
	$fields = $tokens + array( 'mp_revision'=>$revision, 'mp_photo_consent'=>'1' );
	foreach ( $raw as $group=>$values ) { foreach ( $values as $name=>$value ) { $fields['mp_record['.$group.']['.$name.']'.(is_array($value)?'[]':'')] = is_array($value)?$value[0]:$value; } }
	[$code,, $body] = request_media( $endpoint, array_replace( $fields, array( 'mp_revision'=>'obsolete' ) ) ); mc( 400 === $code, 'Révision périmée refusée sans perdre les pièces' );
	[$code,, $body] = request_media( $endpoint, $fields + array( 'mp_media_test_fail'=>'metadata' ) );
	mc( 400 === $code && get_option( $key ) && count( array_filter( $before, 'get_post' ) ) === 6, 'Échec d’enregistrement injecté : les six médias restent disponibles pour réessayer' );
	[$code,, $body] = request_media( $endpoint, $fields ); $json = json_decode( $body, true );
	mc( 200 === $code && ! empty( $json['data']['redirect'] ), 'Nouvelle tentative : candidature enregistrée sans renvoi des fichiers' );
	$app = UploadDrafts::receipt( $key ); $posts[] = $app; $posts[] = Records::data( $app )['potier_id']; $data = Records::data( $app );
	mc( $app > 0 && ! get_option( $key ), 'Brouillon effacé après validation, reçu conservé' );
	foreach ( $before as $slot=>$id ) {
		mc( $data['files'][$slot]['attachment_id'] === $id && wp_get_post_parent_id( $id ) === $app && ! get_post_meta( $id, '_mp_temporary_until', true ), 'Même média, devenu définitif et rattaché : ' . $slot );
		if ( str_starts_with( $slot, 'product' ) ) { $social = $data['files'][$slot]['social']; $info = getimagesize( PrivateFiles::path( $social ) ); mc( $info[0] === 1080 && $info[1] === 1350 && wp_get_post_parent_id( $social['attachment_id'] ) === $app, 'Copie sociale dans la médiathèque : ' . $slot ); }
	}
	[$code,, $body] = request_media( $endpoint, $fields ); mc( 200 === $code && UploadDrafts::receipt( $key ) === $app, 'Double validation idempotente' );
	mc( CsvExport::file_url( $app, 'insurance' ) === wp_get_attachment_url( $before['insurance'] ), 'CSV : URL directe du PDF' );
	foreach ( array( 'mp_private_file','mp_export_file' ) as $action ) { [$code, $h] = request_media( WP_HOME . '/wp-admin/admin-post.php?action=' . $action . '&application=' . $app . '&slot=insurance', null, false ); mc( 302 === $code && str_contains( $h, wp_get_attachment_url( $before['insurance'] ) ), 'Ancien lien redirigé sans connexion : ' . $action ); }
	mc( ! Gallery::eligible( $app ), 'Média public sans publication automatique dans la sélection' );
	// Expired temporary media and ordinary unattached media must behave differently.
	$abandoned = provisional( 'abandoned-' . $run ); $ordinary = provisional( '' ); delete_post_meta( $ordinary['attachment_id'], '_mp_temporary_until' );
	update_post_meta( $abandoned['attachment_id'], '_mp_temporary_until', time()-1 );
	$referenced = $before['status']; update_post_meta( $referenced, '_mp_temporary_until', time()-1 ); wp_update_post( array( 'ID'=>$referenced, 'post_parent'=>0 ) );
	locked_media( static fn() => MediaLibrary::cleanup() );
	mc( ! get_post( $abandoned['attachment_id'] ), 'Nettoyage : média provisoire expiré supprimé' );
	mc( null !== get_post( $ordinary['attachment_id'] ), 'Nettoyage : média ordinaire non attaché conservé' );
	mc( get_post( $referenced ) && ! get_post_meta( $referenced, '_mp_temporary_until', true ) && wp_get_post_parent_id( $referenced ) === $app, 'Finalisation interrompue : média référencé conservé et rattachement réparé' );
	$alien = get_option( $key, array() ); $foreignkey = UploadDrafts::key( 'foreign-' . $run, $edition ); $drafts[] = $foreignkey;
	update_option( $foreignkey, array( 'revision'=>'r', 'expires'=>time()+100, 'files'=>$data['files'] ), false );
	mc( is_wp_error( locked_media( static fn() => UploadDrafts::files( $foreignkey, 'r' ) ) ), 'Un autre brouillon ne peut pas revendiquer les médias validés' );
	// Real replacement via the WordPress administrator multipart form.
	$password = wp_generate_password( 28, false ); $login = 'mp_media_' . $run;
	$user = wp_insert_user( array( 'user_login'=>$login, 'user_pass'=>$password, 'user_email'=>$login.'@example.test', 'role'=>'mp_organizer' ) );
	request_media( WP_HOME . '/wp-login.php' );
	[$code,, $body] = request_media( WP_HOME . '/wp-login.php', array( 'log'=>$login, 'pwd'=>$password, 'wp-submit'=>'Log In', 'testcookie'=>'1', 'redirect_to'=>WP_HOME.'/wp-admin/' ) );
	mc( 302 === $code, 'Connexion organisateur dans WordPress' );
	[$code, $adminheaders, $html] = request_media( WP_HOME . '/wp-admin/post.php?post=' . $app . '&action=edit' );
	file_put_contents( $workspace . '/media-test-admin.html', $html );
	$adminfields = array( 'action'=>'editpost', 'post_ID'=>(string)$app, 'post_type'=>'mp_candidature', 'post_status'=>'publish' );
	foreach ( array( '_wpnonce', 'mp_record_nonce' ) as $name ) { preg_match( '/name="'.$name.'" value="([^"]+)"/', $html, $m ); $adminfields[$name] = $m[1] ?? ''; }
	mc( 200 === $code && $adminfields['mp_record_nonce'] !== '', 'Écran de modification du dossier ouvert' );
	foreach ( $data as $group=>$values ) { if ( in_array($group,array('identity','activity','internal'),true) ) { foreach($values as $name=>$value) { if(is_array($value)) { foreach($value as $i=>$v) { $adminfields['mp_record['.$group.']['.$name.']['.$i.']']=$v; } } else { $adminfields['mp_record['.$group.']['.$name.']']=(string)$value; } } } }
	$adminfields['mp_admin_status'] = new CURLFile( $temporary . '/photo.png', 'image/png', 'statut-remplace.png' );
	[$code,, $body] = request_media( WP_HOME . '/wp-admin/post.php', $adminfields );
	$newstatus = Records::data($app)['files']['status']['attachment_id'];
	mc( 302 === $code && $newstatus !== $before['status'] && wp_get_post_parent_id($newstatus)===$app && get_post($before['status']), 'Remplacement depuis l’administration : nouveau média rattaché et ancien conservé' );
	PrivateFiles::remove( $data['files'] ); mc( count( array_filter( $before, 'get_post' ) ) === 6, 'Les médias définitifs ne sont pas détruits par le nettoyage des pièces remplacées' );
	// In-place legacy import, rerun, and checked missing-file behavior.
	$legacyroot = PrivateFiles::root(); $name = bin2hex( random_bytes(24) ) . '.jpg'; copy( $temporary . '/photo.jpg', $legacyroot . '/' . $name );
	$legacy = array( 'name'=>$name, 'mime'=>'image/jpeg', 'original_name'=>'ancien.jpg' ); $hash = hash_file( 'sha256', $legacyroot . '/' . $name );
	$imported = MediaLibrary::import( $legacy ); $again = MediaLibrary::import( $legacy );
	mc( ! is_wp_error( $imported ) && $imported['attachment_id'] === $again['attachment_id'] && $hash === hash_file( 'sha256', $legacyroot . '/' . $name ), 'Migration sur place relançable, aucun doublon ni changement des octets' );
	[$migratedstatus,, $migratedbytes] = request_media(PrivateFiles::file_url($imported),null,false);
	mc(200===$migratedstatus && hash('sha256',$migratedbytes)===$hash && PrivateFiles::path($imported)!==false, 'URL et chemin du média migré valides sous Windows');
	mc( is_wp_error( MediaLibrary::import( array( 'name'=>str_repeat('f',48).'.pdf', 'mime'=>'application/pdf' ) ) ), 'Migration : fichier absent signalé sans effacer sa référence' );
	$oldapp = media_post( 'mp_candidature', 'Dossier ancien' ); update_post_meta( $oldapp, Records::META, array('files'=>array('product1'=>$legacy),'edition_id'=>$edition) );
	$limitmigration = static function( $query ) use ($oldapp) { foreach($query->get('meta_query') ?: array() as $clause) { if(is_array($clause) && ($clause['key']??'')==='_mp_media_migrated') { $query->set('post__in',array($oldapp)); } } };
	add_action('pre_get_posts',$limitmigration); $migration = MediaLibrary::migrate(); $second = MediaLibrary::migrate(); remove_action('pre_get_posts',$limitmigration);
	mc( !is_wp_error($migration) && Records::data($oldapp)['files']['product1']['attachment_id']===$imported['attachment_id'] && $second['moved']===0, 'Migration du dossier : référence remplacée, rattachement créé, deuxième passage sans doublon' );
	$expiredkey = UploadDrafts::key('expired-'.$run,$edition); $drafts[]=$expiredkey; $expiredfile=provisional($expiredkey);
	update_option($expiredkey,array('expires'=>time()-1,'files'=>array('stand'=>$expiredfile)),false);
	locked_media(static fn()=>UploadDrafts::read($expiredkey));
	mc(!get_option($expiredkey) && !get_post($expiredfile['attachment_id']), 'Expiration du brouillon : option et média provisoire supprimés');
	$legacydraftname = bin2hex(random_bytes(24)).'.jpg'; copy($temporary.'/photo.jpg',$legacyroot.'/'.$legacydraftname);
	$legacydraftkey = UploadDrafts::key('legacy-'.$run,$edition); $drafts[]=$legacydraftkey;
	update_option($legacydraftkey,array('expires'=>time()-1,'files'=>array('stand'=>array('name'=>$legacydraftname,'mime'=>'image/jpeg'))),false);
	locked_media(static fn()=>UploadDrafts::read($legacydraftkey));
	mc(!file_exists($legacyroot.'/'.$legacydraftname), 'Ancien brouillon expiré : nettoyage du fichier historique');
	// Synchronous HTML form keeps the non-JavaScript fallback working.
	$fallback = $fields; unset($fallback['mp_revision']); $fallback['mp_record[identity][email]']='fallback-'.$run.'@example.test';
	[$code,, $freshhtml] = request_media($url);
	foreach(array('mp_edition','mp_issued','mp_random','mp_signature','mp_nonce') as $name) { preg_match('/name="'.$name.'" value="([^"]+)"/',$freshhtml,$m); $fallback[$name]=html_entity_decode($m[1]??'',ENT_QUOTES,'UTF-8'); }
	foreach(array('product1','product2','product3','stand','status','insurance') as $slot) { $fallback[$slot]=new CURLFile($temporary.'/photo.jpg','image/jpeg','photo.jpg'); }
	[$code,, $body]=request_media($url,$fallback);
	mc($code===303, 'Formulaire sans JavaScript : dépôt multipart accepté');
	foreach(get_posts(array('post_type'=>'mp_candidature','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_mp_edition_id','meta_value'=>$edition)) as $id) { $posts[]=$id; if(!empty(Records::data($id)['potier_id'])) { $posts[]=Records::data($id)['potier_id']; } }
	if ( ! $keep ) { wp_delete_post( $app, true ); mc( count( array_filter( $before, 'get_post' ) ) === 6, 'Suppression définitive du dossier : médias réutilisables conservés' ); }
	file_put_contents( $workspace . '/media-test-state.json', wp_json_encode( array( 'page'=>$page, 'edition'=>$edition, 'application'=>$app, 'posts'=>$posts, 'drafts'=>$drafts, 'run'=>$run, 'user'=>$user, 'login'=>$login, 'password'=>$password ), JSON_PRETTY_PRINT ) );
	$passed = true;
	echo "SUCCÈS : $checks vérifications médias, emails interceptés.\n";
} finally {
	if ( ! $keep || ! $passed ) {
		$_POST = array(); foreach ( $drafts as $key ) { delete_option( $key ); }
		foreach ( array_reverse( array_unique( $posts ) ) as $id ) { if ( $id ) { wp_delete_post( $id, true ); } }
		require_once ABSPATH . 'wp-admin/includes/user.php'; if($user && !is_wp_error($user)) { wp_delete_user($user); }
		foreach ( get_posts( array( 'post_type'=>'attachment','post_status'=>array_values(get_post_stati()),'posts_per_page'=>-1,'fields'=>'ids' ) ) as $id ) {
			if ( in_array( $id, $existing_media, true ) ) { continue; }
			$path = wp_normalize_path( get_attached_file( $id ) ?: '' );
			if ( str_starts_with( $path, wp_normalize_path( $workspace . '/media-test-uploads/' ) ) ) { wp_delete_attachment( $id, true ); }
		}
	}
}
