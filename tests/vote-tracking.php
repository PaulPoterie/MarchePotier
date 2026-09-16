<?php
/** Suivi partagé, contrôle de l’édition et dénominateur des compteurs. */
use MarchePotier\{VoteTracking,Jury,Records,Plugin};
$tracking = VoteTracking::data( $edition );
mp_check( ! is_wp_error( $tracking ) && count( $tracking['ids'] ) === 2 && count( $tracking['members'] ) === 4 && isset( $tracking['members'][ $manager ] ), 'Suivi : deux candidatures et quatre membres, administrateur compris' );
mp_check( $tracking['counts'][ $a ] === 1 && $tracking['scores'][ $app ][ $a ] === 0 && $tracking['counts'][ $b ] === 0 && ! isset( $tracking['scores'][ $second ][ $a ] ), 'Suivi : le zéro compte, l’absence de note ne compte pas' );
$_GET = array( 'mp_edition' => (string) $edition, 'mp_my_vote' => 'rated' );
ob_start(); VoteTracking::render(); $tracking_html = ob_get_clean();
mp_check( str_contains( $tracking_html, '1 vote / 2 candidatures' ) && str_contains( $tracking_html, '0 votes / 2 candidatures' ) && str_contains( $tracking_html, '>0/5</td>' ) && str_contains( $tracking_html, 'Pas encore voté' ), 'Suivi : notes et compteurs complets, indépendants du filtre personnel de la gestion' );
mp_check( ! str_contains( $tracking_html, 'name="score"' ) && ! str_contains( $tracking_html, 'Enregistrer la sélection' ) && ! str_contains( $tracking_html, 'confidentielle' ), 'Suivi en consultation uniquement, aucune édition étrangère affichée au votant' );
$_GET = array( 'mp_edition' => (string) $edition, 'mp_from' => 'votes', 'candidature' => (string) $app );
ob_start(); Records::view(); $tracking_html = ob_get_clean();
mp_check( str_contains( $tracking_html, esc_url( VoteTracking::url( $edition ) ) ) && strpos( $tracking_html, '>Suivi des votes</a>' ) < strpos( $tracking_html, '>Historique des sélections</a>' ) && Records::list_context()['mp_from'] === 'votes', 'Examiner : retour au suivi de la bonne édition et bouton avant Historique des sélections' );
ob_start(); Plugin::render_dashboard(); $tracking_html = ob_get_clean();
mp_check( str_contains( $tracking_html, '>Suivi des votes</a>' ) && str_contains( $tracking_html, '>Historique des sélections</a>' ) && ! str_contains( $tracking_html, '>Historique des candidatures</a>' ), 'Accueil : accès au suivi et nouveau libellé de l’historique' );
wp_set_current_user( $b );
mp_check( VoteTracking::data( $edition )['counts'] === $tracking['counts'], 'Tous les votants de l’édition consultent les mêmes compteurs' );
$_GET = array( 'mp_edition' => (string) $other );
mp_check( is_wp_error( VoteTracking::data( $other ) ), 'Suivi : accès refusé à une édition non affectée' );
mp_denied( array( VoteTracking::class, 'render' ), 'URL de suivi forgée : édition non affectée refusée' );
$_GET = array( 'mp_edition' => array( (string) $edition ) );
mp_denied( array( VoteTracking::class, 'render' ), 'Suivi : paramètre édition non scalaire refusé' );
$tracking_jury = get_post_meta( $edition, Jury::META, true );
try {
	$inactive = $tracking_jury; $inactive['members'][ $a ]['active'] = false;
	update_post_meta( $edition, Jury::META, $inactive );
	$tracking_inactive = VoteTracking::data( $edition );
	mp_check( ! isset( $tracking_inactive['members'][ $a ] ) && ! isset( $tracking_inactive['scores'][ $app ][ $a ] ) && count( $tracking_inactive['members'] ) === 3, 'Suivi : les membres inactifs ne faussent pas la progression du jury actif' );
	$simple = $tracking_jury; $simple['mode'] = 'simple'; update_post_meta( $edition, Jury::META, $simple );
	$_GET = array( 'mp_edition' => (string) $edition );
	ob_start(); VoteTracking::render(); $tracking_html = ob_get_clean();
	mp_check( str_contains( $tracking_html, 'aucune notation n’est attendue' ) && ! str_contains( $tracking_html, 'mp-vote-tracking-table' ), 'Suivi : le mode simple explique l’absence de notation' );
} finally { update_post_meta( $edition, Jury::META, $tracking_jury ); }
wp_set_current_user( $manager );
mp_check( VoteTracking::data( $edition )['counts'] === $tracking['counts'], 'Administrateur marché : mêmes notes et compteurs que les votants' );
wp_set_current_user( 1 );
mp_check( VoteTracking::data( $edition )['counts'] === $tracking['counts'], 'Administrateur WordPress : consultation possible sans participation au jury' );
$tracking_status = get_post_status( $second );
try {
	wp_update_post( array( 'ID' => $second, 'post_status' => 'trash' ) );
	$tracking_trash = VoteTracking::data( $edition );
	mp_check( $tracking_trash['ids'] === array( $app ) && $tracking_trash['counts'][ $a ] === 1, 'Suivi : candidatures à la corbeille exclues des lignes et des totaux' );
} finally { wp_update_post( array( 'ID' => $second, 'post_status' => $tracking_status ) ); }
wp_set_current_user( $outsider );
mp_check( is_wp_error( VoteTracking::data( $edition ) ), 'Compte sans droits : suivi inaccessible' );
wp_set_current_user( 0 ); $_GET = array();
mp_denied( array( VoteTracking::class, 'render' ), 'Visiteur anonyme : suivi inaccessible' );
wp_set_current_user( $a );
