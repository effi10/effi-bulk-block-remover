# Effi Bulk Block Remover

**Auteur :** Cédric Girard ([effi10](https://www.effi10.com/))
**Version :** 1.1.0 · **Licence :** GPL-2.0-or-later · **WordPress :** 5.3.1 ou plus · **PHP :** 7.4 ou plus

Extension WordPress qui retire en masse un bloc Gutenberg de tous les contenus d'un type donné (articles, pages, types personnalisés publics), y compris le bloc d'une extension désinstallée, avec simulation, sauvegarde de chaque contenu modifié et restauration.

## ⚠️ Avertissement

Cette extension effectue une **opération en masse qui modifie directement en base le contenu** de tous les éléments du type choisi qui contiennent le bloc ciblé.

- **Faites une sauvegarde complète de la base de données avant toute suppression.** La sauvegarde intégrée à l'outil (copie de chaque contenu modifié) est un filet de sécurité, elle ne remplace pas une vraie sauvegarde.
- **Testez d'abord en mode simulation** : il liste les contenus concernés, sans rien modifier.
- **Idéalement, faites l'opération d'abord sur une préproduction** (copie du site), vérifiez les pages concernées, puis reproduisez-la en production.
- L'extension est fournie sans aucune garantie (voir le fichier [LICENSE](LICENSE)).

## À quoi elle sert

Quand on désinstalle une extension de blocs, ses blocs restent dans les contenus et s'affichent comme « bloc non pris en charge ». Cet outil les retrouve et les retire partout, y compris à l'intérieur des Groupes, Colonnes et autres blocs conteneurs. Il sert aussi à retirer en masse n'importe quel bloc enregistré.

## Fonctionnement

L'outil se trouve dans **Outils › Suppression de blocs en masse** (réservé aux administrateurs : capacité `manage_options`).

- **Type de contenu** : types publics du site (hors fichiers médias). Statuts traités : publié, brouillon, en attente de relecture, programmé et privé.
- **Bloc à retirer** : la liste propose les blocs enregistrés sur le site et, après un **inventaire**, les blocs réellement présents dans les contenus du type choisi, y compris ceux qui ne sont plus enregistrés (marqués « non enregistré »). On peut aussi saisir le nom du bloc (`extension/nom-du-bloc`, en minuscules ; il est validé).
- **Analyse récursive** : les blocs sont cherchés à tous les niveaux d'imbrication. Un bloc retiré l'est **avec tout ce qu'il contient** (blocs placés à l'intérieur compris) ; la simulation indique combien de blocs internes partent avec lui.
- **Reconstruction fidèle** : le contenu est reconstruit avec `serialize_blocks()`. Les autres blocs gardent leurs délimiteurs, leurs attributs et leur HTML enregistré ; rien n'est « rendu ». Seuls les contenus où le bloc a été trouvé sont enregistrés : les autres ne sont pas touchés.
- **Traitement par lots** de 25 contenus, avec barre de progression. En cas d'erreur réseau ou serveur, le traitement s'arrête proprement et peut être **repris** là où il s'est arrêté.
- **Bilan réel** à la fin : contenus modifiés, blocs retirés, erreurs (avec la liste des contenus et un lien vers chacun).

### Contrôles de sécurité

- Jeton de sécurité (nonce) et vérification des droits sur chaque action AJAX ; type de contenu limité à la liste des types publics ; nom de bloc validé (`^[a-z0-9-]+/[a-z0-9-]+$`).
- La suppression et la restauration exigent la capacité `unfiltered_html` (administrateur d'un site simple, super-administrateur d'un multisite). Sans elle, WordPress filtrerait les contenus enregistrés (iframes, scripts, formulaires supprimés) : l'outil refuse donc l'opération et le dit clairement. L'inventaire et la simulation restent possibles.
- Certains contenus sont **laissés intacts et signalés** (dès la simulation) :
  - un autre bloc du contenu a des attributs JSON illisibles (les reconstruire les effacerait) ;
  - les délimiteurs de blocs sont déséquilibrés (bloc non fermé, fermeture orpheline) ;
  - la reconstruction, relue par WordPress, ne donne pas exactement la structure attendue ;
  - le contenu est programmé à une date déjà passée (l'enregistrer le publierait) ;
  - le contenu est ouvert dans l'éditeur par quelqu'un d'autre ;
  - l'utilisateur n'a pas le droit de modifier ce contenu.

## Utilisation pas à pas

1. **Faites une sauvegarde complète de la base de données.**
2. Choisissez le **type de contenu**.
3. Cliquez sur **Inventorier les blocs présents** : un tableau liste les blocs trouvés (enregistrés ou non, nombre de contenus et d'occurrences). Le bouton **Choisir** sélectionne un bloc. Vous pouvez aussi le prendre dans la liste ou le saisir (« Autre »).
4. Cliquez sur **Simuler la suppression** : aucun contenu n'est modifié. Vous obtenez le nombre de contenus et d'occurrences, puis la liste des contenus concernés (titre, statut, lien d'édition, nombre de blocs trouvés, blocs internes supprimés avec eux, remarques).
5. Le bouton **Retirer les blocs** s'active après une simulation qui trouve le bloc (il se désactive dès que le type ou le bloc change). Une confirmation est demandée, puis les contenus sont traités par lots. Ne fermez pas la page pendant le traitement.
6. Vérifiez quelques pages concernées. En cas de problème, utilisez **Restaurer la dernière opération**.

## Sauvegarde et restauration

**Avant chaque modification**, l'ancien contenu est enregistré dans une métadonnée du contenu, `_effi_bbr_backup_{identifiant de l'opération}`, avec ses dates de modification d'origine et l'empreinte du contenu réellement enregistré. La sauvegarde est relue et comparée à l'original avant toute modification : si elle échoue, le contenu n'est pas modifié.

Pourquoi une métadonnée plutôt qu'une révision : elle fonctionne pour tous les types de contenu, y compris ceux sans révisions, elle ne dépend ni de `WP_POST_REVISIONS` ni de la limite du nombre de révisions, et elle conserve les dates de modification (que les révisions ne restaurent pas). Les révisions WordPress habituelles continuent d'être créées pour les types qui les prennent en charge.

La section **Opérations et restauration** affiche l'historique des dix dernières opérations et la dernière opération restaurable :

- **Restaurer la dernière opération** remet chaque contenu dans son état d'avant la suppression (contenu et date de modification). Un contenu modifié depuis l'opération n'est **pas** restauré, pour ne pas écraser ces modifications : il est signalé et sa sauvegarde est conservée (statut « Restaurée en partie »). Les opérations se restaurent dans l'ordre inverse : une fois la dernière restaurée, la précédente devient restaurable.
- **Reprendre la suppression** / **Reprendre la restauration** termine un traitement interrompu.
- **Supprimer la sauvegarde** efface définitivement les copies de l'opération (elle ne pourra plus être restaurée). Une nouvelle suppression ne peut pas être lancée tant qu'une opération est en cours ou interrompue.

**Attention :** supprimer l'extension depuis l'écran Extensions efface toutes ses sauvegardes (`uninstall.php`). Restaurez ce qui doit l'être avant.

## Conserver la date de modification

L'option **Conserver la date de modification des contenus** est cochée par défaut : la date « modifié le » des contenus traités reste celle d'avant le retrait, pour ne pas fausser la date `lastmod` des sitemaps ni les mentions « mis à jour le ». Elle passe par un filtre temporaire sur `wp_insert_post_data`, ajouté au début de chaque lot et retiré à la fin, qui ne touche que les contenus traités.

## Limites connues

- Le bloc est retiré avec son contenu : l'outil ne sait pas « déballer » un bloc conteneur en gardant les blocs placés à l'intérieur.
- Dans un contenu modifié, WordPress réécrit les attributs des autres blocs sous une forme équivalente mais pas toujours identique à l'octet près (par exemple `&` devient `&`, un antislash devient `\`, un objet vide `{}` devient `[]`). Les valeurs relues sont les mêmes ; seul l'objet vide peut changer de nature pour l'éditeur (cas rare).
- Seuls les types de contenu publics sont proposés (pas les compositions synchronisées `wp_block`, ni les modèles du thème).
- Les noms de blocs doivent respecter le format du registre WordPress (minuscules, chiffres et tirets) ; un nom avec un tiret bas est listé par l'inventaire mais ne peut pas être ciblé.

## Installation

1. Téléchargez l'archive depuis GitHub (bouton **Code › Download ZIP** ou page des versions). Le dossier de l'extension doit s'appeler `effi-bulk-block-remover` : renommez-le si l'archive a ajouté un suffixe (`-main`).
2. Dans WordPress, allez dans **Extensions › Ajouter › Téléverser une extension**, choisissez le fichier `.zip`, puis **Installer maintenant**.
3. Activez l'extension, puis allez dans **Outils › Suppression de blocs en masse**.

## Journal des versions

### 1.1.0

Version corrective issue d'un audit de sécurité et de qualité. **La 1.0.1 ne doit plus être utilisée.**

- **Correctif critique :** le contenu est reconstruit avec `serialize_blocks()` au lieu de `render_block()`. La 1.0.1 enregistrait le HTML rendu, sans les délimiteurs de blocs : les contenus devenaient un bloc « Classique » et les blocs dynamiques étaient figés ou perdus. Seuls les contenus où le bloc est trouvé sont désormais enregistrés (la 1.0.1 réécrivait tous les contenus du type). Un nom de bloc vide ou invalide est refusé.
- **Sécurité :** jeton (nonce) sur chaque action AJAX, contrôle de `manage_options`, `wp_unslash()` et assainissement des paramètres, liste blanche des types de contenu publics, validation du nom de bloc.
- **Blocs imbriqués :** recherche et retrait à tous les niveaux (Groupe, Colonnes…), en mettant à jour `innerBlocks` et les marqueurs de `innerContent`.
- **Enregistrement :** `wp_update_post( wp_slash( … ) )` pour préserver les antislashs (attributs JSON, `<`, chemins) ; opération refusée, avec un message clair, si l'utilisateur n'a pas `unfiltered_html`.
- **Traitement par lots** de 25 contenus (curseur sur le dernier ID traité), barre de progression, reprise après interruption, gestion des erreurs, boutons désactivés pendant le traitement, bilan réel (contenus modifiés, blocs retirés, erreurs).
- **Filet de sécurité :** sauvegarde de chaque contenu avant modification, mode simulation détaillé (titre, lien d'édition, nombre de blocs), restauration de la dernière opération, suppression des sauvegardes.
- **Option « Conserver la date de modification »** (cochée par défaut).
- **Blocs d'extensions désinstallées :** inventaire des blocs réellement présents dans les contenus (enregistrés ou non) et saisie libre validée.
- **Contrôles supplémentaires :** contenus laissés intacts si des attributs JSON sont illisibles, si les délimiteurs sont déséquilibrés ou si la reconstruction n'est pas conforme ; contenus programmés à une date passée et contenus en cours d'édition ignorés ; flottants des attributs réécrits à l'identique quel que soit `serialize_precision`.
- **Statuts :** les contenus en attente de relecture et programmés sont désormais traités.
- **Tenue du dépôt :** licence GPL-2.0-or-later déclarée et fichier `LICENSE`, en-tête complet (`Requires at least`, `Requires PHP`, `License`, `Text Domain`, `Update URI`…), préfixe `effi_bbr_` / `Effi_BBR_`, constante de version utilisée pour le script, chaînes traduisibles, `uninstall.php`, compatibilité PHP 8 (plus d'accès à des clés `$_POST` absentes).

### 1.0.1

- **Amélioration :** remplacement du champ texte pour le nom du bloc par une liste déroulante de tous les blocs enregistrés.
- **Amélioration :** ajout d'un message d'avertissement explicite sur le caractère irréversible de la suppression.
- **Mise à jour :** incrémentation de la version de l'extension.

### 1.0.0

- Version initiale de l'extension.
