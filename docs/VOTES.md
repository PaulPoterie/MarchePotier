# Administration du marché et votes — 0.19.0-beta.4

## Utilisation

1. Dans **Marché Potier → Éditions**, ouvrir l’édition et descendre jusqu’à **Organisateur et votes**.
2. Choisir **Votes multiples — notes de 0 à 5**. Le mode **Simple — sélection directe**, utilisé par défaut pour les anciennes éditions, conserve le fonctionnement précédent.
3. Dans **Administrateur du marché**, renseigner au moins un nom et un email actifs. Ce compte reçoit le rôle **[MP] Administrateur marché** : gestion des éditions, candidatures, pages et articles sur tout le site, décision finale dans les deux modes et participation aux votes multiples. Compléter ensuite **Votant pour la sélection** pour les comptes limités **[MP] Votant sélection**. Un email figure dans un seul tableau ; les boutons permettent de déplacer une personne sans perdre ses notes.
4. Enregistrer l’édition. Un email déjà connu rattache le compte existant ; le premier tableau lui ajoute les droits de gestion du marché, le second conserve ses droits préexistants. Un email nouveau crée le compte avec le rôle correspondant et envoie une invitation personnalisée avec le bouton **Choisir mon mot de passe**. La connexion utilise **l’adresse email et le mot de passe choisi**. Pour réinviter un compte existant, cocher **Envoyer une invitation** avant d’enregistrer : il reçoit un accès aux candidatures et un lien de récupération, sans changement de mot de passe. Les liens de mot de passe sont gérés par WordPress.
5. À chaque dépôt public terminé, le candidat reçoit sa confirmation et les administrateurs actifs de l’édition reçoivent le dossier. En votes multiples, les votants actifs le reçoivent aussi. Le premier administrateur actif du tableau sert d’adresse de réponse des candidats. Il n’y a plus d’email organisateur indépendant ni de destinataire de secours. Les simples téléversements de pièces ne déclenchent pas ces notifications.
6. Dans **Gestion des candidatures → Examiner**, chaque personne choisit sa note entière entre **0 et 5** sur sa propre ligne puis clique sur **Valider**. Les autres notes sont visibles en lecture seule. Les notes des autres personnes sont actualisées au chargement de la page.
7. La colonne **Point** affiche la somme des notes et la participation, par exemple **9 points · 2 votes sur 3**. Cliquer sur son titre trie toutes les candidatures filtrées, avant pagination. Les dossiers sans vote sont placés après les dossiers notés. **0 est une note**, l’absence de vote est indiquée par un tiret.
8. Le responsable enregistre séparément la décision finale **À examiner / Sélectionné / Non sélectionné**. Le score ne provoque pas de sélection automatique. L’autorisation de publication de la galerie reste indépendante.
9. Les notes restent modifiables à tout moment en mode multiple, avant, pendant et après les inscriptions. Les dates limitent uniquement le dépôt des candidatures. L’option de clôture des votes est supprimée.

## Affichage public

Dans une page WordPress, ajouter le bloc **Formulaire de candidature** ou **Présentation de la sélection** avec le bouton **+**, puis choisir **Titre de l’édition (Année)**. Les blocs enregistrent l’identifiant WordPress de l’édition : deux marchés de la même année restent distincts, et changer leur titre ou leur année ne change pas l’affectation du bloc.

Le premier champ de l’édition est **Édition de l’année**. La rubrique **Affichage sur le site**, après les rôles en bas de l’édition, explique les deux blocs et contient **Autoriser l’affichage public de la sélection**. Le formulaire conserve ses dates d’inscription ; la sélection conserve ses contrôles de consentement et de publication.

## Affectations et conservation

- Le votant consulte seulement ses éditions, leurs candidatures et leurs pièces. Les listes, filtres, navigation et historique respectent cette restriction, y compris en accès direct par URL.
- Les anciens rôles sont renommés sans recréer les comptes : `mp_organizer` devient **[MP] Administrateur marché**, `mp_juror` devient **[MP] Votant sélection**. Les administrateurs du marché ont les droits de création, publication, modification et suppression des éditions, candidatures, pages et articles, y compris privés ou créés par d’autres comptes. Ils ne gèrent pas les extensions, utilisateurs ou réglages techniques de WordPress. Les anciens membres possédant déjà les droits de gestion sont présentés dans le premier tableau ; les autres restent votants, avec leurs notes.
- Le votant ne dispose pas des actions Modifier, Corbeille, Exporter CSV ni de la sélection finale. Les contrôles existent aussi côté serveur.
- Décocher **Actif** retire les notifications, la participation et les notes du total de cette édition. Un votant limité perd aussi l’accès à ses dossiers. Les droits globaux d’un administrateur restent attachés à son compte, même s’il est désactivé ou déplacé vers le tableau votant : seul un administrateur WordPress peut les retirer dans la gestion des comptes. Les notes restent affichées avec la mention **Inactif · note exclue du total** ; une réactivation les réintègre.
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
- Résultat du 16 septembre 2026 : **120 vérifications d’intégration réussies**, dont les blocs, les éditions d’une même année, les contrôles de publication et de consentement, les droits de la liste d’éditions, les votes hors période d’inscription et les scénarios des versions précédentes. Les comptes et dossiers fictifs sont retirés après vérification.
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
- Bêta installable : `C:\Users\paul\Projects\MarchePotier\dist\marche-potier-0.19.0-beta.4.zip`.

Changer de branche Git ne change pas automatiquement le plugin du site Local. Pour revenir au fonctionnement précédent, réinstaller le ZIP **avant-votes** via **Extensions → Ajouter → Téléverser**, en choisissant le remplacement de la version installée. Les candidatures, fichiers, décisions, réglages et notes restent en base. Les comptes et les droits déjà attribués subsistent aussi : un retour du code seul ne retire pas les droits de gestion ajoutés aux rôles. Une restauration complète des anciens droits nécessite la sauvegarde de la base réalisée avant la mise à jour, ou une intervention sur les rôles. Il n’y a pas de suppression de données lors de ce retour.
