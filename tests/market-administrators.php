<?php
/** Scénarios complémentaires, exécutés par votes-local.php avec ses fixtures isolées. */
use MarchePotier\{Jury,Votes,Records,Editions,Review,Notifications};
if ( ! isset( $state, $manager, $checks ) || PHP_SAPI !== 'cli' ) { exit; }

marcpo_check( wp_roles()->role_names['marcpo_organizer'] === '[Poterie Navarraise] Administrateur marché' && wp_roles()->role_names['marcpo_juror'] === '[Poterie Navarraise] Votant sélection', 'Les deux rôles existants ont les nouveaux noms' );
wp_set_current_user( $manager );
foreach ( array( 'post', 'page', 'marcpo_edition', 'marcpo_candidature' ) as $type ) {
	$object = get_post_type_object( $type );
	$record = marcpo_post( $type, 'Droits administrateur ' . $type );
	wp_update_post( array( 'ID' => $record, 'post_author' => 1 ) );
	marcpo_check( current_user_can( $object->cap->create_posts ) && current_user_can( $object->cap->publish_posts ) && current_user_can( 'edit_post', $record ) && current_user_can( 'delete_post', $record ), 'Créer, publier, modifier et supprimer : ' . $type . ', y compris un contenu publié par un autre compte' );
	wp_update_post( array( 'ID' => $record, 'post_status' => 'private' ) );
	marcpo_check( current_user_can( 'read_post', $record ) && current_user_can( 'edit_post', $record ) && current_user_can( 'delete_post', $record ), 'Lire, modifier et supprimer un contenu privé : ' . $type );
}
marcpo_check( ! current_user_can( 'manage_options' ) && ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'promote_users' ), 'Administrateur marché sans administration technique de WordPress' );
marcpo_check( true === Votes::record( $app, '2' ) && Votes::summary( $app )['total'] === 11 && Votes::summary( $app )['count'] === 3, 'Administrateur inscrit : note personnelle incluse une seule fois' );
ob_start(); Votes::render( $app ); $html = ob_get_clean();
marcpo_check( substr_count( $html, 'name="score"' ) === 1 && str_contains( $html, 'Responsable — vous' ), 'Administrateur : seule sa propre note est modifiable' );
$raw = marcpo_raw( $edition ); $raw['mode'] = 'simple'; marcpo_config( $edition, $raw );
ob_start(); Review::decision_form( $app ); $html = ob_get_clean();
marcpo_check( str_contains( $html, 'Enregistrer la sélection' ) && is_wp_error( Votes::record( $app, '4' ) ), 'Administrateur en mode simple : sélection disponible, notation désactivée' );
$simple_app = marcpo_post( 'marcpo_candidature', 'Notifications mode simple' ); update_post_meta( $simple_app, Records::META, Records::data( $app ) );
$before_mails = count( $mails ); Notifications::send( $simple_app ); $sent = array_slice( $mails, $before_mails );
marcpo_check( count( $sent ) === 2 && $sent[1]['to'] === get_userdata( $manager )->user_email && in_array( 'Reply-To: ' . get_userdata( $manager )->user_email, $sent[0]['headers'], true ), 'Mode simple : candidat et administrateur unique notifiés, réponse à cet administrateur' );
$raw = marcpo_raw( $edition ); $raw['mode'] = 'multiple'; marcpo_config( $edition, $raw );
$before = Jury::settings( $edition );
foreach ( array( null, array(), 'invalide', array( marcpo_raw( $edition )['administrator'] ), array( 'name' => '', 'email' => 'gestion@example.test' ), array( 'name' => 'Gestion', 'email' => 'incorrect' ), array( 'name' => 'Gestion', 'email' => array( 'gestion@example.test' ) ), array( 'name' => 'Gestion', 'email' => 'gestion@example.test', 'active' => '0' ) ) as $invalid ) {
	$raw = marcpo_raw( $edition ); $raw['administrator'] = $invalid;
	marcpo_check( is_wp_error( marcpo_config( $edition, $raw ) ) && Jury::settings( $edition ) === $before, 'Administrateur obligatoire : structure, nom et email contrôlés avant écriture — ' . wp_json_encode( $invalid ) );
}
$raw = marcpo_raw( $edition ); $raw['administrators'] = array( $raw['administrator'], array( 'name' => 'Autre', 'email' => 'autre@example.test' ) );
marcpo_check( is_wp_error( marcpo_config( $edition, $raw ) ) && Jury::settings( $edition ) === $before && ! email_exists( 'autre@example.test' ), 'Ancienne liste de plusieurs administrateurs refusée sans création de compte' );
$raw = marcpo_raw( $edition ); $raw['administrator'] = array( 'name' => 'Alice', 'email' => get_userdata( $a )->user_email );
marcpo_check( is_wp_error( marcpo_config( $edition, $raw ) ) && ! user_can( $a, 'marcpo_manage_editions' ), 'Même email administrateur et votant actif refusé avant toute promotion' );

// Une erreur de paramètres ne doit jamais créer un compte ou accorder des droits.
$new_email = 'mpvote_' . substr( $state['run'], 0, 8 ) . '_gestion@example.test';
$raw = marcpo_raw( $edition ); $raw['administrator'] = array( 'name' => 'Gestion test', 'email' => $new_email, 'invite' => '1' );
$_POST = array( 'marcpo_edition_nonce' => wp_create_nonce( 'marcpo_save_edition_' . $edition ), 'marcpo_edition' => array( 'year' => '2098', 'opens' => 'invalide', 'closes' => '2098-12-31T23:00' ), 'marcpo_jury_nonce' => wp_create_nonce( 'marcpo_jury_' . $edition ), 'marcpo_jury' => $raw );
$before_mails = count( $mails ); Editions::save( $edition );
marcpo_check( ! email_exists( $new_email ) && count( $mails ) === $before_mails && Jury::settings( $edition ) === $before, 'Dates invalides : aucun compte créé, aucune invitation ni changement d’équipe' );
$_POST['marcpo_edition'] = array( 'year' => '2098', 'opens' => '2020-01-01T00:00', 'closes' => '2098-12-31T23:00' );
Editions::save( $edition ); $_POST = array();
$new_admin = (int) email_exists( $new_email );
if ( $new_admin ) { update_user_meta( $new_admin, '_marcpo_vote_test', $state['run'] ); $state['users'][] = $new_admin; marcpo_state(); }
marcpo_check( $new_admin && get_userdata( $new_admin )->roles === array( 'marcpo_organizer' ) && Jury::administrator( $edition ) === $new_admin, 'Enregistrer l’édition crée et affecte l’administrateur unique' );
$invitation = end( $mails );
marcpo_check( count( $mails ) === $before_mails + 1 && str_contains( $invitation['subject'], 'Invitation administrateur du marché' ) && str_contains( $invitation['message'], 'Choisir mon mot de passe' ) && str_contains( $invitation['message'], 'enregistrez la sélection finale' ), 'Invitation administrateur avec choix du mot de passe et explication de son rôle' );
marcpo_check( ! array_key_exists( 'organizer_email', Editions::settings( $edition ) ) && Jury::contact_email( $edition ) === $new_email, 'Le contact suit le remplacement de l’administrateur unique' );
marcpo_check( count( array_filter( Jury::settings( $edition )['members'], static fn( $member ) => 'administrator' === $member['kind'] ) ) === 1 && ! isset( Jury::members( $edition )[ $manager ] ), 'Un seul administrateur enregistré ; le précédent devient votant inactif' );
marcpo_check( is_wp_error( Votes::record( $app, '3' ) ) && user_can( $manager, 'marcpo_manage_editions' ), 'Ancien administrateur exclu du vote, droits WordPress préexistants conservés' );
wp_set_current_user( $new_admin ); marcpo_check( true === Votes::record( $app, '0' ) && Votes::summary( $app )['expected'] === 4 && Votes::summary( $app )['total'] === 9, 'Nouvel administrateur autorisé à voter, zéro comptabilisé et ancienne note exclue' );
$multiple_app = marcpo_post( 'marcpo_candidature', 'Notifications administrateur unique' ); update_post_meta( $multiple_app, Records::META, Records::data( $app ) );
$before_mails = count( $mails ); Notifications::send( $multiple_app ); $sent = array_slice( $mails, $before_mails );
marcpo_check( count( $sent ) === 5 && count( array_unique( array_column( $sent, 'to' ) ) ) === 5 && in_array( $new_email, array_column( $sent, 'to' ), true ) && ! in_array( get_userdata( $manager )->user_email, array_column( $sent, 'to' ), true ), 'Administrateur unique et votants actifs notifiés, ancien administrateur exclu' );

// Remplacement par un votant existant : sa note suit son compte sans doublon.
$raw = marcpo_raw( $edition ); $raw['administrator'] = array( 'name' => 'Bruno', 'email' => get_userdata( $b )->user_email );
foreach ( $raw['members'] as &$row ) { if ( (int) $row['user_id'] === $b ) { $row['active'] = '0'; } } unset( $row );
marcpo_check( true === marcpo_config( $edition, $raw ) && Jury::administrator( $edition ) === $b && in_array( 'marcpo_organizer', get_userdata( $b )->roles, true ) && ! in_array( 'marcpo_juror', get_userdata( $b )->roles, true ), 'Un votant existant peut remplacer l’administrateur sans créer de compte' );
marcpo_check( (int) Votes::all( array( $app ) )[ $app ][ $b ]['score'] === 4 && Votes::summary( $app )['total'] === 9 && Votes::summary( $app )['expected'] === 3, 'Remplacement : ancienne note du votant conservée sans double participation' );
$site_admin = marcpo_user( 'AdminSite', 'administrator' );
$raw = marcpo_raw( $edition );
$raw['administrator'] = array( 'name' => 'Administrateur WordPress existant', 'email' => get_userdata( $site_admin )->user_email );
$roles_before = get_userdata( $site_admin )->roles;
marcpo_check( true === marcpo_config( $edition, $raw ) && get_userdata( $site_admin )->roles === $roles_before, 'Le rôle d’un administrateur WordPress existant reste inchangé' );

wp_set_current_user( $a );
marcpo_check( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) && ! current_user_can( 'marcpo_manage_jury' ), 'Le votant limité ne récupère aucun droit de publication ni de gestion de l’équipe' );
wp_set_current_user( $manager );
ob_start(); Jury::box( get_post( $edition ) ); $html = ob_get_clean();
marcpo_check( substr_count( $html, 'name="marcpo_jury[administrator][name]"' ) === 1 && substr_count( $html, 'name="marcpo_jury[administrator][email]"' ) === 1 && ! str_contains( $html, 'marcpo_jury[administrators]' ) && ! str_contains( $html, 'marcpo-jury-move' ) && ! str_contains( $html, 'Ajouter un administrateur' ), 'Un seul formulaire administrateur, aucun ajout ni déplacement ; tableau votants conservé' );
ob_start(); Editions::render_box( get_post( $edition ) ); $html = ob_get_clean();
marcpo_check( ! str_contains( $html, 'name="marcpo_edition[organizer_email]"' ), 'Le champ email indépendant de l’organisateur a disparu' );
$before_settings = Editions::settings( $edition );
$_POST = array( 'marcpo_edition_nonce' => wp_create_nonce( 'marcpo_save_edition_' . $edition ), 'marcpo_edition' => array( 'year' => '2097', 'opens' => '2020-01-01T00:00', 'closes' => '2097-12-31T23:00' ) );
Editions::save( $edition ); $_POST = array();
marcpo_check( Editions::settings( $edition ) === $before_settings, 'Une requête omettant l’administrateur ne peut pas enregistrer les paramètres de l’édition' );
wp_set_current_user( 1 );
