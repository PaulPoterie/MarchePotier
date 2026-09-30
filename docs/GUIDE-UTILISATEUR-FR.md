# Poterie Navarraise Pottery Market Manager

Poterie Navarraise — Organisation de marchés potiers — Guide utilisateur

Version de travail 0.19.1 — 29 septembre 2026 — branche `fix/wordpress-review`.

Cette version prépare les corrections demandées par WordPress.org. Le nouveau préfixe technique est `marcpo_` : les données de test de la 0.19.0 ne sont pas migrées et ne sont plus utilisées. Recréez les éditions et affectations pour les nouveaux essais. Le nom officiel est « Poterie Navarraise Pottery Market Manager » ; le slug WordPress.org et le domaine de traduction sont `poterie-navarraise-market-manager`. Le préfixe interne `marcpo_` est conservé.

Ce dépôt contient le code actif du plugin : administration du marché, candidatures, notes de 0 à 5, invitations, suivi des votes et blocs publics.

- Pour utiliser le plugin : suivre le guide ci-dessous et le [guide des votes](VOTES.md).
- Pour reprendre le développement : commencer par [l’architecture, les règles métier et les vérifications](DEVELOPPEMENT.md).
- Présentation générale en anglais : [README du dépôt](../README.md).

Les blocs **Formulaire de candidature** et **Présentation de la sélection** utilisent l’ID de l’édition choisie. Il n’y a plus de shortcodes. Chaque édition demande **un seul administrateur**, avec nom et email obligatoires. Les votes restent possibles à toute date en mode multiple.

Dernière version soumise : **0.19.1**, sous l’ancien nom « Marché Potier ». Cette branche contient les corrections en cours, non encore soumises.

## 1. Installer le plugin

Dans WordPress, ouvrez **Extensions → Ajouter une extension → Téléverser une extension**, choisissez le ZIP de la version à installer, puis cliquez sur **Installer maintenant** et **Activer**.

Les prochains ZIP de livraison utiliseront le dossier `poterie-navarraise-market-manager`, prêt à installer. Les dossiers de développement `docs`, `tests` et `.tools` ne doivent pas être copiés dans les extensions. Les modifications de la branche et du site local ne régénèrent pas automatiquement un ZIP : un paquet déjà présent peut donc être antérieur au code courant.

Pour mettre à jour une installation existante, sauvegardez d’abord la base WordPress et le dossier `wp-content/uploads`, puis téléversez le nouveau ZIP et choisissez le remplacement de la version existante. Les candidatures sont conservées en base ; leurs fichiers sont dans uploads. Ne supprimez pas les fichiers uploads pour effectuer une mise à jour. Le passage de 0.19.1 à 0.19.2 conserve les éditions, candidatures, affectations et notes. Téléchargez le fichier `poterie-navarraise-market-manager-0.19.2.zip` dans les fichiers de la [release GitHub](https://github.com/PaulPoterie/MarchePotier/releases/tag/v0.19.2), plutôt que les archives automatiques « Source code ».

Lors du passage de l’ancien dossier `marche-potier` au nouveau dossier `poterie-navarraise-market-manager`, désactivez l’ancienne extension avant d’activer la nouvelle. WordPress peut les présenter comme deux extensions distinctes : ne les activez pas ensemble.

Prévoir WordPress 6.6 minimum déclaré et PHP 8.2 minimum déclaré ; les essais de cette livraison portent sur WordPress 7.1 et PHP 8.3. L’hébergement doit disposer de MySQL/MariaDB avec les verrous nommés, de Fileinfo et de GD avec JPEG, PNG et WebP. Les versions minimales déclarées n’ont pas toutes été testées séparément ; vérifiez le formulaire et les emails sur votre hébergement avant ouverture.

## 2. Donner accès aux organisateurs

Un administrateur WordPress accède au menu **Gestion Marché Potier**. Le rôle **[Poterie Navarraise] Administrateur marché** permet de créer, modifier, publier et supprimer les éditions, les candidatures, les pages et les articles, y compris ceux des autres comptes. Ces droits s’appliquent à l’ensemble du site. Ce rôle ne donne pas accès à la gestion des extensions, des utilisateurs ni aux réglages techniques de WordPress.

Dans **Organisateur et votes** d’une édition, renseignez le nom et l’email obligatoires de l’**Administrateur du marché**. Il est unique, toujours actif, décide de la sélection en mode simple et participe aussi aux votes multiples. Le tableau **Votant pour la sélection** crée des comptes **[Poterie Navarraise] Votant sélection**, limités à la consultation et à leur propre note dans les éditions affectées. Il n’existe plus de bouton pour ajouter d’autres administrateurs.

Pour remplacer l’administrateur, modifiez son nom et son email dans l’édition. En mode votes multiples, si le remplaçant figure déjà parmi les votants, décochez sa case **Actif** avant d’enregistrer : sa note reste liée au même compte. En mode simple, le changement de rôle est pris en charge à l’enregistrement sans afficher le tableau des votants. L’ancien administrateur devient votant inactif et sa note sort du total. Les droits WordPress préexistants de son compte sont conservés ; seul un administrateur du site peut les retirer.

Les comptes existants gardent leurs identifiants et leurs mots de passe. Les anciens rôles sont renommés automatiquement sans recréer les comptes ; les notes restent associées aux mêmes personnes.

Une connexion générale avec le profil **[Poterie Navarraise] Votant sélection** ouvre directement **Gestion des candidatures**, limitée aux éditions affectées. Si le lien de connexion demande une destination précise (dossier, édition, profil…), celle-ci est conservée.

Dans cette liste, le filtre **Tous (avec ou sans notes) / Déjà noté par moi / À noter par moi** permet de retrouver ses dossiers. Sous **Examiner**, **Ma note (nom) : X/5** affiche la note du membre connecté, ou **À noter** si elle est absente. La note **0** compte comme un vote. Les filtres personnels concernent les éditions où ce membre participe aux votes multiples ; ils sont conservés lors du tri, de la pagination et du retour depuis un dossier.

## 3. Créer une édition

Ouvrez **Gestion Marché Potier → Éditions → Ajouter**. Donnez un titre explicite, par exemple « Marché de potiers de Bayonne 2027 ».

Renseignez :

- **Édition de l’année**, le premier champ des paramètres. Plusieurs éditions peuvent avoir la même année : chaque bloc conserve l’édition choisie, même si son titre ou son année change.
- L’ouverture et la fermeture des candidatures. Les heures déterminent la disponibilité réelle du formulaire, dans le fuseau horaire WordPress. La page publique affiche uniquement les dates.
- Les dates du marché, son lieu, le nombre d’exposants et le prix de l’emplacement pour l’ensemble du marché.
- Le tarif réduit, si nécessaire, avec l’explication des personnes concernées.
- Le texte complémentaire, avec l’éditeur WordPress, et le règlement intérieur en PDF.
- La longueur de stand par défaut et l’autorisation ou non de la modifier.
- Le message de remerciement (un message standard est utilisé s’il reste vide) et l’administrateur unique dans **Organisateur et votes**. Celui-ci reçoit les nouvelles candidatures et les réponses des candidats.

Précisez dans le texte complémentaire ce qui est compris dans le prix, les éventuels équipements fournis, les modalités de paiement et la date prévue de réponse aux candidats.

Dans **Administrateur du marché**, le bouton **Enregistrer l’édition et envoyer l’invitation** sauvegarde l’ensemble des modifications puis invite l’administrateur renseigné, après validation des champs obligatoires. Le résultat de l’envoi apparaît sous le bouton. Une nouvelle édition reste en brouillon : ce bouton ne remplace pas sa publication. Les nouveaux comptes reçoivent aussi une invitation automatiquement lors de leur premier enregistrement ; les comptes existants conservent leur mot de passe et ne sont pas réinvités par un simple enregistrement.

Publiez l’édition. L’ouverture des candidatures respecte automatiquement les dates : la publication ne rend pas le formulaire disponible avant la date de début.

Le conseil sous le titre propose « Marché potier 20XX » comme exemple ; il ne rend pas le titre obligatoire. En mode **Simple — sélection directe**, la rubrique des votants est masquée. Choisir **Votes multiples** la fait réapparaître immédiatement. Les affectations et notes déjà enregistrées sont conservées lors d'un changement de mode.

## 4. Afficher le formulaire

Créez ou ouvrez une page WordPress. Cliquez sur **+**, recherchez **Formulaire de candidature** et ajoutez ce bloc. Dans **Édition à afficher**, choisissez **Titre de l’édition (Année)**, puis publiez ou mettez à jour la page. Ajoutez son lien au menu de votre site si nécessaire.

Le menu est aussi disponible dans les réglages du bloc. **Actualiser les éditions** recharge la liste après la création d’un marché dans un autre onglet. Une édition non publiée ou supprimée est signalée. Le bloc ne choisit jamais une autre édition à sa place. Ajoutez un seul formulaire de candidature par page.

La rubrique **Affichage sur le site**, tout en bas de l’édition après les rôles, rappelle ces étapes.

Les anciens shortcodes ne sont plus reconnus. Remplacez-les par les blocs correspondants et choisissez l’édition à afficher.

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

Les textes et les choix sont sauvegardés dans le navigateur pendant au plus 24 heures. Les pièces reçues disposent également d’une conservation temporaire de 24 heures ; le délai du texte est raccourci si les pièces expirent plus tôt. Pour reprendre, utiliser le même navigateur et conserver ses cookies et données de site. Une erreur de validation ne supprime pas le brouillon. Après confirmation de l’enregistrement, les mêmes médias sont rattachés à la candidature et deviennent définitifs ; seules les données du brouillon sont effacées. Les copies pour les réseaux sociaux sont générées après validation. Le nettoyage ne supprime que les pièces provisoires expirées et non utilisées par une candidature, jamais les médias ordinaires non attachés. Aucun bouton « Effacer le brouillon » n’est encore proposé.

Le consentement à la présentation publique en cas de sélection est obligatoire. Une seconde case, **facultative et décochée par défaut**, autorise l’envoi de l’adresse à l’IGN et sa localisation sur la carte publique. Refuser cette seconde case n’empêche ni la candidature ni la présentation dans la galerie. Le téléphone reste obligatoire pour le suivi du dossier ; il n’est transmis ni à l’IGN ni à OpenStreetMap.

Une même adresse email ne peut déposer deux candidatures pour la même édition, y compris si une candidature est à la corbeille. Pour une autre édition, un nouveau dossier est possible. Nom, prénom et email servent au rapprochement de l’historique ; une identité différente avec le même email peut apparaître sur une autre ligne.

## 6. Recevoir les confirmations

L’écran final confirme l’enregistrement. Le candidat et l’administrateur de l’édition reçoivent chacun un récapitulatif comportant les noms des pièces reçues. En votes multiples, les votants actifs le reçoivent aussi. Les pièces ne sont pas jointes aux emails. L’équipe reçoit un lien vers le dossier, accessible après connexion. L’administrateur est l’adresse de réponse du candidat.

La confirmation de dépôt ne vaut pas sélection. Changer une décision ne déclenche pas d’email automatique de sélection ou de refus dans cette version.

L’envoi utilise le système mail de WordPress. Testez la réception sur votre hébergement : une acceptation par le service d’envoi ne garantit pas l’arrivée dans la boîte de réception. Il n’existe pas de relance automatique des emails échoués.

## 7. Examiner, modifier et sélectionner

La vue regroupe les actions de gestion en haut, puis les filtres dans un encadré avec leurs libellés. Le compteur indique les lignes affichées et la page en cours. Dans le tableau, les noms et les points ressortent, les lignes alternent et les colonnes sont séparées. La note personnelle apparaît dans un encadré vert lorsqu’elle est enregistrée, ou ambre avec **À noter** lorsqu’elle manque. Les informations complètes restent affichées ; les en-têtes et la première colonne restent visibles pendant le défilement sur ordinateur. Sur petit écran, les filtres se répartissent sur plusieurs lignes.

Le menu **Trier par**, au-dessus du tableau à côté du nombre de candidatures, propose **Points** (croissants ou décroissants), **Soumission** (récentes ou anciennes) et **Nom** (A à Z ou Z à A). Par défaut, les soumissions récentes apparaissent en premier. Le changement s’applique immédiatement à tous les résultats et revient à la première page, en conservant les filtres et la recherche. Le tri est aussi conservé pendant l’examen des dossiers. Les candidatures sans note restent en dernier pour le tri par points ; zéro compte comme une note. Les dates absentes restent en dernier pour le tri par soumission.

Dans **Gestion des candidatures**, utilisez la recherche et les filtres d’édition, de décision et de note personnelle : **Tous (avec ou sans notes)**, **Déjà noté par moi**, **À noter par moi**. Le tableau regroupe les informations dans huit colonnes : **Potier / édition** (nom, édition, actions, date de soumission sous les boutons et note personnelle), **Photos**, **Sélection et points**, **Identité** (informations personnelles), **Contact**, **Présentation**, **Production et techniques**, **Statuts et vie associative**. Le code postal, la ville et le pays sont regroupés sans libellés, par exemple **21000 Dijon, France**. Les autres informations sont présentées sur des lignes séparées ; téléphone, email, site et réseaux sociaux sont cliquables. Le titre **Sélection et points** ne déclenche pas de tri. Le tableau peut défiler horizontalement, avec la première colonne fixée à gauche sur ordinateur. **Options de l’écran** permet de choisir le nombre de lignes par page. Les justificatifs et le suivi interne se consultent dans **Examiner**.

- **Examiner** présente d’abord la production, les techniques, le stand demandé et les photos du candidat. Cliquez sur une vignette pour ouvrir la visionneuse ; utilisez les flèches pour avancer et Échap pour fermer. La présentation de l’atelier suit les photos. Toutes les rubriques sont ouvertes par défaut, sur ordinateur comme sur téléphone ; cliquez sur leur titre pour les replier si besoin. Si le navigateur ne sait pas afficher un PDF, utilisez son lien d’ouverture ou de téléchargement.
- En votes multiples, le panneau **Ma note** reste à droite pendant la lecture sur ordinateur. Choisissez une note entre 0 et 5 puis cliquez sur **Valider ma note**. Le panneau distingue une note enregistrée d’une modification encore à valider. Les notes du jury sont ouvertes par défaut pour tous les profils, avec le total des points. L’administrateur dispose aussi d’un encadré distinct pour la décision finale.
- Choisissez **À examiner**, **Sélectionné** ou **Non sélectionné**, puis **Enregistrer la sélection**.
- **Modifier** permet de corriger ensemble les coordonnées et la candidature, et de remplacer les photos ou justificatifs.
- **Retour à la liste** ramène à la gestion des candidatures, ou au suivi des votes si le dossier a été ouvert depuis ce tableau.

En **Votes multiples**, chaque administrateur ou votant affecté peut noter et corriger sa propre note à tout moment, même avant l’ouverture ou après la fermeture des inscriptions. Il n’y a pas d’option de clôture des votes. Le mode simple conserve les notes mais masque la notation.

La mise à la corbeille permet de retirer un spam ou un doublon. Le bouton **Corbeille** ouvre la liste native WordPress : restaurez-y un dossier supprimé par erreur. La suppression définitive du dossier conserve ses médias dans la médiathèque, pour ne pas casser leurs éventuelles réutilisations. Supprimez explicitement les médias devenus inutiles dans la médiathèque.

### Suivi des votes

Ouvrez **Suivi des votes** depuis le menu Gestion Marché Potier, l’accueil du plugin ou le bouton placé avant **Historique des sélections** dans **Examiner**. Depuis un dossier, son édition est déjà choisie.

Chaque ligne représente une candidature et chaque colonne un membre actif du jury, **administrateur compris**. Sous son nom, **10 votes / 30 candidatures** indique l’avancement de ce membre pour l’ensemble de l’édition. Une case **0/5** est un vote ; **—** signifie qu’aucune note n’est enregistrée. Les candidatures à la corbeille et les membres inactifs sont exclus du suivi.

Les administrateurs et les votants peuvent consulter ce tableau. Les votants ne voient que leurs éditions affectées. Cliquez sur un candidat pour examiner son dossier et voter dans votre propre ligne ; le tableau de suivi lui-même est en consultation uniquement. Une édition en mode simple affiche un message indiquant qu’aucune notation n’est attendue.

## 8. Consulter l’historique et exporter

L’**Historique des sélections** garde nom, prénom et email fixes à gauche ; les éditions défilent vers la droite. Vert : sélectionné ; rouge : non sélectionné ; gris : à examiner ; tiret : aucune candidature.

Ces trois colonnes sont compactes. Le champ **Rechercher un potier** filtre uniquement le nom, le prénom et l'email affichés, sans distinction de casse ou d'accents. Plusieurs mots peuvent être combinés ; les intitulés d'édition et les autres réponses ne sont pas recherchés. **Effacer la recherche** réaffiche les personnes accessibles. Les totaux en tête des éditions ne changent pas avec la recherche.

L'accueil **Gestion de Marché Potier** regroupe les accès et un guide de démarrage. En bas, **Besoin d'aide ?** permet de contacter `paul@poterie-navarraise.info` ; le lien prépare un email dont l'objet contient la version installée. Décrivez le problème avant de l'envoyer : le plugin n'envoie aucun message de support automatiquement.

Sous chaque édition, `12 / 30` signifie 12 candidatures sélectionnées pour 30 places prévues. La ligne suivante donne le total des candidatures. La corbeille est exclue. Un tiret au dénominateur signifie que la capacité de l’édition n’a pas été renseignée.

Le petit bouton **Exporter CSV**, en haut à droite de la gestion, exporte toutes les candidatures correspondant aux filtres et à la recherche, et pas seulement la page courante. Le fichier s’ouvre dans Excel ou LibreOffice ; en cas d’import manuel, choisissez UTF-8 et le séparateur point-virgule. L’export exige les droits de gestion ; les liens directs des pièces s’ouvrent sans connexion, conformément au stockage dans la médiathèque.

## 9. Publier les potiers sélectionnés

Créez ou ouvrez une page, ajoutez le bloc **Présentation de la sélection** avec le bouton **+**, puis choisissez **Titre de l’édition (Année)** dans le menu du bloc et publiez la page.

Dans la rubrique **Affichage sur le site**, en bas de l’édition après les rôles, cochez **Autoriser l’affichage public de la sélection**, puis enregistrez l’édition. Un dossier apparaît seulement s’il est sélectionné, dispose de l’autorisation de présentation et n’est pas à la corbeille. Retirer cette autorisation masque la galerie. L’édition doit elle-même être publiée.

La galerie affiche tous les sélectionnés : trois colonnes sur grand écran, une sur téléphone, avec un diaporama carré des trois photos de créations. Elle présente nom, prénom, ville, code postal, techniques et liens web/réseaux. Les justificatifs et la photo du stand n’apparaissent pas dans cette galerie.

Les services cartographiques sont **désactivés par défaut**. Un administrateur WordPress peut les autoriser dans **Gestion Marché Potier → Services externes**, après lecture des informations sur les données transmises et des conditions des prestataires. Les deux cases sont indépendantes : l’IGN localise les adresses françaises ; OpenStreetMap fournit le fond de carte. Les organisateurs et votants ne disposent pas de ce réglage technique.

La carte nécessite aussi l’accord distinct de chaque candidat. Son absence n’empêche pas l’affichage de la fiche dans la galerie. L’accord et sa date figurent dans le dossier et l’export CSV ; la confirmation de candidature rappelle le choix. À la demande d’un candidat, décochez son autorisation cartographique dans **Modifier** pour retirer sa position et arrêter sa localisation. L’ancien accord de présentation publique ne vaut pas accord cartographique.

Aucune clé API n’est requise. Le géocodage dépend des tâches planifiées WordPress, qui revérifient l’autorisation du site et du candidat à chaque exécution. Désactiver IGN arrête les nouvelles localisations ; désactiver OpenStreetMap masque la carte. Purgez les caches de pages/CDN après un changement ou un retrait d’accord pour actualiser les pages déjà mises en cache. Une adresse étrangère ou non reconnue peut rester absente de la carte sans empêcher la fiche d’apparaître dans la galerie.

Après validation de la candidature, le plugin prépare automatiquement des copies JPEG de 1080 × 1350 pixels pour de futures publications Instagram et Facebook. La photo entière est conservée sur fond blanc, sans remplacer l’image du dossier. Les copies sont disponibles dans la médiathèque et par des liens sous les photos dans **Examiner**. Cette version ne publie pas automatiquement sur ces réseaux.

## 10. Sauvegarde et entretien

Sauvegardez **la base WordPress et uploads ensemble**. Les nouvelles pièces sont des médias WordPress dans le répertoire uploads configuré. Les anciennes pièces sont importées progressivement dans la médiathèque, sans déplacement si elles sont déjà dans uploads. Une réinstallation du seul ZIP ne restaure pas les candidatures ni leurs fichiers.

Avant chaque campagne, contrôlez les dates, le règlement, le tarif, les coordonnées organisateur et la réception des emails. Après une mise à jour WordPress, PHP, du thème, du cache ou du plugin, essayez un dépôt sur un site de test, l’examen d’un dossier et la galerie.

La page de candidature doit être exclue des caches de page/CDN. Le navigateur doit accepter les cookies ; JavaScript apporte la progression et la reprise. Les tâches planifiées WordPress doivent fonctionner pour nettoyer les pièces temporaires et préparer les localisations.

Cette version ne comporte pas de service de mise à jour automatique depuis GitHub. La mise à jour se fait en téléversant le nouveau ZIP. Conservez une sauvegarde avant tout remplacement.

### Médiathèque et liens des pièces

Photos, justificatifs et copies pour les réseaux sociaux sont accessibles par leur URL sans connexion. Les justificatifs ne figurent pas dans la galerie publique. Le CSV contient les liens directs ; son export et les dossiers restent réservés aux comptes autorisés. Dans la vue liste de la médiathèque, la colonne Poterie Navarraise distingue les envois provisoires des pièces rattachées à une candidature. Remplacer une pièce conserve l’ancien média.

### Adresses et centre de commune
Avant l’envoi à IGN, les espaces du code postal sont retirés, les mots st/ST deviennent saint dans la rue et la commune, et les pays Pays Basque, Pays-Basque, Euskal Herri, Euskal-Herri, Euskal Herria sont interprétés comme France. Les réponses originales du dossier sont conservées. Si l’adresse précise ne convient pas, une seconde recherche porte sur la commune avec son code postal. Le point communal affiche « position approximative ». Une commune encore ambiguë ou introuvable reste sans point.
