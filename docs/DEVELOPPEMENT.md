# Reprendre le développement de Marché Potier

## 1. Point de départ

Le fichier `marche-potier.php` charge explicitement les classes du namespace `MarchePotier`. `Plugin::boot()` enregistre leurs hooks WordPress. Le plugin utilise PHP, CSS et JavaScript natifs ; il n’y a ni Composer, ni npm, ni compilation. Leaflet est une dépendance embarquée dans `assets/vendor/leaflet` ; ne pas y appliquer les changements du plugin.

Le README est le guide utilisateur ; ce document décrit les contrats du code. Le guide `VOTES.md` détaille les affectations, invitations et changements d’administrateur.

### Particularité de cet espace de travail

| Emplacement | Usage |
| --- | --- |
| `C:\Users\paul\Projects\MarchePotier\.tools\publish-MarchePotier` | Dépôt actif, branche `feature/votes-organisateurs`. Modifier et committer ici. |
| `C:\Users\paul\Projects\MarchePotier\marche-potier` | Ancienne version stable conservée. |
| `C:\Users\paul\Local Sites\marche-potier-test\app\public\wp-content\plugins\marche-potier` | Copie exécutée par le site Local. La synchroniser après vérification. |
| `C:\Users\paul\Projects\MarchePotier\reports` | Rapports et résultats locaux, hors du paquet installable. |
| `C:\Users\paul\Projects\MarchePotier\dist` | ZIP de livraisons ponctuelles, potentiellement antérieurs à la branche. |

Ce dépôt ne déploie rien automatiquement. Sur un autre ordinateur, son emplacement peut être quelconque ; ces chemins décrivent uniquement l’installation de développement actuelle.

## 2. Où intervenir ?

| Besoin | Fichiers / classes dans `includes/` |
| --- | --- |
| Initialisation, accueil du plugin | `class-plugin.php` |
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
| Copies d’images 1080 × 1350, sans champs supplémentaires | `class-social-images.php` |
| Emails après candidature | `class-notifications.php` |
| Présentation publique et localisation | `class-gallery.php`, `class-gallery-map.php` |
| Verrou commun et migration des identités anciennes | `class-submission-lock.php`, `class-identity-migration.php` |

Les noms des fichiers CSS/JS suivent leur écran : `review.*` sert à Gestion et Examiner, `jury.*` aux affectations, `vote-tracking.css` au suivi. `blocks.js` utilise les bibliothèques fournies par WordPress. `form.js` gère le formulaire public ; `draft.js` sa sauvegarde dans le navigateur.

## 3. Données et règles métier

| Donnée | Stockage et responsabilité |
| --- | --- |
| Édition | Contenu `mp_edition`, paramètres dans `_mp_edition_settings`. L’ID est la référence ; l’année est un libellé, pas une clé unique. |
| Équipe | `_mp_jury_settings` : `mode`, `revision`, `members[ID compte]`. Chaque membre possède `name`, `kind`, `active` et éventuellement `invitation`. |
| Identité d’historique | Contenu `mp_potier`, `_mp_record.identity` minimal (nom, prénom, email). Ce n’est pas un compte WordPress de candidat. |
| Candidature | Contenu `mp_candidature`, réponses dans `_mp_record` : `edition_id`, `potier_id`, `identity`, `activity`, `internal`, `decision`, `files`, consentement et date de dépôt. |
| Index de candidature | `_mp_edition_id`, `_mp_potier_id`, `_mp_decision` servent aux requêtes. Les maintenir cohérents avec `_mp_record` à chaque écriture. |
| Notes | Table `${prefix}mp_votes`, clé unique `(application_id, user_id)`. Une correction remplace la note ; elle ne crée pas un deuxième vote. |
| Fichiers | Pièces jointes WordPress identifiées par `files[slot].attachment_id`, copies Meta par `files[slot].social.attachment_id`. `_mp_temporary_until` et `_mp_draft_owner` identifient exclusivement les médias provisoires. Après écriture du dossier, le rattachement est établi et ces marqueurs sont retirés. Les URLs sont publiques, y compris pour les justificatifs. |

Les réponses sont propres à chaque candidature : corriger une année ne doit pas réécrire les réponses d’une autre année. L’identité d’historique est rapprochée par nom, prénom et email. Une adresse email ne peut déposer deux fois pour une même édition, corbeille comprise.

### Droits : rôle du compte et affectation ne sont pas synonymes

- `mp_organizer` est le rôle global **[MP] Administrateur marché**. Ses droits de gestion concernent tout le site, pas uniquement les éditions dont il est le contact.
- `mp_juror` est **[MP] Votant sélection**. `Jury::can_view_edition()` et `can_view_application()` contrôlent l’affectation active à chaque accès.
- `kind=administrator` dans une édition désigne son unique administrateur et contact. Le remplacer désactive son ancienne affectation ; cela ne révoque pas son rôle global WordPress.
- Pour voter, même un administrateur doit être membre actif de cette édition. `Votes::record()` prend exclusivement le compte de la session ; aucun ID d’auteur envoyé par le navigateur n’est accepté.
- `Jury::settings()` conserve les membres inactifs. `Jury::members()` ne renvoie que les actifs dont le compte existe : c’est cette liste qui sert aux totaux et aux destinataires.

### Trois décisions indépendantes

1. Les dates d’inscription autorisent ou refusent le **dépôt public** (`Editions::is_open`).
2. Le mode multiple et l’affectation active autorisent la **notation**, sans contrôle de date. `null` signifie absence de note ; `0` est une note.
3. La **publication** exige une édition publiée, l’autorisation de montrer sa sélection, une décision `selected` et le consentement du candidat (`Gallery::eligible`). Un total de points ne sélectionne personne automatiquement.

Le mode simple conserve les notes, mais masque et interdit leur modification. Retirer un membre actif conserve ses anciennes notes tout en les excluant des totaux.

Les types de contenus des dossiers ne sont pas publics. Le statut WordPress `publish` d’une candidature ne signifie donc pas qu’elle apparaît dans la galerie : les règles de publication ci-dessus s’appliquent séparément.

## 4. Parcours du code

### Gestion, filtres, tris et navigation

`Records::list_context()` ne conserve que les paramètres GET autorisés. Le select `mp_sort` devient `orderby` + `order` dans les liens. `Records::navigation_ids()` applique accès, filtres, recherche et tri à la liste entière. Cette liste est partagée par la gestion, l’export, les boutons précédent/suivant et le suivi.

`Review::table()` prépare la pagination puis appelle les fonctions `management_header`, `management_filters`, `management_toolbar`, `management_rows` et `management_pagination`. Ces fonctions rendent les zones de l’écran ; elles ne doivent pas inventer une autre liste de candidatures.

Le select de tri est visuellement hors du formulaire, mais associé par `form="mp-application-filters"`. Le formulaire omet `paged` volontairement : changer un filtre ou un tri revient à la première page. Les liens de pagination et d’examen conservent le contexte. Le tri par soumission utilise `submitted_at`, pas la création WordPress ; le tri par nom utilise les coordonnées, pas le titre généré.

### Écritures et concurrence

Les points d’entrée HTTP vérifient les droits et le nonce avant d’appeler les fonctions métier. Le dépôt public ajoute une signature liée au cookie, vérifie l’édition du bloc et les dates, puis valide les champs et les pièces.

`SubmissionLock` est un verrou MySQL commun au site, lié à la connexion. Il n’est pas réentrant : une fonction appelée sous verrou ne doit pas le reprendre. Utiliser `try/finally` pour le libérer. `Jury::configure()` et les opérations de brouillon attendent un verrou détenu par leur appelant ; `Votes::record()` et `PublicForm::submit()` prennent le leur. Le verrou ne rend pas plusieurs écritures transactionnelles. La `revision` du jury et celle des pièces détectent les formulaires périmés ; les réponses internes du dossier n’ont pas de révision équivalente.

Les notifications de candidature réservent chaque tentative sous verrou puis appellent `wp_mail` après libération. Les invitations de comptes sont actuellement envoyées pendant la configuration du jury, sous son verrou : ne pas confondre ces deux circuits. Un échec d’email ne supprime pas la candidature et n’est pas relancé automatiquement.

## 5. Conventions à garder

- Valider les types d’entrée avant nettoyage ; utiliser `wp_unslash` aux frontières HTTP et `wp_slash` lors de l’écriture des tableaux de métadonnées contenant du texte.
- Échapper au rendu (`esc_html`, `esc_attr`, `esc_url`). Les valeurs retournées par les fonctions métier ne sont pas pré-échappées.
- Un champ de `Fields` suit `[libellé, type, obligatoire, choix?, condition?]`. Ajouter un champ à ce schéma peut affecter formulaire, dossier, export et emails : vérifier ces usages ensemble.
- Charger les notes d’un ensemble de dossiers avec `Votes::all($ids)` puis transmettre celles du dossier au calcul. Cette fonction de stockage ne filtre pas les droits elle-même.
- Garder les notes séparées de la sélection ; ne pas ajouter de clôture de vote ni de sélection automatique par score.
- Garder les éditions par ID dans les blocs ; ne pas réintroduire de résolution par année ou de shortcodes.
- Les versions des assets sont des clés de cache et peuvent différer de la version du plugin. Changer celle d’un CSS/JS dont le comportement ou le rendu change. Les options de version du schéma et des droits sont des migrations distinctes, à modifier seulement si leur installation évolue.
- Commenter les invariants, les préconditions et les raisons d’un choix ; éviter les commentaires qui répètent simplement une instruction.

## 6. Vérifier une modification

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

Les fichiers secondaires partagent les fixtures du point d’entrée : ne pas les exécuter seuls. Pour un changement visuel, vérifier Gestion et Examiner avec un administrateur et un votant, ainsi que le défilement sur écran étroit. Les tests CLI ne prouvent ni le rendu navigateur ni la livraison réelle des emails.

## 7. Limites connues à distinguer des garanties du code

- Les médias sont intentionnellement publics par URL. Les anciens liens de téléchargement redirigent vers les médias sans authentification. La galerie conserve ses règles de sélection et de consentement. La migration conserve les anciennes copies extérieures à uploads après vérification ; leur éventuel retrait relève d’une maintenance distincte.
- Les listes et l’historique chargent tous les IDs concernés, et le rapprochement d’identité parcourt les fiches. Le fonctionnement est adapté au jeu de démonstration ; un grand volume demande un profilage avant toute promesse de performance.
- Les sauvegardes de candidature ne détectent pas deux modifications concurrentes de leurs réponses par deux administrateurs. Le jury, lui, possède une révision de formulaire.
- Pas de publication automatique Meta, de relance automatique des emails ni de mise à jour depuis GitHub.

Le [rapport de relecture du 16 septembre 2026](REVUE-CODE-2026-09-16.md) distingue les clarifications réalisées et les points restant à traiter, dont l’accès HTTP direct confirmé sur le site Local.

## 8. Livraison locale

Vérifier la branche et son diff, exécuter les contrôles adaptés, puis copier les fichiers d’exécution modifiés dans le plugin du site Local. Comparer les empreintes des fichiers copiés. Ne pas copier `tests` ni les documents de développement. Enregistrer le résultat de vérification et le commit ; générer un ZIP uniquement lors d’une demande de livraison. Changer de branche ne change ni les fichiers installés ni la base WordPress.

## Cycle des médias

`MediaLibrary::store` reçoit les fichiers via `wp_handle_upload`, puis crée les pièces jointes et leurs métadonnées. Le formulaire signé ou le nonce administrateur est validé par l’appelant. `UploadDrafts::files` vérifie la révision et le propriétaire ; aucun identifiant de média fourni par le navigateur n’est accepté. Un rollback de candidature ne supprime pas les médias du brouillon.

La finalisation intervient après sauvegarde de `_mp_record` et du reçu. Le nettoyage et les écritures partagent `SubmissionLock`. Avant suppression, le nettoyage vérifie toutes les références de candidature, corbeille comprise : un arrêt entre sauvegarde et finalisation ne détruit pas les médias. Les fichiers définitifs restent conservés après remplacement ou suppression du dossier. Les suppressions explicites de médias passent par `wp_delete_attachment`.

La migration par lots de dix dossiers conserve les URLs dans uploads et déduplique les pièces jointes par leur chemin. `_mp_media_migrated` marque les dossiers traités et `_mp_media_library_migrated` la fin du parcours. Une pièce manquante est signalée sans perte de sa référence.
