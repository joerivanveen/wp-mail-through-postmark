<?php

declare( strict_types=1 );

namespace Ruigehond019\Crypt;

defined( 'ABSPATH' ) || die();

function getKey(): string {
	if ( ! defined( 'NONCE_KEY' ) || ! is_string( NONCE_KEY ) ) {
		throw new \RuntimeException( 'NONCE_KEY is not defined.' );
	}
	if ( strlen( NONCE_KEY ) < 32 || 'put your unique phrase here' === NONCE_KEY ) {
		throw new \RuntimeException( 'NONCE_KEY is too weak, set a long unique value in wp-config.php.' );
	}

	// A key for this plugin only (32 raw bytes), so it is not the same secret WordPress uses for nonces.
	return hash_hmac( 'sha256', 'ruigehond019 postmark server api token', NONCE_KEY, true );
}

/**
 * Encrypt a message
 *
 * @param string $message - message to encrypt
 *
 * @return string
 * @throws \RangeException|\SodiumException|\Random\RandomException
 */
function encrypt(string $message): string
{
	$key = getKey();

	if (mb_strlen($key, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
		throw new \RangeException('Key is not the correct size (must be 32 bytes).');
	}
	$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

	$cipher = base64_encode(
		$nonce.
		sodium_crypto_secretbox(
			$message,
			$nonce,
			$key
		)
	);
	sodium_memzero($message);
	sodium_memzero($key);
	return $cipher;
}

/**
 * Decrypt a message
 *
 * @param string $encrypted - message encrypted with encrypt()
 * @return string
 * @throws \Exception
 */
function decrypt(string $encrypted): string
{
	$key = getKey();

	$decoded = base64_decode($encrypted);
	$nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
	$ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

	$plain = sodium_crypto_secretbox_open(
		$ciphertext,
		$nonce,
		$key
	);
	if (!is_string($plain)) {
		throw new \Exception('Invalid MAC');
	}
	sodium_memzero($ciphertext);
	sodium_memzero($key);
	return $plain;
}

