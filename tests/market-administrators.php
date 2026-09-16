<?php
/** Scénarios complémentaires, exécutés par votes-local.php avec ses fixtures isolées. */
use MarchePotier\{Jury,Votes,Records,Editions,Review,Notifications};
if ( ! isset( $state, $manager, $checks ) || PHP_SAPI !== 'cli' ) { exit; }

mp_check( wp_roles()->role_names['mp_organizer'] === '[MP] Administrateur marché' && wp_roles()->role_names['mp_juror'] === '[MP] Votant sélection', 'Les deux rôles existants ont les nouveaux noms' );
wp_set_current_user( $manager );
foreach ( array( 'post', 'page', 'mp_edition', 'mp_candidature' ) as $type ) {
	$object = get_post_type_object( $type );
	$record = mp_post( $type, 'Droits administrateur ' . $type );
	wp_update_post( array( 'ID' => $record, 'post_author' => 1 ) );
	mp_check( current_user_can( $object->cap->create_posts ) && current_user_can( $object->cap->publish_posts ) && current_user_can( 'edit_post', $record ) && current_user_can( 'delete_post', $record ), 'Créer, publier, modifier et supprimer : ' . $type . ', y compris un contenu publié par un autre compte' );
	wp_update_post( array( 'ID' => $record, 'post_status' => 'private' ) );
	mp_check( current_user_can( 'read_post', $record ) && current_user_can( 'edit_post', $record ) && current_user_can( 'delete_post', $record ), 'Lire, modifier et supprimer un contenu privé : ' . $type );
}
mp_check( ! current_user_can( 'manage_options' ) && ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'promote_users' ), 'Administrateur marché sans administration technique de WordPress' );
mp_check( true === Votes::record( $app, '2' ) && Votes::summary( $app )['total'] === 11 && Votes::summary( $app )['count'] === 3, 'Administrateur inscrit : note personnelle incluse une seule fois' );
ob_start(); Votes::render( $app ); $html = ob_get_clean();
mp_check( substr_count( $html, 'name="score"' ) === 1 && str_contains( $html, 'Responsable — vous' ), 'Administrateur : seule sa propre note est modifiable' );
$raw = mp_raw( $edition ); $raw['mode'] = 'simple'; mp_config( $edition, $raw );
ob_start(); Review::decision_form( $app ); $html = ob_get_clean();
mp_check( str_contains( $html, 'Enregistrer la sélection' ) && is_wp_error( Votes::record( $app, '4' ) ), 'Administrateur en mode simple : sélection disponible, notation désactivée' );
$simple_app = mp_post( 'mp_candidature', 'Notifications mode simple' ); update_post_meta( $simple_app, Records::META, Records::data( $app ) );
$before_mails = count( $mails ); Notifications::send( $simple_app ); $sent = array_slice( $mails, $before_mails );
mp_check( count( $sent ) === 2 && $sent[1]['to'] === get_userdata( $manager )->user_email && in_array( 'Reply-To: ' . get_userdata( $manager )->user_email, $sent[0]['headers'], true ), 'Mode simple : candidat et administrateur notifiés, réponse au premier administrateur' );
$raw = mp_raw( $edition ); $raw['mode'] = 'multiple'; mp_config( $edition, $raw );
$before = Jury::settings( $edition ); $raw = mp_raw( $edition ); $raw['administrators'] = array();
mp_check( is_wp_error( mp_config( $edition, $raw ) ) && Jury::settings( $edition ) === $before, 'Au moins un administrateur actif obligatoire, réglages conservés en cas de refus' );
$raw = mp_raw( $edition ); $raw['administrators'][] = $raw['members'][0];
mp_check( is_wp_error( mp_config( $edition, $raw ) ) && ! user_can( $a, 'mp_manage_editions' ), 'Même email dans les deux tableaux refusé avant toute promotion' );
$raw = mp_raw( $edition ); $raw['administrators'] = 'invalide';
mp_check( is_wp_error( mp_config( $edition, $raw ) ), 'Structure du tableau administrateur validée côté serveur' );

// Une erreur de paramètres ne doit jamais créer un compte ou accorder des droits.
$new_email = 'mpvote_' . substr( $state['run'], 0, 8 ) . '_gestion@example.test';
$raw = mp_raw( $edition ); $raw['administrators'][] = array( 'user_id' => '0', 'name' => 'Gestion test', 'email' => $new_email, 'active' => '1' );
$_POST = array( 'mp_edition_nonce' => wp_create_nonce( 'mp_save_edition_' . $edition ), 'mp_edition' => array( 'year' => '2098', 'opens' => 'invalide', 'closes' => '2098-12-31T23:00' ), 'mp_jury_nonce' => wp_create_nonce( 'mp_jury_' . $edition ), 'mp_jury' => $raw );
$before_mails = count( $mails ); Editions::save( $edition );
mp_check( ! email_exists( $new_email ) && count( $mails ) === $before_mails && Jury::settings( $edition ) === $before, 'Dates invalides : aucun compte créé, aucune invitation ni changement d’équipe' );
$_POST['mp_edition'] = array( 'year' => '2098', 'opens' => '2020-01-01T00:00', 'closes' => '2098-12-31T23:00' );
Editions::save( $edition ); $_POST = array();
$new_admin = (int) email_exists( $new_email );
if ( $new_admin ) { update_user_meta( $new_admin, '_mp_vote_test', $state['run'] ); $state['users'][] = $new_admin; mp_state(); }
mp_check( $new_admin && get_userdata( $new_admin )->roles === array( 'mp_organizer' ) && isset( Jury::administrators( $edition )[ $new_admin ] ), 'Enregistrer l’édition crée le profil administrateur du marché' );
$invitation = end( $mails );
mp_check( count( $mails ) === $before_mails + 1 && str_contains( $invitation['subject'], 'Invitation administrateur du marché' ) && str_contains( $invitation['message'], 'Choisir mon mot de passe' ) && str_contains( $invitation['message'], 'enregistrez la sélection finale' ), 'Invitation administrateur avec choix du mot de passe et explication de son rôle' );
mp_check( ! array_key_exists( 'organizer_email', Editions::settings( $edition ) ) && Jury::contact_email( $edition ) === get_userdata( $manager )->user_email, 'Le contact dépend uniquement du compte administrateur' );
wp_set_current_user( $new_admin ); mp_check( true === Votes::record( $app, '0' ) && Votes::summary( $app )['expected'] === 5, 'Nouvel administrateur immédiatement autorisé à voter, zéro comptabilisé' );
$multiple_app = mp_post( 'mp_candidature', 'Notifications plusieurs administrateurs' ); update_post_meta( $multiple_app, Records::META, Records::data( $app ) );
$before_mails = count( $mails ); Notifications::send( $multiple_app ); $sent = array_slice( $mails, $before_mails );
mp_check( count( $sent ) === 6 && count( array_unique( array_column( $sent, 'to' ) ) ) === 6 && in_array( $new_email, array_column( $sent, 'to' ), true ), 'Tous les administrateurs et votants actifs notifiés en mode multiple, sans doublon' );
$raw = mp_raw( $edition ); $before = Jury::settings( $edition ); $raw['administrators'][1]['active'] = '0'; mp_config( $edition, $raw );
mp_check( user_can( $new_admin, 'mp_manage_editions' ) && is_wp_error( Votes::record( $app, '3' ) ) && Votes::summary( $app )['expected'] === 4, 'Désactivation dans une édition : vote retiré, droits globaux du compte conservés' );

// Déplacement d'un compte existant : la note suit son identifiant, sans doublon.
$raw = mp_raw( $edition ); $raw['administrators'][] = $raw['members'][1]; unset( $raw['members'][1] );
mp_check( true === mp_config( $edition, $raw ) && user_can( $b, 'mp_manage_editions' ) && in_array( 'mp_organizer', get_userdata( $b )->roles, true ) && ! in_array( 'mp_juror', get_userdata( $b )->roles, true ), 'Déplacer un votant dans les administrateurs attribue le rôle de gestion' );
mp_check( (int) Votes::all( array( $app ) )[ $app ][ $b ]['score'] === 4 && Votes::summary( $app )['total'] === 11 && Votes::summary( $app )['expected'] === 4, 'Promotion : anciennes notes et totaux conservés sans double participation' );
$site_admin = mp_user( 'AdminSite', 'administrator' );
$raw = mp_raw( $edition );
$raw['administrators'][] = array( 'user_id' => '0', 'name' => 'Administrateur WordPress existant', 'email' => get_userdata( $site_admin )->user_email, 'active' => '1' );
$roles_before = get_userdata( $site_admin )->roles;
mp_check( true === mp_config( $edition, $raw ) && get_userdata( $site_admin )->roles === $roles_before, 'Le rôle d’un administrateur WordPress existant reste inchangé' );

wp_set_current_user( $a );
mp_check( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) && ! current_user_can( 'mp_manage_jury' ), 'Le votant limité ne récupère aucun droit de publication ni de gestion de l’équipe' );
wp_set_current_user( $manager );
ob_start(); Jury::box( get_post( $edition ) ); $html = ob_get_clean();
mp_check( str_contains( $html, '<h3>Administrateur du marché</h3>' ) && str_contains( $html, '<h3>Votant pour la sélection</h3>' ) && substr_count( $html, '<table ' ) === 2, 'Deux tableaux distincts dans Organisateur et votes' );
ob_start(); Editions::render_box( get_post( $edition ) ); $html = ob_get_clean();
mp_check( ! str_contains( $html, 'name="mp_edition[organizer_email]"' ), 'Le champ email indépendant de l’organisateur a disparu' );
wp_set_current_user( 1 );
