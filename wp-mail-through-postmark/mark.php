<?php

declare( strict_types=1 );

namespace Ruigehond019\Mark;

defined( 'ABSPATH' ) || die();

class Mail {
	private string $api_key;
	private string $parameters;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * @param array $atts the `wp_mail()` arguments.
	 *
	 * @type string|string[] $to Array or comma-separated list of email addresses to send message.
	 * @type string $subject Email subject.
	 * @type string $message Message contents.
	 * @type string|string[] $headers Additional headers.
	 * @type string|string[] $attachments Paths to files to attach.
	 * @type string|string[] $embeds Paths to files to embed.
	 *
	 * Ruigehond specific, always present:
	 * @type string $from_email Verified sender email address
	 * @type string $from_name Verified sender name
	 *
	 * @return void
	 */
	public function prepare( array $atts ): void {
		$to          = $atts['to'] ?? array();
		$subject     = $atts['subject'] ?? 'Mail from WordPress site';
		$message     = $atts['message'] ?? '';
		$headers     = $atts['headers'] ?? array();
		$attachments = $atts['attachments'] ?? array();
		$embeds      = $atts['embeds'] ?? array();

		if ( ! is_array( $to ) ) {
			$to = explode( ',', $to );
		}

		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}

		if ( ! is_array( $embeds ) ) {
			$embeds = explode( "\n", str_replace( "\r\n", "\n", $embeds ) );
		}

		// Headers.
		$cc  = array();
		$bcc      = array();
		$reply_to = array();

		if ( empty( $headers ) ) {
			$headers = array();
		} else {
			if ( ! is_array( $headers ) ) {
				/*
				 * Explode the headers out, so this function can take
				 * both string headers and an array of headers.
				 */
				$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
			} else {
				$tempheaders = $headers;
			}
			$headers = array();

			// If it's actually got contents.
			if ( ! empty( $tempheaders ) ) {
				// Iterate through the raw headers.
				foreach ( (array) $tempheaders as $header ) {
					if ( ! str_contains( $header, ':' ) ) {
						if ( false !== stripos( $header, 'boundary=' ) ) {
							$parts    = preg_split( '/boundary=/i', trim( $header ) );
							$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
						}
						continue;
					}
					// Explode them out.
					list( $name, $content ) = explode( ':', trim( $header ), 2 );

					// Cleanup crew.
					$name    = trim( $name );
					$content = trim( $content );

					switch ( strtolower( $name ) ) {
						case 'from':
							// we use our own verified sender
							break;
						case 'content-type':
							if ( str_contains( $content, ';' ) ) {
								list( $type, $charset_content ) = explode( ';', $content );
								$content_type = trim( $type );
								if ( false !== stripos( $charset_content, 'charset=' ) ) {
									$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
								} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
									$boundary = trim( str_replace( array(
										'BOUNDARY=',
										'boundary=',
										'"'
									), '', $charset_content ) );
									$charset  = '';
									if ( preg_match( '~^multipart/(\S+)~', $content_type, $matches ) ) {
										$content_type = 'multipart/' . strtolower( $matches[1] ) . '; boundary="' . $boundary . '"';
									}
								}

								// Avoid setting an empty $content_type.
							} elseif ( '' !== trim( $content ) ) {
								$content_type = trim( $content );
							}
							break;
						case 'cc':
							$cc = array_merge( (array) $cc, explode( ',', $content ) );
							break;
						case 'bcc':
							$bcc = array_merge( (array) $bcc, explode( ',', $content ) );
							break;
						case 'reply-to': // <- Postmark has a separate ReplyTo field
							$reply_to = array_merge( (array) $reply_to, explode( ',', $content ) );
							break;
						default:
							// Add it to our grand headers array.
							$headers[ trim( $name ) ] = trim( $content );
							break;
					}
				}
			}
		}

		// If we don't have a Content-Type from the input headers.
		if ( ! isset( $content_type ) ) {
			$content_type = 'text/plain';
		}

		// https://postmarkapp.com/developer/api/email-api#send-a-single-email
		$post_data                = new \stdClass();
		$post_data->From          = $this->formatAddress( $atts['from_email'], $atts['from_name'] );
		$post_data->To            = $this->joinAddresses( $to );
		$post_data->Subject       = $subject;
		$post_data->MessageStream = 'outbound';

		if ( $cc = $this->joinAddresses( $cc ) ) {
			$post_data->Cc = $cc;
		}

		if ( $bcc = $this->joinAddresses( $bcc ) ) {
			$post_data->Bcc = $bcc;
		}

		if ( $reply_to = $this->joinAddresses( $reply_to ) ) {
			$post_data->ReplyTo = $reply_to;
		}

		if ( $headers ) {
			$post_data->Headers = array();
			foreach ( $headers as $name => $value ) {
				$post_data->Headers[] = (object) array( 'Name' => $name, 'Value' => $value );
			}
		}

		if ( 'text/html' === $content_type ) {
			$post_data->HtmlBody = $message;
		} else {
			$post_data->TextBody = $message;
		}

		// Postmark puts inline images in Attachments too, recognisable by their ContentID.
		$post_data->Attachments = array();
		$this->addAttachments( $post_data->Attachments, $attachments );
		$this->addImages( $post_data->Attachments, $embeds );
		if ( ! $post_data->Attachments ) {
			unset( $post_data->Attachments );
		}

		$this->parameters = json_encode( $post_data );
	}

	public function send(): bool {
		$ch = curl_init( 'https://api.postmarkapp.com/email' );
		if ( ! $ch ) {
			error_log( "WP mail through Postmark error: could not initialize cURL." );

			return false;
		}
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 3 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Accept: application/json',
			'Content-Type: application/json',
			'X-Postmark-Server-Token: ' . $this->api_key,
		) );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $this->parameters );

		$result = curl_exec( $ch );

		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_error  = curl_error( $ch );

		curl_close( $ch );

		$return_value = is_string( $result ) ? json_decode( $result ) : null;

		// Postmark answers 200 with ErrorCode 0 when the message is accepted.
		if ( 200 === $status_code && is_object( $return_value ) && 0 === ( $return_value->ErrorCode ?? null ) ) {
			return true;
		}

		if ( ! is_object( $return_value ) ) {
			$return_value = (object) array( 'return_value' => $result );
		}
		$return_value->status_code = $status_code;
		if ( '' !== $curl_error ) {
			$return_value->curl_error = $curl_error;
		}

		error_log( "WP mail through Postmark error result:\n" . var_export( $return_value, true ) );

		return false;
	}

	private function formatAddress( string $email, string $name ): string {
		$name = trim( str_replace( array( '"', "\r", "\n" ), '', $name ) );
		if ( '' === $name || $name === $email ) {
			return $email;
		}

		return "\"$name\" <$email>";
	}

	private function joinAddresses( array $addresses ): string {
		return implode( ',', array_filter( array_map( 'trim', $addresses ) ) );
	}

	private function addAttachments( array &$attachments, array $urls ): void {
		foreach ( $urls as $key => $url ) {
			$path = trim( (string) $url );
			if ( '' === $path ) {
				continue;
			}
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$attachments[] = (object) array(
					'Name'        => is_string( $key ) && '' !== $key ? $key : basename( $path ),
					'Content'     => base64_encode( file_get_contents( $path ) ),
					'ContentType' => mime_content_type( $path ) ?: 'application/octet-stream',
				);
			} else {
				error_log( "WP mail through Postmark warning: attachment file '$path' does not exist or is not readable, skipping." );
			}
		}
	}

	private function addImages( array &$attachments, array $embeds ): void {
		foreach ( $embeds as $key => $embed_path ) {
			$path = trim( (string) $embed_path );
			if ( '' === $path ) {
				continue;
			}
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$mime_type = (string) mime_content_type( $path );
				if ( false === str_starts_with( $mime_type, 'image/' ) ) {
					error_log( "WP mail through Postmark warning: embedded file '$path' is not an image (mime type: $mime_type), skipping." );
					continue;
				}
				$attachments[] = (object) array(
					'Name'        => basename( $path ),
					'Content'     => base64_encode( file_get_contents( $path ) ),
					'ContentType' => $mime_type,
					'ContentID'   => 'cid:' . $key, // <- the CID Content ID of the image!!
				);
			} else {
				error_log( "WP mail through Postmark warning: embedded image file '$path' does not exist or is not readable, skipping." );
			}
		}
	}
}
