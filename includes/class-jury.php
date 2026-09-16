<?php
/** Affectation des votants et accès aux dossiers, édition par édition. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Jury {
	public const META = '_mp_jury_settings';
	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'install' ) );
		add_action( 'add_meta_boxes_mp_edition', static function () {
			add_meta_box( 'mp-jury', 'Organisateur et votes', array( self::class, 'box' ), 'mp_edition', 'normal', 'high' );
		} );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_enqueue_scripts', static function () {
			$screen = get_current_screen();
			if ( $screen && 'mp_edition' === $screen->post_type && 'post' === $screen->base ) {
				wp_enqueue_script( 'mp-jury', plugins_url( '../assets/jury.js', __FILE__ ), array(), '0.19.0-beta.5', true );
				wp_enqueue_style( 'mp-jury', plugins_url( '../assets/jury.css', __FILE__ ), array(), '0.19.0-beta.5' );
			}
		} );
	}
	public static function install(): void {
		if ( ! current_user_can( 'manage_options' ) || '2' === get_option( 'mp_jury_permissions_version' ) ) { return; }
		add_role( 'mp_juror', '[MP] Votant sélection', array( 'read' => true, 'mp_access_market' => true, 'mp_review_applications' => true ) );
		Editions::rename_role( 'mp_juror', '[MP] Votant sélection' );
		foreach ( array( 'administrator', 'mp_organizer' ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) { return; }
			$role->add_cap( 'mp_review_applications' );
			$role->add_cap( 'mp_manage_jury' );
		}
		update_option( 'mp_jury_permissions_version', '2', false );
		wp_get_current_user()->get_role_caps();
	}
	public static function settings( int $edition ): array {
		$data = get_post_meta( $edition, self::META, true );
		$data = array_merge( array( 'mode' => 'simple', 'members' => array(), 'revision' => '' ), is_array( $data ) ? $data : array() );
		// Les anciennes affectations restent liées aux mêmes comptes et aux mêmes notes.
		foreach ( $data['members'] as $uid => &$member ) {
			$member['kind'] = $member['kind'] ?? ( user_can( $uid, 'mp_manage_editions' ) ? 'administrator' : 'voter' );
		} unset( $member );
		return $data;
	}
	public static function multiple( int $edition ): bool { return 'multiple' === self::settings( $edition )['mode']; }
	public static function can_review(): bool { return current_user_can( 'mp_manage_applications' ) || current_user_can( 'mp_review_applications' ); }
	public static function members( int $edition ): array {
		return array_filter( self::settings( $edition )['members'], static fn( $member, $id ) => ! empty( $member['active'] ) && (bool) get_userdata( $id ), ARRAY_FILTER_USE_BOTH );
	}
	public static function administrator( int $edition ): int {
		foreach ( self::members( $edition ) as $uid => $member ) { if ( 'administrator' === $member['kind'] ) { return (int) $uid; } }
		return 0;
	}
	public static function contact_email( int $edition ): string {
		$user = get_userdata( self::administrator( $edition ) );
		return $user ? $user->user_email : '';
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
		if ( ! current_user_can( 'mp_manage_jury' ) ) { echo '<p>La gestion de l’équipe est réservée aux administrateurs du marché.</p>'; return; }
		$data = self::settings( $post->ID );
		wp_nonce_field( 'mp_jury_' . $post->ID, 'mp_jury_nonce' );
		echo '<input type="hidden" name="mp_jury[revision]" value="' . esc_attr( $data['revision'] ) . '">';
		echo '<p><label for="mp-jury-mode"><strong>Mode de sélection</strong></label> <select id="mp-jury-mode" name="mp_jury[mode]"><option value="simple" ' . selected( $data['mode'], 'simple', false ) . '>Simple — sélection directe</option><option value="multiple" ' . selected( $data['mode'], 'multiple', false ) . '>Votes multiples — notes de 0 à 5</option></select></p>';
		echo '<p class="description">En mode votes multiples, les notes restent modifiables à tout moment, avant, pendant et après les inscriptions.</p>';
		echo '<h3>Administrateur du marché</h3><p>Un seul administrateur est obligatoire pour cette édition. Il peut créer, modifier, publier et supprimer les éditions, les candidatures, les pages et les articles du site, et gérer l’équipe. En mode simple, il décide de la sélection. En votes multiples, il donne aussi sa note de 0 à 5 et conserve la décision finale.</p>';
		echo '<p class="description">Le rôle « [MP] Administrateur marché » donne ces droits sur l’ensemble du site. Cet administrateur reçoit les nouvelles candidatures et les réponses des candidats.</p>';
		$uid = self::administrator( $post->ID ); $member = $data['members'][ $uid ] ?? array(); $user = get_userdata( $uid );
		echo '<table class="form-table" role="presentation"><tr><th><label for="mp-administrator-name">Nom *</label></th><td><input class="regular-text" id="mp-administrator-name" name="mp_jury[administrator][name]" type="text" maxlength="120" required value="' . esc_attr( $member['name'] ?? '' ) . '"></td></tr>';
		echo '<tr><th><label for="mp-administrator-email">Email *</label></th><td><input class="regular-text" id="mp-administrator-email" name="mp_jury[administrator][email]" type="email" required value="' . esc_attr( $user ? $user->user_email : '' ) . '"><p class="description">Pour remplacer l’administrateur, renseignez le nom et l’email de son remplaçant. Si celui-ci figure parmi les votants, décochez sa case « Actif » avant d’enregistrer.</p></td></tr>';
		echo '<tr><th>Invitation</th><td><label><input type="checkbox" name="mp_jury[administrator][invite]" value="1"> Envoyer une invitation</label>';
		if ( isset( $member['invitation'] ) ) { echo '<p class="description">' . esc_html( $member['invitation'] ) . '</p>'; }
		echo '</td></tr></table>';
		echo '<h3>Votant pour la sélection</h3><p>Le rôle « [MP] Votant sélection » permet de consulter les dossiers des éditions affectées, de voir toutes les notes et de modifier uniquement sa propre note. Les votants reçoivent les nouvelles candidatures en mode votes multiples. Le mode simple conserve leurs affectations et leurs notes, mais désactive la notation.</p>';
		self::member_table( $data );
		echo '<p class="description">Enregistrez l’édition pour appliquer les changements. Un nouveau compte reçoit une invitation pour choisir son mot de passe ; la connexion se fait ensuite avec son email et son mot de passe. Pour un compte existant, cochez « Envoyer une invitation » si nécessaire ; son mot de passe est conservé.</p>';
		echo '<p class="description">Décocher « Actif » retire un votant des notifications et du total des votes : ses anciennes notes sont conservées. Lors d’un remplacement, l’ancien administrateur devient un votant inactif de cette édition. Ses droits de gestion sur le site restent attachés à son compte ; seul un administrateur WordPress peut les retirer dans la gestion des comptes.</p>';
	}
	private static function member_table( array $data ): void {
		echo '<div class="mp-jury-group"><div class="mp-jury-scroll" role="region" aria-label="Votants pour la sélection" tabindex="0"><table class="widefat mp-jury-members" id="mp-jury-members"><thead><tr><th>Nom</th><th>Email</th><th>Participation</th><th>Invitation</th></tr></thead><tbody>';
		foreach ( $data['members'] as $uid => $member ) { if ( 'voter' === $member['kind'] ) { self::member_row( (string) $uid, $member, $uid ); } }
		echo '</tbody></table></div><p><button type="button" class="button mp-jury-add">Ajouter un votant</button></p><template>';
		self::member_row( '__INDEX__', array( 'name' => '', 'active' => true ), 0 );
		echo '</template></div>';
	}
	private static function member_row( string $index, array $member, int $uid ): void {
		$user = $uid ? get_userdata( $uid ) : false;
		$prefix = 'mp_jury[members][' . $index . ']';
		echo '<tr><td><input type="hidden" name="' . esc_attr( $prefix . '[user_id]' ) . '" value="' . esc_attr( $uid ) . '"><input aria-label="Nom" type="text" maxlength="120" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $member['name'] ) . '"></td>';
		echo '<td><input aria-label="Email" type="email" name="' . esc_attr( $prefix . '[email]' ) . '" value="' . esc_attr( $user ? $user->user_email : '' ) . '"' . ( $uid ? ' readonly' : '' ) . '>';
		if ( $user && user_can( $user, 'mp_manage_editions' ) ) { echo '<br><small>Ce compte conserve ses droits d’administration du marché.</small>'; }
		echo '</td>';
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
		if ( ( $raw['revision'] ?? null ) !== $old['revision'] ) { return new \WP_Error( 'conflict', 'L’équipe a changé depuis l’ouverture de cette page. Rechargez l’édition avant de recommencer.' ); }
		if ( ! in_array( $raw['mode'] ?? null, array( 'simple', 'multiple' ), true ) || ! is_array( $raw['members'] ?? array() ) || count( $raw['members'] ?? array() ) > 99 ) { return new \WP_Error( 'invalid', 'Réglages invalides (99 votants maximum).' ); }
		$administrator = $raw['administrator'] ?? null;
		if ( isset( $raw['administrators'] ) || ! is_array( $administrator ) || array_is_list( $administrator ) || array_diff( array_keys( $administrator ), array( 'name', 'email', 'invite' ) ) ) { return new \WP_Error( 'administrator', 'Renseignez un seul administrateur du marché avec son nom et son email.' ); }
		$rows = array(); $emails = array();
		foreach ( array( 'administrator' => array( $administrator ), 'voter' => $raw['members'] ?? array() ) as $kind => $group ) {
			foreach ( $group as $row ) {
				if ( ! is_array( $row ) ) { return new \WP_Error( 'invalid', 'Membre invalide.' ); }
				foreach ( array( 'name', 'email', 'user_id', 'active', 'invite' ) as $key ) { if ( isset( $row[ $key ] ) && ! is_string( $row[ $key ] ) ) { return new \WP_Error( 'invalid', 'Membre invalide.' ); } }
				$uid = $row['user_id'] ?? '0';
				if ( ! ctype_digit( $uid ) || ! in_array( $row['active'] ?? '0', array( '0', '1' ), true ) || ! in_array( $row['invite'] ?? '0', array( '0', '1' ), true ) ) { return new \WP_Error( 'invalid', 'Membre invalide.' ); }
				$uid = (int) $uid; $name = sanitize_text_field( $row['name'] ?? '' ); $email = trim( $row['email'] ?? '' );
				if ( 'voter' === $kind && ! $uid && '' === $name && '' === $email ) { continue; }
				if ( $uid && ! isset( $old['members'][ $uid ] ) ) { return new \WP_Error( 'invalid', 'Compte inconnu dans cette édition. Ajoutez-le avec son email.' ); }
				if ( $uid && ! get_userdata( $uid ) ) { continue; }
				if ( '' === $name || strlen( $name ) > 480 || ! is_email( $email ) ) { return new \WP_Error( 'invalid', 'Indiquez un nom et un email valide pour l’administrateur et chaque votant.' ); }
				if ( $uid && strcasecmp( get_userdata( $uid )->user_email, $email ) !== 0 ) { return new \WP_Error( 'invalid', 'L’email du compte a changé. Rechargez l’édition.' ); }
				// Une ligne de votant désactivée peut devenir l’administrateur, sans double participation.
				if ( 'voter' === $kind && '1' !== ( $row['active'] ?? '0' ) && strcasecmp( $email, trim( $administrator['email'] ) ) === 0 ) { continue; }
				if ( isset( $emails[ strtolower( $email ) ] ) ) { return new \WP_Error( 'invalid', 'Un email ne peut participer qu’une fois. Si vous nommez un votant administrateur, décochez sa case « Actif » avant d’enregistrer.' ); }
				$emails[ strtolower( $email ) ] = true;
				$rows[] = array( 'user_id' => $uid, 'email' => $email, 'name' => $name, 'kind' => $kind, 'active' => 'administrator' === $kind || '1' === ( $row['active'] ?? '0' ), 'invite' => '1' === ( $row['invite'] ?? '0' ) );
			}
		}
		$data = array( 'mode' => $raw['mode'], 'members' => $old['members'], 'revision' => wp_generate_uuid4() );
		foreach ( $data['members'] as &$member ) { $member['active'] = false; $member['kind'] = 'voter'; } unset( $member );
		$invitations = array();
		foreach ( $rows as $row ) {
			$uid = $row['user_id'] ?: (int) email_exists( $row['email'] ); $created = false;
			if ( ! $uid ) {
				if ( ! $row['active'] ) { continue; }
				$uid = wp_insert_user( array( 'user_login' => 'mp_' . wp_generate_password( 16, false ), 'user_email' => $row['email'], 'display_name' => $row['name'], 'user_pass' => wp_generate_password( 32, true ), 'role' => 'administrator' === $row['kind'] ? 'mp_organizer' : 'mp_juror' ) );
				if ( is_wp_error( $uid ) ) { return new \WP_Error( 'account', 'Création du compte impossible : ' . $uid->get_error_message() ); }
				$created = true;
			}
			$data['members'][ $uid ] = array_merge( $old['members'][ $uid ] ?? array(), array( 'name' => $row['name'], 'active' => $row['active'], 'kind' => $row['kind'] ) );
			if ( $row['active'] ) {
				$user = get_userdata( $uid );
				if ( 'administrator' === $row['kind'] && ! user_can( $user, 'manage_options' ) ) {
					$user->add_role( 'mp_organizer' );
					$user->remove_role( 'mp_juror' );
				}
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
		$member = self::settings( $edition )['members'][ $uid ] ?? array();
		$name = $member['name'] ?? $user->display_name;
		$administrator = 'administrator' === ( $member['kind'] ?? 'voter' );
		$role_label = $administrator ? 'administrateur du marché' : 'votant pour la sélection';
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
			$instructions = 'Votre compte d’accès est prêt. Choisissez votre mot de passe avec le bouton ci-dessous, puis connectez-vous avec votre adresse email et ce mot de passe.';
		}
		$message = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f6f3ef;color:#292524;font-family:Arial,sans-serif;font-size:16px;line-height:1.6">';
		$message .= '<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:24px 12px"><table role="presentation" style="width:100%;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e7e0d8;border-collapse:collapse"><tr><td style="padding:28px">';
		$message .= '<p style="margin:0;color:#76533c;font-size:13px;font-weight:bold">MARCHÉ POTIER · INVITATION</p><h1 style="margin:8px 0 24px;font-size:24px;line-height:1.3">' . esc_html( $title ) . '</h1>';
		$message .= '<p>Bonjour ' . esc_html( $name ) . ',</p><p>Vous êtes invité en tant que <strong>' . esc_html( $role_label ) . '</strong> pour cette édition.</p>';
		$message .= '<p>' . esc_html( $instructions ) . '</p><p style="padding:16px;background:#f6f3ef">Votre identifiant de connexion est votre adresse email :<br><strong style="overflow-wrap:anywhere">' . esc_html( $user->user_email ) . '</strong></p>';
		$message .= '<p style="margin:28px 0"><a href="' . esc_url( $action_url ) . '" style="display:inline-block;background:#76533c;color:#ffffff;padding:12px 20px;border-radius:4px;text-decoration:none;font-weight:bold">' . esc_html( $action_label ) . '</a></p>';
		if ( $created ) { $message .= '<p>Une fois votre mot de passe choisi : <a href="' . esc_url( $login_url ) . '">accéder aux candidatures de l’édition</a>.</p>'; }
		if ( $administrator ) { $message .= '<p>Vous pouvez gérer les éditions, les candidatures, les pages et les articles du site. Dans <strong>Gestion des candidatures → Examiner</strong>, vous enregistrez la sélection finale, en mode simple comme en votes multiples.</p>'; }
		$message .= '<p>Dans <strong>Gestion des candidatures → Examiner</strong>, vous consultez les dossiers. En mode votes multiples, vous attribuez votre note de 0 à 5, voyez les autres notes et modifiez uniquement la vôtre. Les dates d’inscription ne limitent pas la notation.</p>';
		$message .= '<p style="margin-top:24px;font-size:14px">Mot de passe oublié ou lien expiré ? <a href="' . esc_url( wp_lostpassword_url( $destination ) ) . '">Demander un nouveau lien</a> en indiquant votre adresse email.</p>';
		$message .= '</td></tr></table></td></tr></table></body></html>';
		return wp_mail( $user->user_email, ( $administrator ? 'Invitation administrateur du marché — ' : 'Invitation au jury — ' ) . $title, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
	public static function save( int $edition ): bool {
		if ( wp_is_post_revision( $edition ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'mp_manage_jury' ) ) { return false; }
		$nonce = $_POST['mp_jury_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'mp_jury_' . $edition ) ) {
			set_transient( 'mp_jury_error_' . get_current_user_id(), 'Session expirée. Rechargez l’édition avant de réessayer.', 120 );
			return false;
		}
		$locked = SubmissionLock::acquire();
		try {
			$result = $locked && is_array( $_POST['mp_jury'] ?? null ) ? self::configure( $edition, wp_unslash( $_POST['mp_jury'] ) ) : new \WP_Error( 'busy', 'Enregistrement indisponible. Réessayez.' );
			if ( is_wp_error( $result ) ) { set_transient( 'mp_jury_error_' . get_current_user_id(), $result->get_error_message(), 120 ); }
		} finally { if ( $locked ) { SubmissionLock::release(); } }
		return true === $result;
	}
	public static function notice(): void {
		$error = get_transient( 'mp_jury_error_' . get_current_user_id() );
		if ( $error ) { echo '<div class="notice notice-error"><p>Organisateur et votes — paramètres non enregistrés : ' . esc_html( $error ) . '</p></div>'; delete_transient( 'mp_jury_error_' . get_current_user_id() ); }
	}
}
