# Reprendre le développement de Poterie Navarraise Pottery Market Manager

## 1. Point de départ

Le fichier `marche-potier.php` charge explicitement les classes du namespace `MarchePotier`. `Plugin::boot()` enregistre leurs hooks WordPress. Le plugin utilise PHP, CSS et JavaScript natifs ; il n’y a ni Composer, ni npm, ni compilation. Leaflet est une dépendance embarquée dans `assets/vendor/leaflet` ; ne pas y appliquer les changements du plugin.

Le README présente le plugin en anglais ; le guide utilisateur français se trouve dans `GUIDE-UTILISATEUR-FR.md`. Ce document décrit les contrats du code. Le guide `VOTES.md` détaille les affectations, invitations et changements d’administrateur.

### Particularité de cet espace de travail

| Emplacement | Usage |
| --- | --- |
| `C:\Users\paul\Projects\MarchePotier\.tools\publish-MarchePotier` | Dépôt actif. Les correctifs de `fix/wordpress-review` sont fusionnés dans `main` depuis la version 0.19.2 ; `feature/ville-editions` est intégrée pour la 0.19.3. Vérifier la branche avant toute nouvelle modification. |
| `C:\Users\paul\Projects\MarchePotier\marche-potier` | Copie de lecture du code courant. À synchroniser depuis le dépôt actif après chaque modification du plugin et changement de branche de travail, puis vérifier les empreintes. |
| `C:\Users\paul\Local Sites\marche-potier-test\app\public\wp-content\plugins\marche-potier` | Ancienne copie installée (0.19.0-beta.9). Les suites de test de revue chargent directement le dépôt actif à sa place, uniquement dans leur processus. |
| `C:\Users\paul\Local Sites\marche-potier-migration\app\public\wp-content\plugins\poterie-navarraise-market-manager` | Copie récente du plugin utilisée dans Local. Synchroniser les fichiers vérifiés depuis le dépôt, après comparaison et sauvegarde des fichiers remplacés. |
| `C:\Users\paul\Projects\MarchePotier\reports` | Rapports et résultats locaux, hors du paquet installable. |
| `C:\Users\paul\Projects\MarchePotier\dist` | ZIP de livraisons ponctuelles, potentiellement antérieurs à la branche. |

Ce dépôt ne déploie rien automatiquement. Sur un autre ordinateur, son emplacement peut être quelconque ; ces chemins décrivent uniquement l’installation de développement actuelle.

### Nom, traductions et identifiants

Le menu d'administration est **Gestion Marché Potier** et son accueil **Gestion de Marché Potier**, avec la mention « fait par Poterie Navarraise ». Le nom officiel ci-dessous reste celui de l'extension dans l'annuaire et les en-têtes. `Plugin::dashboard_assets()` limite le CSS de l'accueil à sa page et aux utilisateurs autorisés ; la version de contact vient de l'en-tête du fichier principal.

Le nom officiel est « Poterie Navarraise Pottery Market Manager », avec la description française « Poterie Navarraise — Organisation de marchés potiers ». Le slug WordPress.org, le dossier du prochain paquet et le domaine de traduction sont `poterie-navarraise-market-manager`. Les appels gettext doivent utiliser ce domaine littéral.

Le site de présentation est `https://gestion-marche-potier.poterie-navarraise.info/`. L'en-tête `Plugin URI` de `marche-potier.php` est relu avec la version pour le lien « Visiter le site du plugin » dans la rubrique d'aide de l'accueil. Ce sont de simples liens : aucun appel au site n'est effectué automatiquement par le plugin. Les liens GitHub des sources et des releases gardent leur fonction distincte.

Le préfixe `marcpo_` (six lettres), sa variante `marcpo-` et le namespace PHP `MarchePotier` restent les identifiants internes. Ils évitent les collisions et ne sont pas tenus de correspondre au slug. Le fichier principal `marche-potier.php`, les identifiants des blocs, la route REST, les clés de stockage et les chemins historiques restent stables. Ne pas faire de remplacement global de `marche-potier`.

Références : [préfixes WordPress](https://developer.wordpress.org/plugins/plugin-basics/best-practices/#prefix-everything) et [domaine de traduction](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#text-domains).

## 2. Où intervenir ?

| Besoin | Fichiers / classes dans `includes/` |
| --- | --- |
| Initialisation, accueil du plugin | `class-plugin.php` |
| Autorisation des services IGN et OpenStreetMap par l’administrateur | `class-external-services.php` |
| Paramètres, dates et droits de gestion | `class-editions.php` |
| Administrateur unique, votants, invitations, accès par édition | `class-jury.php` |
| Enregistrement et calcul des notes | `class-votes.php` |
| Matrice d’avancement des notes | `class-vote-tracking.php` |
| Coordonnées, champs métier, validation commune | `class-fields.php` |
| Types de contenus, édition interne, liste des IDs autorisés, Examiner, historique | `class-records.php` |
| Gestion des candidatures, rendu des détails, sélection finale | `class-review.php` |
| Export des réponses et liens durables vers les pièces | `class-csv-export.php` |
| Blocs WordPress et liste d’éditions de l’éditeur | `class-blocks.php` |
| Dépôt public, session, validation HTTP et confirmation | `class-public-form.php` |
| Pièces temporaires et reprise du dépôt | `class-upload-drafts.php` |
| Compatibilité des anciens liens et affichage des fichiers | `class-private-files.php` |
| Médias WordPress, états provisoires, rattachement et migration | `class-media-library.php` |
| Copies d’images 1080 × 1350, sans champs supplémentaires | `class-social-images.php`, `class-social-image-editor-gd.php`, `class-social-image-editor-imagick.php` |
| Emails après candidature | `class-notifications.php` |
| Présentation publique et localisation | `class-gallery.php`, `class-gallery-map.php` |
| Verrou commun et migration des identités anciennes | `class-submission-lock.php`, `class-identity-migration.php` |

Les noms des fichiers CSS/JS suivent leur écran : `review.*` sert à Gestion et Examiner, `jury.*` aux affectations, `vote-tracking.css` au suivi. `blocks.js` utilise les bibliothèques fournies par WordPress. `form.js` gère le formulaire public ; `draft.js` sa sauvegarde dans le navigateur.

## 3. Données et règles métier

| Donnée | Stockage et responsabilité |
| --- | --- |
| Édition | Contenu `marcpo_edition`, paramètres dans `_marcpo_edition_settings`. L’ID est la référence ; l’année est un libellé, pas une clé unique. |
| Équipe | `_marcpo_jury_settings` : `mode`, `revision`, `members[ID compte]`. Chaque membre possède `name`, `kind`, `active` et éventuellement `invitation`. |
| Identité d’historique | Contenu `marcpo_potier`, `_marcpo_record.identity` minimal (nom, prénom, email). Ce n’est pas un compte WordPress de candidat. |
| Candidature | Contenu `marcpo_candidature`, réponses dans `_marcpo_record` : `edition_id`, `potier_id`, `identity`, `activity`, `internal`, `decision`, `files`, consentement et date de dépôt. |
| Index de candidature | `_marcpo_edition_id`, `_marcpo_potier_id`, `_marcpo_decision` servent aux requêtes. Les maintenir cohérents avec `_marcpo_record` à chaque écriture. |
| Notes | Table `${prefix}marcpo_votes`, clé unique `(application_id, user_id)`. Une correction remplace la note ; elle ne crée pas un deuxième vote. |
| Fichiers | Pièces jointes WordPress identifiées par `files[slot].attachment_id`, copies Meta par `files[slot].social.attachment_id`. `_marcpo_temporary_until` et `_marcpo_draft_owner` identifient exclusivement les médias provisoires. Après écriture du dossier, le rattachement est établi et ces marqueurs sont retirés. Les URLs sont publiques, y compris pour les justificatifs. |

Les réponses sont propres à chaque candidature : corriger une année ne doit pas réécrire les réponses d’une autre année. L’identité d’historique est rapprochée par nom, prénom et email. Une adresse email ne peut déposer deux fois pour une même édition, corbeille comprise.

### Droits : rôle du compte et affectation ne sont pas synonymes

- `marcpo_organizer` est le rôle global **[Poterie Navarraise] Administrateur marché**. Ses droits de gestion concernent tout le site, pas uniquement les éditions dont il est le contact.
- `marcpo_juror` est **[Poterie Navarraise] Votant sélection**. `Jury::can_view_edition()` et `can_view_application()` contrôlent l’affectation active à chaque accès.
- `kind=administrator` dans une édition désigne son unique administrateur et contact. Le remplacer désactive son ancienne affectation ; cela ne révoque pas son rôle global WordPress.
- Pour voter, même un administrateur doit être membre actif de cette édition. `Votes::record()` prend exclusivement le compte de la session ; aucun ID d’auteur envoyé par le navigateur n’est accepté.
- `Jury::settings()` conserve les membres inactifs. `Jury::members()` ne renvoie que les actifs dont le compte existe : c’est cette liste qui sert aux totaux et aux destinataires.

### Trois décisions indépendantes

1. Les dates d’inscription autorisent ou refusent le **dépôt public** (`Editions::is_open`).
2. Le mode multiple et l’affectation active autorisent la **notation**, sans contrôle de date. `null` signifie absence de note ; `0` est une note.
3. La **publication** exige une édition publiée, l’autorisation de montrer sa sélection, une décision `selected` et le consentement du candidat (`Gallery::eligible`). Un total de points ne sélectionne personne automatiquement.

La **cartographie** ajoute l’accord distinct `map_consent === true` (`GalleryMap::eligible`). Les options `marcpo_external_services[ign]` et `[osm]` sont désactivées par défaut et enregistrées via la Settings API, avec nonce et permission `manage_options`. IGN est contrôlé à la planification et à l’exécution ; OSM est contrôlé au rendu. Retirer l’accord du candidat efface ses coordonnées dérivées et sa tâche planifiée ; les caches partagés de réponses IGN expirent séparément. Aucun accord ne doit être déduit de `publication_consent`.

Le mode simple conserve les notes, mais masque et interdit leur modification. Retirer un membre actif conserve ses anciennes notes tout en les excluant des totaux.

Les types de contenus des dossiers ne sont pas publics. Le statut WordPress `publish` d’une candidature ne signifie donc pas qu’elle apparaît dans la galerie : les règles de publication ci-dessus s’appliquent séparément.

## 4. Parcours du code

### Gestion, filtres, tris et navigation

`Records::list_context()` ne conserve que les paramètres GET autorisés. Le select `marcpo_sort` devient `orderby` + `order` dans les liens. `Records::navigation_ids()` applique accès, filtres, recherche et tri à la liste entière. Cette liste est partagée par la gestion, l’export, les boutons précédent/suivant et le suivi.

`Review::table()` prépare la pagination puis appelle les fonctions `management_header`, `management_filters`, `management_toolbar`, `management_rows` et `management_pagination`. Ces fonctions rendent les zones de l’écran ; elles ne doivent pas inventer une autre liste de candidatures.

Le select de tri est visuellement hors du formulaire, mais associé par `form="marcpo-application-filters"`. Le formulaire omet `paged` volontairement : changer un filtre ou un tri revient à la première page. Les liens de pagination et d’examen conservent le contexte. Le tri par soumission utilise `submitted_at`, pas la création WordPress ; le tri par nom utilise les coordonnées, pas le titre généré.

### Écritures et concurrence

Les points d’entrée HTTP vérifient les droits et le nonce avant d’appeler les fonctions métier. Le dépôt public ajoute une signature liée au cookie, vérifie l’édition du bloc et les dates, puis valide les champs et les pièces.

`SubmissionLock` est un verrou commun à la table d'options du site. Il utilise une insertion atomique de `_marcpo_submission_lock` avec `INSERT IGNORE`, comme le principe du verrou de `WP_Upgrader`, et l'index unique `option_name`. La valeur contient un propriétaire aléatoire et une date de diagnostic ; `autoload=no`. Aucune lecture ne passe par le cache d'options. La suppression exige le même propriétaire et utilise la table mémorisée à l'acquisition, même après un changement de blog. L'attente d'acquisition est limitée à trois secondes.

Il n’est pas réentrant : une fonction appelée sous verrou ne doit pas le reprendre. Utiliser `try/finally` pour le libérer ; un callback de fin de requête sert de secours. `Jury::configure()` et les opérations de brouillon attendent un verrou détenu par leur appelant ; `Votes::record()` et `PublicForm::submit()` prennent le leur. Le verrou ne rend pas plusieurs écritures transactionnelles. La `revision` du jury et celle des pièces détectent les formulaires périmés ; les réponses internes du dossier n’ont pas de révision équivalente.

Il n'y a pas de vol automatique d'un verrou ancien : dépasser une durée supposée ne prouve pas que son propriétaire s'est arrêté. Un arrêt brutal empêchant les callbacks PHP ou une perte de connexion à la base peut laisser la ligne en place. Pour la récupération, placer le site en maintenance, arrêter aussi cron/CLI et confirmer la fin de tous les traitements du plugin ; seulement ensuite retirer l'option `_marcpo_submission_lock` du site concerné, puis rouvrir les écritures. Ne pas ajouter un bouton de déblocage inconditionnel ni une expiration arbitraire. Une mise à jour depuis l'ancien verrou MySQL doit également attendre la fin des requêtes exécutant l'ancien code.

MySQL et SQLite via SQLite Database Integration 3.0.2 sont testés avec WordPress 7.1.2 / PHP 8.3.33. MariaDB suit le même chemin SQL WordPress, sans banc distinct pour ce correctif. L'intégration SQLite traduit aussi le schéma `dbDelta`, `SHOW TABLES` et l'UPSERT des votes : les conserver tant que leurs tests de compatibilité passent. Les autres adaptateurs de base ne sont pas validés. Ne pas transformer le résultat simulé `1=1` de `GET_LOCK` sous SQLite en succès : il ne constitue pas un verrou.

Les notifications de candidature réservent chaque tentative sous verrou puis appellent `wp_mail` après libération. Les invitations de comptes sont actuellement envoyées pendant la configuration du jury, sous son verrou : ne pas confondre ces deux circuits. Un échec d’email ne supprime pas la candidature et n’est pas relancé automatiquement.

L’invitation de l’administrateur utilise un bouton de soumission du formulaire natif d’édition, nommé `marcpo_jury[administrator][invite]`. Sa valeur n’est envoyée que lorsque ce bouton est utilisé. Avec JavaScript, `jury.js` transmet temporairement cette intention au bouton natif de sauvegarde pour conserver la gestion WordPress de l’autosauvegarde et du verrou ; une validation bloquante ne laisse pas l’intention active pour la sauvegarde suivante. Sans JavaScript, le bouton soumet directement le formulaire. Le circuit existant `Editions::save()` puis `Jury::save()` valide paramètres, nonces, droits et révision ; aucune nouvelle route d’envoi n’est ajoutée. Une nouvelle édition est enregistrée en brouillon. Les nouveaux comptes restent invités à leur création ; un enregistrement ordinaire ne réinvite pas un compte existant. Les cases d’invitation des votants restent liées à la sauvegarde.

## 5. Conventions à garder

Les [consignes de contribution et de prévention des régressions WordPress.org](../AGENTS.md) regroupent les exigences à appliquer à chaque modification et les contrôles avant livraison. Elles s'appuient sur les retours de revue déjà corrigés.

- Valider les types d’entrée avant nettoyage ; utiliser `wp_unslash` aux frontières HTTP et `wp_slash` lors de l’écriture des tableaux de métadonnées contenant du texte.
- Échapper au rendu (`esc_html`, `esc_attr`, `esc_url`). Les valeurs retournées par les fonctions métier ne sont pas pré-échappées.
- Un champ de `Fields` suit `[libellé, type, obligatoire, choix?, condition?]`. Ajouter un champ à ce schéma peut affecter formulaire, dossier, export et emails : vérifier ces usages ensemble.
- Charger les notes d’un ensemble de dossiers avec `Votes::all($ids)` puis transmettre celles du dossier au calcul. Cette fonction de stockage ne filtre pas les droits elle-même.
- Garder les notes séparées de la sélection ; ne pas ajouter de clôture de vote ni de sélection automatique par score.
- Garder les éditions par ID dans les blocs ; ne pas réintroduire de résolution par année ou de shortcodes.
- Les versions des assets sont des clés de cache et peuvent différer de la version du plugin. Changer celle d’un CSS/JS dont le comportement ou le rendu change. Les options de version du schéma et des droits sont des migrations distinctes, à modifier seulement si leur installation évolue.
- Commenter les invariants, les préconditions et les raisons d’un choix ; éviter les commentaires qui répètent simplement une instruction.

## 6. Vérifier une modification

`jury.js` masque et désactive le fieldset des votants en mode simple. L'absence de `members` dans une sauvegarde signifie « conserver les affectations », pas « désactiver tous les votants » ; aucune invitation n'est déduite de ces données conservées. Ce contrat couvre aussi le changement de mode sans JavaScript. Un administrateur remplaçant un votant conservé reprend son compte et sa note sans doublon.

L'historique applique les permissions avant la recherche. `s` est lu via `Request`, nettoyé et limité à 200 caractères ; seuls `last_name`, `first_name` et `email` de l'identité affichée participent au filtrage. Les mots sont littéraux, combinés par ET, sans distinction de casse ou d'accents. Les totaux d'édition sont calculés avant ce filtre. Les trois colonnes d'identité utilisent un `colgroup` de largeurs fixes pour ne pas s'étirer quand il y a peu d'éditions.

La ville interne d’une édition est `city` dans `_marcpo_edition_settings`. Toute sauvegarde complète exige une chaîne non vide de 120 caractères maximum ; nettoyage par `sanitize_text_field`, normalisation des espaces Unicode, contrôle serveur avant modification de l’équipe ou invitation. Les anciennes métadonnées sans `city` donnent une chaîne vide, sans migration déduite du lieu public ni blocage des candidatures existantes. `Editions::introduction()` et le formulaire public n’utilisent pas cette ville.

L’historique construit les choix de ville après contrôle d’accès aux éditions. Le paramètre `marcpo_city` utilise une empreinte de la ville normalisée sans accents et en minuscules, ou `missing` pour les anciennes éditions ; il doit correspondre aux choix autorisés. Un paramètre malformé ou inconnu donne un résultat vide. Ce filtre réduit les colonnes d’éditions et les candidatures avant les totaux et la recherche d’identité. La ville du candidat n’intervient pas. `tests/edition-city.php`, appelée par `votes-local.php`, couvre validation, conservation des données en cas d’échec, formulaire public, regroupement et cloisonnement des accès.

Depuis ce dépôt, avec un PHP CLI configuré pour joindre la base Local :

```powershell
php -l includes/class-review.php
php tests/votes-local.php 'C:/Users/paul/Local Sites/marche-potier-test/app/public'
git diff --check
```

Sur l’installation de Paul, le runtime utilisé est `C:/Users/paul/Projects/MarchePotier/.tools/php83/php.exe` avec `-c C:/Users/paul/Projects/MarchePotier/.tools/php83-test.ini`.

La suite refuse un site autre que `marche-potier-test.local`. Elle charge le code du dépôt, intercepte les emails, crée ses propres comptes et dossiers marqués, puis les retire dans `finally`. Elle écrit un manifeste temporaire `votes-fixtures.json` dans le parent du dépôt. Ne pas lancer deux exemplaires de cette suite simultanément. `--keep` sert à inspecter ses fixtures ; `--cleanup` retire celles du manifeste. Les données de démonstration existantes ne servent pas de fixtures jetables.

| Fichier de test | Couverture |
| --- | --- |
| `votes-local.php` | Bootstrap, droits, invitations, votes, concurrence et nettoyage ; appelle les fichiers suivants. |
| `personal-votes.php` | Filtre personnel, zéro, redirection de connexion. |
| `vote-tracking.php` | Cellules, compteurs, accès et membres actifs. |
| `market-administrators.php` | Administrateur unique, remplacement, droits et notifications. |
| `blocks-local.php` | Blocs par ID, dates et règles de publication ; absence de shortcodes. |
| `application-sorting.php` | Six tris, accents, données absentes, pagination et navigation. |
| `submission-lock-local.php` | Suite CLI autonome sur base jetable : exclusion entre processus, écritures concurrentes, propriétaire, cache, fin de requête, contexte de table, erreurs et migration vide. |
| `social-images-local.php` | Vrais éditeurs WordPress : JPEG/PNG/WebP, huit orientations EXIF, transparence, proportions, absence d'agrandissement, filtres, échecs et conservation de la candidature. |

Le banc d'images utilise une base SQLite jetable au préfixe `marcpo_img261002_`, la constante `MARCPO_IMAGE_REVIEW` et un `WP_CONTENT_DIR` isolé ; emails interceptés, HTTP externe bloqué, `WP_DEBUG` actif. Préparer les fixtures une fois avec `tests/social-images-fixtures.php <workspace>` dans un processus GD, puis exécuter `tests/social-images-local.php <racine WordPress> <workspace> <gd|imagick|both|none>` avec les quatre configurations PHP correspondantes, sans exécutions concurrentes sur la même base. Le générateur refuse d'écraser des fixtures existantes. Le processus Imagick doit réellement désactiver GD ; `both` vérifie aussi la préférence native pour Imagick. Les scripts locaux et DLL de test restent hors distribution dans `.tools/image-editor-review-20261002`, résultats dans `reports/image-editor-2026-10-02`. Relancer aussi `tests/media-lifecycle-local.php` sur le serveur HTTP isolé : son client GD fabrique les fichiers de test, le serveur doit être essayé avec GD seul puis Imagick seul.

Le banc de compatibilité de base, distinct du site Local habituel, définit `MARCPO_DB_REVIEW` et `WP_CONTENT_DIR` avant le bootstrap WordPress. Son `db.php` sélectionne exclusivement le préfixe jetable `marcpo_db261002_`, et pour SQLite définit `DB_ENGINE=sqlite` avec un `DB_DIR` isolé et l'intégration officielle. Les configurations PHP des processus enfants doivent reprendre ce bootstrap. Il intercepte les emails, bloque le HTTP externe et active `WP_DEBUG`. Ne jamais lancer ce test de verrou sur une base de production : il simule notamment un remplacement de propriétaire. Le banc courant et les résultats sont dans `.tools/database-review-20261002` et `reports/database-compatibility-2026-10-02`, hors distribution.

Les fichiers secondaires partagent les fixtures du point d’entrée : ne pas les exécuter seuls. Pour un changement visuel, vérifier Gestion et Examiner avec un administrateur et un votant, ainsi que le défilement sur écran étroit. Les tests CLI ne prouvent ni le rendu navigateur ni la livraison réelle des emails.

## 7. Limites connues à distinguer des garanties du code

- Les médias sont intentionnellement publics par URL. Les anciens liens de téléchargement redirigent vers les médias sans authentification. La galerie conserve ses règles de sélection et de consentement. La migration conserve les anciennes copies extérieures à uploads après vérification ; leur éventuel retrait relève d’une maintenance distincte.
- Les listes et l’historique chargent tous les IDs concernés, et le rapprochement d’identité parcourt les fiches. Le fonctionnement est adapté au jeu de démonstration ; un grand volume demande un profilage avant toute promesse de performance.
- Un formulaire de candidature resté ouvert peut écraser des réponses enregistrées entre-temps, y compris par le même administrateur dans deux onglets. La configuration de l'équipe possède, elle, une révision de formulaire.
- Pas de publication automatique Meta, de relance automatique des emails ni de mise à jour depuis GitHub.

Le [rapport de relecture du 16 septembre 2026](REVUE-CODE-2026-09-16.md) distingue les clarifications réalisées et les points restant à traiter, dont l’accès HTTP direct confirmé sur le site Local.

### Entretien du 2 octobre 2026

La recherche de code inutilisé couvre les 23 fichiers PHP d'exécution, les références aux ressources JS/CSS propres au plugin et les callbacks enregistrés dans WordPress. Elle a identifié `Notifications::render_status()`, ancien rendu sans appel ni hook : cette méthode est retirée. L'envoi, la réservation contre les doublons et les métadonnées de diagnostic des emails restent utilisés et conservés. Aucun fichier d'exécution entier n'a été identifié comme supprimable lors de cette revue. Le chargement inutile de `media.php` avait été retiré dans le correctif précédent.

Les migrations d'identités et de médias, ainsi que les routes d'anciens téléchargements/exports, restent nécessaires aux sites mettant à jour une ancienne version. `PrivateFiles` garde son nom pour la compatibilité, mais délègue le stockage à `MediaLibrary` ; ce nom ne promet pas de stockage privé. Des commentaires ciblés expliquent ces responsabilités, le reçu de dépôt, la propriété des brouillons et les contrôles avant géocodage.

Les grandes méthodes mêlant préparation des données et rendu (`Records::history()`, `PublicForm::request()`) restent des candidates à une extraction progressive lors de leurs prochaines évolutions, avec conservation des contrôles d'accès et des parcours sans JavaScript. Les anciennes clés stockées `application_document` (plus d'interface associée) et `email_verified` (toujours faux au dépôt, aucune vérification d'email) restent présentes : ne pas leur attribuer une fonctionnalité active ni modifier leur contrat de stockage implicitement. Les limites de volume et de modifications concurrentes ci-dessus ne sont pas corrigées par cet entretien.

Cette revue combine recherche de références et lecture des parcours ; elle ne constitue pas une preuve exhaustive d'absence de code mort, notamment dans les sélecteurs CSS ou les comportements conditionnels du navigateur. Les tests et documents du dépôt restent utiles au développement et exclus du ZIP.

## 8. Livraison locale

À la fin de chaque modification du plugin et après un changement de branche de travail, actualiser également la copie de lecture `C:\Users\paul\Projects\MarchePotier\marche-potier` depuis le dépôt actif. Elle contient `marche-potier.php`, `readme.txt`, `includes/` et `assets/`, avec les ajouts et suppressions correspondants. Comparer les fichiers avant remplacement et sauvegarder ceux remplacés ou retirés dans un nouveau rapport hors du plugin ; préserver et examiner les modifications inattendues de la copie. Vérifier ensuite l'égalité des chemins et des empreintes SHA-256, et conserver ce résultat dans `reports/` à la racine de l'espace de travail. L'ancienne copie 0.18.0 est conservée dans `reports/root-copy-sync-2026-10-02/before/`.

Cette synchronisation fait partie de la procédure de travail ; aucun service en arrière-plan ne la réalise. La copie de lecture inclut les correctifs encore non publiés. Elle n'est pas une source de livraison : construire les ZIP depuis le dépôt actif. Sur un autre ordinateur, adapter le chemin de cette copie si elle existe.

Vérifier la branche et son diff, exécuter les contrôles adaptés, puis copier les fichiers d’exécution modifiés dans le plugin du site Local. Comparer les empreintes des fichiers copiés. Ne pas copier `tests` ni les documents de développement. Enregistrer le résultat de vérification et le commit ; générer un ZIP uniquement lors d’une demande de livraison. Changer de branche ne change ni les fichiers installés ni la base WordPress.

## Cycle des médias

Les bibliothèques du cœur sont chargées au point d'utilisation : `file.php` avant `wp_handle_upload`, `image.php` avant `wp_generate_attachment_metadata` lors de l'enregistrement ou de l'import. Le plugin n'utilise aucune fonction de `wp-admin/includes/media.php` et ne le charge pas. Les classes de système de fichiers restent nécessaires à la copie des anciens médias ; `upgrade.php` reste nécessaire à `dbDelta` pour la table des votes. Aucun fichier de démarrage WordPress n'est inclus par le code distribué.

`MediaLibrary::store` reçoit les fichiers via `wp_handle_upload`, puis crée les pièces jointes et leurs métadonnées. Le formulaire signé ou le nonce administrateur est validé par l’appelant. `UploadDrafts::files` vérifie la révision et le propriétaire ; aucun identifiant de média fourni par le navigateur n’est accepté. Un rollback de candidature ne supprime pas les médias du brouillon.

La finalisation intervient après sauvegarde de `_marcpo_record` et du reçu. Le nettoyage et les écritures partagent `SubmissionLock`. Avant suppression, le nettoyage vérifie toutes les références de candidature, corbeille comprise : un arrêt entre sauvegarde et finalisation ne détruit pas les médias. Les fichiers définitifs restent conservés après remplacement ou suppression du dossier. Les suppressions explicites de médias passent par `wp_delete_attachment`.

`SocialImages::create()` reçoit le chemin du média confirmé et utilise `wp_get_image_editor`, `maybe_exif_rotate`, `resize`, `set_quality` et `save`. WordPress choisit le moteur disponible ; le filtre temporaire `wp_image_editors` remplace seulement ses classes GD/Imagick par nos sous-classes. WordPress charge leurs parents avant ce filtre : aucun chargement manuel de bibliothèque du cœur n'est ajouté. Les adaptateurs ne font que centrer l'image entière sur une toile blanche, opération absente de l'API commune. Le redimensionnement ne s'applique qu'aux photos dépassant 1080 × 1350, sans recadrage ni agrandissement. Le résultat est un JPEG à qualité 90 ; la conversion globale des JPEG en WebP est neutralisée exclusivement pour ce chemin, jusqu'à la génération des métadonnées du média. Les deux filtres sont retirés dans `finally`, y compris après erreur. Une erreur de traitement laisse l'original et la candidature confirmés et renseigne `social_error` ; une prochaine finalisation peut réessayer. Les limites de mémoire et le cycle temporaire/rattachement restent en place. Cette évolution du code de développement n'est pas incluse dans le ZIP 0.19.4 déjà publié.

La migration par lots de dix dossiers conserve les URLs dans uploads et déduplique les pièces jointes par leur chemin. `_marcpo_media_migrated` marque les dossiers traités et `_marcpo_media_library_migrated` la fin du parcours. Une pièce manquante est signalée sans perte de sa référence.

### Géocodage avec repli communal (30 septembre 2026)
GalleryMap normalise uniquement les requêtes (espaces postaux Unicode, mots st/ST, alias basques du pays). La signature reste fondée sur les réponses originales. Les caches address_v2 et commune_v2 expirent à 180 jours ; les anciens points valides sont conservés. Repli IGN type=municipality, postcode obligatoire, score minimal 0,5 et écart minimal 0,1 entre deux résultats. Les réponses malformées/échecs réseau ne déclenchent pas de repli. La précision municipality est stockée et explicitée au rendu. Tests cartography-local.php : fixtures isolées désormais autorisées aussi sur marche-potier-migration.local, réponses HTTP simulées et nettoyage final.
