# Administration du marché et votes — 0.19.0-beta.5

## Utilisation

1. Dans **Marché Potier → Éditions**, ouvrir l’édition et descendre jusqu’à **Organisateur et votes**.
2. Choisir **Votes multiples — notes de 0 à 5**. Le mode **Simple — sélection directe**, utilisé par défaut pour les anciennes éditions, conserve le fonctionnement précédent.
3. Dans **Administrateur du marché**, renseigner un nom et un email obligatoires. Cet administrateur est unique et toujours actif. Son rôle **[MP] Administrateur marché** permet la gestion des éditions, candidatures, pages et articles sur tout le site, la décision finale dans les deux modes et la participation aux votes multiples. Compléter ensuite le tableau **Votant pour la sélection** pour les comptes limités **[MP] Votant sélection**. Aucun bouton n’ajoute d’administrateur supplémentaire.
4. Enregistrer l’édition. Un email déjà connu rattache le compte existant ; le champ administrateur lui ajoute les droits de gestion du marché, le tableau votant conserve ses droits préexistants. Un email nouveau crée le compte avec le rôle correspondant et envoie une invitation personnalisée avec le bouton **Choisir mon mot de passe**. La connexion utilise **l’adresse email et le mot de passe choisi**. Pour réinviter un compte existant, cocher **Envoyer une invitation** avant d’enregistrer : il reçoit un accès aux candidatures et un lien de récupération, sans changement de mot de passe. Les liens de mot de passe sont gérés par WordPress.
5. À chaque dépôt public terminé, le candidat reçoit sa confirmation et l’administrateur unique de l’édition reçoit le dossier. En votes multiples, les votants actifs le reçoivent aussi. L’administrateur sert d’adresse de réponse des candidats. Il n’y a plus d’email organisateur indépendant ni de destinataire de secours. Les simples téléversements de pièces ne déclenchent pas ces notifications.
6. Dans **Gestion des candidatures → Examiner**, chaque personne choisit sa note entière entre **0 et 5** sur sa propre ligne puis clique sur **Valider**. Les autres notes sont visibles en lecture seule. Les notes des autres personnes sont actualisées au chargement de la page.
7. La colonne **Sélection et points** regroupe la décision, la somme des notes et la participation, par exemple **9 points · 2 votes sur 3**, sur des lignes séparées. Son titre ne propose pas de tri. **0 est une note**, l’absence de vote est indiquée par un tiret.
8. Le responsable enregistre séparément la décision finale **À examiner / Sélectionné / Non sélectionné**. Le score ne provoque pas de sélection automatique. L’autorisation de publication de la galerie reste indépendante.
9. Les notes restent modifiables à tout moment en mode multiple, avant, pendant et après les inscriptions. Les dates limitent uniquement le dépôt des candidatures. L’option de clôture des votes est supprimée.

## Tri des candidatures

Le menu **Trier par**, au-dessus du tableau à côté du compteur, propose six classements : points croissants ou décroissants, soumissions récentes ou anciennes, noms de famille de A à Z ou de Z à A. Les soumissions les plus récentes apparaissent par défaut. Le changement de tri recharge la liste depuis la première page, en conservant la recherche et les filtres ; les liens Examiner, précédent/suivant et retour à la liste conservent ce classement.

Le tri par points utilise le total des notes actives ; les dossiers sans note restent en dernier dans les deux sens, tandis que **0** est bien une note. Le tri par soumission utilise la date affichée dans le dossier, avec les dates absentes en dernier. Le tri alphabétique utilise le nom de famille puis le prénom, sans tenir compte de la casse ni des accents. Le classement s’applique à tous les résultats avant leur répartition en pages.

## Affichage public

Dans une page WordPress, ajouter le bloc **Formulaire de candidature** ou **Présentation de la sélection** avec le bouton **+**, puis choisir **Titre de l’édition (Année)**. Les blocs enregistrent l’identifiant WordPress de l’édition : deux marchés de la même année restent distincts, et changer leur titre ou leur année ne change pas l’affectation du bloc.

Le premier champ de l’édition est **Édition de l’année**. La rubrique **Affichage sur le site**, après les rôles en bas de l’édition, explique les deux blocs et contient **Autoriser l’affichage public de la sélection**. Le formulaire conserve ses dates d’inscription ; la sélection conserve ses contrôles de consentement et de publication.

Les shortcodes ont été supprimés : enregistrement des balises, recherche par année et détection des anciennes pages. Les pages existantes doivent utiliser les blocs et sélectionner leur édition.

## Affectations et conservation

- Le votant consulte seulement ses éditions, leurs candidatures et leurs pièces. Les listes, filtres, navigation et historique respectent cette restriction, y compris en accès direct par URL.
- Les rôles sont `mp_organizer` (**[MP] Administrateur marché**) et `mp_juror` (**[MP] Votant sélection**). L’administrateur du marché a les droits de création, publication, modification et suppression des éditions, candidatures, pages et articles, y compris privés ou créés par d’autres comptes. Il ne gère pas les extensions, utilisateurs ou réglages techniques de WordPress.
- Le votant ne dispose pas des actions Modifier, Corbeille, Exporter CSV ni de la sélection finale. Les contrôles existent aussi côté serveur.
- Décocher **Actif** retire un votant des notifications, de la participation et du total de cette édition. Un votant limité perd aussi l’accès à ses dossiers. Les notes restent affichées avec la mention **Inactif · note exclue du total** ; une réactivation les réintègre.
- Pour remplacer l’administrateur, modifier son nom et son email. Si le remplaçant est déjà votant, décocher sa case **Actif** avant d’enregistrer : il devient l’administrateur unique avec sa note existante. L’ancien administrateur devient votant inactif. Ses droits globaux WordPress restent attachés à son compte ; seul un administrateur du site peut les retirer dans la gestion des comptes.
- Le passage au mode simple masque les notes et empêche leur modification sans effacer les données. Repasser en votes multiples retrouve ces notes.
- Supprimer une candidature définitivement supprime ses votes. La corbeille les conserve mais interdit le vote. Supprimer un compte le retire du calcul sans effacer les notes historiques de ses candidatures.
- Un compte votant limité retiré de tous les jurys peut encore ouvrir le menu Marché Potier, mais n’y voit aucun dossier. Les droits préexistants des comptes rattachés sont conservés.

## Technique et validation

- Réglages du jury stockés dans `_mp_jury_settings`, avec une fonction `administrator` ou `voter` par membre et une version de formulaire pour détecter les modifications concurrentes. Les administrateurs et votants utilisent la même table de notes, sans doublon. L’enregistrement valide les paramètres de l’édition avant de créer des comptes ; une équipe invalide empêche aussi la sauvegarde de ces paramètres. Le titre et le statut natifs WordPress restent enregistrés séparément.
- Table `${prefix}mp_votes` : candidature, édition, utilisateur, note et date UTC. La contrainte unique candidature/utilisateur et l’écriture atomique évitent les doubles votes.
- Vérification du compte connecté, de l’affectation active, du mode et de la note côté serveur ; nonce spécifique au dossier pour les envois du formulaire. Aucun contrôle de date ni de clôture ne limite la notation.
- Verrou MySQL commun aux votes et changements d’affectation. Les emails sont réservés par destinataire sous verrou puis envoyés hors verrou.
- Mise à jour du schéma et des droits à la visite de l’administration par un administrateur, sans réactivation du plugin.
- Script d’intégration `tests/votes-local.php` limité au site nommé `marche-potier-test.local`. Il charge directement cette branche, intercepte les emails, crée des fixtures marquées et les retire en fin de test. Deux processus PHP distincts vérifient les votes simultanés. `--keep` conserve temporairement les fixtures pour la vérification visuelle ; `--cleanup` les retire.
- Parcours navigateur contrôlé avec des comptes fictifs : accès votant, notation et confirmation, affichage des autres notes, configuration du responsable et ajout/retrait de ligne.
- Résultat du 16 septembre 2026 : **130 vérifications d’intégration réussies**, dont l’administrateur unique obligatoire, son remplacement, les invitations et destinataires, le retrait des shortcodes, les blocs, les éditions d’une même année, les contrôles de publication et de consentement, les droits de la liste d’éditions et les votes hors période d’inscription. Les comptes et dossiers fictifs sont retirés après vérification.
- Un dépôt complet depuis une page utilisant le bloc a passé **15 vérifications HTTP** : session, six transferts de pièces, affectation à l’édition par ID, enregistrement et confirmation. Le formulaire public, la sélection publique et le placement du champ année ont aussi été vérifiés dans le navigateur.
- Environnement essayé : WordPress 7.1 et PHP CLI 8.3 sur le site Local Windows. Les emails sont vérifiés par interception, pas par livraison réelle à des boîtes externes.
- Avec le compte administrateur marché, l’ouverture des écrans de création d’édition et de candidature, la sauvegarde d’une édition et la publication/modification/mise à la corbeille d’une page ont réussi. Le test de page utilise le mode code de WordPress : le canevas de l’éditeur visuel est resté vide dans le navigateur intégré, sans erreur de permission. Ce point visuel reste à vérifier dans le navigateur habituel.

## Branche et retour à la version précédente

Le dépôt Git préexistant est dans `C:\Users\paul\Projects\MarchePotier\.tools\publish-MarchePotier`.

- Développement : `feature/votes-organisateurs`.
- Point de retour avant les votes : `backup/avant-votes-organisateurs`, commit `84d1af0` (inclut le lien Historique et le guide du tableau de bord).
- La branche `main` n’est pas modifiée. Les branches sont locales ; cette version bêta n’est pas publiée sur GitHub.
- Le dossier source initial `C:\Users\paul\Projects\MarchePotier\marche-potier` conserve la version antérieure. Le code de la branche est installé sur le site Local demandé.
- Sauvegarde installable du plugin avant les votes : `C:\Users\paul\Projects\MarchePotier\dist\marche-potier-avant-votes.zip`.
- Bêta installable : `C:\Users\paul\Projects\MarchePotier\dist\marche-potier-0.19.0-beta.5.zip`.

Changer de branche Git ne change pas automatiquement le plugin du site Local. Pour revenir au fonctionnement précédent, réinstaller le ZIP **avant-votes** via **Extensions → Ajouter → Téléverser**, en choisissant le remplacement de la version installée. Les candidatures, fichiers, décisions, réglages et notes restent en base. Les comptes et les droits déjà attribués subsistent aussi : un retour du code seul ne retire pas les droits de gestion ajoutés aux rôles. Une restauration complète des anciens droits nécessite la sauvegarde de la base réalisée avant la mise à jour, ou une intervention sur les rôles. Il n’y a pas de suppression de données lors de ce retour.
