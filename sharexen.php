<?php

// ShareXen - Another ShareX Custom Uploader PHP Script


/**************************\
*    USER CONFIGURATION    *
* PLEASE READ THE COMMENTS *
\**************************/

/* MANDATORY CONSTANTS BELOW THIS LINE */

// List of ShareXen users
// Format: 'username' => 'token'
// Username can be any string you want, but
// keep in mind users can see their own names
// Never share a token with anyone else than the
// intended recipient, this can be very dangerous
// Set tokens to very long and random strings of
// various characters nobody can ever guess
// Random generator: https://bfnt.io/pwgen
define('USERS', [
	'Mario' => 'change-me',
	'Luigi' => 'change-me',
]);

// Security keys salt - NEVER SHARE THIS
// Used to generate and compute security keys
// Changing this will render all previously generated
// deletion URLs invalid without any exception
// Keep empty to disable this feature, only admins will
// then be able to delete files without security keys
// Mandatory for having deletion URLs, set this to
// a very long and random string of various characters
// Random generator: https://bfnt.io/pwgen
define('SALT', '');

// List of allowed image extensions
// Only put image extensions here unless
// you edit the MIME_TYPE_REGEX option as well,
// which is very discouraged for security reasons
define('EXTS', ['png', 'jpg', 'jpeg', 'gif', 'webm', 'mp4']);


/* OPTIONAL CONSTANTS BELOW THIS LINE */

// Amount of characters used in a
// randomly generated filename
define('NAME_LENGTH', 7);

// Allow all users to upload / rename files
// with custom names instead of random ones
// Random names are still used if the
// filename parameter is unspecified
define('ALLOW_CUSTOM_NAMES', false);

// Admin users can rename / delete all files
// and upload with custom filenames independently
// of the above ALLOW_CUSTOM_NAMES parameter
// This is a list of usernames from the above
// USERS parameter, trusted with great powers
// Example: ['Mario', 'Toad'] (sorry Luigi)
define('ADMINS', []);

// Log requests to Discord using a webhook
// If you do not know what it is about, please ignore
// It is not recommended to set this if your API is heavily used
// By security, make sure the webhook outputs in a channel only you can see
// https://support.discordapp.com/hc/en-us/articles/228383668-Intro-to-Webhooks
define('DISCORD_WEBHOOK_URL', '');

// If the Discord webhook above is enabled,
// set this to false to stop logging bad requests
define('DISCORD_LOG_ERRORS', true);

// If the Discord webhook above is enabled,
// set this to false to embed logged image links
define('DISCORD_PREVENT_EMBED', true);


/* DANGEROUS CONSTANTS BELOW THIS LINE */

/***************************************\
* CHANGE THEM AT YOUR OWN RISK AND ONLY *
* IF YOU REALLY KNOW WHAT YOU ARE DOING *
\***************************************/

// Characters used to randomly generate the filename
// By security and to avoid breaking this application,
// do not use the following characters: / \ . : # ? &
// This isn't a comprehensive list of dangerous characters
define('KEYSPACE', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');

// Characters listed here will be allowed within
// custom filenames, but won't be used to generate
// random ones (which is what the KEYSPACE is for)
// It has the same limitations than the KEYSPACE
define('ALLOWED_CHARACTERS', '-_');

// Allow admin users to use custom filenames
// containing any character, thus ignoring the
// above keyspace entirely, which can be a huge
// security issue (e.g. path traversal)
// File extensions are still checked
define('ADMIN_IGNORE_KEYSPACE', false);

// This regular expression is used to
// enforce the mime type of uploaded files.
define('MIME_TYPE_REGEX', '/^(image|video)\//');

/*****************************\
*  END OF USER CONFIGURATION  *
* DO NOT TOUCH THE CODE BELOW *
\*****************************/


define('VERSION', '2.0.0');
define('SOURCE', 'https://github.com/Xenthys/ShareXen');

$data = [
	'api_version' => VERSION,
	'api_source' => SOURCE
];

if (version_compare(PHP_VERSION, '7.0.0', '<'))
{
	http_response_code(500);

	header('Content-Type: application/json; charset=utf-8');

	error_log('ShareXen v'.VERSION.': you need to use at least PHP 7.0'.
		' in order to run this script. You are running PHP '.PHP_VERSION);

	$data['http_code'] = 500;
	$data['status'] = 'error';
	$data['error'] = 'outdated_php_version';
	$data['debug'] = PHP_VERSION.' < 7.0.0';

	die(json_encode($data));
}

$endpoints = [
	'upload' => "\u{1F517}",
	'delete' => "\u{1F5D1}",
	'rename' => "\u{1F4DD}",
	'info' => "\u{2139}"
];

$keys = array_keys($endpoints);

function get_parameter($field)
{
	if (isset($_GET[$field]))
	{
		$result = $_GET[$field];

		if ($result)
		{
			return $result;
		}
	}

	if (isset($_POST[$field]))
	{
		return $_POST[$field];
	}
}

$endpoint = get_parameter('endpoint');
$data['endpoint'] = strval($endpoint) ?: 'unknown';

function perform_auth(&$data)
{
	if (!isset($_POST['token']))
	{
		return;
	}

	$token = $_POST['token'];

	if (!$token || $token === 'change-me')
	{
		error_die($data, 403, 'invalid_credentials');
	}

	foreach (USERS as $u => $t) {
		if ($t === $token)
		{
			$data['username'] = $u;
			break;
		}
	}

	if (!isset($data['username']))
	{
		error_die($data, 403, 'invalid_credentials');
	}
}
perform_auth($data);

function send_to_discord($msg)
{
	if (!defined('DISCORD_WEBHOOK_URL') || !DISCORD_WEBHOOK_URL)
	{
		return false;
	}

	if (!function_exists('curl_init'))
	{
		return false;
	}

	$headers = [
		'Content-Type: application/json',
		'User-Agent: ShareXen/'.VERSION.' (+'.SOURCE.')'
	];

	$c['content'] = '`['.date('H:i:s').']` '.$msg;

	$ch = curl_init();

	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_URL, DISCORD_WEBHOOK_URL);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($c));

	$r = json_decode(curl_exec($ch), true);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	curl_close($ch);

	if ($code !== 204)
	{
		error_log('ShareXen Webhook Error: '.$r['message']);
		return false;
	}

	return true;
}

function log_request(&$data)
{
	global $endpoints;

	$user = NULL;

	if (isset($data['username']))
	{
		$user = $data['username'];
	}

	$msg = NULL;

	if ($user)
	{
		$msg .= 'Authenticated user '.$user.' ';
	}
	else
	{
		$msg .= 'Unauthenticated user ';
	}

	$endpoint = $data['endpoint'];
	$status = $data['error'] ?: $data['status'];
	$msg .= 'got a '.$data['http_code'].' ('.$status.') reponse '.
		'code, after calling the "'.$endpoint.'" endpoint.';

	$discord_logging = true;
	$discord_header = "\u{2705}";

	if (isset($endpoints[$endpoint]))
	{
		$discord_header = $endpoints[$endpoint] ?: $discord_header;
	}

	if ($status !== 'success')
	{
		if (defined('DISCORD_LOG_ERRORS') && DISCORD_LOG_ERRORS)
		{
			$discord_header = "\u{26A0}";
		}
		else
		{
			$discord_logging = false;
		}
	}

	$url = isset($data['url']) ? $data['url'] : 0;

	if ($url)
	{
		$msg .= ' File URL: '.$url;
		if (isset($data['old_name']))
		{
			$msg .= ' (old name: '.$data['old_name'].')';
		}
	}
	elseif (isset($data['filename']))
	{
		$msg .= ' Target file: '.$data['filename'];
	}

	error_log('ShareXen v'.VERSION.': '.$msg);

	if ($discord_logging)
	{
		if (defined('DISCORD_PREVENT_EMBED') &&
			DISCORD_PREVENT_EMBED && $url)
		{
			$msg = str_replace($url, '<'.$url.'>', $msg);
		}

		send_to_discord($discord_header.' '.$msg);
	}
}

function end_request(&$data, $code = 200, $status = 'success')
{
	$data['http_code'] = $code;
	$data['status'] = $status;

	$data['execution_time'] = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];

	ob_start();

	http_response_code($code);

	header('Content-Type: application/json; charset=utf-8');
	header('Content-Encoding: none');

	echo(json_encode($data));

	header('Content-Length: '.ob_get_length());
	header('Connection: close');

	ob_end_flush();
	ob_flush();
	flush();

	log_request($data);

	die();
}

function error_die(&$data, $code, $reason = 'unknown_error', $debug = '')
{
	$data['error'] = $reason;

	if ($debug)
	{
		$data['debug'] = $debug;
	}

	end_request($data, $code, 'error');
}

function retrieve_key($name)
{
	if (!defined('SALT'))
	{
		return false;
	}

	$filehash = hash_file('sha256', $name);
	return hash('sha256', SALT.$filehash.$name);
}

function enforce_auth(&$data)
{
	if (!isset($data['username']))
	{
		error_die($data, 401, 'unauthenticated_request');
	}
}

function user_is_admin(&$data)
{
	if (!isset($data['username']))
	{
		return false;
	}

	if (!defined('ADMINS'))
	{
		define('ADMINS', []);
	}

	$user = $data['username'];
	$admin = array_search($user, ADMINS);

	return ($admin !== false);
}

if (!defined('KEYSPACE'))
{
	define('KEYSPACE', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
}

function random_str($length = NAME_LENGTH, $keyspace = KEYSPACE)
{
	$pieces = [];
	$max = mb_strlen($keyspace, '8bit') - 1;

	for ($i = 0; $i < $length; ++$i) {
		$pieces []= $keyspace[random_int(0, $max)];
	}

	return implode('', $pieces);
}

function generate_all_urls(&$data, $deletion = true)
{
	$protocol = get_parameter('protocol');

	if (!$protocol)
	{
		$https = $_SERVER['HTTPS'];
		$protocol = 'http'.($https?'s':'');
	}

	$protocol = $protocol.'://';

	$domain = get_parameter('domain');
	$host = $_SERVER['HTTP_HOST'];
	$domain = $domain ?: $host;

	$script = $_SERVER['SCRIPT_NAME'];
	$sub = rtrim(dirname($script), '/').'/';

	$name = $data['filename'];

	$data['url'] = $protocol.$domain.$sub.$name;

	if (!$deletion)
	{
		return;
	}

	$key = retrieve_key($name);

	if ($key)
	{
		$data['key'] = $key;

		$data['deletion_url'] = $protocol.$host.
			$_SERVER['REQUEST_URI'].'?endpoint=delete'.
			'&key='.$key.'&filename='.$name;
	}
}

if (!defined('ALLOWED_CHARACTERS'))
{
	define('ALLOWED_CHARACTERS', '');
}

function check_filename(&$data, $name)
{
	if (!$name)
	{
		return false;
	}

	$name = strval($name);

	$chars = preg_quote(KEYSPACE.ALLOWED_CHARACTERS, '/');
	$regex = '/^['.$chars.']+\.('.implode('|', EXTS).')$/';

	if (defined('ADMIN_IGNORE_KEYSPACE') &&
		ADMIN_IGNORE_KEYSPACE && user_is_admin($data))
	{
		$regex = '/^.+\.('.implode('|', EXTS).')$/';
	}

	if (!preg_match($regex, $name))
	{
		return false;
	}

	return true;
}

function get_custom_filename(&$data, $check = true, $field = 'filename')
{
	if ($check && !(defined('ALLOW_CUSTOM_NAMES') &&
		ALLOW_CUSTOM_NAMES) && !user_is_admin($data))
	{
		return false;
	}

	$filename = get_parameter($field);

	if (check_filename($data, $filename))
	{
		if ($check && file_exists($filename))
		{
			error_die($data, 403, 'file_already_exists');
		}

		return $filename;
	}
	elseif (isset($filename))
	{
		error_die($data, 403, 'forbidden_filename');
	}

	return false;
}

function ensure_file_exists(&$data, $name, $field = 'filename')
{
	if (!$name)
	{
		error_die($data, 400, 'missing_filename');
	}

	$data[$field] = $name;

	if (!file_exists($name))
	{
		error_die($data, 404, 'file_not_found');
	}
}

function ensure_file_access(&$data, $name, $restricted = true)
{
	$check_hash = !$restricted;

	if ($restricted) {
		$check_hash = defined('ALLOW_CUSTOM_NAMES') && ALLOW_CUSTOM_NAMES;
	}

	if (!file_exists($name) && $check_hash)
	{
		return;
	}

	if (user_is_admin($data))
	{
		$data['method'] = 'admin_user';
	}
	elseif ($check_hash)
	{
		$key = get_parameter('key');

		if (isset($key))
		{
			$real_key = retrieve_key($name);

			if (!$real_key || $key !== $real_key)
			{
				error_die($data, 403, 'invalid_key');
			}

			$data['method'] = 'key';
		}
	}

	if (!isset($data['method']))
	{
		error_die($data, 403, 'missing_permissions');
	}
}

if (!defined('MIME_TYPE_REGEX'))
{
	define('MIME_TYPE_REGEX', '/^(image|video)\//');
}

function upload_endpoint(&$data)
{
	enforce_auth($data);

	$file = $_FILES['image'];

	if (!isset($file))
	{
		error_die($data, 400, 'missing_file');
	}

	$regex = '/^('.implode('|', EXTS).')$/';
	$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

	if (!isset($ext) || !preg_match($regex, $ext))
	{
		error_die($data, 415, 'invalid_file_extension');
	}

	$ext = '.'.$ext;

	$mime = mime_content_type($file['tmp_name']);
	if (!preg_match(MIME_TYPE_REGEX, $mime))
	{
		error_die($data, 415, 'invalid_file_mime_type');
	}

	if (!defined('NAME_LENGTH'))
	{
		define('NAME_LENGTH', 7);
	}

	$name = get_custom_filename($data);

	if (!$name)
	{
		$name = random_str().$ext;

		while (file_exists($name))
		{
			error_log('ShareXen Collision: File "'.$name.'" already exists.');
			$name = random_str().$ext;
		}
	}

	if (!move_uploaded_file($file['tmp_name'], $name))
	{
		error_die($data, 500, 'upload_failed');
	}

	$data['filename'] = $name;

	generate_all_urls($data);
}

function delete_endpoint(&$data)
{
	$name = get_custom_filename($data, false);

	ensure_file_exists($data, $name);
	ensure_file_access($data, $name, false);

	if (!unlink($name))
	{
		error_die($data, 500, 'delete_failed');
	}
}

function rename_endpoint(&$data)
{
	enforce_auth($data);

	if (!(defined('ALLOW_CUSTOM_NAMES') &&
		ALLOW_CUSTOM_NAMES) && !user_is_admin($data))
	{
		error_die($data, 403, 'missing_permissions');
	}

	$old_name = get_custom_filename($data, false);

	ensure_file_exists($data, $old_name, 'old_name');
	ensure_file_access($data, $old_name);

	$new_name = get_custom_filename($data, true, 'new_name');

	if (!$new_name)
	{
		error_die($data, 400, 'missing_new_name');
	}

	if (!rename($old_name, $new_name))
	{
		error_die($data, 500, 'rename_failed');
	}

	$data['filename'] = $new_name;

	generate_all_urls($data);
}

function info_endpoint(&$data)
{
	enforce_auth($data);

	$admin = user_is_admin($data);
	$data['is_admin'] = $admin;

	$name = get_custom_filename($data, false);

	if ($name)
	{
		$exists = file_exists($name);
		$data['file_exists'] = $exists;

		if ($exists)
		{
			$data['filename'] = $name;
			$data['filesize'] = filesize($name);
			$data['uploaded_at'] = filemtime($name);

			generate_all_urls($data, $admin);
		}
	}
	else
	{
		global $keys;
		$data['endpoints'] = $keys;

		$data['keyspace'] = KEYSPACE;
		$data['name_length'] = NAME_LENGTH;
		$data['allowed_extensions'] = EXTS;
		$data['allowed_characters'] = ALLOWED_CHARACTERS;

		$custom = defined('ALLOW_CUSTOM_NAMES');
		$custom = $custom && ALLOW_CUSTOM_NAMES;
		$data['custom_names'] = $custom;

		$pattern = '*.{'.implode(',', EXTS).'}';
		$files = glob($pattern, GLOB_BRACE) ?: [];
		$data['files_count'] = count($files);

		if ($admin)
		{
			$data['files'] = $files;
		}
	}
}

if (!in_array($endpoint, $keys))
{
	error_die($data, 404, 'unknown_endpoint');
}

($endpoint.'_endpoint')($data);

end_request($data);

?>
