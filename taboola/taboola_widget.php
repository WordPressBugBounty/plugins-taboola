<?php
/**
 * Plugin Name:  Taboola
 * Plugin URI:   https://developers.taboola.com/web-integrations/docs/wordpress-plugin
 * Description:  Taboola
 * Version:      3.1.0
 * Author:       Taboola
 */

define( 'TABOOLA_PLUGIN_VERSION', '3.1.0' );   // track every release
define( 'TABOOLA_MIN_VER',        '3.0' );   // bump only when DB changes
define( 'TABOOLA_DEBUG_MODE',      false );

define( 'TABOOLA_OPTION_NAME', 'taboola_plugin_version' );

define( 'TABOOLA_XPATH_MARKER',  '/' );
define( 'TABOOLA_JS_INDICATOR',  '{JS}' );
define( 'TABOOLA_JS_MARKER',     '{'   );
define( 'TABOOLA_CONTENT_FORMAT_STRING',  'string'  );
define( 'TABOOLA_CONTENT_FORMAT_SCRIPT',  'script'  );
define( 'TABOOLA_CONTENT_FORMAT_HTML',    'html'    );

include_once 'widget.php';
require_once 'JavaScriptWrapper.php';
if ( ! class_exists( 'simple_html_dom' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'simple_html_dom.php'; // ← NEW
}

if ( ! class_exists( 'TaboolaWP' ) ) {
class TaboolaWP {

    /* ───────────────────────────  properties  ─────────────────────────── */
    public $data             = [];
    public $_is_widget_on_page;
    public $_is_head_script_loaded = false;

    private $tpl_sw    = 'importScripts("https://cdn.taboola.com/webpush/tsw.js");';
    private $msg_sw_error  = <<<SWE
        <p>The file <code>sw.js</code> in the WP root is not writable.
        Please change its permissions or paste the code below manually:</p>
        <pre><code>{{SW}}</code></pre>
        <p>Also make sure it's reachable at <code>{{DOMAIN}}/sw.js</code></p>
SWE;

    public  $plugin_name;
    public  $plugin_directory;
    public  $plugin_url;
    public  $settings;
    public  $tbl_taboola_settings;

    /* ───────────────────────────  constructor  ─────────────────────────── */
    public function __construct() {
        global $wpdb;
        //initialize plugin constant
        define( 'TaboolaWP', true );

        $this->_is_widget_on_page   = false;
        $this->_is_head_script_loaded = false;

        $this->plugin_name      = plugin_basename( __FILE__ );
        $this->plugin_directory = plugin_dir_path( __FILE__ );
        $this->plugin_url       = trailingslashit(
            WP_PLUGIN_URL . '/' . dirname( plugin_basename( __FILE__ ) )
        );
        $this->tbl_taboola_settings = $wpdb->prefix . '_taboola_settings';
        $this->settings = $wpdb->get_row(
            "SELECT * FROM {$this->tbl_taboola_settings} LIMIT 1"
        );

        /* activation / upgrade */
        register_activation_hook( $this->plugin_name, [ $this, 'update_db' ] );
        add_action( 'admin_init',                     [ $this, 'update_db' ] );

        /* widgets */
        if ( $this->settings && ! empty( $this->settings->publisher_id ) ) {
            add_action(
                'widgets_init',
                static fn() => register_widget( 'WP_Widget_Taboola' )
            );
        }

        /* admin vs front-end hooks */
        if ( is_admin() ) {
            add_action( 'admin_menu',        [ $this, 'admin_generate_menu' ] );
            add_filter( 'plugin_action_links',
                        [ $this, 'plugin_action_links' ], 10, 2 );
        } 
        
        
        
        
        elseif ( $this->settings ) {
            /* loader & flush */
            add_action( 'wp_head',    [ $this, 'taboola_header_loader_inject' ] );
                                if ( ! empty( $this->settings->publisher_id_push ) ) {
                        add_action( 'wp_head', [ $this, 'taboola_webpush_loader_js' ] );

                        $sw     = 'sw.js';
                        $sw_path = ABSPATH . $sw;

                        $content = file_exists( $sw_path ) ? file_get_contents( $sw_path ) : '';

                        if ( strpos( $content, $this->tpl_sw ) === false ) {
                            if ( ! is_writable( ABSPATH ) || ( file_exists( $sw_path ) && ! is_writable( $sw_path ) ) ) {
                                return $this->notice( $this->msg_sw_error );
                            }
                            $content = $this->tpl_sw . PHP_EOL . $content;
                            if ( file_put_contents( $sw_path, $content ) === false ) {
                                return $this->notice( $this->msg_sw_error );
                            }
                        }
                    }
            add_action( 'wp_footer',  [ $this, 'taboola_footer_loader_js'   ] );

            /* content widgets */
            add_filter( 'the_content', [ $this, 'load_taboola_content'      ] );
            add_filter( 'the_content', [ $this, 'load_taboola_content_mid'  ] );

            /* full-page buffer – home & category */
            add_action( 'template_redirect', [ $this, 'tb_home_buffer_start' ], 0 );
            add_action( 'shutdown',          [ $this, 'tb_home_buffer_flush' ], PHP_INT_MAX );

            add_action( 'template_redirect', [ $this, 'tb_cat_buffer_start'  ], 0 );
            add_action( 'shutdown',          [ $this, 'tb_cat_buffer_flush'  ], PHP_INT_MAX );
        }
    } // __construct


/* ======================================================================
   CATEGORY ▸ should we show the widget?
   ====================================================================== */
private function should_show_content_widget_category(): bool {
    return (
        trim( $this->settings->publisher_id ) !== '' &&
        ( is_category() || is_archive() || is_search() ) &&
        $this->settings->category_enabled &&
        trim( $this->settings->category_widget_id ) !== ''
    );
}

/* ----------------------------------------------------------------------
   HOMEPAGE ▸ full-page buffer
   -------------------------------------------------------------------- */
public function tb_home_buffer_start() {
    if ( $this->should_show_content_widget_home() ) {
        ob_start( [ $this, 'tb_home_buffer_inject' ] );
    }
}
public function tb_home_buffer_inject( $html ) {
    return $this->load_taboola_content_home( $html );
}

/* ----------------------------------------------------------------------
   CATEGORY ▸ buffer hooks
   -------------------------------------------------------------------- */
public function tb_cat_buffer_start() {
    if ( $this->should_show_content_widget_category() ) {
        ob_start( [ $this, 'tb_cat_buffer_inject' ] );
    }
}
public function tb_cat_buffer_inject( $html ) {
    return $this->load_taboola_content_category( $html );
}
public function tb_cat_buffer_flush() {
    if ( ob_get_length() ) {
        echo ob_get_clean();
    }
}

/* ----------------------------------------------------------------------
   CATEGORY ▸ choose where to inject
   -------------------------------------------------------------------- */
private function embed_taboola_content_location_category(
            string $html,
            array  $taboola_content,
            string $selector ) : string {

    $formatted = $this->format_taboola_content_category( $taboola_content );

    /* A.  no selector → bottom of page */
    if ( $selector === '' ) {
        return str_ireplace( '</body>', $formatted . '</body>', $html );
    }

    /* B.  selector given → try server-side injection */
    $doc = str_get_html( $html );
    if ( ! $doc ) {
        // parser failed → graceful fallback
        return str_ireplace( '</body>', $formatted . '</body>', $html );
    }

    $occurrence = max( 0, $this->settings->category_location_string_occurrence - 1 );
    $target     = $doc->find( $selector, $occurrence );

    if ( $target ) {                 // selector found
        $target->outertext .= $formatted;
        return (string) $doc;        // done – no duplicate
    }

    /* C.  selector not found → bottom of page */
    return str_ireplace( '</body>', $formatted . '</body>', $html );
}

/* ------------------------------------------------------------------ */
public function tb_home_buffer_flush() {
    if ( ob_get_length() ) {
        echo ob_get_clean();
    }
}




function plugin_action_links($links, $file) {
            static $this_plugin;

            if (!$this_plugin) {
                $this_plugin = plugin_basename(__FILE__);
            }

            if ($file == $this_plugin) {
                $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=taboola_widget">Settings</a>';
                array_unshift($links, $settings_link);
            }

            return $links;
        }

        private function should_show_content_widget(){
            $retVal = ((trim($this->settings->publisher_id) != '') && is_single() && $this->settings->first_bc_enabled && trim($this->settings->first_bc_widget_id) != '');
            return $retVal;
        }

        private function should_show_content_widget_mid(){
            // PC - only if v2 was installed:
            if (!$this->is_db_updated_for_min_ver("2.0.0"))
                return false;
            $retVal1 = ((trim($this->settings->publisher_id) != '') && is_single() && !empty($this->settings->mid_enabled));
            return $retVal1;
        }

        private function should_show_content_widget_home(){
            // PC - only if v2 was installed:
          
    //error_log( print_r( $this->settings, true ) );   // TEMP line

            if (!$this->is_db_updated_for_min_ver("2.0.0"))
                return false;
            $retVal2 = ((trim($this->settings->publisher_id) != '') && (is_front_page() || is_home()) && $this->settings->home_enabled && trim($this->settings->home_widget_id) != '');
            return $retVal2;
        }

        private function should_show_sidebar_widget(){
            $retVal = ((trim($this->settings->publisher_id) != '') && is_active_widget( false, false, TABOOLA_WIDGET_BASE_ID, true ));
            return $retVal;
        }

        // Determine if a taboola widget should be added somewhere on the current page (content or sidebar)
        function is_widget_on_page(){
            return  $this->should_show_content_widget() || $this->should_show_content_widget_mid() || $this->should_show_content_widget_home() || $this->should_show_sidebar_widget() || $this->should_show_content_widget_category() ;
        }

        function get_page_type(){
            $page_type='article';
            if (is_front_page()){
                $page_type='home';
            }else if (is_category() || is_archive() || is_search()){
                $page_type='category';
            }
            return $page_type;
        }

        // return the head loader script
      function taboola_header_loader_js() {
            if ($this->is_widget_on_page()){
                
                // New logic to get all mid-article locations
                $mid_locations_string = '';
                if (!empty($this->settings->mid_widgets)) {
                    $mid_widgets_array = json_decode($this->settings->mid_widgets, true);
                    if (is_array($mid_widgets_array)) {
                        $locations = array_column($mid_widgets_array, 'location_string');
                        $mid_locations_string = implode(', ', $locations);
                    }
                }

            	$stringParams = array(
		            '{{PUBLISHER_ID}}' => $this->settings->publisher_id,
		            '{{PAGE_TYPE}}' => $this->get_page_type(),
		            '{{WORDPRESS_VERSION}}' => get_bloginfo('version'),
                    '{{PHP_VERSION}}' => phpversion(),
		            '{{PLUGIN_VERSION}}' => TABOOLA_PLUGIN_VERSION,
                    '{{LOC_MID}}' => $mid_locations_string,
                    '{{LOC_HOME}}' => $this->settings->home_location_string ?? ''
	            );

            	$scriptWrapper = new JavaScriptWrapper("loaderInjectionScript.js",$stringParams);
                return $scriptWrapper->getScriptMarkupString();
            }
            return "";
        }

        // This function is used for the hook action, injects the header content to the <head> tag.
        function taboola_header_loader_inject(){
            echo $this->taboola_header_loader_js();
        }

        // adding webpush loader
        function taboola_webpush_loader_js() {
            if(is_single() || is_home() || is_category() || is_front_page()){
                    $stringParamsWeb = array(
                        '{{PUBLISHER_ID}}' => $this->settings->publisher_id_push
                    );
                $webpushInjectionScript = new JavaScriptWrapper("webPush.js",$stringParamsWeb);
                echo $webpushInjectionScript;
            }
        }

        function taboola_footer_loader_js() {

            // Only adding flush script if a widget is going to be placed on the page.
            if ($this->is_widget_on_page()){
                $flushInjectionScript = new JavaScriptWrapper('flushInjectionScript.js',array());
                echo $flushInjectionScript->getScriptMarkupString();
            }
        }

        // Below-article widget
        function load_taboola_content($content)
        {
            $taboola_content = array();
            if ($this->should_show_content_widget()){

            	$firstWidgetParams = array(
                    '{{WIDGET_ID}}' => $this->settings->first_bc_widget_id,
		            '{{CONTAINER}}' => 'taboola-below-article-thumbnails',
                    // PC - If v2 was not installed, then use v1 logic
		            '{{PLACEMENT}}' =>  (!empty($this->settings->first_bc_placement)) ? $this->settings->first_bc_placement : 'below-article' // In case v1 upgrade has not run yet
                );

            	$firstWidgetScript = new JavaScriptWrapper("widgetInjectionScript.js",$firstWidgetParams);
                $taboola_content[TABOOLA_CONTENT_FORMAT_HTML][] = "<div id='taboola-below-article-thumbnails'></div>";
                $taboola_content[TABOOLA_CONTENT_FORMAT_SCRIPT][] = $firstWidgetScript;

                $content = $this->embed_taboola_content_location($content,$taboola_content);
            }

            return $content;
        }

        // Mid-article-widget
        function load_taboola_content_mid($content) {
            if ($this->should_show_content_widget_mid()){
                $mid_widgets = json_decode($this->settings->mid_widgets ?? '[]');

                if (is_array($mid_widgets) && !empty($mid_widgets)) {
                    foreach ($mid_widgets as $index => $widget) {
                        $container_id = 'taboola-mid-article-thumbnails-' . $index;
                        
                        $widgetParams = array(
                            '{{WIDGET_ID}}' => $widget->widget_id,
                            '{{CONTAINER}}' => $container_id,
                            '{{PLACEMENT}}' => $widget->placement
                        );

                        $widgetScript = new JavaScriptWrapper("widgetInjectionScript.js", $widgetParams);
                        $taboola_content_mid = array(
                            TABOOLA_CONTENT_FORMAT_HTML   => array("<div id='{$container_id}'></div>"),
                            TABOOLA_CONTENT_FORMAT_SCRIPT => array($widgetScript),
                        );

                        $content = $this->embed_taboola_content_location_mid(
                            $content,
                            $taboola_content_mid,
                            $widget->location_string,
                            $widget->occurrence
                        );
                    }
                }
            }
            return $content;
        }

        // Homepage widget 
        function load_taboola_content_home($content)
        {
            //error_log( 'TB-DEBUG ② entered load_taboola_content_home' );
                $taboola_content_home = array();
                if ($this->should_show_content_widget_home()){

                        $homeWidgetParams = array('{{WIDGET_ID}}' => $this->settings->home_widget_id,
                            '{{CONTAINER}}' => 'taboola-homepage-thumbnails',
                            '{{PLACEMENT}}' =>  $this->settings->home_placement);
                                                
                        $homeWidgetScript = new JavaScriptWrapper("widgetInjectionScript.js",$homeWidgetParams);
                        $taboola_content_home[TABOOLA_CONTENT_FORMAT_HTML][] = "<div id='taboola-homepage-thumbnails'></div>";
                        $taboola_content_home[TABOOLA_CONTENT_FORMAT_SCRIPT][] = $homeWidgetScript;

                    $content = $this->embed_taboola_content_location_home($content,$taboola_content_home,trim($this->settings->home_location_string));
                }
            return $content;
           
        }
        /* ------------------------------------------------------------------
 * CATEGORY  ▸  build widget & inject
 * ------------------------------------------------------------------*/
public function load_taboola_content_category( $content ) {

    // 1) Build the DIV that Taboola will fill
    $taboola[TABOOLA_CONTENT_FORMAT_HTML][] =
        "<div id='taboola-category-thumbnails'></div>";

    // 2) Build the JS that loads the feed (reuse the wrapper)
    $taboola[TABOOLA_CONTENT_FORMAT_SCRIPT][] =
        new JavaScriptWrapper( 'widgetInjectionScript.js', [
            '{{WIDGET_ID}}' => $this->settings->category_widget_id,
            '{{CONTAINER}}' => 'taboola-category-thumbnails',
            '{{PLACEMENT}}' => $this->settings->category_placement,
        ]);

    // 3) Decide where to insert (selector or end of <body>)
    return $this->embed_taboola_content_location_category(
                $content,
                $taboola,
                trim( $this->settings->category_location_string )
           );
}

        // Below-article widget

        // Extract the taboola content in the required format:
        // String - for injecting on the servr side
        // Script or HTML - for injecting on the client side
        function format_taboola_content($taboola_content,$format){
            $ret_val = null;

            switch($format){
                case TABOOLA_CONTENT_FORMAT_STRING:
                    $result_string = join("",$taboola_content[TABOOLA_CONTENT_FORMAT_HTML]).
                        "<script type='text/javascript'>".join("\n",$taboola_content[TABOOLA_CONTENT_FORMAT_SCRIPT])."</script>";
                    $ret_val = $result_string;
                    break;

                // script or html
                default:
                    $ret_val = str_replace("\n","",join("",$taboola_content[$format]));
                    break;
            }

            return $ret_val;
        }


        // Mid-article widget
        function format_taboola_content_mid($taboola_content_mid,$format){
            $ret_val = null;

            switch($format){
                case TABOOLA_CONTENT_FORMAT_STRING:
                    $result_string = join("",$taboola_content_mid[TABOOLA_CONTENT_FORMAT_HTML]).
                        "<script type='text/javascript'>".join("\n",$taboola_content_mid[TABOOLA_CONTENT_FORMAT_SCRIPT])."</script>";
                    $ret_val = $result_string;
                    break;

                // script or html
                default:
                    $ret_val = str_replace("\n","",join("",$taboola_content_mid[$format]));
                    break;
            }

            return $ret_val;
        }


        // Homepage widget
        function format_taboola_content_home($taboola_content_home,$format){
            
            $ret_val = null;

            switch($format){
                case TABOOLA_CONTENT_FORMAT_STRING:
                    $result_string = join("",$taboola_content_home[TABOOLA_CONTENT_FORMAT_HTML]).
                        "<script type='text/javascript'>".join("\n",$taboola_content_home[TABOOLA_CONTENT_FORMAT_SCRIPT])."</script>";
                    $ret_val = $result_string;     
                    break;

                // script or html
                default:
                    $ret_val = str_replace("\n","",join("",$taboola_content_home[$format]));
                    break;
            }

            return $ret_val;
        
         }


         // ► CATEGORY formatter  ◄
private function format_taboola_content_category( $arr ) {
    return implode( "\n", [
        implode( "\n", $arr[TABOOLA_CONTENT_FORMAT_HTML] ?? [] ),
        '<script type="text/javascript">' .
            implode( "\n", $arr[TABOOLA_CONTENT_FORMAT_SCRIPT] ?? [] ) .
        '</script>',
    ]);
}
        /* ------------------------------------------------------------------
         * wpautop() hardening for anything injected through the_content.
         *
         * wpautop() pads block-level tags with blank lines, then splits the
         * content on blank lines and wraps each chunk in <p>. Its <script>
         * protection only runs after that split, so two things tear an injected
         * <script> apart and spill its tail onto the page as reader-visible text:
         *   1. a blank line anywhere in the script body, and
         *   2. a block-level tag (e.g. <div>) sitting inside a JS string literal.
         * Both have to be neutralised; fixing only one still breaks.
         * ------------------------------------------------------------------*/

        // Hide markup from wpautop's block-tag scan. \x3C decodes back to "<"
        // when the surrounding JS string literal is evaluated.
        private function js_escape_markup($markup){
            return str_replace('<', '\x3C', (string) $markup);
        }

        // Collapse blank lines so wpautop has no paragraph boundary to split on.
        private function wpautop_safe_script($script){
            return preg_replace('/(\R[ \t]*){2,}/', "\n", (string) $script);
        }

        // Below-article widget
        // Do the actual logic of choosing where to place the taboola content.
        function embed_taboola_content_location($content, $taboola_content){
            $do_default = true;

			// tag is placed outside of content in order to allow "read more" functionality.
            if ($this->settings->out_of_content_enabled){

	            $scriptWrapper = new JavaScriptWrapper("js_inject.min.js",array(
			            "{{HTML}}" => $this->js_escape_markup($this->format_taboola_content($taboola_content,TABOOLA_CONTENT_FORMAT_HTML)),
			            "{{SCRIPT}}" => $this->js_escape_markup($this->format_taboola_content($taboola_content,TABOOLA_CONTENT_FORMAT_SCRIPT)))
	            );
	            $scriptWrapper->appendScript("injectWidgetByMarker('tbmarker');");
            	$content = $content."<span id='tbmarker'></span><script type='text/javascript'>".$this->wpautop_safe_script($scriptWrapper)."</script>";
	            $do_default = false;
            }

            // Default for below-article widget - add to the end of the content
            if ($do_default){
                $content = $content.$this->format_taboola_content($taboola_content,TABOOLA_CONTENT_FORMAT_STRING);
            }

            return $content;
        }

        // Mid-article widget
        // Do the actual logic of choosing where to place the taboola content based on the "location" attribute        
        function embed_taboola_content_location_mid($content, $taboola_content_mid, $location, $occurrence = 1){
            $do_default = true;

            if (isset($location) && $location != ''){
                $first_char = substr($location,0,1);

                // DIV/XPATH provided for JS handling
                if ($first_char == TABOOLA_JS_MARKER){
                    $full_indicator = substr($location,0,strlen(TABOOLA_JS_INDICATOR));

                    if ($full_indicator == TABOOLA_JS_INDICATOR){

                        $xpath = substr($location,strlen(TABOOLA_JS_INDICATOR));
                        $scriptWrapper = new JavaScriptWrapper("js_inject.min.js",array(
                            "{{HTML}}" => $this->js_escape_markup($this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_HTML)),
                            "{{SCRIPT}}" => $this->js_escape_markup($this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_SCRIPT)))
                        );
                        $scriptWrapper->appendScript("injectWidgetByXpath('".$xpath."');");
                        $content = $content."<span id='tbdefault'></span><script type='text/javascript'>".$this->wpautop_safe_script($scriptWrapper)."</script>";

                        $do_default = false;
                    }

                // server side selector provided (see simple_html_dom selectors http://simplehtmldom.sourceforge.net/manual.htm)
                // basically it's CSS selectors like in jQuery
                } else{
                    if ( ! class_exists( 'simple_html_dom' ) ) {
                        require_once('simple_html_dom.php');
                    }

                    $html_doc = str_get_html($content);
                    $target_location = $html_doc->find($location, ($occurrence) - 1);

                    // if the location was found within the html content
                    if (isset($target_location) && is_object($target_location)){

                        // adding taboola content AFTER the target location
                        $target_location->outertext = $target_location->outertext.$this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_STRING);
                        $content = $html_doc;
                        $do_default = false;
                    }
                }
            }

            return $content;
        }

        // Homepage widget
        // Do the actual logic of choosing where to place the taboola content based on the "location" attribute  
        function embed_taboola_content_location_home($content, $taboola_content_home, $location){


         //   error_log( 'TB-DEBUG ③ raw selector string: [' . $location . ']' );

    // load parser
    //require_once plugin_dir_path( __FILE__ ) . 'simple_html_dom.php';
   // $html_doc = str_get_html( $content );

    // occurrence: settings is 1-based, find() wants 0-based
   // $occurrence = max( 1, intval( $this->settings->home_location_string_occurrence ) );
  //  $node       = $html_doc->find( $location, $occurrence - 1 );
   // $found      = is_object( $node ) ? 1 : 0;

  //  error_log( "TB-DEBUG ③ found {$found} matching node(s) for selector [{$location}]" );
    // ────────────────────────────
                $do_default = true;

                if (isset($location) && $location != ''){
                    $first_char = substr($location,0,1);

                    // DIV/XPATH provided for JS handling
                    if ($first_char == TABOOLA_JS_MARKER){
                        $full_indicator = substr($location,0,strlen(TABOOLA_JS_INDICATOR));

                        if ($full_indicator == TABOOLA_JS_INDICATOR){

                            $xpath = substr($location,strlen(TABOOLA_JS_INDICATOR));
                            $scriptWrapper = new JavaScriptWrapper("js_inject.min.js",array(
                                "{{HTML}}" => $this->js_escape_markup($this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_HTML)),
                                "{{SCRIPT}}" => $this->js_escape_markup($this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_SCRIPT)))
                            );
                            $scriptWrapper->appendScript("injectWidgetByXpath('".$xpath."');");
                            $content = $content."<span id='tbdefault'></span><script type='text/javascript'>".$this->wpautop_safe_script($scriptWrapper)."</script>";

                            $do_default = false;
                        }

                    // server side selector provided (see simple_html_dom selectors http://simplehtmldom.sourceforge.net/manual.htm)
                    // basically it's CSS selectors like in jQuery
                   } else{
                    if ( ! class_exists( 'simple_html_dom' ) ) {
                        require_once('simple_html_dom.php');
                    }

                        $html_doc = str_get_html($content);
                        $target_location = $html_doc->find($location,($this->settings->home_location_string_occurrence)-1);

                        // if the location was found within the html content
                        if (isset($target_location) && is_object($target_location)){

                            // adding taboola content AFTER the target location
                            $target_location->outertext = $target_location->outertext.$this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_STRING);
                            $content = $html_doc;
                            $do_default = false;
                        }
                    }
                }
                // Default for below-article widget - add to the end of the content
                if ($do_default){
                 $content = $content.$this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_STRING);
                 }
                return $content;
               

        }

        function admin_generate_menu(){
            global $current_user;
            add_menu_page(__('Taboola','taboola_widget'), __('Taboola','taboola_widget'), 'manage_options', 'taboola_widget', array(&$this, 'admin_taboola_settings'), $this->plugin_url.'img/taboola_icon.png', 110);
        }

        // Empty numeric inputs must reach MySQL as NULL, not '', or strict mode
        // rejects the whole row.
        private function nullable_int($value){
            return (isset($value) && trim((string) $value) !== '') ? (int) $value : null;
        }

        function admin_taboola_settings(){
            global $wpdb;
            $settings = $wpdb->get_row("select * from ".$wpdb->prefix."_taboola_settings limit 1");
            $taboola_errors = array();
            $taboola_save_error = '';
            if($_SERVER['REQUEST_METHOD'] == 'POST'){

                if(trim(strip_tags($_POST['publisher_id'])) == ''){ 
                    $taboola_errors[] = "Publisher ID";
                }

                if(isset($_POST['web_push_enabled'])) {
                    if (trim(strip_tags($_POST['publisher_id_push'])) == '') {
                        $taboola_errors[] = "Publisher Push ID";
                    }
                }

                if(isset($_POST['first_bc_enabled'])) {
                    if (trim(strip_tags($_POST['first_bc_widget_id'])) == '') {
                        $taboola_errors[] = "Below-article > Widget ID";
                    }
                    if (trim(strip_tags($_POST['first_bc_placement'])) == '') {
                        $taboola_errors[] = "Below-article > Placement Name";
                    }
                }

                if(isset($_POST['mid_enabled'])) {
                    if(isset($_POST['mid_widget_id']) && is_array($_POST['mid_widget_id'])) {
                        foreach ($_POST['mid_widget_id'] as $index => $widget_id) {
                            if(trim($widget_id) == '') {
                                $taboola_errors[] = "Mid-article Widget #".($index + 1)." > Widget ID";
                            }
                            if(trim($_POST['mid_placement'][$index]) == '') {
                                $taboola_errors[] = "Mid-article Widget #".($index + 1)." > Placement Name";
                            }
                        }
                    }
                }

                if(isset($_POST['home_enabled'])) {
                    if (trim(strip_tags($_POST['home_widget_id'])) == '') {
                        $taboola_errors[] = "Homepage > Widget ID";
                    }
                    if (trim(strip_tags($_POST['home_placement'])) == '') {
                        $taboola_errors[] = "Homepage > Placement Name";
                    }

                    if (trim(strip_tags($_POST['home_location_string'])) == '') {
                        $taboola_errors[] = "Homepage > CSS selector";
                    }
                    else {
                        if (!empty($_POST['home_location_string']) && !$this->is_home_location_string_occurrence_valid($_POST['home_location_string_occurrence'])) {
                            $taboola_errors[] = "Homepage > Occurance (must be >= 1)";
                        }
                    }
                }     
                if ( isset($_POST['category_enabled']) ) {
                    if ( trim(strip_tags($_POST['category_widget_id'])) == '' )
                        $taboola_errors[] = "Category > Widget ID";
                    if ( trim(strip_tags($_POST['category_placement'])) == '' )
                        $taboola_errors[] = "Category > Placement Name";
                }   
                
                if(count($taboola_errors) == 0){
                    
                    $mid_widgets_data = array();
                    if (isset($_POST['mid_widget_id']) && is_array($_POST['mid_widget_id'])) {
                        foreach ($_POST['mid_widget_id'] as $index => $widget_id) {
                            if (!empty(trim($widget_id))) {
                                
                                $location_string = 'p'; 
                                if (isset($_POST['mid_paragraph_ui_mode'][$index]) && $_POST['mid_paragraph_ui_mode'][$index] === 'Other') {
                                    if (isset($_POST['mid_location_string'][$index])) {
                                        $location_string = sanitize_text_field($_POST['mid_location_string'][$index]);
                                    }
                                }

                                $mid_widgets_data[] = array(
                                    'widget_id'       => sanitize_text_field($widget_id),
                                    'placement'       => sanitize_text_field($_POST['mid_placement'][$index]),
                                    'location_string' => $location_string,
                                    'occurrence'      => intval($_POST['mid_location_string_occurrence'][$index]),
                                );
                            }
                        }
                    }
                    $mid_widgets_json = json_encode($mid_widgets_data);

                    /* $wpdb formats every value as %s unless told otherwise, so a PHP
                       false or an empty string reaches MySQL as ''. Under
                       STRICT_TRANS_TABLES (the MySQL 8 default) '' is rejected for the
                       TINYINT/INT columns and the whole write is refused. Send real
                       integers for the flags and NULL for empty numeric fields. */
                    $data = array(
                        "publisher_id" => trim($_POST['publisher_id']),

                        "web_push_enabled" => isset($_POST['web_push_enabled']) ? 1 : 0,
                        "publisher_id_push" => $this->nullable_int($_POST['publisher_id_push'] ?? null),

                        "first_bc_enabled" => isset($_POST['first_bc_enabled']) ? 1 : 0,
                        "first_bc_widget_id" => !empty($_POST['first_bc_widget_id']) ? trim($_POST['first_bc_widget_id']) : '',
                        "first_bc_placement" => !empty($_POST['first_bc_placement']) ? trim($_POST['first_bc_placement']) : '',

                        "out_of_content_enabled" => isset($_POST['out_of_content_enabled']) ? 1 : 0,

                        "mid_enabled" => isset($_POST['mid_enabled']) ? 1 : 0,
                        "mid_widgets" => $mid_widgets_json,
                        
                        "home_enabled" => isset($_POST['home_enabled']) ? 1 : 0,
                        "home_widget_id" => !empty($_POST['home_widget_id']) ? trim($_POST['home_widget_id']) : '',
                        "home_placement" => !empty($_POST['home_placement']) ? trim($_POST['home_placement']) : '',

                        "home_location_string_occurrence" => $this->nullable_int($_POST['home_location_string_occurrence'] ?? null),
                        "home_location_string" => !empty($_POST['home_location_string']) ? trim($_POST['home_location_string']) : '',
                        "category_enabled"                    => isset($_POST['category_enabled']) ? 1 : 0,
                        "category_widget_id"                  => !empty($_POST['category_widget_id']) ? trim($_POST['category_widget_id']) : '',
                        "category_placement"                  => !empty($_POST['category_placement']) ? trim($_POST['category_placement']) : '',
                        "category_location_string_occurrence" => $this->nullable_int($_POST['category_location_string_occurrence'] ?? null),
                        "category_location_string"            => !empty($_POST['category_location_string']) ? trim($_POST['category_location_string']) : '',

                    );

                    $is_valid_nonce = false;

                    if (isset( $_POST['my_plugin_nonce']) && wp_verify_nonce( $_POST['my_plugin_nonce'], 'my_plugin_update_field_action' ) ) {
                        $is_valid_nonce = true;
                    }

                    if ($is_valid_nonce) {
                        if($settings == NULL){
                            $saved = $wpdb->insert($this->tbl_taboola_settings, $data);
                        } else {
                            $saved = $wpdb->update($this->tbl_taboola_settings, $data, array('id' => $settings->id));
                        }

                        // update() returns 0 when nothing changed; only false is a failure.
                        if ($saved === false) {
                            $taboola_save_error = "The database rejected the write, so your changes were not saved: "
                                . ($wpdb->last_error !== '' ? $wpdb->last_error : 'unknown database error');
                        }
                    } else {
                        $taboola_save_error = "Security check failed - the settings page had been open too long. Reload it and apply your changes again.";
                    }
                }
                $settings = $wpdb->get_row("select * from ".$wpdb->prefix."_taboola_settings limit 1");
            }
            include_once('settings.php');
        }
            
        function is_mid_location_string_valid($mid_location_string){
            // TODO:: validate the location string
            return true;
        }

        function is_mid_location_string_occurrence_valid($mid_location_string_occurrence){

            if ((int)$mid_location_string_occurrence >= 1) {
                return true;
            }
            return false;
        }

        function is_home_location_string_valid($home_location_string){
            // TODO:: validate the location string
            return true;
        }

        function is_home_location_string_occurrence_valid($home_location_string_occurrence){

            if ((int)$home_location_string_occurrence >= 1) {
                return true;
            }
            return false;
        }

        function columnExists($column_name) {
            global $wpdb;
            $table_name = $wpdb->prefix . "_taboola_settings";
            tb_write_log("table name : " . $table_name);
            tb_write_log("column name : " . $column_name);

            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE '$column_name'") !== null;

            if ($column_exists) {
                tb_write_log("Yes - column " . $column_name . " DOES exist!"); //PC
                return true;
            } else {
                tb_write_log("No - column " . $column_name . " does NOT exist!"); //PC
                return false;
            }
        }

        function is_upgrade_from_v1() {          

            if($this->columnExists("first_bc_widget_id") && !$this->columnExists("first_bc_placement")){
                tb_write_log("This IS a v1 upgrade!"); //PC
                return true;
            } 
            else {
                tb_write_log("This is NOT a v1 upgrade!"); //PC
                return false;
            }
        }

        function is_db_updated_for_min_ver($min_ver) {
            global $wpdb;
            $table_name = $wpdb->prefix . "_taboola_settings";

            if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                tb_write_log("Table NOT FOUND");
                return false;
            }
            else {
                tb_write_log("Table EXISTS");
            }
            $saved_version = get_option(TABOOLA_OPTION_NAME, '1.0.0');
            
            $db_is_up_to_date = version_compare($saved_version, $min_ver, '>=');
            return $db_is_up_to_date;
  
        }          
      
        function is_db_updated_for_current_ver() {
            global $wpdb;
            $saved_version = get_option(TABOOLA_OPTION_NAME, '1.0.0');
            $plugin_version = $this->get_loaded_plugin_version();
            $db_is_up_to_date = version_compare($saved_version, $plugin_version, '>=');
            return $db_is_up_to_date;
        }        

        function get_loaded_plugin_version() {
            $plugin_data = get_plugin_data( __FILE__ ); 
            $loaded_plugin_version = $plugin_data['Version'];
            return $loaded_plugin_version;
        }

        function save_taboola_version($min_ver) {
            tb_write_log("SAVING NEW MIN VERSION: " . $min_ver); // PC
            $saved_version = get_option(TABOOLA_OPTION_NAME, '');
            if ($saved_version == '') {
                add_option(TABOOLA_OPTION_NAME, $min_ver);
            }
            else {
                update_option(TABOOLA_OPTION_NAME, $min_ver);
            }
        }

        function update_db(){
            if ($this->is_db_updated_for_min_ver(TABOOLA_MIN_VER)) {
                return;
            }
            
            $this->save_taboola_version(TABOOLA_MIN_VER);
            
            global $wpdb;
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $charset_collate = '';
            if ( ! empty( $wpdb->charset ) ) {
                $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
            }
            if ( ! empty( $wpdb->collate ) ) {
                $charset_collate .= " COLLATE {$wpdb->collate}";
            }

            $is_upgrade_from_v1 = $this->is_upgrade_from_v1();
            $sql_table_settings = "
                CREATE TABLE `" . $wpdb->prefix . "_taboola_settings` (
                    `id` INT NOT NULL AUTO_INCREMENT ,
                    `publisher_id` VARCHAR(255) DEFAULT NULL,
                    `web_push_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `publisher_id_push` INT(15) DEFAULT NULL,
                    `first_bc_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `first_bc_widget_id` VARCHAR(255) DEFAULT NULL,
                    `first_bc_placement` VARCHAR(255) DEFAULT " . ($is_upgrade_from_v1 ? "'below-article'" : "NULL") .",
                    `out_of_content_enabled` TINYINT(1) NOT NULL DEFAULT TRUE,
                    `mid_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `mid_widgets` TEXT DEFAULT NULL,
                    `home_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `home_widget_id` VARCHAR(255) DEFAULT NULL,
                    `home_placement` VARCHAR(255) DEFAULT NULL,
                    `home_location_string` TEXT DEFAULT NULL,
                    `home_location_string_occurrence` SMALLINT DEFAULT NULL,
                    `category_enabled`               TINYINT(1)  NOT NULL DEFAULT FALSE,
                    `category_widget_id`             VARCHAR(255) DEFAULT NULL,
                    `category_placement`             VARCHAR(255) DEFAULT NULL,
                    `category_location_string`       TEXT         DEFAULT NULL,
                    `category_location_string_occurrence` SMALLINT DEFAULT NULL,
                    PRIMARY KEY (`id`)
                ) $charset_collate;";
                dbDelta($sql_table_settings);
        }
    }
}

global $taboolaWP;
$taboolaWP = new TaboolaWP();

function tb_write_log($log_msg)
{
    if (!TABOOLA_DEBUG_MODE) 
        return;
       
    $log_filename = "logs";
    if (!file_exists($log_filename))
    {
        mkdir($log_filename, 0777, true);
    }
    $log_file_data = $log_filename.'/debug.log';

     $date = date('Y-m-d H:i:s');
    $log_msg_with_date = $date." : ".$log_msg;

    file_put_contents($log_file_data, $log_msg_with_date . "\n", FILE_APPEND);
}

function tb_console_log( $data ){

    If(wp_get_environment_type() === 'development') {

        echo '<script>';
        echo 'console.log('. json_encode( $data ) .')';
        echo '</script>';

    }
}

function tb_print_to_page($data) {
    If(wp_get_environment_type() === 'development') {
        print_r($data);
        die;
    }
}

function enqueue_taboola_scripts() {
    wp_register_script(
        'taboola-injector',
        plugins_url('js/js_inject.min.js', __FILE__),
        array(), 
        null,    
        true     
    );

    wp_enqueue_script('taboola-injector');
}
add_action('wp_enqueue_scripts', 'enqueue_taboola_scripts');