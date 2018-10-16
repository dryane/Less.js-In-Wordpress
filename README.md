# Less.js-In-Wordpress
Enqueue and cache .less files in wordpress

A full description can be found [here](http://www.danieljosephryan.com/projects/web-development/less-js-in-wordpress/).
But all you have to do is:

	require get_template_directory() . '/less-js.php';
	add_action( 'wp_enqueue_scripts', 'enqueueLess', 1 );
	function enqueueLess() {
			wp_enqueue_style( 'less-style', '/style.less', array(), null, 'all');
		}
