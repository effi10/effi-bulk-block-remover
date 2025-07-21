# Effi Bulk Block Remover

**Auteurs :** Cédric Girard ([Effi'10'](https://www.effi10.com))
**Version :** 1.0.1

Plugin WordPress pour supprimer en masse un type de bloc Gutenberg spécifique au sein d'un type de publication donné.

## Description

Ce plugin ajoute une page d'administration simple dans le menu "Outils" de WordPress. Il vous permet de sélectionner un type de publication (ex: articles, pages) et un type de bloc Gutenberg spécifique (ex: `core/image`) pour ensuite le supprimer de toutes les publications concernées.

C'est un outil puissant destiné aux administrateurs de site qui ont besoin de nettoyer leur contenu en retirant des blocs obsolètes ou non désirés de manière efficace.

## Fonctionnalités

*   Interface simple et intuitive dans le menu "Outils".
*   Liste déroulante de tous les types de publication publics.
*   Liste déroulante de tous les blocs Gutenberg enregistrés, facilitant la sélection.
*   Fonction d'analyse pour prévisualiser le nombre de publications qui seront affectées.
*   Suppression en masse des blocs en un clic.

## Installation

1.  Téléchargez le fichier `effi-bulk-block-remover.zip` depuis la page de release GitHub (ou zippez les fichiers du plugin).
2.  Depuis votre tableau de bord WordPress, allez dans `Extensions` > `Ajouter`.
3.  Cliquez sur le bouton `Téléverser une extension` en haut de la page.
4.  Choisissez le fichier `.zip` que vous venez de télécharger et cliquez sur `Installer maintenant`.
5.  Une fois l'installation terminée, cliquez sur `Activer l'extension`.

## Utilisation

1.  Une fois l'extension activée, allez dans le menu `Outils` > `Suppression de blocs en masse`.
2.  Choisissez le **Type de publication** dans la première liste déroulante.
3.  Sélectionnez le **Nom du bloc Gutenberg** à supprimer dans la seconde liste.
4.  Cliquez sur le bouton **Analyser** pour voir combien de publications contiennent ce bloc. Le résultat s'affichera juste en dessous.
5.  Pour lancer la suppression définitive, cliquez sur **Supprimer ces blocs**.

> **ATTENTION :** Cette action est **irréversible** ! Elle modifie le contenu directement dans la base de données. Il est fortement recommandé de faire une **sauvegarde complète de votre site** (fichiers et base de données) avant de procéder à la suppression.

## Changelog

### 1.0.1
*   **Amélioration :** Remplacement du champ texte pour le nom du bloc par une liste déroulante de tous les blocs enregistrés.
*   **Amélioration :** Ajout d'un message d'avertissement explicite sur le caractère irréversible de la suppression.
*   **Mise à jour :** Incrémentation de la version du plugin.

### 1.0.0
*   Version initiale du plugin. 