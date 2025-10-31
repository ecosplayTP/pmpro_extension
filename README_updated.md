# pmpro_extension

template pour la création d'un plugin wordpress
A partir ce ce code, il va falloir créer un mini plugin avec interface admin (dans un sous-menu à l'intérieur de `<div class="wp-menu-name">Adhésions</div>`) pour gérer les parainages.
Le menu admin doit permettre de voir les codes actifs pour les utilisateurs avec compte niveau d'adhésion "premium".
Voir les codes qui ont été utilisés
Voir les crédits gagnés par des utilisateurs (afin de voir qui on doit rembourser)
Il faut créer une batadase qui contient toutes les informations utilisateurs centralisées.
Je veux un interface avec des statistiques sur les codes utilisés, les temporalité, les sommes à veser etc...
Il faudrait pouvoir afficher par dessus le site, il fenêtre flottante pour rappeler l'existance du système de parrainage et enregistrer en base si l'utilisateur à déjà vu ou non la notification. Il faut pouvoir dans l'interface admin, remettre à zéro cette information pour relancer une campagne de communication.
Il faut pouvoir paramétrer le niveau de remise pour le parrainé et le gain pour le parrain dans l'interface.
Il faut pouvoir remettre à jour manuellement le code de parrainage pour tous les utilisateurs ou pour un seul (histoire que les codes ne soit pas valable à vie pour question de sécurité)
Il faut un shortcode pour afficher pour les utilisateurs premium le nombre de points qu'ils ont gagné.
n'utilise que php, html et JS.

## Arborescence du plugin

```
wp-content/
└── plugins/
    └── ecosplay-referrals/
        ├── ecosplay-referrals.php     # Point d'entrée du plugin, charge les constantes, l'autoloader et les hooks d'activation.
        ├── includes/
        │   └── index.php               # Garde de sécurité pour empêcher l'accès direct au dossier des fonctionnalités partagées.
        ├── admin/
        │   └── index.php               # Garde de sécurité pour les composants de l'interface d'administration.
        ├── public/
        │   └── index.php               # Garde de sécurité pour les ressources front-office.
        └── assets/
            ├── css/
            │   └── index.php           # Garde de sécurité pour les feuilles de style du plugin.
            └── js/
                └── index.php           # Garde de sécurité pour les scripts JavaScript du plugin.
```

Chaque dossier est prêt à accueillir les classes et scripts dédiés (logique partagée, administration, affichage public, ressources statiques) en respectant l'autoloader déclaré dans `ecosplay-referrals.php`.
