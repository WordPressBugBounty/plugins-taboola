<?php
define ("TABOOLA_WIDGET_BASE_ID","taboola");
define ("TABOOLA_CONTAINER_PREFIX","inner-");
class WP_Widget_Taboola extends WP_Widget {

    private static $counter;
    function __construct() {


        $widget_ops = array('classname' => 'widget_taboola', 'description' => __( "A taboola widget for your site.") );
        parent::__construct( TABOOLA_WIDGET_BASE_ID, _x( 'Taboola Widget', 'Taboola Widget' ), $widget_ops );
        $this->plugin_directory = plugin_dir_path(__FILE__);
    }

    function get_id($tag){

        $start_pos = strpos($tag,"id=");

        $container_id = null;
        if ($start_pos !== false){

            $end_pos = strpos($tag," ",$start_pos);
            $extracted_id = str_replace(array('"',"'"),"",substr($tag,$start_pos+3,$end_pos-$start_pos-3));
            if ($extracted_id != ""){
                $container_id = $extracted_id;
            }
        }
        return $container_id;
    }

    function widget( $args, $instance ) {
        if (!isset(WP_Widget_Taboola::$counter)){
            WP_Widget_Taboola::$counter = 1;
        }
        else{
            WP_Widget_Taboola::$counter = WP_Widget_Taboola::$counter + 1;
        }
        if (trim($instance['widget_id']) == ''){
            return;
        }
        extract($args);

        /** This filter is documented in wp-includes/default-widgets.php */
        $title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'], $instance, $this->id_base );

        $tmp_id = explode("-",$args["widget_id"]);
        $widget_num = $tmp_id[1];

        $widget_id = ! empty( $instance['widget_id'] ) ? $instance['widget_id'] : '';

        // Prefix each placement with "WP Right Rail". Grab the ID of the container (i.e. the sidebar) and append 'widget_num' to make it unique.
        $placement = "WP Right Rail (". $args["id"]."-".$widget_num.")";

        echo $before_widget;

        if ( $title ){
            echo $before_title . $title . $after_title;
        }

        $container = $this->get_id($before_widget);

        if ($container == null){
            // widget container (adding a div)
            $container = TABOOLA_CONTAINER_PREFIX.$this->id;
            echo '<div id="'.$container.'"></div>';
        }

        //widget content
        $stringParams = array(
            "{{WIDGET_ID}}" => $widget_id,
            "{{CONTAINER}}" => $container,
            "{{PLACEMENT}}" => $placement
        );
        $widgetInjectionScript_content = str_replace(array_keys($stringParams), array_values($stringParams), file_get_contents($this->plugin_directory.'js/widgetInjectionScript.js'));
        echo '<script type="text/javascript">'.$widgetInjectionScript_content.'</script>';

        echo $after_widget;
    }

    function form( $instance ) { 
        $instance = wp_parse_args( (array) $instance );
        $widget_id = esc_attr( $instance['widget_id'] );
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('widget_id'); ?>"><?php _e('Mode (Widget ID):'); ?>
                <input class="widefat" id="<?php echo $this->get_field_id('widget_id'); ?>" name="<?php echo $this->get_field_name('widget_id'); ?>" type="text" value="<?php echo esc_attr($widget_id); ?>" />
            </label>
        </p>
    <?php
    }

    function update( $new_instance, $old_instance ) {

        // canceling save if the field is empty
        if (strip_tags($new_instance['widget_id']) == ""){
            return false;
	    }

        $instance = $old_instance;
        $instance['widget_id'] = strip_tags($new_instance['widget_id']);

        return $instance;
    }

}
