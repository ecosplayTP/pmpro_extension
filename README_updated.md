# pmpro_extension

## Vue d'ensemble
Ce projet fournit le plugin WordPress **ECOSplay Referrals** destiné à étendre Paid Memberships Pro avec un programme de parrainage complet pour les membres « premium ». Le cœur du plugin crée les tables personnalisées nécessaires, génère un code unique pour chaque utilisateur éligible, applique automatiquement les remises configurées au checkout et crédite les parrains lors des conversions. Une interface d'administration dédiée dans le menu « Adhésions » expose la gestion des codes, le suivi des usages, des statistiques synthétiques et les réglages métier. Côté front-end, une notification flottante promeut la campagne et enregistre son état de lecture tandis que des shortcodes permettent d'afficher les points gagnés et le lien de parrainage du membre connecté.

## Fonctionnalités clés
- **Provision des données** : création de trois tables (`wp_ecos_referrals`, `wp_ecos_referral_uses`, `wp_ecos_referral_notifications`) pour stocker les codes, les conversions et l'état des notifications.
- **Génération automatique des codes** : attribution ou régénération d'un code unique à l'inscription et à la demande depuis l'administration.
- **Intégration checkout PMPro** : champ dédié, validations avancées (nonce, adhésion active, anti auto-parrainage) et application dynamique de la remise configurée.
- **Récompense des parrains** : journalisation des utilisations, calcul des crédits et agrégation des totaux à reverser.
- **Interface d'administration** (sous-menu « Parrainages » dans « Adhésions ») :
  - *Codes actifs* : liste des codes premium, régénération unitaire ou globale, remise à zéro des notifications.
  - *Historique* : journal filtrable des usages avec montant accordé et lien vers les parrains.
  - *Statistiques* : synthèse hebdomadaire/mensuelle des conversions et montants remisés.
  - *Réglages* : configuration des montants de remise et de récompense via la Settings API.
- **Notification flottante front-end** : bannière AJAX avec cookie et persistance par utilisateur, réinitialisable depuis l'admin.
- **Shortcodes premium** : `[ecos_referral_points]` pour afficher les crédits gagnés, `[ecos_referral_link]` pour générer un lien de parrainage vers la page d’adhésion, et `[ecos_referral_code]` pour afficher le code avec un bouton de copie.
- **Pré-remplissage marketing** : capture du paramètre `?ref=` et mémorisation temporaire pour les conversions ultérieures.

## Tests

### Tests manuels
1. Se connecter avec un compte standard (sans niveau autorisé), afficher une page contenant les shortcodes `[ecos_referral_points]` et `[ecos_referral_link]` et vérifier qu'aucun contenu n'est rendu.
2. Se connecter avec un membre disposant d'un niveau de la liste autorisée (ex. `pmpro_role_2`), recharger la même page et constater l'affichage des points et du lien de parrainage.
3. Ouvrir l'écran « Codes de parrainage » dans l'administration, confirmer que seuls les comptes autorisés apparaissent et que les comptes standards sont absents de la liste.

## Arborescence du plugin
```
wp-content/
└── plugins/
    └── ecosplay-referrals/
        ├── ecosplay-referrals.php            # Point d'entrée : constantes, autoload, hooks d'activation et bootstrap.
        ├── includes/
        │   ├── index.php                     # Garde de sécurité pour l'accès direct.
        │   ├── class-referrals-store.php     # Accès aux tables personnalisées et opérations SQL.
        │   ├── class-referrals-service.php   # Logique métier du programme de parrainage.
        │   └── class-referrals-shortcodes.php # Shortcodes front-end pour points, lien et code de parrainage.
        ├── admin/
        │   ├── index.php                     # Protection d'accès direct.
        │   ├── class-admin-menu.php          # Déclaration du sous-menu et dispatch des onglets.
        │   ├── class-admin-codes-page.php    # Gestion de la liste des codes et actions de maintenance.
        │   ├── class-admin-usage-page.php    # Historique des utilisations de codes.
        │   ├── class-admin-stats-page.php    # Agrégats statistiques pour le tableau de bord.
        │   ├── class-admin-settings.php      # Intégration Settings API pour montants de remise/récompense.
        │   └── views/
        │       ├── codes.php                 # Gabarit de la liste des codes et formulaires d'actions.
        │       ├── usage.php                 # Vue du journal des utilisations.
        │       ├── stats.php                 # Présentation des statistiques agrégées.
        │       └── settings.php              # Formulaire des réglages du programme.
        ├── public/
        │   ├── index.php                     # Garde d'accès pour les ressources publiques.
        │   └── class-floating-notice.php     # Notification flottante et gestion AJAX côté visiteur.
        └── assets/
            ├── css/
            │   ├── index.php                 # Garde d'accès.
            │   ├── admin.css                 # Styles de l'interface d'administration.
            │   └── floating-notice.css       # Styles de la notification front-end.
            └── js/
                ├── index.php                 # Garde d'accès.
                ├── admin.js                  # Interactions JS pour les écrans d'administration.
                ├── floating-notice.js        # Script de gestion de la notification flottante.
                └── referral-code.js          # Copie du code de parrainage depuis le front-end.
```
