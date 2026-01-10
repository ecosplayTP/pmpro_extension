# pmpro_extension

# plugin scope
Ce plugin a pour objectif d’étendre PaidMemberships Pro afin d’intégrer un système complet de parrainage utilisateur pour les membres ayant un niveau d’adhésion « premium ». Il permet de générer et attribuer automatiquement un code de parrainage unique pour chaque utilisateur éligible, et d’offrir une remise configurable au filleul lors de son inscription ainsi qu’un gain de points / crédit pour le parrain. Une interface d’administration dédiée (accessible dans le menu « Adhésions ») donne accès à la gestion des codes actifs, au suivi des codes utilisés, aux crédits accumulés, aux opérations de réinitialisation des codes ainsi qu’à des tableaux de statistiques (utilisation, temporalité, montants à reverser). Le plugin inclut également un système d’affichage d’une notification flottante sur le site, enregistrant si l’utilisateur l’a déjà vue, et permettant de réinitialiser cette information lors de campagnes de communication. Enfin, il met à disposition des shortcodes pour afficher le code de parrainage de l’utilisateur ainsi que le total de points gagnés.

# functionnalities details
- Le menu admin doit permettre de voir les codes actifs pour les utilisateurs avec compte niveau d'adhésion "premium". 
Voir les codes qui ont été utilisés
 -Voir les crédits gagnés par des utilisateurs (afin de voir qui on doit rembourser)
- Il faut créer une batadase qui contient toutes les informations utilisateurs centralisées.
- Je veux un interface avec des statistiques sur les codes utilisés, les temporalité, les sommes à veser etc...
- Il faudrait pouvoir afficher par dessus le site, il fenêtre flottante pour rappeler l'existance du système de parrainage et enregistrer en base si l'utilisateur à déjà vu ou non la notification. Il faut pouvoir dans l'interface admin, remettre à zéro cette information pour relancer une campagne de communication. 
- Il faut pouvoir paramétrer le niveau de remise pour le parrainé et le gain pour le parrain dans l'interface. 
- Il faut pouvoir remettre à jour manuellement le code de parrainage pour tous les utilisateurs ou pour un seul (histoire que les codes ne soit pas valable à vie pour question de sécurité)
- Il faut un shortcode pour afficher pour les utilisateurs premium le nombre de points qu'ils ont gagné.
- N'utilise que php, html et JS.
- La création du plugin d'accompagne de la mise à jour du document README_updated.md.

## Shortcodes disponibles

- `[ecos_referral_code button_text="Copier le code" copied_text="Code copié"]` affiche le code de parrainage du membre premium connecté avec un bouton de copie.
- `[ecos_referral_points decimals="0"]` affiche le total de points gagnés par le membre connecté disposant d’un niveau « premium ». L’attribut `decimals` est optionnel pour préciser le nombre de décimales.
- `[ecos_referral_link url="https://exemple.com" text="Partager mon lien" param="ref"]` génère un lien de parrainage contenant le code du membre premium connecté. Les attributs sont facultatifs : `url` définit la cible de base (par défaut la page d’adhésion), `text` le texte cliquable et `param` le nom du paramètre (par défaut `ref`).

# initial code template 
template pour la création d'un plugin wordpress
A partir ce ce code, il va falloir créer un mini plugin avec interface admin (dans un sous-menu à l'intérieur de <div class="wp-menu-name">Adhésions</div>) pour gérer les parainages. 
<?php
/**
 * Plugin Name: ECOSplay – Parrainage PMPro (10€ + 10 pts)
 * Description: Ajoute un champ "Code de parrainage" au checkout PMPro, applique -10€ si valide et crédite 10 points au parrain.
 * Version:     1.0.0
 * Author:      ECOSplay
 */

if ( ! defined('ABSPATH') ) exit;

class ECOS_PMPro_Referrals {
    const DISCOUNT_EUR = 10;      // Remise à l'inscription
    const POINTS_EARN  = 10;      // Points pour le parrain
    const CODE_META    = 'ecos_ref_code';
    const POINTS_META  = 'ecos_points';
    const FIELD_NAME   = 'ecos_referral_code'; // name du champ au checkout
    // Facultatif: limiter aux niveaux (IDs) -> laisser [] pour tous
    const LIMIT_LEVELS = [];

    public function __construct() {
        // Générer un code pour chaque nouvel utilisateur
        add_action('user_register', [$this, 'ensure_user_code'], 10, 1);

        // Champ “Code de parrainage” au checkout PMPro
        add_action('pmpro_checkout_boxes', [$this, 'render_checkout_field']);
        add_filter('pmpro_registration_checks', [$this, 'validate_referral_code']);

        // Appliquer la remise de 10€ si code valide
        add_filter('pmpro_checkout_level', [$this, 'apply_referral_discount']);

        // Après paiement: créditer le parrain + tracer
        add_action('pmpro_after_checkout', [$this, 'after_checkout_award_points'], 10, 2);

        // Pré-remplir depuis ?ref=CODE
        add_action('init', [$this, 'prefill_from_query']);

        // Infos dans le profil WP
        add_action('show_user_profile', [$this, 'profile_fields']);
        add_action('edit_user_profile',  [$this, 'profile_fields']);

        // Shortcodes
        add_shortcode('my_referral_code', [$this, 'sc_my_referral_code']);
        add_shortcode('my_referral_link', [$this, 'sc_my_referral_link']);
    }

    /** Génère/assure un code unique pour un user */
    public function ensure_user_code($user_id) {
        $code = get_user_meta($user_id, self::CODE_META, true);
        if ($code) return;

        $base = strtoupper( wp_generate_password(8, false, false) );
        // Optionnel: ajouter checksum léger
        $code = 'ECOS-' . $base;
        update_user_meta($user_id, self::CODE_META, $code);
    }

    /** Pré-remplir le champ depuis ?ref= */
    public function prefill_from_query() {
        if (!is_admin() && isset($_GET['ref'])) {
            $_SESSION['ecos_ref_qs'] = sanitize_text_field($_GET['ref']);
            if (!session_id()) @session_start();
        }
    }

    /** Affiche le champ “Code de parrainage” sur le checkout */
    public function render_checkout_field() {
        if ( function_exists('pmpro_getLevelAtCheckout') ) {
            $level = pmpro_getLevelAtCheckout();
        } else {
            global $pmpro_level; $level = $pmpro_level;
        }
        if (!$this->is_level_allowed($level)) return;

        if (!session_id()) @session_start();
        $prefill = '';
        if (!empty($_REQUEST[self::FIELD_NAME])) {
            $prefill = sanitize_text_field($_REQUEST[self::FIELD_NAME]);
        } elseif (!empty($_SESSION['ecos_ref_qs'])) {
            $prefill = sanitize_text_field($_SESSION['ecos_ref_qs']);
        }
        ?>
        <div id="ecos-referral" class="pmpro_checkout">
          <hr/>
          <h3><?php esc_html_e('Parrainage', 'ecosplay-referrals');?></h3>
          <div class="pmpro_checkout-fields">
            <div class="pmpro_checkout-field pmpro_checkout-field-referral">
              <label for="<?php echo esc_attr(self::FIELD_NAME); ?>">
                <?php esc_html_e('Code de parrainage (optionnel)', 'ecosplay-referrals'); ?>
              </label>
              <input type="text" name="<?php echo esc_attr(self::FIELD_NAME); ?>"
                     id="<?php echo esc_attr(self::FIELD_NAME); ?>"
                     value="<?php echo esc_attr($prefill); ?>"
                     placeholder="Ex: ECOS-AB12CD34"
                     class="input" />
              <p class="pmpro_asterisk">
                <?php esc_html_e('Si le code est valide, 10 € seront déduits et votre parrain gagnera 10 points.', 'ecosplay-referrals'); ?>
              </p>
            </div>
          </div>
        </div>
        <?php
    }

    /** Valide le code (sans forcer la saisie) */
    public function validate_referral_code($okay) {
        if (!$okay) return $okay; // ne pas masquer d'autres erreurs

        if (empty($_REQUEST[self::FIELD_NAME])) return $okay;

        $code = sanitize_text_field($_REQUEST[self::FIELD_NAME]);
        $referrer = $this->get_user_by_code($code);

        if (!$referrer) {
            return $this->fail_checkout(__('Code de parrainage invalide.', 'ecosplay-referrals'));
        }

        // Interdire auto-parrainage (comparaison email)
        $email = isset($_REQUEST['bemail']) ? sanitize_email($_REQUEST['bemail']) : ( isset($_REQUEST['username']) ? sanitize_email($_REQUEST['username']) : '' );
        if ($email && $referrer->user_email === $email) {
            return $this->fail_checkout(__('Vous ne pouvez pas utiliser votre propre code.', 'ecosplay-referrals'));
        }

        // Exiger un parrain actuellement membre (au moins un niveau actif)
        if (!function_exists('pmpro_hasMembershipLevel') || !pmpro_hasMembershipLevel(null, $referrer->ID)) {
            return $this->fail_checkout(__('Le code appartient à un compte non actif.', 'ecosplay-referrals'));
        }

        return $okay;
    }

    private function fail_checkout($msg) {
        global $pmpro_msg, $pmpro_msgt;
        $pmpro_msg  = $msg;
        $pmpro_msgt = 'pmpro_error';
        return false;
    }

    /** Applique -10€ sur le montant d’inscription (une seule fois) si code valide */
    public function apply_referral_discount($level) {
        if (!$this->is_level_allowed($level)) return $level;

        if (empty($_REQUEST[self::FIELD_NAME])) return $level;

        $code = sanitize_text_field($_REQUEST[self::FIELD_NAME]);
        $referrer = $this->get_user_by_code($code);
        if (!$referrer) return $level; // sécurité: ne rien modifier

        // Remise sur le paiement initial uniquement
        $initial = floatval($level->initial_payment);
        $initial = max(0, $initial - self::DISCOUNT_EUR);
        $level->initial_payment = $initial;

        // On n’altère PAS les prélèvements récurrents ($level->billing_amount)
        return $level;
    }

    /** Après paiement: créditer le parrain de 10 points + tracer le referrer sur la commande */
    public function after_checkout_award_points($user_id, $order = null) {
        if (empty($_REQUEST[self::FIELD_NAME])) return;

        $code = sanitize_text_field($_REQUEST[self::FIELD_NAME]);
        $referrer = $this->get_user_by_code($code);
        if (!$referrer) return;

        // Double sécurité: éviter auto-parrainage
        $new_user = get_user_by('ID', $user_id);
        if ($new_user && $referrer->user_email === $new_user->user_email) return;

        // Crédit points (user_meta basique)
        $points = (int) get_user_meta($referrer->ID, self::POINTS_META, true);
        $points += self::POINTS_EARN;
        update_user_meta($referrer->ID, self::POINTS_META, $points);

        // Intégration myCRED si présent
        if (function_exists('mycred_add')) {
            mycred_add(
                'ecos_referral',
                $referrer->ID,
                self::POINTS_EARN,
                'Parrainage: inscription de l’utilisateur #' . intval($user_id),
                $user_id,
                ['referrer' => $referrer->ID],
                'ecosplay'
            );
        }

        // Intégration GamiPress si présent
        if (function_exists('gamipress_award_points_to_user')) {
            gamipress_award_points_to_user($referrer->ID, self::POINTS_EARN, [
                'description' => 'Parrainage: inscription de l’utilisateur #' . intval($user_id),
            ]);
        }

        // Tracer sur la commande (si objet dispo)
        if ( $order && is_object($order) && method_exists($order, 'updateMeta') ) {
            $order->updateMeta('ecos_referrer_id', $referrer->ID);
            $order->updateMeta('ecos_referral_code', $code);
            $order->updateMeta('ecos_referral_reward', self::POINTS_EARN);
        }
    }

    /** Utilitaire: retrouver user par code */
    private function get_user_by_code($code) {
        $users = get_users([
            'meta_key'   => self::CODE_META,
            'meta_value' => $code,
            'number'     => 1,
            'fields'     => 'all',
        ]);
        return !empty($users) ? $users[0] : null;
    }

    /** Restreindre à certains niveaux si voulu */
    private function is_level_allowed($level) {
        if (empty(self::LIMIT_LEVELS)) return true;
        if (empty($level) || empty($level->id)) return false;
        return in_array((int)$level->id, array_map('intval', self::LIMIT_LEVELS), true);
    }

    /** Champs informatifs dans le profil WP */
    public function profile_fields($user) {
        $code   = get_user_meta($user->ID, self::CODE_META, true);
        $points = (int) get_user_meta($user->ID, self::POINTS_META, true);
        ?>
        <h2>Parrainage ECOSplay</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label>Code de parrainage</label></th>
                <td><input type="text" class="regular-text" value="<?php echo esc_attr($code); ?>" readonly /></td>
            </tr>
            <tr>
                <th><label>Points cumulés</label></th>
                <td><input type="text" class="regular-text" value="<?php echo esc_attr($points); ?>" readonly /></td>
            </tr>
        </table>
        <?php
    }

    /** [my_referral_code] */
    public function sc_my_referral_code() {
        if (!is_user_logged_in()) return '';
        $code = get_user_meta(get_current_user_id(), self::CODE_META, true);
        return esc_html($code ?: '');
    }

    /** [my_referral_link url="/inscription/"] */
    public function sc_my_referral_link($atts) {
        if (!is_user_logged_in()) return '';
        $a = shortcode_atts(['url' => wp_login_url()], $atts);
        $code = get_user_meta(get_current_user_id(), self::CODE_META, true);
        if (!$code) return '';
        $sep = (strpos($a['url'], '?') === false) ? '?' : '&';
        return esc_url($a['url'] . $sep . 'ref=' . rawurlencode($code));
    }
}

new ECOS_PMPro_Referrals();
