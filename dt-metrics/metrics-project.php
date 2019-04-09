<?php

Disciple_Tools_Metrics_Project::instance();
class Disciple_Tools_Metrics_Project extends Disciple_Tools_Metrics_Hooks_Base
{
    public $permissions = [ 'view_any_contacts', 'view_project_metrics' ];
    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );

        if ( !$this->has_permission() ){
            return;
        }

        $url_path = dt_get_url_path();
        if ( 'metrics' === substr( $url_path, '0', 7 ) ) {

            add_filter( 'dt_templates_for_urls', [ $this, 'add_url' ] ); // add custom URL
            add_filter( 'dt_metrics_menu', [ $this, 'add_menu' ], 50 );

            if ( 'metrics/project' === $url_path ) {
                add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ], 99 );
            }
        }
    }

    public function add_url( $template_for_url ) {
        $template_for_url['metrics/project'] = 'template-metrics.php';
        return $template_for_url;
    }

    public function add_menu( $content ) {
        $content .= '
            <li><a href="">' .  esc_html__( 'Project', 'disciple_tools' ) . '</a>
                <ul class="menu vertical nested" id="project-menu" aria-expanded="true">
                    <li><a href="'. site_url( '/metrics/project/' ) .'#project_overview" onclick="project_overview()">'. esc_html__( 'Overview', 'disciple_tools' ) .'</a></li>
                    <li><a href="'. site_url( '/metrics/project/' ) .'#group_tree" onclick="project_group_tree()">'. esc_html__( 'Group Tree', 'disciple_tools' ) .'</a></li>
                    <li><a href="'. site_url( '/metrics/project/' ) .'#baptism_tree" onclick="project_baptism_tree()">'. esc_html__( 'Baptism Tree', 'disciple_tools' ) .'</a></li>
                    <li><a href="'. site_url( '/metrics/project/' ) .'#coaching_tree" onclick="project_coaching_tree()">'. esc_html__( 'Coaching Tree', 'disciple_tools' ) .'</a></li>
                    <!-- <li><a href="'. site_url( '/metrics/project/' ) .'#project_locations" onclick="project_locations()">'. esc_html__( 'Locations' ) .'</a></li> -->
                </ul>
            </li>
            ';
        return $content;
    }

    public function scripts() {
        wp_register_script( 'amcharts-core', 'https://www.amcharts.com/lib/4/core.js', false, '4' );
        wp_register_script( 'amcharts-charts', 'https://www.amcharts.com/lib/4/charts.js', false, '4' );
        wp_enqueue_script( 'dt_metrics_project_script', get_stylesheet_directory_uri() . '/dt-metrics/metrics-project.js', [
            'jquery',
            'jquery-ui-core',
            'amcharts-core',
            'amcharts-charts',
        ], filemtime( get_theme_file_path() . '/dt-metrics/metrics-project.js' ), true );

        wp_localize_script(
            'dt_metrics_project_script', 'dtMetricsProject', [
                'root' => esc_url_raw( rest_url() ),
                'theme_uri' => get_stylesheet_directory_uri(),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'current_user_login' => wp_get_current_user()->user_login,
                'current_user_id' => get_current_user_id(),
                'map_key' => dt_get_option( 'map_key' ),
                'data' => $this->data(),
            ]
        );
    }

    public function data() {
        return [
            'hero_stats' => self::chart_project_hero_stats(),
            'contacts_progress' => self::chart_contacts_progress( 'project' ),
            'group_types' => self::chart_group_types( 'project' ),
            'group_health' => self::chart_group_health( 'project' ),
            'group_generations' => self::chart_group_generations( 'project' ),
            'group_generation_tree' => $this->get_group_generations_tree(),
            'baptism_generation_tree' => $this->get_baptism_generations_tree(),
            'coaching_generation_tree' => $this->get_coaching_generations_tree(),
//            'location_hero_stats' => Disciple_Tools_Queries::instance()->tree( 'locations_hero_stats' ),

        ];
    }

    /**
     * API Routes
     */
    public function add_api_routes() {
        $version = '1';
        $namespace = 'dt/v' . $version;
        register_rest_route(
            $namespace, '/metrics/project/tree', [
                [
                    'methods'  => WP_REST_Server::CREATABLE,
                    'callback' => [ $this, 'tree' ],
                ],
            ]
        );
    }


    public function tree( WP_REST_Request $request ) {
        if ( !$this->has_permission() ){
            return new WP_Error( __METHOD__, "Missing Permissions", [ 'status' => 400 ] );
        }
        $params = $request->get_params();

        if ( isset( $params['type'] ) ) {
            switch ( $params['type'] ) {
                case 'groups':
                    return $this->get_group_generations_tree();
                    break;
                case 'baptisms':
                    return $this->get_baptism_generations_tree();
                    break;
                case 'coaching':
                    return $this->get_coaching_generations_tree();
                    break;
                case 'location':
                    return $this->get_locations_tree();
                    break;
                default:
                    return new WP_Error( __METHOD__, "No matching type set.", [ 'status' => 400 ] );
                    break;
            }
        } else {
            return new WP_Error( __METHOD__, "No type set.", [ 'status' => 400 ] );
        }
    }

    public function get_group_generations_tree(){
        $query = dt_queries()->tree( 'multiplying_groups_only' );
        if ( empty( $query ) ) {
            return $this->_no_results();
        }
        $menu_data = $this->prepare_menu_array( $query );
        return $this->build_group_tree( 0, $menu_data, 0 );
    }

    public function get_baptism_generations_tree(){
        $query = dt_queries()->tree( 'multiplying_baptisms_only' );
        if ( empty( $query ) ) {
            return $this->_no_results();
        }
        $menu_data = $this->prepare_menu_array( $query );
        return $this->build_menu( 0, $menu_data, -1 );
    }

    public function get_coaching_generations_tree(){
        $query = dt_queries()->tree( 'multiplying_coaching_only' );
        if ( empty( $query ) ) {
            return $this->_no_results();
        }
        $menu_data = $this->prepare_menu_array( $query );
        return $this->build_menu( 0, $menu_data, -1 );
    }

    public function get_locations_tree() {
        $query = dt_queries()->tree( 'locations' );
        if ( empty( $query ) ) {
            return $this->_no_results();
        }
        $menu_data = $this->prepare_menu_array( $query );
        return $this->build_location_tree( 0, $menu_data, 0 );
    }

    public function _no_results() {
        return '<p>'. esc_attr( 'No Results', 'disciple_tools' ) .'</p>';
    }

    /**
     * Prepares the id, parent_id list into a nested array
     *
     * @param $query
     *
     * @return array
     */
    public function prepare_menu_array( $query) {
        // prepare special array with parent-child relations
        $menu_data = array(
            'items' => array(),
            'parents' => array()
        );

        foreach ( $query as $menu_item )
        {
            $menu_data['items'][$menu_item['id']] = $menu_item;
            $menu_data['parents'][$menu_item['parent_id']][] = $menu_item['id'];
        }
        return $menu_data;
    }

    /**
     * Recursively builds html tree from nested array data.
     *
     * @param $parent_id
     * @param $menu_data
     * @param $gen
     *
     * @return string
     */
    public function build_menu( $parent_id, $menu_data, $gen) {
        $html = '';

        if (isset( $menu_data['parents'][$parent_id] ))
        {
            $gen++;

            $first_section = '';
            if ( $gen === 0 ) {
                $first_section = 'first-section';
            }

            $html = '<ul class="ul-gen-'.$gen.'">';
            foreach ($menu_data['parents'][$parent_id] as $item_id)
            {
                $html .= '<li class="gen-node li-gen-'.$gen.' '.$first_section.'">';
                $html .= '('.$gen.') ';
                $html .= '<strong><a href="'. site_url( "/groups/" ).$item_id.'">'. $menu_data['items'][$item_id]['name'] . '</a></strong><br>';

                // find childitems recursively
                $html .= $this->build_menu( $item_id, $menu_data, $gen );

                $html .= '</li>';
            }
            $html .= '</ul>';

        }
        return $html;
    }

    public function build_group_tree( $parent_id, $menu_data, $gen) {
        $html = '';

        if (isset( $menu_data['parents'][$parent_id] ))
        {
            $first_section = '';
            if ( $gen === 0 ) {
                $first_section = 'first-section';
            }

            $html = '<ul class="ul-gen-'.$gen.'">';
            $gen++;
            foreach ($menu_data['parents'][$parent_id] as $item_id)
            {
                $html .= '<li class="gen-node li-gen-'.$gen.' '.$first_section.'">';
                $html .= '<span class="'.$menu_data['items'][$item_id]['group_status'].' '.$menu_data['items'][$item_id]['group_type'].'">('.$gen.') ';
                $html .= '<a onclick="open_modal_details('.$item_id.');">'. $menu_data['items'][$item_id]['name'] . '</a></span>';

                $html .= $this->build_group_tree( $item_id, $menu_data, $gen );

                $html .= '</li>';
            }
            $html .= '</ul>';

        }
        return $html;
    }

    public function build_location_tree( $parent_id, $menu_data, $gen) {
        $html = '';

        if (isset( $menu_data['parents'][$parent_id] ))
        {
            $first_section = '';
            if ( $gen === 0 ) {
                $first_section = 'first-section';
            }

            $html = '<ul class="ul-gen-'.$gen.'">';
            $gen++;
            foreach ($menu_data['parents'][$parent_id] as $item_id)
            {
                $html .= '<li class="gen-node li-gen-'.$gen.' '.$first_section.'">';
                $html .= '<a onclick="open_location_modal_details('.$item_id.');">'. $menu_data['items'][$item_id]['name'] . '</a>';

                $html .= $this->build_location_tree( $item_id, $menu_data, $gen );

                $html .= '</li>';
            }
            $html .= '</ul>';

        }
        return $html;
    }
}
