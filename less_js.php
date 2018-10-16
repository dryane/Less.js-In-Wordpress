<?php
/**
 * Plugin Name:  Less.js in Wordpress
 * Plugin URI:   https://github.com/dryane/Less.js-In-Wordpress
 * Description:  Allows you to enqueue <code>.less</code> files and have them automatically compiled whenever a change is detected.
 * Author:       Daniel Joseph Ryan
 * Version:      1.0
 * Author URI:   https://danieljosephryan.com/
 * License:      MIT
 */

// Busted! No direct file access
! defined( 'ABSPATH' ) AND exit;

if ( ! class_exists( 'less_js' ) ) {
	add_action( 'init', array( 'less_js', 'instance' ) );
	class less_js {
		
		protected static $instance = null;
		/**
		 * Creates a new instance. Called on 'after_setup_theme'.
		 * May be used to access class methods from outside.
		 *
		 * @see    __construct()
		 * @static
		 * @return \less_js
		 */
		public static function instance() {
			null === self:: $instance AND self:: $instance = new self;
			return self:: $instance;
		}
		
		public $alwaysCompile = false;
		
		public $directory = "/less-css/";
		
		public $lessLink = "https://cdnjs.cloudflare.com/ajax/libs/less.js/3.7.1/less.min.js";
		
		public $minify = true;
		
		public $vars = array();
		
		/**
		 * Constructor
		 */
		public function __construct() {
			add_filter( 'style_loader_tag', array( $this,'parse_enqueued_style'), 10, 1 ); 
			
			add_action( 'wp_ajax_save_less', array( $this,'save_less') );
			add_action( 'wp_ajax_nopriv_save_less', array( $this,'save_less') );
			
		}
		
		public function parse_enqueued_style( $html ) {
			$startTime = microtime(true);
			if ( !strpos($html, '.less') ) { // If not .less
				return $html;
			}
			
			$id = $this->getStringBetween($html,"id='","-css");
			$serverCSSFile = $this->get_cache_dir(true) . $id . ".css";
			
			if ( file_exists( $serverCSSFile ) ) {
				$exists = true;
			} else {
				$exists = false;
			}
			
			$lessURL = $this->getStringBetween($html,"href='","'");
			$serverLESSFile = $_SERVER['DOCUMENT_ROOT'] . str_replace( get_site_url() , "", $lessURL);
			$modTime = filemtime($serverLESSFile);
			if (get_option("less-js-".$id) == $modTime) {
				$unchanged = true;
			} else {
				$unchanged = false;
			}
			
			if ($exists && $unchanged && !$this->alwaysCompile){ // is cached
				$oldUrl = $this->getStringBetween($html, "href='", "'");
				$html = str_replace($oldUrl,$this->get_cache_dir() . $id . ".css",$html);
				return $html;
			} else {
				$html = str_replace("rel='stylesheet'",'rel="stylesheet/less"',$html);
				$this->enqueue_less_scripts();
				return $html; 	
			} 
		}
		public function enqueue_less_scripts() {
			wp_enqueue_script( 'less-js', $this->lessLink, array('jquery') , null, false  );
			
			$this->vars['themeurl'] = '"' . get_stylesheet_directory_uri() . '"';
			$this->vars['themedirectory'] = '"' . get_stylesheet_directory() . '"';
			$this->vars['parenturl'] = '"' . get_template_directory_uri() . '"';
			$this->vars['parentdirectory'] = '"' . get_template_directory() . '"';
			$this->vars = apply_filters( 'less_vars', $this->vars, $handle );
			
			/**
			 * How to Add More vars
			 * variable names MUST be lowercase letters only
			 * add_filter( 'less_vars', 'my_less_vars', 10, 2 );
			 * function my_less_vars( $vars, $handle ) {
			 * 	$vars[ 'black' ] = "#000";
			 * 	return $vars;
			 * }
			 * 
			 **/
			
			$vars;
			$values = $this->vars;
			$keys = array_keys($this->vars);
			
			for ($i = 0; $i < count($keys); $i++) {
				$vars .= $keys[$i] . ": '" . $values[$keys[$i]] . "'";
				if ($i + 1 != count($keys)) {
					$vars .= ",\n";
				}
			} 
						
			$before_script = <<<END
<script>
  less = {
    globalVars: {
$vars
	}
  };
</script>	
END;
			wp_add_inline_script( 'less-js', $before_script, 'before' );

			$after_script = <<<END
document.addEventListener("DOMContentLoaded", function() {
  var head = document.querySelector("html head");

  head.addEventListener('DOMNodeInserted', function(evt) {

  	var id = evt.target.id;
  	if (!id.startsWith("less:")) {
  		return;
  	}

  	var pageURL = window.location.pathname.replace(RegExp('%', 'g'), '-').replace(RegExp('/', 'g'),'');

  	var name = evt.target.previousSibling.id.replace("-css","");
  	var href = evt.target.previousSibling.href;
    var css = evt.target.innerHTML;
    var data = {};
    data.name = name;
	data.href = href;
    data.css = css;
	
	jQuery.post(
		document.location.origin + '/wp-admin/admin-ajax.php', 
		{
			'action': 'save_less',
			'data':   data
		}
	);

  }, false);

}, false);
END;
			if (!$this->alwaysCompile) {		
				wp_add_inline_script( 'less-js', $after_script, 'after' );	
			}	
		}
		
		public function save_less() {
			$data = $_POST['data'];
			if ($this->minify) {
				$data = $this->minifyCss($data);	
			}

			$my_file = $this->get_cache_dir(true) . $data['name'] . ".css";
			$handle = fopen($my_file, 'w') or die('Cannot open file:  '.$my_file);
			fwrite($handle, stripslashes($data['css']));
			fclose($handle);
			
			$serverLESSFile = $_SERVER['DOCUMENT_ROOT'] . str_replace( get_site_url() , "", $data['href']);
			$modTime = filemtime($serverLESSFile);
			update_option("less-js-".$data['name'], $modTime);
			
			return;
			
		}
		/** Helper Functions **/
		public function getStringBetween($str,$from,$to) {
			$sub = substr($str, strpos($str,$from)+strlen($from),strlen($str));
			return substr($sub,0,strpos($sub,$to));
		}
		public function get_cache_dir($returnServer = false) {

			$server = get_stylesheet_directory() . $this->directory;
			$baseurl = get_stylesheet_directory_uri() . $this->directory;

			if ( ! file_exists( $server ) ) {
				mkdir( $server );
			}
			if ($returnServer) {
				return $server;
			}
			return $baseurl;

		}
		public function minifyCss($css) {
			$css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
			preg_match_all('/(\'[^\']*?\'|"[^"]*?")/ims', $css, $hit, PREG_PATTERN_ORDER);
			for ($i=0; $i < count($hit[1]); $i++) {
				$css = str_replace($hit[1][$i], '##########' . $i . '##########', $css);
			}
			$css = preg_replace('/;[\s\r\n\t]*?}[\s\r\n\t]*/ims', "}\r\n", $css);
			$css = preg_replace('/;[\s\r\n\t]*?([\r\n]?[^\s\r\n\t])/ims', ';$1', $css);
			$css = preg_replace('/[\s\r\n\t]*:[\s\r\n\t]*?([^\s\r\n\t])/ims', ':$1', $css);
			$css = preg_replace('/[\s\r\n\t]*,[\s\r\n\t]*?([^\s\r\n\t])/ims', ',$1', $css);
			$css = preg_replace('/[\s\r\n\t]*>[\s\r\n\t]*?([^\s\r\n\t])/ims', '>$1', $css);
			$css = preg_replace('/[\s\r\n\t]*\+[\s\r\n\t]*?([^\s\r\n\t])/ims', '+$1', $css);
			$css = preg_replace('/[\s\r\n\t]*{[\s\r\n\t]*?([^\s\r\n\t])/ims', '{$1', $css);
			$css = preg_replace('/([\d\.]+)[\s\r\n\t]+(px|em|pt|%)/ims', '$1$2', $css);
			$css = preg_replace('/([^\d\.]0)(px|em|pt|%)/ims', '$1', $css);
			$css = preg_replace('/\p{Zs}+/ims',' ', $css);
			$css = str_replace(array("\r\n", "\r", "\n"), '', $css);
			for ($i=0; $i < count($hit[1]); $i++) {
				$css = str_replace('##########' . $i . '##########', $hit[1][$i], $css);
			}
			return $css;
		}
		/** End Helper Functions **/
		
	}
}
