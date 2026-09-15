# Votes des organisateurs — 0.19.0-beta.1

## Utilisation

1. Dans **Marché Potier → Éditions**, ouvrir l’édition et descendre jusqu’à **Organisateurs et votes**.
2. Choisir **Votes multiples — notes de 0 à 5**. Le mode **Simple — sélection directe**, utilisé par défaut pour les anciennes éditions, conserve le fonctionnement précédent.
3. Cliquer sur **Ajouter un organisateur** et renseigner nom et email. Ajouter aussi son propre email si le responsable souhaite voter.
4. Enregistrer l’édition. Un email déjà connu rattache le compte existant sans changer son rôle. Un email nouveau crée un compte « Organisateur votant » et déclenche l’invitation WordPress pour définir un mot de passe. Pour réinviter un compte existant, cocher **Envoyer une invitation** avant d’enregistrer.
5. À chaque dépôt public terminé, le candidat reçoit sa confirmation et chaque organisateur actif reçoit un récapitulatif avec le lien du dossier. L’adresse de contact principale de l’édition reçoit également une notification ; si elle correspond à un votant, elle n’en reçoit qu’une. Les simples téléversements de pièces ne déclenchent pas cette notification.
6. Dans **Gestion des candidatures → Examiner**, chaque personne choisit sa note entière entre **0 et 5** sur sa propre ligne puis clique sur **Valider**. Les autres notes sont visibles en lecture seule. Les notes des autres personnes sont actualisées au chargement de la page.
7. La colonne **Point** affiche la somme des notes et la participation, par exemple **9 points · 2 votes sur 3**. Cliquer sur son titre trie toutes les candidatures filtrées, avant pagination. Les dossiers sans vote sont placés après les dossiers notés. **0 est une note**, l’absence de vote est indiquée par un tiret.
8. Le responsable enregistre séparément la décision finale **À examiner / Sélectionné / Non sélectionné**. Le score ne provoque pas de sélection automatique. L’autorisation de publication de la galerie reste indépendante.
9. Cocher **Votes clôturés** dans l’édition empêche toute nouvelle note ou correction. Cette clôture est indépendante des dates de dépôt ; décocher permet de rouvrir le vote.

## Affectations et conservation

- Le votant consulte seulement ses éditions, leurs candidatures et leurs pièces. Les listes, filtres, navigation et historique respectent cette restriction, y compris en accès direct par URL.
- Les comptes de responsable et les administrateurs conservent leurs droits existants sur toutes les éditions. Une affectation au jury ne transforme pas un administrateur existant en compte limité.
- Le votant ne dispose pas des actions Modifier, Corbeille, Exporter CSV ni de la sélection finale. Les contrôles existent aussi côté serveur.
- Décocher **Actif** coupe l’accès à l’édition et exclut les notes de ce compte du total et du nombre de votes attendus. Ses notes restent affichées avec la mention **Inactif · note exclue du total**. Une réactivation les réintègre.
- Le passage au mode simple masque les notes et empêche leur modification sans effacer les données. Repasser en votes multiples retrouve ces notes.
- Supprimer une candidature définitivement supprime ses votes. La corbeille les conserve mais interdit le vote. Supprimer un compte le retire du calcul sans effacer les notes historiques de ses candidatures.
- Les nouveaux droits de lecture n’accordent pas de droits de gestion de WordPress. Un compte retiré de tous les jurys peut encore ouvrir le menu Marché Potier, mais n’y voit aucun dossier.

## Technique et validation

- Réglages du jury séparés des autres paramètres de l’édition : métadonnée `_mp_jury_settings` avec version de formulaire pour détecter les modifications concurrentes.
- Table `${prefix}mp_votes` : candidature, édition, utilisateur, note et date UTC. La contrainte unique candidature/utilisateur et l’écriture atomique évitent les doubles votes.
- Vérification du compte connecté, de l’affectation active, du mode, de la clôture et de la note côté serveur ; nonce spécifique au dossier pour les envois du formulaire.
- Verrou MySQL commun aux votes et changements de jury : un vote ne contourne pas une clôture en cours. Les emails sont réservés par destinataire sous verrou puis envoyés hors verrou.
- Mise à jour du schéma et des droits à la visite de l’administration par un administrateur, sans réactivation du plugin.
- Script d’intégration `tests/votes-local.php` limité au site nommé `marche-potier-test.local`. Il charge directement cette branche, intercepte les emails, crée des fixtures marquées et les retire en fin de test. Deux processus PHP distincts vérifient les votes simultanés. `--keep` conserve temporairement les fixtures pour la vérification visuelle ; `--cleanup` les retire.
- Parcours navigateur contrôlé avec deux comptes fictifs : accès votant, notation et confirmation, affichage des autres notes, configuration du responsable, ajout/retrait de ligne et sauvegarde de la clôture.
- Résultat du 15 septembre 2026 : **57 vérifications d’intégration réussies**. Sur l’édition, contrôle visuel à 390 pixels de large : le tableau défile dans sa propre zone, sans débordement de page. Les comptes et dossiers fictifs ont été retirés après vérification.
- Environnement essayé : WordPress 7.1 et PHP CLI 8.3 sur le site Local Windows. Les emails sont vérifiés par interception, pas par livraison réelle à des boîtes externes.

## Branche et retour à la version précédente

Le dépôt Git préexistant est dans `C:\Users\paul\Projects\MarchePotier\.tools\publish-MarchePotier`.

- Développement : `feature/votes-organisateurs`.
- Point de retour avant les votes : `backup/avant-votes-organisateurs`, commit `84d1af0` (inclut le lien Historique et le guide du tableau de bord).
- La branche `main` n’est pas modifiée. Les branches sont locales ; cette version bêta n’est pas publiée sur GitHub.
- Le dossier source initial `C:\Users\paul\Projects\MarchePotier\marche-potier` conserve la version antérieure. Le code de la branche est installé sur le site Local demandé.
- Sauvegarde installable du plugin avant les votes : `C:\Users\paul\Projects\MarchePotier\dist\marche-potier-avant-votes.zip`.
- Bêta installable : `C:\Users\paul\Projects\MarchePotier\dist\marche-potier-0.19.0-beta.1.zip`.

Changer de branche Git ne change pas automatiquement le plugin du site Local. Pour revenir au fonctionnement précédent, réinstaller le ZIP **avant-votes** via **Extensions → Ajouter → Téléverser**, en choisissant le remplacement de la version installée. Les candidatures, fichiers et décisions restent en place. Les réglages et notes de jury déjà enregistrés restent en base et seront retrouvés si la bêta est réinstallée ; les comptes créés subsistent aussi. Il n’y a pas de suppression de données lors de ce retour.
