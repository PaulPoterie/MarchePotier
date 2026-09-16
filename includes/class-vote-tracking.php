<?php
/** Vue de consultation des notes et de leur avancement par édition. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class VoteTracking {
	public static function hooks(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'marche-potier', 'Suivi des votes', 'Suivi des votes', 'mp_review_applications', 'mp-suivi-votes', array( self::class, 'render' ) );
		} );
		add_action( 'admin_enqueue_scripts', static function () {
			if ( 'mp-suivi-votes' === ( $_GET['page'] ?? '' ) ) {
				wp_enqueue_style( 'mp-vote-tracking', plugins_url( '../assets/vote-tracking.css', __FILE__ ), array(), '0.19.0-beta.7' );
			}
		} );
	}
	public static function url( int $edition = 0 ): string {
		$url = admin_url( 'admin.php?page=mp-suivi-votes' );
		return $edition ? add_query_arg( 'mp_edition', $edition, $url ) : $url;
	}
	/** Les compteurs et les cellules utilisent le même ensemble de dossiers et de membres. */
	public static function data( int $edition ): array|\WP_Error {
		if ( ! Jury::can_review() || ! Jury::can_view_edition( $edition ) ) { return new \WP_Error( 'access', 'Accès refusé à cette édition.', array( 'status' => 403 ) ); }
		$members = Jury::members( $edition );
		$result = array( 'multiple' => Jury::multiple( $edition ), 'members' => $members, 'ids' => array(), 'scores' => array(), 'counts' => array_fill_keys( array_keys( $members ), 0 ) );
		if ( ! $result['multiple'] ) { return $result; }
		$result['ids'] = Records::navigation_ids( array( 'mp_edition' => $edition, 'orderby' => 'title', 'order' => 'ASC' ) );
		$votes = Votes::all( $result['ids'] );
		foreach ( $result['ids'] as $id ) {
			foreach ( $votes[ $id ] ?? array() as $uid => $vote ) {
				if ( isset( $members[ $uid ] ) && (int) $vote['edition_id'] === $edition ) {
					$result['scores'][ $id ][ $uid ] = (int) $vote['score'];
					++$result['counts'][ $uid ];
				}
			}
		}
		return $result;
	}
	public static function render(): void {
		if ( ! Jury::can_review() ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
		$requested = $_GET['mp_edition'] ?? '';
		if ( ! is_string( $requested ) || ( '' !== $requested && ( ! ctype_digit( $requested ) || (int) $requested < 1 ) ) ) { wp_die( 'Édition invalide.', '', array( 'response' => 400 ) ); }
		$editions = Jury::edition_ids();
		$edition = '' !== $requested ? (int) $requested : ( $editions[0] ?? 0 );
		if ( $edition && ! in_array( $edition, $editions, true ) ) { wp_die( 'Accès refusé à cette édition.', '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>Suivi des votes</h1>';
		if ( ! $editions ) { echo '<p>Aucune édition accessible.</p></div>'; return; }
		$data = self::data( $edition );
		if ( is_wp_error( $data ) ) { wp_die( esc_html( $data->get_error_message() ), '', array( 'response' => $data->get_error_data()['status'] ?? 403 ) ); }
		echo '<form method="get" class="mp-vote-tracking-filter"><input type="hidden" name="page" value="mp-suivi-votes"><label for="mp-tracking-edition">Édition</label> <select id="mp-tracking-edition" name="mp_edition">';
		foreach ( $editions as $id ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $edition, $id, false ) . '>' . esc_html( Blocks::edition_label( $id ) ) . '</option>'; }
		echo '</select> <button class="button">Afficher</button></form>';
		echo '<h2>' . esc_html( Blocks::edition_label( $edition ) ) . '</h2>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( 'mp_edition', $edition, admin_url( 'admin.php?page=mp-gestion' ) ) ) . '">Gestion des candidatures</a></p>';
		if ( ! $data['multiple'] ) { echo '<p>Cette édition utilise le mode simple : aucune notation n’est attendue.</p></div>'; return; }
		if ( ! $data['members'] ) { echo '<p>Aucun membre actif dans le jury de cette édition.</p></div>'; return; }
		$total = count( $data['ids'] );
		echo '<p>Membres actifs du jury, administrateur compris. Chaque case affiche la note sur 5 ; « — » signifie que le membre n’a pas encore voté. Le zéro compte comme un vote.</p>';
		echo '<div class="mp-vote-tracking-scroll" role="region" aria-label="Notes par candidature et par membre du jury" tabindex="0"><table class="widefat mp-vote-tracking-table" style="--mp-members:' . count( $data['members'] ) . '"><caption class="screen-reader-text">Suivi des votes — ' . esc_html( Blocks::edition_label( $edition ) ) . '</caption><thead><tr><th scope="col">Candidature</th>';
		foreach ( $data['members'] as $uid => $member ) {
			$count = $data['counts'][ $uid ];
			$progress = $count . ( 1 === $count ? ' vote / ' : ' votes / ' ) . $total . ( 1 === $total ? ' candidature' : ' candidatures' );
			echo '<th scope="col" class="mp-vote-tracking-member" data-member="' . esc_attr( $uid ) . '"><span>' . esc_html( $member['name'] ) . '</span>';
			if ( 'administrator' === ( $member['kind'] ?? '' ) ) { echo '<small class="mp-vote-tracking-role">Administrateur du marché</small>'; }
			echo '<span class="mp-vote-tracking-count">' . esc_html( $progress ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $data['ids'] as $id ) {
			$identity = Records::data( $id )['identity'] ?? array();
			$name = trim( ( $identity['last_name'] ?? '' ) . ' ' . ( $identity['first_name'] ?? '' ) );
			$url = Records::view_url( $id, array( 'mp_from' => 'votes', 'mp_edition' => $edition, 'orderby' => 'title', 'order' => 'ASC' ) );
			echo '<tr><th scope="row"><a href="' . esc_url( $url ) . '">' . esc_html( $name ?: get_the_title( $id ) ) . '</a>';
			if ( ! empty( $identity['company'] ) ) { echo '<small>' . esc_html( $identity['company'] ) . '</small>'; }
			echo '</th>';
			foreach ( $data['members'] as $uid => $member ) {
				$score = $data['scores'][ $id ][ $uid ] ?? null;
				echo '<td class="' . ( null === $score ? 'mp-vote-tracking-empty' : 'mp-vote-tracking-rated' ) . '">' . ( null === $score ? '<span aria-hidden="true">—</span><span class="screen-reader-text">Pas encore voté</span>' : esc_html( $score . '/5' ) ) . '</td>';
			}
			echo '</tr>';
		}
		if ( ! $data['ids'] ) { echo '<tr><td colspan="' . esc_attr( count( $data['members'] ) + 1 ) . '">Aucune candidature pour cette édition.</td></tr>'; }
		echo '</tbody></table></div></div>';
	}
}
