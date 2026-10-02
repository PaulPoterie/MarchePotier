# Consignes de développement du plugin

Ces consignes conservent les enseignements des retours WordPress.org et les choix du propriétaire. Elles s'appliquent à chaque modification. Les demandes explicites de l'utilisateur priment ; si une demande risque de réintroduire un défaut ou de contredire une exigence WordPress.org, expliquer le point précis et proposer une solution compatible.

## Avant une modification

- Vérifier la branche, le diff et les changements existants ; les préserver.
- Lire `docs/DEVELOPPEMENT.md` pour les contrats métier. Consulter les bilans `docs/REVUE-WORDPRESS-2026-09-23.md` et `docs/REVUE-WORDPRESS-2026-09-29.md` pour les défauts corrigés. Ces bilans sont historiques : les choix plus récents priment, notamment pour le nom.
- Repérer les autres points d'entrée et usages du comportement modifié : administration, formulaire public, AJAX/REST, tâches planifiées, exports, emails et galerie. Corriger les cas similaires concernés, pas seulement l'exemple signalé par un contrôleur.
- Vérifier la documentation officielle lorsqu'une nouvelle fonctionnalité touche une règle de l'annuaire ou une API dont le comportement est incertain. Distinguer une exigence officielle d'une convention propre au projet.

## Identité et compatibilité

- Nom officiel : **Poterie Navarraise Pottery Market Manager**. Présentation française : **Poterie Navarraise — Organisation de marchés potiers**.
- Dans l'administration, le menu s'appelle **Gestion Marché Potier**, l'accueil **Gestion de Marché Potier**, avec la mention discrète « fait par Poterie Navarraise ». Ces libellés ne changent ni le nom officiel, ni le slug, ni les rôles existants.
- Slug WordPress.org, dossier distribué et domaine gettext : `poterie-navarraise-market-manager`. Utiliser ce domaine littéral dans les traductions, avec échappement adapté au contexte.
- Préfixes internes : `marcpo_`, `_marcpo_` et `marcpo-` selon le contexte ; namespace PHP `MarchePotier`. Ne pas réintroduire le préfixe court `mp_` pour les nouveaux identifiants propres au plugin.
- Conserver les identifiants existants tels que `marche-potier.php`, les blocs `marche-potier/...`, la route REST et les clés de stockage. Le nom public n'impose pas leur remplacement global.
- L'abandon des anciennes données de test lors du passage à `marcpo_` était une exception autorisée. Pour les évolutions futures, préserver les candidatures, votes, affectations, réglages et médias ; prévoir et tester une migration si nécessaire.

## Entrées, autorisations et sorties

- Vérifier présence, type et structure avant toute transformation. Refuser les tableaux à la place des scalaires, valeurs hors liste, identifiants invalides, nombres hors limites et champs structurés inattendus. Ne pas convertir une entrée invalide en une valeur valide par un nettoyage permissif.
- Nettoyer les données au plus tôt avec la fonction adaptée (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_email`, `sanitize_url`, etc.) et toujours appliquer la validation métier. Valider aussi les réponses des services externes et les valeurs relues du cache.
- Appliquer `wp_unslash()` une seule fois aux entrées HTTP textuelles slashes par WordPress. `Request::post()` et `Request::query()` le font déjà et contrôlent les scalaires ; ils ne remplacent pas les validations propres à chaque champ. Ne pas les utiliser pour du texte libre arbitraire sans adapter explicitement leur contrat.
- Tout nonce fourni à `wp_verify_nonce()` doit être une chaîne nettoyée par `sanitize_text_field()` après cet unique `wp_unslash()`. Pour un accès direct à `$_POST` ou `$_GET`, rendre ce contrôle visible au point d'utilisation. Ne pas désactiver une règle PHPCS pour éviter de corriger un problème réel.
- Avant une écriture ou un accès protégé, contrôler l'autorisation serveur sur l'objet et l'édition. Un nonce n'est pas une permission. Conserver les contrôles d'affectation active des votants ; l'auteur du vote vient de la session, jamais d'un ID envoyé par le navigateur.
- Le candidat public n'a pas de compte WordPress : préserver session, signature liée au formulaire et à l'édition, nonce, dates, limites de débit et propriété/révision des brouillons. Ne pas remplacer ces protections par une simple vérification de connexion.
- Échapper au dernier moment selon la destination : `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` ; `wp_kses_post` seulement pour le HTML effectivement autorisé. Ne pas pré-échapper les données stockées. Utiliser `wp_json_encode` pour les données JavaScript et les API WordPress pour les réponses/redirections.
- Utiliser les API WordPress et préparer les requêtes SQL contenant des valeurs variables. Garder des listes autorisées pour les tris et identifiants SQL dynamiques. Préserver l'usage adapté de `wp_slash()` lors des écritures via des API qui retirent les slashs.

## Fichiers et données conservées

- Passer par `Request::uploads()` et `MediaLibrary::validate_uploads()` ; ne pas transmettre `$_FILES` brut au métier. Conserver la liste des emplacements autorisés, les contrôles de structure, d'origine réelle du transfert, de taille réelle, de nom, d'extension, de MIME détecté et les limites de traitement des images/PDF.
- Ne pas faire confiance au MIME ou à la taille déclarés par le navigateur. Ne pas appliquer `wp_unslash` ou un nettoyage textuel aux chemins temporaires PHP ou aux données binaires.
- Préserver les API de transfert et de pièces jointes WordPress, la propriété des brouillons, les révisions et le verrou commun. Ne pas reprendre un `SubmissionLock` déjà détenu ; garantir sa libération avec `finally`.
- Le verrou commun utilise une ligne d'options atomique et un propriétaire unique, indépendamment du cache. Ne pas réintroduire `GET_LOCK`/`RELEASE_LOCK` ni interpréter leur simulation SQLite comme une protection. Préserver la suppression conditionnelle par propriétaire, le secours de fin de requête et l'absence de vol par expiration ; documenter la récupération après arrêt brutal. Tester les modifications de ce mécanisme sur MySQL et SQLite officiel, avec plusieurs processus.
- Ne nettoyer comme temporaires que les médias explicitement marqués et non référencés, corbeille comprise. Préserver les médias confirmés lors d'un échec, remplacement ou retrait de candidature, selon le cycle documenté.
- Les URL des médias, justificatifs compris, sont publiques par choix explicite du propriétaire. Garder cette information exacte dans les écrans et documents ; ne pas promettre une confidentialité que le stockage n'assure pas. Toute nouvelle demande de confidentialité doit être traitée comme une évolution du stockage et des accès.

## Services externes et consentements

- IGN et OpenStreetMap restent désactivés par défaut et activables séparément par un administrateur avec `manage_options`, via la Settings API. Aucun appel ne doit précéder l'autorisation correspondante.
- La localisation du candidat nécessite en plus `map_consent === true`, distinct de l'accord de publication, facultatif et décoché par défaut. Son absence ne vaut pas accord. Le refus ne bloque ni la candidature ni la galerie autorisée sans carte.
- Vérifier IGN à la planification ET à l'exécution, OSM au rendu. Préserver la révocation, la suppression des coordonnées dérivées et l'annulation des tâches concernées ; tenir compte des caches documentés.
- Le téléphone reste obligatoire, sans opt-in de contact téléphonique : c'est le choix actuel, à ne pas confondre avec le consentement cartographique.
- Documenter en anglais dans `readme.txt` tout service externe : fournisseur, finalité, données envoyées, moment du contact, activation, liens vers conditions et confidentialité. Expliquer les conséquences d'un nouveau service avant d'introduire un nouveau transfert de données.

## Ressources, interface et documentation

- Charger les scripts/styles avec les hooks et API d'enqueue WordPress, les bonnes dépendances et uniquement sur les écrans concernés. Utiliser les API d'ajout inline pour les données dynamiques nécessaires, pas des balises script/style improvisées dans les rendus.
- Ne jamais amorcer WordPress depuis le code distribué avec `wp-load.php`, `wp-config.php` ou `wp-blog-header.php`. Charger une bibliothèque du cœur uniquement si elle est nécessaire, avec `require_once` au point d'utilisation : `file.php` pour `wp_handle_upload`, `image.php` pour `wp_generate_attachment_metadata`. Ne pas réintroduire le chargement inutilisé de `wp-admin/includes/media.php` signalé lors de la revue du 1er octobre 2026. Les bancs CLI/HTTP hors distribution restent distincts du plugin.
- Réutiliser les bibliothèques fournies par WordPress. Leaflet reste embarqué localement ; préserver sa licence et les liens vers les sources correspondant à la version et aux outils de construction. Ne pas modifier ses fichiers pour un changement métier du plugin.
- Garder les notices administratives ciblées par écran et permission, utiles à la résolution, et refermables lorsque requis. Ne pas réintroduire les alertes globales répétitives corrigées pendant la revue.
- Garder des dépendances sous licences compatibles, leurs attributions et des sources lisibles accessibles. Ne pas ajouter de télémétrie, ressources distantes, crédits publics promotionnels ou mécanisme de mise à jour externe par défaut.
- Mettre à jour ensemble les textes affectés : `readme.txt` et présentation GitHub en anglais, guide utilisateur français, documentation métier, changelog lors d'une livraison. Ne pas annoncer une compatibilité ou un résultat de test non vérifié.

## Entretien et lisibilité

- Pour le périmètre modifié, rechercher les fonctions, ressources, paramètres et branches devenus inutiles. Vérifier les appels directs mais aussi les hooks, callbacks de blocs/REST, tâches planifiées, scripts et anciens liens avant de supprimer. Une recherche textuelle sans appel direct ne prouve pas qu'un callback WordPress est mort.
- Retirer les restes confirmés d'une implémentation abandonnée. Conserver les migrations et contrats de données encore utiles aux mises à jour ; expliquer leur rôle au lieu de les supprimer parce que le site de développement a déjà migré.
- Ajouter des commentaires ciblés sur les responsabilités, préconditions, invariants et raisons des choix : verrou, reprise après échec, consentement, compatibilité. Ne pas paraphraser chaque instruction ni annoncer une garantie absente du code.
- Consigner les limites et dettes identifiées dans `docs/DEVELOPPEMENT.md`. Une revue ponctuelle ne garantit pas l'absence totale de code mort ou de dette ; distinguer les corrections réalisées des refontes restant à envisager.

## Vérifications proportionnées à la modification

- Relire le diff et les usages similaires ; vérifier la syntaxe PHP/JS modifiée et `git diff --check`.
- Pour une modification de sécurité ou de comportement, exécuter les suites pertinentes ci-dessous ; ajouter un cas de régression utile si le défaut n'est pas couvert. Ne pas créer de tests qui recopient l'implémentation pour une simple modification rédactionnelle.

| Modification | Contrôles pertinents |
| --- | --- |
| Verrou commun, compatibilité de base | `tests/submission-lock-local.php` dans les bases jetables, puis suites votes et médias sur chaque moteur ciblé |
| Entrées, droits, éditions, votes, blocs, tri | `tests/votes-local.php`, qui appelle aussi les régressions Plugin Check et les suites secondaires |
| Transferts, formulaires, brouillons, médias | `tests/media-lifecycle-local.php`, avec les véritables requêtes HTTP du banc local |
| Cartographie, consentement, services | `tests/cartography-local.php`, avec réponses externes simulées |
| Modification rapide, scripts, notices, capacités | `tests/review-compliance-local.php` |
| Rendu et interactions | Vérification navigateur des parcours affectés comme organisateur, votant et/ou candidat |

- Lire les prérequis des suites et du guide de développement. Intercepter les emails, simuler les services externes et nettoyer uniquement les fixtures créées par le test. Ne pas effacer un manifeste ou des données préexistantes pour forcer un test à démarrer.
- Pour les changements d'entrées, sorties ou autorisations, relancer les contrôles WPCS adaptés : `WordPress.Security.ValidatedSanitizedInput`, `WordPress.Security.EscapeOutput`, `WordPress.Security.NonceVerification`. Pour les identifiants/traductions, vérifier aussi préfixes et domaine.
- Avant un nouveau ZIP destiné à WordPress.org, lancer Plugin Check officiel sur le paquet actualisé, avec tous les contrôles standards applicables, sans masquage d'avertissements. Corriger ou expliquer précisément les diagnostics restants.
- Ne pas confondre contrôle statique et contrôle à l'exécution : charger le bootstrap officiel, vérifier que le plugin cible est effectivement actif/chargé dans l'environnement de test isolé, avec `WP_DEBUG`, et conserver la liste des contrôles réellement exécutés. Le simple `--require` de `cli.php` ne prouve pas leur activation.
- Conserver résultat, versions des outils et empreintes des sources testées dans un nouveau rapport. Un ancien résultat vert ne valide pas des fichiers modifiés. Les tests CLI ne prouvent pas le rendu navigateur ni la livraison d'emails ; Plugin Check ne remplace pas la revue humaine WordPress.org.

## Préparation d'une livraison

- Changer la version pour une nouvelle livraison publiée, sans modifier silencieusement un tag publié. Les champs `Version:` et `Stable tag:` doivent correspondre et contenir uniquement chiffres et points ; les suffixes comme `-beta.1` ont déjà été rejetés pour cette soumission.
- Actualiser les versions de cache des assets modifiés ; les versions de migration du schéma et des droits sont distinctes. Ne pas transformer une modification de documentation en changement de version automatique.
- Construire le ZIP depuis la source vérifiée, sous le dossier `poterie-navarraise-market-manager/`, avec `marche-potier.php`, `readme.txt`, `includes/` et `assets/`. Réévaluer cette liste si de nouveaux fichiers d'exécution sont nécessaires.
- Exclure tests, docs de développement, `AGENTS.md`, rapports, outils locaux, secrets et fixtures du ZIP. Contrôler contenu, cohérence de version et empreinte ; garder les sources tierces et licences requises accessibles comme documenté.
- GitHub sert au développement ; une sauvegarde GitHub ne publie pas une mise à jour WordPress.org. La stratégie d'automatisation des versions reste à décider avec le propriétaire. Ne pas ajouter un updater GitHub au plugin destiné à l'annuaire.

## Références officielles

Consultées le 30 septembre 2026 ; vérifier à nouveau les points concernés si les règles évoluent :

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Sanitizing Data](https://developer.wordpress.org/apis/security/sanitizing/)
- [Escaping Data](https://developer.wordpress.org/apis/security/escaping/)
- [Utilisation et vérification des nonces](https://developer.wordpress.org/news/2023/08/understand-and-use-wordpress-nonces-properly/)

Ces consignes complètent les règles officielles ; elles ne constituent pas une certification de conformité du plugin.
