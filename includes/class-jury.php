<?php
/** Affectation des votants et accès aux dossiers, édition par édition. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Jury {
	public const META = '_mp_jury_settings';
	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'install' ) );
		add_action( 'add_meta_boxes_mp_edition', static function () {
			add_meta_box( 'mp-jury', 'Organisateurs et votes', array( self::class, 'box' ), 'mp_edition', 'normal', 'high' );
		} );
		add_action( 'save_post_mp_edition', array( self::class, 'save' ), 20 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_enqueue_scripts', static function () {
			$screen = get_current_screen();
			if ( $screen && 'mp_edition' === $screen->post_type && 'post' === $screen->base ) {
				wp_enqueue_script( 'mp-jury', plugins_url( '../assets/jury.js', __FILE__ ), array(), '0.19.0', true );
				wp_enqueue_style( 'mp-jury', plugins_url( '../assets/jury.css', __FILE__ ), array(), '0.19.0' );
			}
		} );
	}
	public static function install(): void {
		if ( ! current_user_can( 'manage_options' ) || '1' === get_option( 'mp_jury_permissions_version' ) ) { return; }
		add_role( 'mp_juror', 'Organisateur votant', array( 'read' => true, 'mp_access_market' => true, 'mp_review_applications' => true ) );
		foreach ( array( 'administrator', 'mp_organizer' ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) { return; }
			$role->add_cap( 'mp_review_applications' );
			$role->add_cap( 'mp_manage_jury' );
		}
		update_option( 'mp_jury_permissions_version', '1', false );
		wp_get_current_user()->get_role_caps();
	}
	public static function settings( int $edition ): array {
		$data = get_post_meta( $edition, self::META, true );
		return array_merge( array( 'mode' => 'simple', 'closed' => false, 'members' => array(), 'revision' => '' ), is_array( $data ) ? $data : array() );
	}
	public static function multiple( int $edition ): bool { return 'multiple' === self::settings( $edition )['mode']; }
	public static function can_review(): bool { return current_user_can( 'mp_manage_applications' ) || current_user_can( 'mp_review_applications' ); }
	public static function members( int $edition ): array {
		return array_filter( self::settings( $edition )['members'], static fn( $member, $id ) => ! empty( $member['active'] ) && (bool) get_userdata( $id ), ARRAY_FILTER_USE_BOTH );
	}
	public static function can_view_edition( int $edition ): bool {
		if ( 'mp_edition' !== get_post_type( $edition ) || ! in_array( get_post_status( $edition ), array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) { return false; }
		return current_user_can( 'mp_manage_applications' ) || ( self::can_review() && isset( self::members( $edition )[ get_current_user_id() ] ) );
	}
	public static function can_view_application( int $id ): bool {
		if ( 'mp_candidature' !== get_post_type( $id ) || ! in_array( get_post_status( $id ), array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) { return false; }
		return current_user_can( 'mp_manage_applications' ) || self::can_view_edition( (int) ( Records::data( $id )['edition_id'] ?? 0 ) );
	}
	public static function edition_ids(): array {
		$ids = get_posts( array( 'post_type' => 'mp_edition', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
		return array_values( array_filter( array_map( 'intval', $ids ), array( self::class, 'can_view_edition' ) ) );
	}
	public static function box( \WP_Post $post ): void {
		if ( ! current_user_can( 'mp_manage_jury' ) ) { echo '<p>La gestion des organisateurs est réservée au responsable.</p>'; return; }
		$data = self::settings( $post->ID );
		wp_nonce_field( 'mp_jury_' . $post->ID, 'mp_jury_nonce' );
		echo '<input type="hidden" name="mp_jury[revision]" value="' . esc_attr( $data['revision'] ) . '">';
		echo '<p><label for="mp-jury-mode"><strong>Mode de sélection</strong></label> <select id="mp-jury-mode" name="mp_jury[mode]"><option value="simple" ' . selected( $data['mode'], 'simple', false ) . '>Simple — sélection directe</option><option value="multiple" ' . selected( $data['mode'], 'multiple', false ) . '>Votes multiples — notes de 0 à 5</option></select></p>';
		echo '<p><label><input type="checkbox" name="mp_jury[closed]" value="1" ' . checked( $data['closed'], true, false ) . '> Votes clôturés (notes visibles, modifications désactivées)</label></p>';
		echo '<p>Les responsables conservent la décision finale. Chaque votant accède uniquement aux éditions auxquelles il est affecté, voit les autres notes et modifie seulement la sienne. Ajoutez aussi votre propre compte si vous souhaitez voter.</p>';
		echo '<div class="mp-jury-scroll" role="region" aria-label="Tableau des organisateurs, défilement horizontal" tabindex="0"><table class="widefat" id="mp-jury-members"><thead><tr><th>Nom</th><th>Email</th><th>Accès</th><th>Invitation</th></tr></thead><tbody>';
		foreach ( $data['members'] as $uid => $member ) { self::member_row( (string) $uid, $member, $uid ); }
		echo '</tbody></table></div><p><button type="button" class="button" id="mp-jury-add">Ajouter un organisateur</button></p><template id="mp-jury-template">';
		self::member_row( '__INDEX__', array( 'name' => '', 'active' => true ), 0 );
		echo '</template><p class="description">Enregistrez l’édition pour appliquer les changements. Les nouveaux comptes reçoivent une invitation au nom de l’édition pour choisir leur mot de passe. Chaque votant se connecte avec son adresse email et son mot de passe. Pour un compte existant, cochez « Envoyer une invitation » si nécessaire ; son mot de passe est conservé. Les invitations ne sont pas répétées à chaque enregistrement.</p>';
		echo '<p class="description">Désactiver un organisateur conserve ses anciennes notes, visibles dans les dossiers mais exclues du total. Le réactiver réintègre ses notes. Le mode simple conserve les votes et masque leur affichage.</p>';
	}
	private static function member_row( string $index, array $member, int $uid ): void {
		$user = $uid ? get_userdata( $uid ) : false;
		$prefix = 'mp_jury[members][' . $index . ']';
		echo '<tr><td><input type="hidden" name="' . esc_attr( $prefix . '[user_id]' ) . '" value="' . esc_attr( $uid ) . '"><input aria-label="Nom de l’organisateur" type="text" maxlength="120" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $member['name'] ) . '"></td>';
		echo '<td><input aria-label="Email de l’organisateur" type="email" name="' . esc_attr( $prefix . '[email]' ) . '" value="' . esc_attr( $user ? $user->user_email : '' ) . '"' . ( $uid ? ' readonly' : '' ) . '></td>';
		echo '<td><label><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="1" ' . checked( ! empty( $member['active'] ) && ( ! $uid || $user ), true, false ) . '> Actif</label>' . ( $uid && ! $user ? ' — compte supprimé' : '' ) . '</td>';
		echo '<td><label><input type="checkbox" name="' . esc_attr( $prefix . '[invite]' ) . '" value="1"> Envoyer une invitation</label>';
		if ( isset( $member['invitation'] ) ) { echo '<br><small>' . esc_html( $member['invitation'] ) . '</small>'; }
		if ( ! $uid ) { echo ' <button type="button" class="button-link mp-jury-remove">Retirer</button>'; }
		echo '</td></tr>';
	}
	/** Tous les champs sont validés avant la création de comptes. Appel sous verrou. */
	public static function configure( int $edition, array $raw ): true|\WP_Error {
		if ( ! current_user_can( 'mp_manage_jury' ) || ! current_user_can( 'mp_manage_editions' ) || 'mp_edition' !== get_post_type( $edition ) ) { return new \WP_Error( 'access', 'Accès refusé.' ); }
		$old = self::settings( $edition );
		if ( ( $raw['revision'] ?? null ) !== $old['revision'] ) { return new \WP_Error( 'conflict', 'Les organisateurs ont changé depuis l’ouverture de cette page. Rechargez l’édition avant de recommencer.' ); }
		if ( ! in_array( $raw['mode'] ?? null, array( 'simple', 'multiple' ), true ) || ! in_array( $raw['closed'] ?? '0', array( '0', '1' ), true ) || ! is_array( $raw['members'] ?? array() ) || count( $raw['members'] ?? array() ) > 100 ) { return new \WP_Error( 'invalid', 'Réglages de vote invalides (100 organisateurs maximum).' ); }
		$rows = array(); $emails = array();
		foreach ( $raw['members'] ?? array() as $row ) {
			if ( ! is_array( $row ) ) { return new \WP_Error( 'invalid', 'Organisateur invalide.' ); }
			foreach ( array( 'name', 'email', 'user_id', 'active', 'invite' ) as $key ) { if ( isset( $row[ $key ] ) && ! is_string( $row[ $key ] ) ) { return new \WP_Error( 'invalid', 'Organisateur invalide.' ); } }
			$uid = $row['user_id'] ?? '0';
			if ( ! ctype_digit( $uid ) || ! in_array( $row['active'] ?? '0', array( '0', '1' ), true ) || ! in_array( $row['invite'] ?? '0', array( '0', '1' ), true ) ) { return new \WP_Error( 'invalid', 'Organisateur invalide.' ); }
			$uid = (int) $uid; $name = sanitize_text_field( $row['name'] ?? '' ); $email = trim( $row['email'] ?? '' );
			if ( ! $uid && '' === $name && '' === $email ) { continue; }
			if ( $uid && ! isset( $old['members'][ $uid ] ) ) { return new \WP_Error( 'invalid', 'Compte organisateur inconnu. Ajoutez-le avec son email.' ); }
			if ( $uid && ! get_userdata( $uid ) ) { continue; }
			if ( '' === $name || strlen( $name ) > 480 || ! is_email( $email ) || isset( $emails[ strtolower( $email ) ] ) ) { return new \WP_Error( 'invalid', 'Indiquez un nom et un email valide et unique pour chaque organisateur.' ); }
			if ( $uid && strcasecmp( get_userdata( $uid )->user_email, $email ) !== 0 ) { return new \WP_Error( 'invalid', 'L’email du compte a changé. Rechargez l’édition.' ); }
			$emails[ strtolower( $email ) ] = true;
			$rows[] = array( 'user_id' => $uid, 'email' => $email, 'name' => $name, 'active' => '1' === ( $row['active'] ?? '0' ), 'invite' => '1' === ( $row['invite'] ?? '0' ) );
		}
		$data = array( 'mode' => $raw['mode'], 'closed' => '1' === ( $raw['closed'] ?? '0' ), 'members' => $old['members'], 'revision' => wp_generate_uuid4() );
		foreach ( $data['members'] as &$member ) { $member['active'] = false; } unset( $member );
		$invitations = array();
		foreach ( $rows as $row ) {
			$uid = $row['user_id'] ?: (int) email_exists( $row['email'] ); $created = false;
			if ( ! $uid ) {
				if ( ! $row['active'] ) { continue; }
				$uid = wp_insert_user( array( 'user_login' => 'jury_' . wp_generate_password( 16, false ), 'user_email' => $row['email'], 'display_name' => $row['name'], 'user_pass' => wp_generate_password( 32, true ), 'role' => 'mp_juror' ) );
				if ( is_wp_error( $uid ) ) { return new \WP_Error( 'account', 'Création du compte impossible : ' . $uid->get_error_message() ); }
				$created = true;
			}
			$data['members'][ $uid ] = array_merge( $old['members'][ $uid ] ?? array(), array( 'name' => $row['name'], 'active' => $row['active'] ) );
			if ( $row['active'] ) {
				$user = get_userdata( $uid );
				// Ces deux droits ouvrent les écrans ; chaque dossier vérifie ensuite l’affectation.
				$user->add_cap( 'mp_access_market' ); $user->add_cap( 'mp_review_applications' );
				if ( $created || $row['invite'] ) { $invitations[ $uid ] = $created; }
			}
		}
		update_post_meta( $edition, self::META, wp_slash( $data ) );
		if ( self::settings( $edition ) !== $data ) { return new \WP_Error( 'storage', 'Organisateurs non enregistrés. Réessayez.' ); }
		foreach ( $invitations as $uid => $created ) {
			$status = 'Invitation confiée au service d’envoi';
			$failed = static function () use ( &$status ) { $status = 'Échec de l’invitation — cochez pour réessayer'; };
			$intercepted = static function ( $pre ) use ( $failed ) { if ( false === $pre ) { $failed(); } return $pre; };
			add_action( 'wp_mail_failed', $failed );
			add_filter( 'pre_wp_mail', $intercepted, PHP_INT_MAX );
			try {
				if ( ! self::send_invitation( $edition, $uid, $created ) ) { $failed(); }
			} catch ( \Throwable $error ) { $failed(); }
			remove_action( 'wp_mail_failed', $failed );
			remove_filter( 'pre_wp_mail', $intercepted, PHP_INT_MAX );
			$data['members'][ $uid ]['invitation'] = $status;
		}
		if ( $invitations ) { update_post_meta( $edition, self::META, wp_slash( $data ) ); }
		return true;
	}
	/** Invitation propre au jury ; les liens de mot de passe restent gérés par WordPress. */
	private static function send_invitation( int $edition, int $uid, bool $created ): bool {
		$user = get_userdata( $uid );
		if ( ! $user ) { return false; }
		$title = sanitize_text_field( wp_specialchars_decode( get_the_title( $edition ), ENT_QUOTES ) );
		if ( '' === $title ) { $title = 'Marché Potier'; }
		$name = self::settings( $edition )['members'][ $uid ]['name'] ?? $user->display_name;
		$destination = add_query_arg( array( 'page' => 'mp-gestion', 'mp_edition' => $edition ), admin_url( 'admin.php' ) );
		$login_url = wp_login_url( $destination );
		$action_url = $login_url;
		$action_label = 'Accéder aux candidatures';
		$instructions = 'Vous avez déjà un compte sur ce site. Connectez-vous avec votre adresse email et votre mot de passe habituel. Votre mot de passe est conservé.';
		if ( $created ) {
			$key = get_password_reset_key( $user );
			if ( is_wp_error( $key ) ) { return false; }
			$action_url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
			$action_label = 'Choisir mon mot de passe';
			$instructions = 'Votre compte de votant est prêt. Choisissez votre mot de passe avec le bouton ci-dessous, puis connectez-vous avec votre adresse email et ce mot de passe.';
		}
		$message = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f6f3ef;color:#292524;font-family:Arial,sans-serif;font-size:16px;line-height:1.6">';
		$message .= '<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:24px 12px"><table role="presentation" style="width:100%;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e7e0d8;border-collapse:collapse"><tr><td style="padding:28px">';
		$message .= '<p style="margin:0;color:#76533c;font-size:13px;font-weight:bold">MARCHÉ POTIER · INVITATION AU JURY</p><h1 style="margin:8px 0 24px;font-size:24px;line-height:1.3">' . esc_html( $title ) . '</h1>';
		$message .= '<p>Bonjour ' . esc_html( $name ) . ',</p><p>Vous êtes invité à rejoindre les organisateurs de cette édition pour examiner les candidatures et participer aux votes.</p>';
		$message .= '<p>' . esc_html( $instructions ) . '</p><p style="padding:16px;background:#f6f3ef">Votre identifiant de connexion est votre adresse email :<br><strong style="overflow-wrap:anywhere">' . esc_html( $user->user_email ) . '</strong></p>';
		$message .= '<p style="margin:28px 0"><a href="' . esc_url( $action_url ) . '" style="display:inline-block;background:#76533c;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;font-weight:bold">' . esc_html( $action_label ) . '</a></p>';
		if ( $created ) { $message .= '<p>Une fois votre mot de passe choisi : <a href="' . esc_url( $login_url ) . '">accéder aux candidatures de l’édition</a>.</p>'; }
		$message .= '<p>Dans <strong>Gestion des candidatures → Examiner</strong>, vous pouvez consulter les dossiers et, lorsque les votes sont ouverts, attribuer votre note de 0 à 5. Vous voyez les notes des autres organisateurs et pouvez modifier uniquement la vôtre.</p>';
		$message .= '<p style="margin-top:24px;font-size:14px">Mot de passe oublié ou lien expiré ? <a href="' . esc_url( wp_lostpassword_url( $destination ) ) . '">Demander un nouveau lien</a> en indiquant votre adresse email.</p>';
		$message .= '</td></tr></table></td></tr></table></body></html>';
		return wp_mail( $user->user_email, 'Invitation au jury — ' . $title, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
	public static function save( int $edition ): void {
		if ( wp_is_post_revision( $edition ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'mp_manage_jury' ) ) { return; }
		$nonce = $_POST['mp_jury_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'mp_jury_' . $edition ) ) { return; }
		$locked = SubmissionLock::acquire();
		try {
			$result = $locked && is_array( $_POST['mp_jury'] ?? null ) ? self::configure( $edition, wp_unslash( $_POST['mp_jury'] ) ) : new \WP_Error( 'busy', 'Enregistrement indisponible. Réessayez.' );
			if ( is_wp_error( $result ) ) { set_transient( 'mp_jury_error_' . get_current_user_id(), $result->get_error_message(), 120 ); }
		} finally { if ( $locked ) { SubmissionLock::release(); } }
	}
	public static function notice(): void {
		$error = get_transient( 'mp_jury_error_' . get_current_user_id() );
		if ( $error ) { echo '<div class="notice notice-error"><p>Organisateurs et votes : ' . esc_html( $error ) . '</p></div>'; delete_transient( 'mp_jury_error_' . get_current_user_id() ); }
	}
}
