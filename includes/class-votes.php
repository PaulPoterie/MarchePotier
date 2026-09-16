<?php
/** Une note par candidature et compte, indépendante de la décision finale. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Votes {
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'mp_votes'; }
	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'install' ) );
		add_action( 'admin_post_mp_vote', array( self::class, 'save' ) );
		add_action( 'before_delete_post', static function ( $id, $post ) {
			if ( 'mp_candidature' === $post->post_type && '1' === get_option( 'mp_votes_schema_version' ) ) { global $wpdb; $wpdb->delete( self::table(), array( 'application_id' => $id ), array( '%d' ) ); }
		}, 10, 2 );
	}
	public static function install(): void {
		if ( ! current_user_can( 'manage_options' ) || '1' === get_option( 'mp_votes_schema_version' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table(); $collation = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL,
			edition_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			score tinyint(3) unsigned NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY application_user (application_id,user_id),
			KEY edition_id (edition_id)
		) $collation;" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { update_option( 'mp_votes_schema_version', '1', false ); }
	}
	/** Lecture groupée pour éviter une requête SQL par cellule du classement. */
	public static function all( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids || '1' !== get_option( 'mp_votes_schema_version' ) ) { return array(); }
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT application_id, edition_id, user_id, score, updated_at FROM ' . self::table() . ' WHERE application_id IN (' . implode( ',', $ids ) . ')', ARRAY_A );
		$result = array();
		foreach ( $rows as $row ) { $result[ (int) $row['application_id'] ][ (int) $row['user_id'] ] = $row; }
		return $result;
	}
	/** Null hors du jury actif en mode multiple ; une note absente reste distincte de zéro. */
	public static function mine( int $id, ?array $votes = null ): ?array {
		$edition = (int) ( Records::data( $id )['edition_id'] ?? 0 );
		$uid = get_current_user_id(); $members = Jury::members( $edition );
		if ( ! $uid || ! Jury::multiple( $edition ) || ! isset( $members[ $uid ] ) ) { return null; }
		$votes = $votes ?? ( self::all( array( $id ) )[ $id ] ?? array() );
		$vote = $votes[ $uid ] ?? null;
		return array( 'name' => $members[ $uid ]['name'], 'score' => $vote && (int) $vote['edition_id'] === $edition ? (int) $vote['score'] : null );
	}
	public static function summary( int $id, ?array $votes = null ): array {
		$edition = (int) ( Records::data( $id )['edition_id'] ?? 0 );
		$members = Jury::members( $edition );
		$votes = $votes ?? ( self::all( array( $id ) )[ $id ] ?? array() );
		$total = 0; $count = 0;
		foreach ( $votes as $uid => $vote ) {
			if ( isset( $members[ $uid ] ) && (int) $vote['edition_id'] === $edition ) { $total += (int) $vote['score']; ++$count; }
		}
		return array( 'total' => $total, 'count' => $count, 'expected' => count( $members ), 'multiple' => Jury::multiple( $edition ) );
	}
	public static function sort_ids( array $ids, string $order ): array {
		$votes = self::all( $ids ); $scores = array();
		foreach ( $ids as $id ) { $scores[ $id ] = self::summary( $id, $votes[ $id ] ?? array() ); }
		usort( $ids, static function ( $a, $b ) use ( $scores, $order ) {
			$left = $scores[ $a ]; $right = $scores[ $b ];
			$has_left = $left['multiple'] && $left['count'] > 0; $has_right = $right['multiple'] && $right['count'] > 0;
			if ( $has_left !== $has_right ) { return $has_left ? -1 : 1; }
			$comparison = $left['total'] <=> $right['total'];
			return ( 'ASC' === strtoupper( $order ) ? $comparison : -$comparison ) ?: ( $a <=> $b );
		} );
		return $ids;
	}
	/** Le compte est toujours celui de la session ; aucun identifiant de votant n’est accepté. */
	public static function record( int $id, mixed $score ): true|\WP_Error {
		if ( ! is_string( $score ) || ! preg_match( '/^[0-5]$/D', $score ) ) { return new \WP_Error( 'score', 'Choisissez une note entière entre 0 et 5.', array( 'status' => 400 ) ); }
		if ( ! Jury::can_review() || ! get_current_user_id() ) { return new \WP_Error( 'access', 'Accès refusé.', array( 'status' => 403 ) ); }
		if ( ! SubmissionLock::acquire() ) { return new \WP_Error( 'busy', 'Un enregistrement est en cours. Réessayez.', array( 'status' => 409 ) ); }
		try {
			$data = Records::data( $id ); $edition = (int) ( $data['edition_id'] ?? 0 ); $uid = get_current_user_id();
			if ( ! Jury::can_view_application( $id ) || ! Jury::can_view_edition( $edition ) || empty( $data['potier_id'] ) || ! isset( Jury::members( $edition )[ $uid ] ) ) { return new \WP_Error( 'access', 'Vous ne pouvez pas voter pour cette candidature.', array( 'status' => 403 ) ); }
			$settings = Jury::settings( $edition );
			if ( 'multiple' !== $settings['mode'] ) { return new \WP_Error( 'mode', 'La notation nécessite le mode votes multiples.', array( 'status' => 403 ) ); }
			if ( '1' !== get_option( 'mp_votes_schema_version' ) ) { return new \WP_Error( 'storage', 'Les votes ne sont pas encore disponibles. Contactez le responsable.', array( 'status' => 503 ) ); }
			global $wpdb;
			$result = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (application_id,edition_id,user_id,score,updated_at) VALUES (%d,%d,%d,%d,%s) ON DUPLICATE KEY UPDATE score=VALUES(score), updated_at=VALUES(updated_at)', $id, $edition, $uid, (int) $score, gmdate( 'Y-m-d H:i:s' ) ) );
			return false === $result ? new \WP_Error( 'storage', 'La note n’a pas été enregistrée. Réessayez.', array( 'status' => 500 ) ) : true;
		} finally { SubmissionLock::release(); }
	}
	public static function save(): void {
		$id = is_string( $_POST['candidature'] ?? null ) ? absint( $_POST['candidature'] ) : 0;
		check_admin_referer( 'mp_vote_' . $id );
		$result = self::record( $id, $_POST['score'] ?? null );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => $result->get_error_data()['status'] ?? 400, 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( 'mp_vote_saved', '1', Records::view_url( $id, Records::list_context() ) ), 303 );
		exit;
	}
	public static function render( int $id ): void {
		$edition = (int) ( Records::data( $id )['edition_id'] ?? 0 );
		if ( ! Jury::can_view_application( $id ) || ! Jury::multiple( $edition ) ) { return; }
		$data = Jury::settings( $edition ); $members = Jury::members( $edition ); $votes = self::all( array( $id ) )[ $id ] ?? array();
		$mine = self::mine( $id, $votes );
		$summary = self::summary( $id, $votes );
		echo '<section class="mp-votes" aria-labelledby="mp-votes-title"><h2 id="mp-votes-title">' . ( $mine ? 'Ma note' : 'Votes pour la sélection' ) . '</h2>';
		if ( isset( $_GET['mp_vote_saved'] ) ) { echo '<div class="notice notice-success inline" role="status"><p>Votre note a été enregistrée.</p></div>'; }
		if ( $mine ) {
			$score_value = null === $mine['score'] ? '' : (string) $mine['score'];
			echo '<p class="mp-vote-member">' . esc_html( $mine['name'] ) . '</p><p class="mp-vote-state' . ( '' === $score_value ? ' mp-vote-state-pending' : '' ) . '" id="mp-vote-state" aria-live="polite">' . ( '' === $score_value ? 'À noter par moi' : 'Note enregistrée : ' . esc_html( $score_value ) . '/5' ) . '</p>';
			echo '<form class="mp-vote-form" method="post" action="' . esc_url( add_query_arg( Records::list_context(), admin_url( 'admin-post.php' ) ) ) . '"><input type="hidden" name="action" value="mp_vote"><input type="hidden" name="candidature" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'mp_vote_' . $id );
			echo '<label for="mp-my-score">Votre note de 0 à 5</label><div class="mp-vote-controls"><select required id="mp-my-score" name="score" aria-describedby="mp-vote-state mp-vote-help" data-saved-score="' . esc_attr( $score_value ) . '"><option value="">— Choisir —</option>';
			for ( $score = 0; $score <= 5; ++$score ) { echo '<option value="' . esc_attr( $score ) . '"' . selected( $score_value, (string) $score, false ) . '>' . esc_html( $score ) . '</option>'; }
			echo '</select> <button class="button button-primary">Valider ma note</button></div></form><p class="description" id="mp-vote-help">0 est une note. Vous pouvez modifier votre note puis la valider à nouveau.</p>';
		}
		echo '<p class="mp-vote-totals"><strong>' . esc_html( $summary['total'] ) . ' points</strong><span>' . esc_html( $summary['count'] . ' vote(s) sur ' . $summary['expected'] ) . '</span></p>';
		echo '<details class="mp-jury-details" open><summary>Notes du jury</summary>';
		if ( ! $members ) { echo '<p>Aucun membre actif. Un administrateur du marché peut en ajouter dans l’édition.</p>'; }
		echo '<table class="widefat striped"><thead><tr><th scope="col">Membre</th><th scope="col">Note / 5</th></tr></thead><tbody>';
		foreach ( $data['members'] as $uid => $member ) {
			$vote = $votes[ $uid ] ?? null;
			$active = isset( $members[ $uid ] );
			$is_mine = (int) $uid === get_current_user_id();
			if ( ! $active && ! $vote ) { continue; }
			echo '<tr><th scope="row">' . esc_html( $member['name'] ) . ( $is_mine ? ' — vous' : '' ) . ( ! $active ? '<br><small>Inactif · note exclue du total</small>' : '' ) . '</th><td>';
			echo $vote ? esc_html( $vote['score'] . ' / 5' ) : 'Pas encore voté';
			echo '</td></tr>';
		}
		echo '</tbody></table></details><p class="description">Le responsable du marché enregistre la sélection finale.</p></section>';
	}
}
