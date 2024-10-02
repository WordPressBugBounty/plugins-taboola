<?php
/**
 * Plugin Name: Taboola
 * Plugin URI: https://developers.taboola.com/web-integrations/docs/wordpress-plugin
 * Description: Taboola
 * Version: 2.2.2
 * Author: Taboola
 */

define ("TABOOLA_PLUGIN_VERSION","2.2.2"); // => UPDATE THIS FOR *EVERY* RELEASE (USED FOR TRACKING)
define ("TABOOLA_MIN_VER","2.2.2"); // => UPDATE THIS *ONLY* IF THIS RELEASE HAS *DB CHANGES*
define ("TABOOLA_DEBUG_MODE", false); // => SET THIS TO 'FALSE' FOR *EVERY* RELEASE (USED TO SUPRESS DEBUGGING LOGS)

define ("TABOOLA_OPTION_NAME","taboola_plugin_version"); // Note: if this release has DB changes, then the min version will be saved under 'taboola_plugin_version' in 'wp_options'.

define ("TABOOLA_XPATH_MARKER","/");
define ("TABOOLA_JS_INDICATOR","{JS}");
define ("TABOOLA_JS_MARKER","{");
define ("TABOOLA_CONTENT_FORMAT_STRING",'string');
define ("TABOOLA_CONTENT_FORMAT_SCRIPT",'script');
define ("TABOOLA_CONTENT_FORMAT_HTML",'html');


include_once('widget.php');
require_once('JavaScriptWrapper.php');


if (!class_exists('TaboolaWP')) {
    class TaboolaWP
    {
        //save internal data
        public $data = array();
        public $_is_widget_on_page;
        public $_is_head_script_loaded = false;
        private $tpl_sw = 'importScripts("https://cdn.taboola.com/webpush/tsw.js");';

        private $msg_sw_error = <<<SWE
        <p>
        The file sw.js in the root directory of Wordpress is not writable.
        Please change its permissions and try again. Otherwise replace its contents manually:
        </p>
        <pre><code>{{SW}}</code></pre>
        <p>
        Also make sure that the file is accessible at {{DOMAIN}}/sw.js        
        </p>
        SWE;

        public $plugin_name;
        public $plugin_directory;
        public $plugin_url;
        public $settings;
        public $tbl_taboola_settings;

        public function __construct()
        {
            global $wpdb;

            //initialize plugin constant
            DEFINE('TaboolaWP', true);

            $this->_is_widget_on_page = false;
            $this->_is_head_script_loaded = false;

            $this->plugin_name = plugin_basename(__FILE__);
            $this->plugin_directory = plugin_dir_path(__FILE__);
            $this->plugin_url = trailingslashit(WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__)));
            $this->settings = $wpdb->get_row("select * from ".$wpdb->prefix."_taboola_settings limit 1");

            $this->tbl_taboola_settings = $wpdb->prefix . '_taboola_settings';

            //activation function
            register_activation_hook($this->plugin_name, array(&$this, 'update_db'));
            add_action('admin_init', array(&$this, 'update_db'));

            // Enable sidebar widgets
            if ($this->settings != NULL && !empty($this->settings->publisher_id)){
                //register Taboola widget
                add_action('widgets_init',
                    function(){
                        return register_widget("WP_Widget_Taboola");
                    }
                );
            }

            //$this->should_place_tag_outside_of_content = $this->settings->out_of_content_enabled;

            if (is_admin()) {
                //add menu for plugin
                add_action( 'admin_menu', array(&$this, 'admin_generate_menu') );
                add_filter('plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2 );
            }elseif ($this->settings != NULL){
                    add_action('wp_head', array(&$this, 'taboola_header_loader_inject'));
                    if (!empty($this->settings->publisher_id_push)) {
                        add_action('wp_head', array(&$this, 'taboola_webpush_loader_js'));

                        $sw = 'sw.js';
                        $sw_path = ABSPATH . $sw;
                        
                        $content = file_exists($sw_path) ? file_get_contents($sw_path) : '';
                        
                        if (strpos($content, $this->tpl_sw) === false) {
                            if (!is_writable(ABSPATH) || (file_exists($sw_path) && !is_writable($sw_path))) {
                                return $this->notice($this->msg_sw_error);
                            }
                            $content = $this->tpl_sw . PHP_EOL . $content;
                            if (file_put_contents($sw_path, $content) === false) {
                                return $this->notice($this->msg_sw_error);
                            }
                        }
                    }
                    add_action('wp_footer', array(&$this, 'taboola_footer_loader_js'));
                    add_filter('the_content', array(&$this, 'load_taboola_content'));
                    add_filter('the_content', array(&$this, 'load_taboola_content_mid'));
                    add_filter('the_content', array(&$this, 'load_taboola_content_home'));
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
            $retVal1 = ((trim($this->settings->publisher_id) != '') && is_single() && $this->settings->mid_enabled && trim($this->settings->mid_widget_id) != '');
            return $retVal1;
        }

        private function should_show_content_widget_home(){
            // PC - only if v2 was installed:
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
            return  $this->should_show_content_widget() || $this->should_show_content_widget_mid() || $this->should_show_content_widget_home() || $this->should_show_sidebar_widget();
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
            $head_string = "";

            // Only adding the loader if a widget is going to be placed on the page.
            if ($this->is_widget_on_page()){
                // PC - since these params will be inserted in 'loaderInjectionScript.js' via search and replace, 
               // double brackets are used to ensure that each key is a unique string.
            	$stringParams = array(
		            '{{PUBLISHER_ID}}' => $this->settings->publisher_id,
		            '{{PAGE_TYPE}}' => $this->get_page_type(),
		            '{{WORDPRESS_VERSION}}' => get_bloginfo('version'),
                    '{{PHP_VERSION}}' => phpversion(),
		            '{{PLUGIN_VERSION}}' => TABOOLA_PLUGIN_VERSION,
                    '{{LOC_MID}}' => $this->settings->mid_location_string,
                    '{{LOC_HOME}}' => $this->settings->home_location_string
	            );

            	$scriptWrapper = new JavaScriptWrapper("loaderInjectionScript.js",$stringParams);
                $head_string = $scriptWrapper->getScriptMarkupString();
            }
            return $head_string;
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
        function load_taboola_content_mid($content)
        {
            $taboola_content_mid = array();
            if ($this->should_show_content_widget_mid()){

	                $secondWidgetParams = array('{{WIDGET_ID}}' => $this->settings->mid_widget_id,
	                    '{{CONTAINER}}' => 'taboola-mid-article-thumbnails',
	                    '{{PLACEMENT}}' =>  $this->settings->mid_placement);
                                               
	                $secondWidgetScript = new JavaScriptWrapper("widgetInjectionScript.js",$secondWidgetParams);
                    $taboola_content_mid[TABOOLA_CONTENT_FORMAT_HTML][] = "<div id='taboola-mid-article-thumbnails'></div>";
                    $taboola_content_mid[TABOOLA_CONTENT_FORMAT_SCRIPT][] = $secondWidgetScript;

                $content = $this->embed_taboola_content_location_mid($content,$taboola_content_mid,trim($this->settings->mid_location_string));
            }

            return $content;
        }

        // Homepage widget 
        function load_taboola_content_home($content)
        {
            
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

        // Below-article widget
        // Do the actual logic of choosing where to place the taboola content.
        function embed_taboola_content_location($content, $taboola_content){
            $do_default = true;

			// tag is placed outside of content in order to allow "read more" functionality.
            if ($this->settings->out_of_content_enabled){

	            $scriptWrapper = new JavaScriptWrapper("js_inject.min.js",array(
			            "{{HTML}}" => $this->format_taboola_content($taboola_content,TABOOLA_CONTENT_FORMAT_HTML),
			            "{{SCRIPT}}" => $this->format_taboola_content($taboola_content,TABOOLA_CONTENT_FORMAT_SCRIPT))
	            );
	            $scriptWrapper->appendScript("injectWidgetByMarker('tbmarker');");
            	$content = $content."<span id='tbmarker'></span><script type='text/javascript'>".$scriptWrapper."</script>";
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
        function embed_taboola_content_location_mid($content, $taboola_content_mid, $location){
            $do_default = true;

            if (isset($location) && $location != ''){
                $first_char = substr($location,0,1);

                // DIV/XPATH provided for JS handling
                if ($first_char == TABOOLA_JS_MARKER){
                    $full_indicator = substr($location,0,strlen(TABOOLA_JS_INDICATOR));

                    if ($full_indicator == TABOOLA_JS_INDICATOR){

                        $xpath = substr($location,strlen(TABOOLA_JS_INDICATOR));
                        $scriptWrapper = new JavaScriptWrapper("js_inject.min.js",array(
                            "{{HTML}}" => $this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_HTML),
                            "{{SCRIPT}}" => $this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_SCRIPT))
                        );
                        $scriptWrapper->appendScript("injectWidgetByXpath('".$xpath."');");
                        $content = $content."<span id='tbdefault'></span><script type='text/javascript'>".$scriptWrapper."</script>";

                        $do_default = false;
                    }

                // server side selector provided (see simple_html_dom selectors http://simplehtmldom.sourceforge.net/manual.htm)
                // basically it's CSS selectors like in jQuery
                } else{
                    require_once('simple_html_dom.php');

                    $html_doc = str_get_html($content);
                    $target_location = $html_doc->find($location,($this->settings->mid_location_string_occurrence)-1);

                    // if the location was found within the html content
                    if (isset($target_location) && is_object($target_location)){

                        // adding taboola content AFTER the target location
                        $target_location->outertext = $target_location->outertext.$this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_STRING);
                        $content = $html_doc;
                        $do_default = false;
                    }
                }
            }
            // Default for below-article widget - add to the end of the content
            // if ($do_default){
            //     $content = $content.$this->format_taboola_content_mid($taboola_content_mid,TABOOLA_CONTENT_FORMAT_STRING);
            // }

            return $content;
        }

        // Homepage widget
        // Do the actual logic of choosing where to place the taboola content based on the "location" attribute  
        function embed_taboola_content_location_home($content, $taboola_content_home, $location){

                $do_default = true;

                if (isset($location) && $location != ''){
                    $first_char = substr($location,0,1);

                    // DIV/XPATH provided for JS handling
                    if ($first_char == TABOOLA_JS_MARKER){
                        $full_indicator = substr($location,0,strlen(TABOOLA_JS_INDICATOR));

                        if ($full_indicator == TABOOLA_JS_INDICATOR){

                            $xpath = substr($location,strlen(TABOOLA_JS_INDICATOR));
                            $scriptWrapper = new JavaScriptWrapper("js_inject.min.js",array(
                                "{{HTML}}" => $this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_HTML),
                                "{{SCRIPT}}" => $this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_SCRIPT))
                            );
                            $scriptWrapper->appendScript("injectWidgetByXpath('".$xpath."');");
                            $content = $content."<span id='tbdefault'></span><script type='text/javascript'>".$scriptWrapper."</script>";

                            $do_default = false;
                        }

                    // server side selector provided (see simple_html_dom selectors http://simplehtmldom.sourceforge.net/manual.htm)
                    // basically it's CSS selectors like in jQuery
                    } else{
                        require_once('simple_html_dom.php');

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
                // if ($do_default){
                //     $content = $content.$this->format_taboola_content_home($taboola_content_home,TABOOLA_CONTENT_FORMAT_STRING);
                // }
                return $content;

        }

        function admin_generate_menu(){
            global $current_user;
            add_menu_page(__('Taboola','taboola_widget'), __('Taboola','taboola_widget'), 'manage_options', 'taboola_widget', array(&$this, 'admin_taboola_settings'), $this->plugin_url.'img/taboola_icon.png', 110);
        }

        function admin_taboola_settings(){
            global $wpdb;
            $settings = $wpdb->get_row("select * from ".$wpdb->prefix."_taboola_settings limit 1");
            $taboola_errors = array();
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
                    if (trim(strip_tags($_POST['mid_widget_id'])) == '') {
                        $taboola_errors[] = "Mid-article > Widget ID";
                    }
                    if (trim(strip_tags($_POST['mid_placement'])) == '') {
                        $taboola_errors[] = "Mid-article > Placement Name";
                    }
                    if (trim(strip_tags($_POST['mid_location_string'])) == '') {
                        $taboola_errors[] = "Mid-article > CSS selector";
                    }
                    else {
                        // If the 'location' WAS filled in, then...

                        // 1) Check for a valid 'location':

                        // Validation method has not been implemented
                        // mid_if  (!$this->is_locaton_string_valid1($_POST['mid_location_string'])) {
                        //     $taboola_errors[] = "Mid-article > CSS Selector (invalid value)";
                        // }

                        // 2) Validate the 'occurrence':
                        if (!$this->is_mid_location_string_occurrence_valid($_POST['mid_location_string_occurrence'])) {
                            $taboola_errors[] = "Mid-article > Occurance (must be >= 1)";
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
                        // If the 'location' WAS filled in, then...

                        // 1) Check for a valid 'location':

                        // Validation method has not been implemented
                        // if (!$this->is_home_location_string_valid($_POST['home_location_string'])) {
                        //     $taboola_errors[] = "Homepage > CSS Selector (invalid value)";
                        // }                    

                        // 2) Validate the 'occurrence':
                        if (!empty($_POST['home_location_string']) && !$this->is_home_location_string_occurrence_valid($_POST['home_location_string_occurrence'])) {
                            $taboola_errors[] = "Homepage > Occurance (must be >= 1)";
                        }
                    }
                }        
                
                if(count($taboola_errors) == 0){
                    $data = array(
                        "publisher_id" => trim($_POST['publisher_id']),

                        "web_push_enabled" => isset($_POST['web_push_enabled']) ? true : false,
                        "publisher_id_push" => !empty($_POST['publisher_id_push']) ? trim($_POST['publisher_id_push']) : '',

                        "first_bc_enabled" => isset($_POST['first_bc_enabled']) ? true : false,
                        "first_bc_widget_id" => !empty($_POST['first_bc_widget_id']) ? trim($_POST['first_bc_widget_id']) : '',
                        "first_bc_placement" => !empty($_POST['first_bc_placement']) ? trim($_POST['first_bc_placement']) : '',

                        "out_of_content_enabled" => isset($_POST['out_of_content_enabled']) ? true : false,

                        "mid_enabled" => isset($_POST['mid_enabled']) ? true : false,
                        "mid_widget_id" => !empty($_POST['mid_widget_id']) ? trim($_POST['mid_widget_id']) : '',
                        "mid_placement" => !empty($_POST['mid_placement']) ? trim($_POST['mid_placement']) : '',
                        "mid_paragraph_ui_mode" => !empty($_POST['mid_paragraph_ui_mode']) ? trim($_POST['mid_paragraph_ui_mode']) : '',

                        "mid_location_string_occurrence" => !empty($_POST['mid_location_string_occurrence']) ? $_POST['mid_location_string_occurrence'] : '',
                        "mid_location_string" => !empty($_POST['mid_location_string']) ? trim($_POST['mid_location_string']) : '',

                        "home_enabled" => isset($_POST['home_enabled']) ? true : false,
                        "home_widget_id" => !empty($_POST['home_widget_id']) ? trim($_POST['home_widget_id']) : '',
                        "home_placement" => !empty($_POST['home_placement']) ? trim($_POST['home_placement']) : '',

                        "home_location_string_occurrence" => !empty($_POST['home_location_string_occurrence']) ? $_POST['home_location_string_occurrence'] : '',
                        "home_location_string" => !empty($_POST['home_location_string']) ? trim($_POST['home_location_string']) : ''
                    );

                    //var_dump($settings);

                    $is_valid_nonce = false;

                    if (isset( $_POST['my_plugin_nonce']) && wp_verify_nonce( $_POST['my_plugin_nonce'], 'my_plugin_update_field_action' ) ) {
                        $is_valid_nonce = true;
                    }

                    if ($is_valid_nonce) {
                        if($settings == NULL){
                            $wpdb->insert($this->tbl_taboola_settings, $data, null, null);
                        } else {
                            $wpdb->update($this->tbl_taboola_settings, $data, array('id' => $settings->id));
                        }
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
            /**
             * Checks if the DB was *previously* updated for the minimum version specified.
             * $min_ver should be passed in a format like this: "2.1.0"
             */

            global $wpdb;

            // PC - Temporary patch for v2.1.0

            // START patch ===============================================
            // Check if the `_taboola` table exists.
            // If not, then return false.

            $table_name = $wpdb->prefix . "_taboola_settings";

            if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                tb_write_log("Table NOT FOUND");
                return false;
            }
            else {
                tb_write_log("Table EXISTS");
            }
            // END patch =================================================

            // Check the saved version in wp_options. Default to '1.0.0' if the option is not found.
            $saved_version = get_option(TABOOLA_OPTION_NAME, '1.0.0');
            
            $db_is_up_to_date = version_compare($saved_version, $min_ver, '>=');
            //tb_write_log($db_is_up_to_date);
            return $db_is_up_to_date;
  
        }          
      
        function is_db_updated_for_current_ver() {
            /** 
             * DEPRECATED
             * Checks if the DB was *previously* updated for the version currently loaded.
            */
            global $wpdb;

            // Check the saved version in wp_options. Default to '1.0.0' if the option is not found.
            $saved_version = get_option(TABOOLA_OPTION_NAME, '1.0.0');
            
            // Get the currently loaded plugin version.
            $plugin_version = $this->get_loaded_plugin_version();

            $db_is_up_to_date = version_compare($saved_version, $plugin_version, '>=');
            //tb_write_log($db_is_up_to_date);
            return $db_is_up_to_date;
  
        }        

        function get_loaded_plugin_version() {

            $plugin_data = get_plugin_data( __FILE__ ); // Can be invoked from Admin page only
            $loaded_plugin_version = $plugin_data['Version'];
            return $loaded_plugin_version;

        }

        function save_taboola_version($min_ver) {
            /**
             *  Checks for the saved version in wp_options. 
             *  If not found - adds it. Else - updates it.
             */
            

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
            
            // If we are up to date, then skip this method...
            if ($this->is_db_updated_for_min_ver(TABOOLA_MIN_VER)) {
                //tb_write_log("All up to date!");
                return;
            }
            
            $this->save_taboola_version(TABOOLA_MIN_VER);
            
            global $wpdb;
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            

            // Handling for older MySQL versions
            if (function_exists('mysql_get_server_info') && version_compare(mysql_get_server_info(), '4.1.0', '>=')) {
                if (!empty($wpdb->charset))
                    $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
                if (!empty($wpdb->collate))
                    $charset_collate .= " COLLATE $wpdb->collate";
            }

            // Check if this is an upgrade from v1
            $is_upgrade_from_v1 = $this->is_upgrade_from_v1();

            // tb_write_log("Is upgrade from v1: " . ($is_upgrade_from_v1 ? 'true' : 'false'));


            //settings table structure
            $sql_table_settings = "
                CREATE TABLE `" . $wpdb->prefix . "_taboola_settings` (
                    `id` INT NOT NULL AUTO_INCREMENT ,
                    `publisher_id` VARCHAR(255) DEFAULT NULL,
                    `web_push_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `publisher_id_push` INT(15) DEFAULT NULL,
                    `first_bc_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `first_bc_widget_id` VARCHAR(255) DEFAULT NULL,
                    `first_bc_placement` VARCHAR(255) DEFAULT " . ($is_upgrade_from_v1 ? "'below-article'" : "NULL") .",
                    `first_bc_custom_css` TEXT DEFAULT NULL,
                    `second_bc_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `second_bc_widget_id` VARCHAR(255) DEFAULT NULL,
                    `second_bc_custom_css` TEXT DEFAULT NULL,
                    `location_string` TEXT DEFAULT NULL,
                    `mid_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `mid_widget_id` VARCHAR(255) DEFAULT NULL,
                    `mid_placement` VARCHAR(255) DEFAULT NULL,
                    `out_of_content_enabled` TINYINT(1) NOT NULL DEFAULT TRUE,
                    `mid_location_string` TEXT DEFAULT NULL,
                    `mid_location_string_occurrence` SMALLINT DEFAULT NULL,
                    `mid_paragraph_ui_mode` VARCHAR(255) DEFAULT NULL,
                    `home_enabled` TINYINT(1) NOT NULL DEFAULT FALSE,
                    `home_widget_id` VARCHAR(255) DEFAULT NULL,
                    `home_placement` VARCHAR(255) DEFAULT NULL,
                    `home_location_string` TEXT DEFAULT NULL,
                    `home_location_string_occurrence` SMALLINT DEFAULT NULL,
                    PRIMARY KEY (`id`)
                )" . $charset_collate . ";";
                
                // tb_write_log($sql_table_settings);

                // create/update the table
                dbDelta($sql_table_settings);
        }
    }
}

global $taboolaWP;
$taboolaWP = new TaboolaWP();

/*
A few utility methods for logging
*/

// Writes debugging messages to 'logs/debug.log'.
// The exact file location depends on *where* you trigger the function.
// In this script, we trigger the function from the admin dashboard.
// So the log file is located under 'wp-admin/logs'.
function tb_write_log($log_msg)
{
    $log_filename = "logs";
    if (!file_exists($log_filename))
    {
        mkdir($log_filename, 0777, true);
    }
    $log_file_data = $log_filename.'/debug.log';

    // Add date/time
     $date = date('Y-m-d H:i:s');
    $log_msg_with_date = $date." : ".$log_msg;
    
    if (TABOOLA_DEBUG_MODE)
        file_put_contents($log_file_data, $log_msg_with_date . "\n", FILE_APPEND);
   
}

// Write to console
function tb_console_log( $data ){

    If(wp_get_environment_type() === 'development') {

        echo '<script>';
        echo 'console.log('. json_encode( $data ) .')';
        echo '</script>';

    }
}

// Write to webpage and stop
function tb_print_to_page($data) {
    If(wp_get_environment_type() === 'development') {
        print_r($data);
        die;
    }
}

//
