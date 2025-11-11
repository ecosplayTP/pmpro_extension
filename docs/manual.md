<!--
/**
 * File: docs/manual.md
 * Description: Manuel d'utilisation du plugin ECOSplay Referrals pour administrateurs et membres.
 */
-->

# Manuel d'utilisation – ECOSplay Referrals

## Vue d'ensemble

Le plugin **ECOSplay Referrals** étend Paid Memberships Pro afin d'offrir un programme de parrainage complet aux membres disposant d'un niveau éligible. Il génère des codes uniques, applique automatiquement la remise aux filleuls, crédite les parrains, enregistre l'historique d'utilisation et expose une interface d'administration centralisée avec tableaux de bord, ainsi qu'un bandeau flottant sur le site public pour promouvoir l'offre.【F:wp-content/plugins/ecosplay-referrals/ecosplay-referrals.php†L1-L103】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L17-L144】

Les sections suivantes détaillent l'ensemble des actions disponibles pour l'administrateur puis pour les utilisateurs finaux.

---

## Section administrateur

### Accès au menu Parrainages

Après activation du plugin, un sous-menu **Parrainages** apparaît dans le menu Paid Memberships Pro (`Adhésions`). Ce sous-menu propose par défaut les onglets **Codes actifs**, **Historique**, **Statistiques** et **Réglages**, puis ajoute **Paiements** et/ou **Logs** supplémentaires dès que Stripe ou Tremendous sont activés. Chaque onglet correspond à un contrôleur dédié et charge les styles/scripts d'administration fournis par le plugin.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-menu.php†L49-L216】

### Gestion des codes (onglet « Codes actifs »)

* **Visualisation** : la liste affiche chaque membre éligible avec son code, le total de crédits cumulés et le statut actif/inactif. Chaque nom redirige vers la fiche utilisateur WordPress pour un suivi détaillé.【F:wp-content/plugins/ecosplay-referrals/admin/views/codes.php†L23-L71】
* **Régénération globale** : le bouton « Régénérer tous les codes » génère de nouveaux codes pour l'ensemble des membres autorisés. L'opération est limitée aux administrateurs (`manage_options`).【F:wp-content/plugins/ecosplay-referrals/admin/views/codes.php†L13-L19】【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-codes-page.php†L52-L82】
* **Régénération individuelle** : chaque ligne propose un bouton « Régénérer » qui remplace le code du membre sélectionné, pratique lors d'une compromission supposée.【F:wp-content/plugins/ecosplay-referrals/admin/views/codes.php†L45-L60】【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-codes-page.php†L70-L83】
* **Réinitialisation des notifications** : deux formulaires permettent soit de réinitialiser le statut de lecture du bandeau pour tous les membres, soit uniquement pour l'utilisateur ciblé. Cela force l'affichage du bandeau lors d'une nouvelle campagne.【F:wp-content/plugins/ecosplay-referrals/admin/views/codes.php†L15-L22】【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-codes-page.php†L84-L93】

### Historique d'utilisation (onglet « Historique »)

* **Filtres** : utilisez les champs « ID parrainage » et « Nombre de lignes » pour affiner la consultation. Les autres paramètres de requête sont conservés automatiquement pour faciliter la navigation.【F:wp-content/plugins/ecosplay-referrals/admin/views/usage.php†L13-L30】
* **Tableau de suivi** : chaque entrée indique la date d'utilisation, le code concerné, la commande associée, l'utilisateur ayant utilisé le code, ainsi que les montants de remise et de récompense accordés. Les liens vers les fiches utilisateurs et le formatage monétaire sont fournis automatiquement.【F:wp-content/plugins/ecosplay-referrals/admin/views/usage.php†L31-L66】
* **Population des données** : les informations proviennent du journal des utilisations, alimenté lors de chaque commande validée comportant un code valide, avec enregistrement des montants et de l'utilisateur.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-store.php†L81-L145】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L206-L247】

### Statistiques globales (onglet « Statistiques »)

* **Synthèse** : les cartes en tête de page récapitulent les récompenses dues, le total des remises et la période d'analyse (par semaine ou par mois).【F:wp-content/plugins/ecosplay-referrals/admin/views/stats.php†L13-L27】
* **Tableau des périodes** : affiche pour chaque période le nombre de conversions, les remises totales et les récompenses totales. Les montants sont calculés à partir de l'agrégation des entrées d'utilisation.【F:wp-content/plugins/ecosplay-referrals/admin/views/stats.php†L28-L45】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-store.php†L146-L214】
* **Contrôles** : les paramètres `period` (week|month) et `points` (nombre de périodes) sont pris en compte via les variables GET. Ajustez l'URL (ex. `?tab=stats&period=week&points=12`) pour étendre la période analysée.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-stats-page.php†L47-L91】

### Réglages (onglet « Réglages »)

* **Montants de parrainage** : définissez la remise appliquée à l'inscription (`discount_amount`, en €) et le gain de crédit pour le parrain (`reward_amount`). Ces valeurs alimentent automatiquement la logique front-office via des filtres WordPress.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php†L29-L78】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L167-L213】
* **Niveaux éligibles** : renseignez les identifiants ou slugs de niveaux Paid Memberships Pro autorisés à parrainer. Seuls les utilisateurs appartenant à cette liste recevront un code actif.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php†L79-L142】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L30-L113】
* **Message de notification** : personnalisez le texte affiché dans la notification flottante. Ce message est affiché sur le front-end et peut inclure plusieurs lignes (les retours à la ligne sont convertis automatiquement).【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php†L83-L142】【F:wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php†L61-L103】
* **Sauvegarde** : cliquez sur « Enregistrer les modifications » pour valider. Un message de confirmation est ajouté via les `settings_errors`. Les valeurs sont validées (montants numériques >= 0, niveaux nettoyés) avant enregistrement.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php†L115-L178】

### Intégration Stripe (réglages, onglets « Paiements » et « Logs Stripe »)

* **Activation & clés** : cochez « Activer l’intégration Stripe », renseignez la clé secrète Connect et le seuil d’alerte de trésorerie. Les secrets sont chiffrés avant stockage et le seuil permet d’afficher des avertissements lors des transferts.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php†L156-L260】
* **Accès aux onglets** : une fois Stripe actif, deux onglets supplémentaires apparaissent : **Paiements** (pilotage des virements) et **Logs Stripe** (journal des webhooks). Ils sont masqués automatiquement quand l’intégration est coupée.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-menu.php†L188-L216】
* **Gestion des comptes et virements** : l’onglet **Paiements** liste chaque parrain avec son compte Connect, les montants gagnés/versés et les actions clés : renvoi de lien d’onboarding, accès au dashboard Express, création de transfert (Stripe `POST /v1/transfers`), marquage manuel et annulation de transferts en attente. Chaque action est protégée par un nonce et délègue la logique au service Stripe.【F:wp-content/plugins/ecosplay-referrals/admin/views/payouts.php†L14-L123】【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-payouts-page.php†L66-L201】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L161-L420】
* **Suivi des incidents** : l’onglet **Logs Stripe** consomme l’historique des webhooks (ex. `account.updated`, `transfer.*`, `payout.*`) pour diagnostiquer les blocages KYC ou erreurs de virement. Des filtres par type/date sont disponibles depuis la barre d’outils.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-stripe-logs-page.php†L63-L109】
* **Bonnes pratiques Stripe** : vérifiez régulièrement la balance plateforme (`GET /v1/balance`), programmez des top-ups si nécessaire et proposez des login links pour laisser les parrains mettre à jour leurs informations Express.【F:Stripe_documentation.txt†L1-L110】

### Intégration Tremendous (réglages, onglet « Logs Tremendous »)

* **Activation & campagne** : activez Tremendous, saisissez la clé API (préfixe `TEST_` en sandbox ou `PROD_` en production), l’identifiant de campagne et l’environnement ciblé. Le client considère les deux environnements officiels et vérifie qu’une campagne est définie avant de répondre aux requêtes.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php†L187-L260】【F:wp-content/plugins/ecosplay-referrals/includes/class-tremendous-client.php†L39-L149】【F:tremendous_documentation.txt†L1-L60】
* **Surveillance du solde** : le client expose une récupération du `funding_source` pour suivre le disponible Tremendous et afficher des alertes dans l’interface membre lorsque le solde devient insuffisant.【F:wp-content/plugins/ecosplay-referrals/includes/class-tremendous-client.php†L181-L220】【F:tremendous_documentation.txt†L61-L134】
* **Journal des webhooks** : l’onglet **Logs Tremendous** centralise les événements `CONNECTED_ORGANIZATIONS.*`, `ORDERS.*` et `TOPUPS.*`, avec des notes explicatives pour aider l’équipe support à débloquer les cas (validation, refus, rechargement). Des filtres par type, période et statut sont disponibles.【F:wp-content/plugins/ecosplay-referrals/admin/class-admin-tremendous-logs-page.php†L80-L161】

### Notification flottante

* **Activation** : la bannière s'affiche tant qu'elle n'a pas été masquée par l'utilisateur ou qu'un administrateur a réinitialisé les indicateurs côté admin. Elle respecte un filtre (`ecosplay_referrals_should_display_notice`) pour ajuster dynamiquement l'affichage.【F:wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php†L20-L110】
* **Cookies et suivi** : pour les membres connectés, la lecture est tracée en base afin de survivre aux changements d'appareil. Pour les visiteurs, un cookie versionné (`ecos_referrals_notice_seen`) retient l'information. Une hausse de version est déclenchée lors d'une réinitialisation globale, ce qui force la réapparition de la bannière sur tous les navigateurs.【F:wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php†L111-L176】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L248-L331】
* **Réinitialisation ciblée** : depuis l'onglet « Codes actifs », utilisez le bouton « Réinitialiser la notif. » sur une ligne pour forcer le ré-affichage à un membre spécifique (utile pour relancer une campagne).【F:wp-content/plugins/ecosplay-referrals/admin/views/codes.php†L52-L64】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L248-L284】

---

## Section utilisateurs (membres)

### Éligibilité

Seuls les membres appartenant aux niveaux déclarés comme « autorisés » dans les réglages bénéficient d'un code. Le plugin vérifie systématiquement l'éligibilité avant de créer ou d'exposer un code, y compris lors du rendu des shortcodes.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L30-L113】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-shortcodes.php†L48-L97】

### Obtention du code personnel

* **Création automatique** : lorsqu'un utilisateur devient éligible (nouvelle inscription ou changement de niveau), un code unique est généré automatiquement et stocké de manière sécurisée.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L41-L90】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-store.php†L219-L260】
* **Affichage via shortcode** : ajoutez `[ecos_referral_link]` à une page ou un tableau de bord membre pour afficher un lien de partage prérempli (`ref=`). Optionnellement, ajustez l'URL de destination, le libellé ou le nom du paramètre (`param`).【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-shortcodes.php†L60-L105】
* **Lien direct** : le lien généré inclut le code comme paramètre d'URL et peut être partagé par e-mail ou réseaux sociaux.

### Consultation des points gagnés

* **Shortcode points** : insérez `[ecos_referral_points decimals="2"]` pour montrer le cumul de récompenses, formaté selon la locale et le nombre de décimales désiré (0 par défaut).【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-shortcodes.php†L28-L59】
* **Mise à jour en temps réel** : chaque inscription validée via un code crédite automatiquement le compte du parrain avec le montant défini par l'administrateur.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L206-L247】

### Portefeuille de récompenses (shortcode `[ecos_referral_wallet]`)

* **Accès** : ajoutez le shortcode sur l’espace membre pour proposer un tableau de bord des gains. Sans connexion ou abonnement éligible, un message explicite est affiché et aucune donnée n’est révélée.【F:wp-content/plugins/ecosplay-referrals/public/class-member-wallet.php†L32-L121】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L1240-L1301】
* **Association Tremendous** : si l’intégration est active, le membre voit son statut KYC (en attente, validé, blocage) ainsi que le solde disponible côté Tremendous. Les boutons « Associer » et « Rafraîchir » déclenchent des requêtes AJAX sécurisées pour créer ou synchroniser l’organisation connectée via l’API Tremendous.【F:wp-content/plugins/ecosplay-referrals/public/class-member-wallet.php†L96-L199】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L1304-L1415】【F:wp-content/plugins/ecosplay-referrals/includes/class-tremendous-client.php†L152-L220】
* **Demande de récompense** : le formulaire intégré valide le montant demandé, vérifie les soldes local/Tremendous et crée une commande (`orders.create`) avec les métadonnées utiles. Un message de confirmation ou d’erreur s’affiche immédiatement grâce aux réponses JSON.【F:wp-content/plugins/ecosplay-referrals/public/class-member-wallet.php†L115-L151】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L1418-L1519】【F:tremendous_documentation.txt†L1-L60】
* **Historique & statut** : le bloc « Historique des virements » liste les transferts Stripe ou demandes Tremendous avec date, montant, statut (en cours/réussi/échoué) et éventuel message d’échec. Les lignes reflètent les retours des API et des webhooks enregistrés par le service.【F:wp-content/plugins/ecosplay-referrals/public/class-member-wallet.php†L124-L151】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L320-L420】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L1514-L1528】

### Utilisation du code lors d'une inscription

* **Saisie au checkout** : la page d'inscription Paid Memberships Pro affiche un champ « Code de parrainage (optionnel) ». Le code peut être prérempli via un lien contenant `?ref=CODE` ou saisi manuellement.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L58-L184】
* **Validation** : le système vérifie le nonce de sécurité, l'existence du code, l'absence d'auto-parrainage et l'activité du parrain avant d'accorder la remise. Toute erreur annule la validation et affiche un message explicite.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L91-L169】
* **Remise appliquée** : le montant défini dans les réglages est automatiquement déduit du paiement initial de l'abonné. Les paiements récurrents restent inchangés.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L170-L205】

### Bannière flottante

* **Affichage** : une notification flottante rappelle l'existence du programme. Elle s'affiche tant qu'elle n'a pas été fermée ou que l'administrateur n'a pas indiqué qu'une nouvelle campagne est en cours.【F:wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php†L20-L147】
* **Fermeture** : cliquer sur la croix enregistre la fermeture (en base si vous êtes connecté, ou via cookie sinon). La bannière ne réapparaîtra que si l'administrateur la réactive ou si la version change.【F:wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php†L104-L176】

### Partage d'un lien de parrainage

* **Lien personnalisé** : utilisez le shortcode `[ecos_referral_link url="https://monsite.com/offre" text="Partager mon lien"]` pour afficher un bouton ou un lien cliquable prêt à être partagé avec vos filleuls.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-shortcodes.php†L60-L105】
* **Préremplissage automatique** : lorsqu'un visiteur arrive via ce lien, son champ « Code de parrainage » est rempli automatiquement au checkout grâce à un cookie temporaire, améliorant la conversion.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L117-L145】

---

## Notes techniques

* **Filtres disponibles** : `ecosplay_referrals_discount_amount`, `ecosplay_referrals_reward_amount`, `ecosplay_referrals_allowed_levels`, `ecosplay_referrals_notice_message`, et `ecosplay_referrals_should_display_notice` permettent d'ajuster dynamiquement la configuration via code personnalisé.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L160-L331】【F:wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php†L83-L110】
* **Base de données** : trois tables personnalisées stockent les codes, l'historique d'utilisation et l'état de la notification flottante. Elles sont créées et mises à jour lors de l'activation du plugin.【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-store.php†L17-L144】
* **Sécurité** : chaque action d'administration est protégée par un nonce, et les remises sont appliquées uniquement si le code est actif, valide et associé à un parrain possédant un abonnement actif.【F:wp-content/plugins/ecosplay-referrals/admin/views/codes.php†L13-L64】【F:wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php†L91-L205】

