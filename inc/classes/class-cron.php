<?php
/*
 * Pligin CRON class
 * 
 * @package WALLETOR_GATE
 */

namespace WALLETOR_GATE\Inc;

use WALLETOR_GATE\Inc\Traits\Singleton;

class Cron {
    
    use Singleton;
    
    //Construct function
    protected function __construct() {
        
        //load class
        $this->setup_hooks();
    }
    
    /*
     * Function to load action and filter hooks
     */
    protected function setup_hooks() {
        
        //actions and filters   
        add_filter( 'cron_schedules', [ $this, 'cron_interval' ] );        
        add_action( WALLETOR_GATE_TEXT_DOMAIN.'_cron_event', [ $this, 'run_cron' ] );
    }
    
    /*
     * Cron event hook that will extecuted at custom interval
     */
    public function run_cron() {
        
        $wc_get = WC_Gate::get_instance();
        $wc_get->update_orders_status();
    }
    
    /*
     * Function to add custom CRON interval
     * 
     * @param $schedules array of WP schedules
     * 
     * @return $schedules updated array of schedules
     */
    public function cron_interval( $schedules ) {
        
        $schedules[WALLETOR_GATE_TEXT_DOMAIN.'_cron_interval'] = array(
            'interval' => 60*5, //5 minutes
            'display'  => esc_html__( 'Every 5 Minutes' ), WALLETOR_GATE_TEXT_DOMAIN );
        
        return $schedules;
    }
}