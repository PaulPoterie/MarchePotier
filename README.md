# Marché Potier — Guide utilisateur

Version 0.18.0 — 14 septembre 2026.

[Télécharger le plugin 0.18.0 prêt à installer](https://github.com/PaulPoterie/MarchePotier/releases/download/v0.18.0/marche-potier-0.18.0.zip)

## 1. Installer le plugin

Dans WordPress, ouvrez **Extensions → Ajouter une extension → Téléverser une extension**, choisissez `marche-potier-0.18.0.zip`, puis cliquez sur **Installer maintenant** et **Activer**.

Le ZIP contient le dossier `marche-potier`, prêt à installer. Les dossiers de développement `docs`, `tests` et `.tools` ne doivent pas être copiés dans les extensions.

Pour mettre à jour une installation existante, sauvegardez d’abord la base WordPress et le dossier `wp-content/uploads`, puis téléversez le nouveau ZIP et choisissez le remplacement de la version existante. Les candidatures sont conservées en base ; leurs fichiers sont dans uploads. Ne supprimez pas les fichiers uploads pour effectuer une mise à jour.

Prévoir WordPress 6.6 minimum déclaré et PHP 8.2 minimum déclaré ; les essais de cette livraison portent sur WordPress 7.1 et PHP 8.3. L’hébergement doit disposer de MySQL/MariaDB avec les verrous nommés, de Fileinfo et de GD avec JPEG, PNG et WebP. Les versions minimales déclarées n’ont pas toutes été testées séparément ; vérifiez le formulaire et les emails sur votre hébergement avant ouverture.

## 2. Donner accès aux organisateurs

Un administrateur WordPress accède au menu **Marché Potier**. Pour les autres membres de l’équipe, attribuez le rôle **Organisateur de marché** disponible dans la gestion des comptes WordPress.

Les organisateurs gèrent l’ensemble des éditions du site. L’affectation à une seule édition et le vote individuel de 0 à 5 ne font pas encore partie de cette version. Chaque personne doit utiliser son propre compte.

## 3. Créer une édition

Ouvrez **Marché Potier → Éditions → Ajouter**. Donnez un titre explicite, par exemple « Marché de potiers de Bayonne 2027 ».

Renseignez :

- L’année : une seule édition publiée par année doit correspondre au shortcode du formulaire.
- L’ouverture et la fermeture des candidatures. Les heures déterminent la disponibilité réelle du formulaire, dans le fuseau horaire WordPress. La page publique affiche uniquement les dates.
- Les dates du marché, son lieu, le nombre d’exposants et le prix de l’emplacement pour l’ensemble du marché.
- Le tarif réduit, si nécessaire, avec l’explication des personnes concernées.
- Le texte complémentaire, avec l’éditeur WordPress, et le règlement intérieur en PDF.
- La longueur de stand par défaut et l’autorisation ou non de la modifier.
- L’email organisateur et le message de remerciement. Si ces champs restent vides, le plugin utilise l’email d’administration et son message standard.

Précisez dans le texte complémentaire ce qui est compris dans le prix, les éventuels équipements fournis, les modalités de paiement et la date prévue de réponse aux candidats.

Publiez l’édition. L’ouverture des candidatures respecte automatiquement les dates : la publication ne rend pas le formulaire disponible avant la date de début.

## 4. Afficher le formulaire

Créez une page WordPress et ajoutez un bloc **Code court** contenant :

```text
[inscription_potier edition="2027"]
```

Remplacez 2027 par l’année choisie. Le shortcode à copier est également indiqué dans l’édition. Publiez la page et ajoutez son lien à votre menu.

Les informations du marché précèdent le formulaire. La période de candidature apparaît sous les blocs d’informations. Lorsque les dates ne permettent pas de candidater, un message remplace le formulaire.

## 5. Parcours du candidat

Le candidat n’a pas besoin de créer de compte WordPress. Il renseigne ses coordonnées, une présentation de **300 mots maximum**, ses productions et techniques, la longueur souhaitée et ses informations professionnelles et associatives.

Le justificatif demandé s’adapte au statut choisi :

- Artisan ou micro-entreprise : extrait DATA INPI, avec le lien vers le site.
- Artiste, Maison des artistes : dernière attestation d’affiliation ou d’assujettissement avec noms, prénoms et numéro d’ordre.
- Autres statuts ou installation à l’étranger : justificatif de l’activité professionnelle et précision du statut.

Six pièces sont obligatoires : trois photos de créations, une photo du stand, un justificatif de statut et une assurance RC professionnelle. L’assurance demandée doit être valide au jour de l’envoi et mentionner « Marchés ou foires en extérieur ». Le plugin contrôle le format des fichiers ; l’organisateur vérifie leur contenu.

Photos : JPEG, PNG ou WebP. Justificatifs : PDF ou images dans ces mêmes formats. La limite maximale est **20 Mo par fichier**, abaissée automatiquement si l’hébergement impose moins. Une photo HEIC doit être convertie en JPEG.

Les fichiers partent séparément dès leur sélection. Une progression, un état et une possibilité de réessayer accompagnent chaque pièce. **Recevoir les pièces ne valide pas la candidature** : le candidat doit encore cliquer sur **Envoyer ma candidature**.

Les textes et les choix sont sauvegardés dans le navigateur pendant au plus 24 heures. Les pièces reçues disposent également d’une conservation temporaire de 24 heures ; le délai du texte est raccourci si les pièces expirent plus tôt. Pour reprendre, utiliser le même navigateur et conserver ses cookies et données de site. Une erreur de validation ne supprime pas le brouillon. Après confirmation de l’enregistrement, le brouillon est effacé. Aucun bouton « Effacer le brouillon » n’est encore proposé.

Le consentement à la présentation publique en cas de sélection est obligatoire. L’adresse sera utilisée pour localiser l’atelier sur la carte.

Une même adresse email ne peut déposer deux candidatures pour la même édition, y compris si une candidature est à la corbeille. Pour une autre édition, un nouveau dossier est possible. Nom, prénom et email servent au rapprochement de l’historique ; une identité différente avec le même email peut apparaître sur une autre ligne.

## 6. Recevoir les confirmations

L’écran final confirme l’enregistrement. Le candidat et l’organisateur reçoivent chacun un récapitulatif comportant les noms des pièces reçues. Les pièces ne sont pas jointes aux emails. L’email organisateur comporte un lien vers le dossier, accessible après connexion.

La confirmation de dépôt ne vaut pas sélection. Changer une décision ne déclenche pas d’email automatique de sélection ou de refus dans cette version.

L’envoi utilise le système mail de WordPress. Testez la réception sur votre hébergement : une acceptation par le service d’envoi ne garantit pas l’arrivée dans la boîte de réception. Il n’existe pas de relance automatique des emails échoués.

## 7. Examiner, modifier et sélectionner

Dans **Gestion des candidatures**, utilisez la recherche et les filtres d’édition et de décision. Le tableau peut défiler horizontalement. **Options de l’écran** permet de choisir le nombre de lignes par page.

- **Examiner** ouvre les photos, les justificatifs et les informations du candidat. Cliquez sur une vignette pour ouvrir la visionneuse ; utilisez les flèches pour avancer et Échap pour fermer. Si le navigateur ne sait pas afficher un PDF, utilisez son lien d’ouverture ou de téléchargement.
- Choisissez **À examiner**, **Sélectionné** ou **Non sélectionné**, puis **Enregistrer la sélection**.
- **Modifier** permet de corriger ensemble les coordonnées et la candidature, et de remplacer les photos ou justificatifs.
- **Retour à la liste** ramène à la gestion des candidatures.

La mise à la corbeille permet de retirer un spam ou un doublon. Le bouton **Corbeille** ouvre la liste native WordPress : restaurez-y un dossier supprimé par erreur. La suppression définitive retire aussi les fichiers gérés pour ce dossier ; elle nécessite donc une sauvegarde préalable si vous souhaitez pouvoir revenir en arrière.

## 8. Consulter l’historique et exporter

L’**Historique** garde nom, prénom et email fixes à gauche ; les éditions défilent vers la droite. Vert : sélectionné ; rouge : non sélectionné ; gris : à examiner ; tiret : aucune candidature.

Sous chaque édition, `12 / 30` signifie 12 candidatures sélectionnées pour 30 places prévues. La ligne suivante donne le total des candidatures. La corbeille est exclue. Un tiret au dénominateur signifie que la capacité de l’édition n’a pas été renseignée.

Le petit bouton **Exporter CSV**, en haut à droite de la gestion, exporte toutes les candidatures correspondant aux filtres et à la recherche, et pas seulement la page courante. Le fichier s’ouvre dans Excel ou LibreOffice ; en cas d’import manuel, choisissez UTF-8 et le séparateur point-virgule. Les liens des pièces demandent une connexion organisateur au site d’origine.

## 9. Publier les potiers sélectionnés

Créez une page contenant :

```text
[afficher_selection edition="2027"]
```

Dans l’édition, autorisez la publication de la sélection. Un dossier apparaît seulement s’il est sélectionné, dispose de l’autorisation de présentation et n’est pas à la corbeille. Retirer l’autorisation de publication de l’édition masque sa galerie.

La galerie affiche tous les sélectionnés : trois colonnes sur grand écran, une sur téléphone, avec un diaporama carré des trois photos de créations. Elle présente nom, prénom, ville, code postal, techniques et liens web/réseaux. Les justificatifs et la photo du stand n’apparaissent pas dans cette galerie.

La carte utilise OpenStreetMap, sans clé API configurée dans le plugin. Le géocodage automatique actuel concerne les adresses françaises via l’IGN et dépend des tâches planifiées WordPress. Une adresse étrangère ou non reconnue peut rester absente de la carte, sans empêcher la fiche d’apparaître dans la galerie.

Des copies JPEG de 1080 × 1350 pixels sont générées pour les trois photos de créations. Cette version ne publie pas automatiquement sur Instagram ou Facebook.

## 10. Sauvegarde et entretien

Sauvegardez **la base WordPress et uploads ensemble**. Le plugin utilise le sous-dossier `marche-potier` du répertoire uploads configuré par WordPress, avec des noms de fichiers aléatoires. Une réinstallation du seul ZIP ne restaure pas les candidatures ni leurs fichiers.

Avant chaque campagne, contrôlez les dates, le règlement, le tarif, les coordonnées organisateur et la réception des emails. Après une mise à jour WordPress, PHP, du thème, du cache ou du plugin, essayez un dépôt sur un site de test, l’examen d’un dossier et la galerie.

La page de candidature doit être exclue des caches de page/CDN. Le navigateur doit accepter les cookies ; JavaScript apporte la progression et la reprise. Les tâches planifiées WordPress doivent fonctionner pour nettoyer les pièces temporaires et préparer les localisations.

Cette version ne comporte pas de service de mise à jour automatique depuis GitHub. La mise à jour se fait en téléversant le nouveau ZIP. Conservez une sauvegarde avant tout remplacement.
