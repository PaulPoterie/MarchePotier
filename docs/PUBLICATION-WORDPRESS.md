# Publication GitHub → WordPress.org

GitHub reste le dépôt de développement. WordPress.org distribue les versions publiées via SVN ; aucune mise à jour depuis GitHub n'est ajoutée au plugin.

## Première publication

La première version à transférer est **0.19.4**, à partir du ZIP déjà joint à la release GitHub `v0.19.4`. Ne pas reconstruire ce ZIP depuis `main` : cette branche contient déjà la modification GD/Imagick prévue pour la 0.19.5. Le texte de la 0.19.4 qui mentionne encore la revue est conservé avec ce paquet ; il sera actualisé lors de la prochaine version. Les tags et paquets publiés restent immuables.

Le workflow **Publish WordPress.org**, dans `.github/workflows/wordpress-release.yml`, possède un déclenchement manuel pour cette première publication et les reprises :

1. Ouvrir GitHub → Actions → Publish WordPress.org → Run workflow.
2. Choisir la branche `main`, saisir `v0.19.4`, laisser **dry_run** coché pour la simulation.
3. Après succès de la simulation, relancer avec le même tag et **dry_run** décoché pour l'envoi réel.

L'identifiant SVN est `pauligno`. Le secret de dépôt Actions `SVN_PASSWORD` contient exclusivement le mot de passe SVN WordPress.org. Ne jamais l'écrire dans un fichier, une commande de documentation ou un rapport. Le workflow le transmet uniquement à l'étape d'envoi ; la simulation utilise une valeur factice. Sa rotation se fait dans le profil WordPress.org, puis dans les secrets GitHub.

## Prochaines versions

1. Finaliser le code et les tests métier sur `main`.
2. Choisir un nouveau numéro `X.Y.Z`. Actualiser `Version:`, `Stable tag:`, changelog, documentation et versions de cache des assets concernés. Passer Plugin Check sur le paquet final selon le guide de développement.
3. Créer le tag Git `vX.Y.Z` sur le commit validé, intégré dans `main`.
4. Préparer une release GitHub **en brouillon**, joindre le ZIP installable nommé exactement `poterie-navarraise-market-manager-X.Y.Z.zip` (et son fichier SHA-256 pour les téléchargements manuels).
5. **Publier la release seulement après la fin de l'envoi du ZIP.** Cet événement déclenche automatiquement GitHub Actions. Une préversion ou un brouillon ne se déploie pas ; ajouter un fichier isolément à une release déjà publiée ne déclenche pas ce workflow. Utiliser le lancement manuel pour une reprise, sans remplacer une version déjà livrée.

Le workflow télécharge l'asset ZIP, pas l'archive « Source code » fabriquée par GitHub. Il vérifie son empreinte publiée par l'API GitHub, sa taille, sa structure, ses en-têtes et l'égalité exacte de ses fichiers avec ceux du tag Git. Seuls `marche-potier.php`, `readme.txt`, `includes/` et `assets/` sont autorisés. Les scripts, tests, documentation et fichiers cachés restent hors distribution.

Il refuse les chemins dangereux, liens symboliques, doublons et versions incohérentes. Il empêche les retours de version et ne remplace aucun tag SVN existant : une nouvelle exécution ne devient sans effet que si le tag existant contient exactement les mêmes fichiers. Une différence interrompt le traitement et doit être examinée.

Plugin Check officiel est exécuté sur le paquet extrait, en environnement WordPress jetable, avec `WP_DEBUG` et sans exclusions. Le contrôle de son exécution confirme que le plugin cible et les contrôles à l'exécution sont chargés. Les erreurs et avertissements bloquent l'envoi. Une vérification supplémentaire confirme que ce contrôle n'a pas modifié les fichiers à distribuer.

L'action 10up copie ensuite les fichiers vérifiés dans `trunk`, puis crée `tags/X.Y.Z` en une seule révision SVN. Le dossier `assets` à la racine du SVN, réservé aux illustrations de l'annuaire, reste distinct du dossier `assets` du plugin. Aucun visuel de l'annuaire n'est changé par ce workflow. Les anciennes versions SVN sont conservées. Après l'envoi, un export du nouveau tag est comparé octet par octet au ZIP GitHub.

Les actions sont figées par SHA. Les exécutions sont sérialisées et une publication en cours n'est pas interrompue par une autre. Le jeton GitHub a uniquement la lecture des contenus ; aucun déclenchement depuis une pull request ne reçoit le secret SVN. Les rapports de paquet et d'exécution sont conservés dans les artifacts du run GitHub.

## Vérifier le résultat

Un commit SVN réussi ne prouve pas encore la disponibilité du téléchargement WordPress.org. Vérifier séparément :

- le nouveau tag dans le SVN ;
- la version présentée sur la fiche publique et le ZIP généré par WordPress.org ;
- une éventuelle demande de confirmation de release WordPress.org, si cette option est activée ;
- la mise à jour proposée par WordPress sur une installation de test.

Ne pas republier un tag pour tenter d'accélérer les caches de l'annuaire.

Pour notre essai **0.19.4 → 0.19.5**, conserver d'abord une installation de test en 0.19.4 avec des données repérées et une sauvegarde. Activer les mises à jour automatiques de cette extension dans WordPress. Publier la 0.19.5 seulement après validation de la première publication, puis observer sa réception par le mécanisme natif WordPress. Vérifier versions, activation et conservation des éditions, candidatures, fichiers et notes. WP-Cron doit pouvoir s'exécuter ; une mise à jour manuelle ne démontre pas l'exécution automatique.

## Entretien

- Tests des contrôles de publication : `python -m unittest discover -s tests -p test_wordpress_release.py -v`.
- Les outils `scripts/wordpress_release.py` et `scripts/wordpress-check-runtime.php` sont exclusivement destinés à la livraison, jamais au ZIP.
- Changer la liste des fichiers autorisés seulement lorsqu'une évolution réelle du paquet le nécessite.
- Avant de changer le SHA d'une action, examiner les changements et relancer une simulation.
- Les releases créées par un autre workflow avec son `GITHUB_TOKEN` ne déclenchent normalement pas un nouveau workflow : dans ce cas, appeler explicitement `workflow_dispatch` après l'envoi complet des assets. La publication actuelle par CLI utilisateur déclenche l'événement normalement.

Références : [SVN WordPress.org](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [événement release GitHub](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#release), [action de publication 10up](https://github.com/10up/action-wordpress-plugin-deploy), [Plugin Check](https://github.com/WordPress/plugin-check-action), [confirmation des releases](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/).
