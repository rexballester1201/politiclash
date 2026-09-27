<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * asset_helper.php — cache-busted asset URLs
 *
 * PolitiClash — CodeIgniter 3.x (autoloaded via config/autoload.php)
 *
 * ⚠ WHY THIS EXISTS. Every view linked `assets/css/style.css` as a bare URL with
 * no version on it. That URL never changes, so once a copy is cached it is
 * served until the cache decides otherwise — and this site sits behind
 * Cloudflare, which caches .css/.js at the edge by default. The result: a CSS
 * fix is uploaded, the file on the server is correct, the developer clears their
 * BROWSER cache, and the page still renders the old styling, because the stale
 * copy is coming from the CDN (or from a far-future browser cache entry), not
 * from the browser cache that was cleared.
 *
 * pc_asset() appends the file's modification time as `?v=`, so editing the file
 * changes the URL and every cache in the chain is forced to re-fetch it. No
 * manual version bumping, no purge step to forget.
 *
 *   <link rel="stylesheet" href="<?php echo pc_asset('assets/css/style.css'); ?>">
 *   → https://site/assets/css/style.css?v=1785432109
 */
if ( ! function_exists('pc_asset')) {

	/**
	 * @param  string $path  path relative to the web root, e.g. 'assets/css/style.css'
	 * @return string        absolute URL with a ?v=<mtime> cache buster
	 */
	function pc_asset($path)
	{
		$path = ltrim((string) $path, '/');
		$url  = base_url($path);

		// FCPATH is the directory holding index.php — the web root of the app.
		$file = FCPATH.$path;
		$mtime = (is_file($file)) ? @filemtime($file) : false;

		if ($mtime === false) {
			// Missing or unreadable (odd deploy, open_basedir): return the plain
			// URL rather than emitting a broken one. Worst case is the old
			// caching behaviour, never a 404.
			return $url;
		}

		return $url.((strpos($url, '?') === false) ? '?' : '&').'v='.$mtime;
	}
}
