<?php
/**
 * Helpers providing encryption utilities for Tremendous secrets.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-tremendous-secrets.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles encryption and decryption of the Tremendous secret key.
 */
class Ecosplay_Referrals_Tremendous_Secrets {
    /**
     * Cipher name used for encrypting the secret.
     *
     * @var string
     */
    const CIPHER = 'aes-256-cbc';

    /**
     * Encrypts a plaintext Tremendous secret into a base64 payload.
     *
     * @param string $plaintext Raw secret.
     *
     * @return string
     */
    public static function encrypt( $plaintext ) {
        $secret = trim( (string) $plaintext );

        if ( '' === $secret ) {
            return '';
        }

        $key = self::derive_key();

        if ( '' === $key ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( self::CIPHER );

        if ( ! $iv_length ) {
            return '';
        }

        try {
            $iv = random_bytes( $iv_length );
        } catch ( \Exception $exception ) {
            return '';
        }

        $ciphertext = openssl_encrypt( $secret, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $ciphertext ) {
            return '';
        }

        return base64_encode( $iv . $ciphertext );
    }

    /**
     * Decrypts an encrypted Tremendous secret payload.
     *
     * @param string $encoded Stored base64 string.
     *
     * @return string
     */
    public static function decrypt( $encoded ) {
        $payload = (string) $encoded;

        if ( '' === $payload ) {
            return '';
        }

        $key = self::derive_key();

        if ( '' === $key ) {
            return '';
        }

        $raw = base64_decode( $payload, true );

        if ( false === $raw ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( self::CIPHER );

        if ( ! $iv_length || strlen( $raw ) <= $iv_length ) {
            return '';
        }

        $iv         = substr( $raw, 0, $iv_length );
        $ciphertext = substr( $raw, $iv_length );

        $secret = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $secret ) {
            return '';
        }

        return $secret;
    }

    /**
     * Generates a symmetric key derived from WordPress salts.
     *
     * @return string
     */
    protected static function derive_key() {
        $salt = wp_salt( 'ecosplay_referrals_tremendous' );

        if ( '' === $salt ) {
            return '';
        }

        return hash( 'sha256', $salt, true );
    }
}

/**
 * Returns the decrypted Tremendous secret stored in plugin options.
 *
 * @return string
 */
function ecosplay_referrals_get_tremendous_secret() {
    static $secret = null;

    if ( null !== $secret ) {
        return $secret;
    }

    $options = get_option( 'ecosplay_referrals_options', array() );

    if ( is_array( $options ) && ! empty( $options['tremendous_secret_key'] ) ) {
        $decoded = Ecosplay_Referrals_Tremendous_Secrets::decrypt( $options['tremendous_secret_key'] );

        if ( '' !== $decoded ) {
            $secret = $decoded;
            return $secret;
        }
    }

    $secret = '';

    return $secret;
}
