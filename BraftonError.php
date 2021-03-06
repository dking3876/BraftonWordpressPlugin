<?php 
/*
 * Brafton Error Class
 * rewrite for seperate function to get the erros currently logged, add new error function
 */
//for debugging.  Displays 
class BraftonErrorReport {
    
    /*
     *$url Current url location
     */
    private $url;
    /*
     *$e_key Encryption key for verification for the error logging api
     */
    private $e_key;
    /*
     *$post_url Url location for error reporting with $e_key as [GET] Parameter
     */
    private $post_url;
    /*
     *$section Current sectoin reporting the error set by passing variable to the set_section method
     */
    private $section;
    /*
     *$level current brafton level of severity set by passing int variable to the set_level method
     */
    public $level;
    
    public $debug;
    
    private $domain;
    //Construct our error reporting functions
    public function __construct($api, $brand, $debug){
        $this->debug = $debug;
        $this->url = $_SERVER['REQUEST_URI'];
        $this->domain = $_SERVER['HTTP_HOST'];
        $this->api = $api;
        $this->brand = $brand;
        $this->e_key = 'ucocfukkuineaxf2lzl3x6h9';
        $this->post_url = 'http://updater.cl-subdomains.com/errorlog/wordpress/error/'.$this->e_key;
        $this->level = 1;
        $this->section = 'error initialize';
        register_shutdown_function(array($this,  'check_for_fatal'));
        set_error_handler(array($this, 'log_error') );
        set_exception_handler(array($this, 'log_exception'));
        ini_set( "display_errors", 0 );
        error_reporting( E_ALL );
        

        
    }
    //handles the error log page
    static function errorPage(){
        if($_POST['braftonClearLog'] == 1){
            delete_option('brafton_e_log');
        }
        $_POST['braftonClearLog'] = 0;
        $save = BraftonOptions::saveAllOptions();
    }
    //Sets the current section reporting the error periodically set by the article and video loops themselves
    public function set_section($sec){
        $this->section = $sec;   
    }
    //sets the current level of error reporting used to determine if remote sending is enabled periodically upgraded during article and video loops from 1 (critical error script stopped running) -> 5 (minor error script continued but something happened.)
    public function set_level($level){
        $this->level = $level;
    }
    //upon error being thrown log_error fires off to throw an exception erro
    public function log_error( $num, $str, $file, $line, $context = null )
    {
        $this->log_exception( new ErrorException( $str, 0, $num, $file, $line ) );
    }
    //retrieves the current error log from the db returns an array of current logs
    private function b_e_log(){
        if(!$brafton_error = get_option('brafton_e_log')){
            add_option('brafton_e_log');
            $brafton = null;
        }
        else{
            $brafton_error = get_option('brafton_e_log');
            $brafton = $brafton_error;
        }
        return $brafton;
    }
    //Known minor Errors occuring from normal operation.
    public function check_known_errors($e){
        switch(basename($e->getFile())){
            case 'link-template.php':
            return false;
            break;
            case 'post.php':
            return false;
            break;
            case 'class-wp-image-editor-imagick.php':
            return false;
            break;
            default:
            return true;
        }
    }
    //workhorse of the error reporting.  This function does the heavy lifting of logging the error and sending an error report
    public function log_exception( Exception $e ){

        //if severity == 1 (script stop running error) and the error was not part of one of the below know issues for those pages runs error reporting. 
        if ( ($e->getseverity() == 1) || ($this->debug) || ($this->check_known_errors($e))  ){


            $brafton_error = $this->b_e_log();
            $errorlog = array(
                'Domain'    => $this->domain,
                'API'       => $this->api,
                'Brand'     => $this->brand,
                'client_sys_time'  => date(get_option('date_format')) . " " . date("H:i:s"),
                'error'     => get_class($e).' : '.$e->getseverity().' | '.$e->getMessage().' in '.$e->getFile().' on line '.$e->getLine().' brafton_level '.$this->level.' in section '.$this->section
            );
            $brafton_error[] = $errorlog;
            update_option('brafton_e_log', $brafton_error);
            $errorlog = json_encode($errorlog);
            $post_args = array(
                'body' => array(
                    'error' => $errorlog
                )
            );
            //$this->level = 2;
            if($e->getseverity() == 1 || ($config['debug'] == true && $this->level == 1)){
                $make_report = wp_remote_post($this->post_url, $post_args);
                header("LOCATION:$this->url&b_error=vital");
            }
            return;
        }
        else{
            return;
        }
        exit();
    }

    //function for checking if fatal error has occured and trigger the error flow
    public function check_for_fatal(){
        $error = error_get_last();
        if ( $error["type"] == E_ERROR )
            $this->log_error( $error["type"], $error["message"], $error["file"], $error["line"] );
    }

}
?>