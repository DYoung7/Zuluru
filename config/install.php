<?php

/**
 * Configure various static application options, related to system installation.
 * Dynamic configuration options are handled through the application itself and
 * stored in the database.
 */

// Set up how we sent email.
$config['email'] = array(
	// SMTP is preferred, as it will retry in the case of transient failures.
	'use_smtp'			=> false,
	'smtp_options'		=> array(
		'host'				=> '',
		'username'			=> '',
		'password'			=> '',
	),

	// Setting debug to true overrides settings above and instead writes all email
	// contents into the 'email' flash message.  If you use this, make sure you have
	//		echo $this->Session->flash('email');
	// in your default.ctp layout, and beware that email addresses enclosed in <>
	// will look like invalid HTML to your browser and be hidden unless you view source.
	'debug'				=> false,
);

// Set up which theme to use. Themes allow you to substantially customize the look of
// your site, without making changes to the distributed files.
// See README.themes for more details.
$config['theme'] = null;

// Set up which icon pack to use. Icon packs replace some or all of the default icons
// by placing image files with the same name in a subfolder of the base icon folder
// (see icon_base setting below). For example, to use an icon pack called "bubbles",
// change this setting to 'bubbles', create an {icon_base}/bubbles folder, and put
// your new images in there. They will automatically be used by the system. For any
// image that doesn't exist there, it will automatically fall back to the default one,
// no need to copy everything over.
$config['icon_pack'] = 'default';

// Add any additional CSS files required. By adding CSS here, you can make basic
// changes to the layout without changing the layout file.
$config['additional_css'] = array();

// Set up where certain resources are found in the filesystem.
$config['folders'] = array(
	// The filesystem location where files for permits, etc, shall live.
	// The league_base setting in the 'urls' section below will be the URL
	// where this folder is visible at. If you are allowing profile photos,
	// this must have a webserver-writable folder called "temp" in it.
	'league_base'		=> '/var/www/files',

	// The filesystem location where uploaded files go
	'uploads'			=> '/var/www/../upload',

	// The filesystem location where icon packs live.
	'icon_base'		=> '/var/www/img',
);

// Set up where certain resources are found in the web site.
//
// Note that, if Zuluru is running in a subfolder (e.g.
// http://example.com/zuluru), URLs below will all have the
// subfolder name automatically prefixed, unless you include
// http:// at the beginning.
//
// Some examples of where Zuluru will look for CSS files based
// on the configuration:
// zuluru_css                 Zuluru URL                    CSS URL
// /css/                      http://example.com/           http://example.com/css/
// /css/                      http://example.com/zuluru/    http://example.com/zuluru/css/
// http://example.com/css/    http://example.com/zuluru/    http://example.com/css/
// 
$config['urls'] = array(
	// Name of the domain this copy of Zuluru is running on
	'domain'			=> 'stpbball.hopto.org',

	// Location of Zuluru CSS and JS files, must end in /
	'zuluru_css'		=> '/css/',
	'zuluru_js'			=> '/js/',
	'zuluru_img'		=> '/img/',

	// URL where files for permits, etc., shall live.
	'league_base'		=> '/files',

	// URL for your organization's privacy policy
	'privacy_policy'	=> '/pages/privacy',

	// URLs for various user management pages.
	// These values are defaults and only need to be changed if you're using
	// a third-party user database or a different base folder for Zuluru.
	// TODO: Determine these dynamically in the most common situations and
	// only require configuration here in extreme cases.
	'register'			=> '/users/create_account',
	'login'				=> '/users/login',
);

// Set up system security.
$config['security'] = array(
	// Which model do we use for user authentication? Use 'User' if you're not sure.
	'auth_model'		=> 'UserDrupal',

	// If you are using a third-party user database, you may need to add more parameters
	// here. See the comments in the model file specified by auth_model above for details.

	'drupal_root'		=> '/var/www/NVJCYO',
	'auth_session'		=> 'stpbball.hopto.org/NVJCYO',

	// Do we add the system salt value to strings before hashing? Set to true if this
	// is a new system, false if you have pre-existing Zuluru member data or if
	// you are using a third-party user database which doesn't use this.
	'salted_hash'		=> true,
);

// Set up callback components
$config['callbacks'] = array(
	'user'				=> array(),
);

// Set up time zone options
$config['timezone'] = array(
	// See http://ca3.php.net/timezones for a list of valid timezone names
	'name'				=> 'US/Eastern',

	// Difference, in minutes, from the local timezone to the server timezone.
	// Value is negative if server is to your west, positive if it's east.
	'adjust'			=> 0,
);

// This is the generic word to be used for all field-type spaces that the
// site will deal with. If you offer Ultimate, volleyball and hockey, you
// will need to find something that conveys "field, court or rink". Perhaps
// "location"?
$field = 'court';
$config['ui'] = array(
	'field' => $field,
	'field_cap' => Inflector::humanize($field),
	'fields' => Inflector::pluralize($field),
	'fields_cap' => Inflector::humanize(Inflector::pluralize($field)),
);

?>
