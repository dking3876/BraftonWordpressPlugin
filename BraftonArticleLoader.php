<?php
require 'libs/APIClientLibrary/ApiHandler.php';

class BraftonArticleLoader extends BraftonFeedLoader {
    //set as costants instead 
    private $API_Domain;
    private $API_Key;
    private $articles;
    private $fh;
    private $counter;
    private $connection;
    
    public function __construct(){
        parent::__construct();
        //set the url and api key for use during the entire run.
        $this->API_Domain = 'http://'.$this->options['braftonApiDomain'];
        $this->API_Key = $this->options['braftonApiKey'];
        $this->connection = new ApiHandler($this->API_Key, $this->API_Domain);
        
    }
    //method for full import of articles
    public function ImportArticles(){
        //Gets the feed for importing
        $this->getArticleFeed();
        //Gets the complete category tree and adds any new categories
        $this->ImportCategories();
        //imports each article in the feed
        $this->runLoop();
    }
    public function loadXMLArchive(){
        echo "Archive Option Selected<br/>";
		$this->articles = NewsItem::getNewsList($_FILES['archive']['tmp_name'], "html");
        $this->runLoop();
    }
    public function getArticleFeed(){
        $this->articles = $this->connection->getNewsHTML();
    }
    //Imports complete list of categories with child categories.  
    //TODO: Still need to create algorythm for joining the sames in a string to search for matches of parent child categories for use with the same child name as other child names.  all child categories that are in the db after the first imported one take on the parent slug as an appended variable to the child name turned into a slug.
    public function ImportCategories(){
        global $wpdb;
        $this->errors->set_section('Importing Categories');
        $CatColl = $this->connection->getCategoryDefinitions();
        $custom_cat = explode(',',$this->options['braftonCustomCategories']);
        foreach ($CatColl as $c){
				$category = $wpdb->escape($c->getName());
                $cat_id = wp_create_category($category);
            foreach($c->child as $child){
                wp_create_category($child['name'], $cat_id);   
            }
        }
        foreach($custom_cat as $cat){
                wp_create_category($cat);
        }
        
    }
    //Assigns the categories listed for the post to the post including any custom categories.
    private function assignCategories($obj){
        global $wpdb;
        $this->errors->set_section('assign categories');
        $cats = array();
        $CatColl = $obj->getCategories();
        $custom_cat = explode(',',$this->options['braftonCustomCategories']);
        if($this->options['braftonCategories'] == 'categories'){
            foreach($CatColl as $cat){
                $slugObj = get_category_by_slug($wpdb->escape($cat->getName()));
                $cats[] = $slugObj->term_id;
            }
        }
        foreach($custom_cat as $cat){
            if($slugObj = get_category_by_slug($wpdb->escape($cat))){
                $cats[] = $slugObj->term_id;
            }
        }
        return $cats;
    }
    //Assigns the tags based on the option selected for the importer
    private function assignTags($obj){
        
    }
    
    public function runLoop(){
        global $level, $wpdb, $post, $wp_rewrite;
        $this->errors->set_section('master loop');
        $article_count = count($this->articles);
        $counter = 0;
        foreach($this->articles as $article){//start individual article loop
            if($counter == 30){ return; }
            $brafton_id = $article->getId();
            if(!($post_id = $this->brafton_post_exists($brafton_id)) || $this->override){//Start actual importing
                $this->errors->set_section('individual article loop');
                set_time_limit(60);
                $post_title = $article->getHeadline();
                $post_content = $article->getText();
                //format the content for use with wp 
                $post_content = preg_replace_callback('|<(/?[A-Z]+)|', function ($m){ return "'<' . strtolower('$m')"; } , $post_content);
                $post_content = str_replace('<br>', '<br />', $post_content);
                $post_content = str_replace('<hr>', '<hr />', $post_content);
                $keywords = $article->getKeywords();
                $post_author = $this->checkAuthor($this->options['braftonArticleDynamic'], $article->getByLine());
                $post_status = $this->publish_status;
                $photos = $article->getPhotos();
                $photo_option = 'large';
		        $post_image = NULL;
		        $post_image_caption = NULL;
                if (!empty($photos))
                {
                    if ($photo_option == 'large') //Large photo
                        $image = $photos[0]->getLarge();
                    
                    if (!empty($image))
                    {
                        $post_image = $image->getUrl();
                        $post_image_caption = $photos[0]->getCaption();
                        $image_id = $photos[0]->getId();
                        $image_alt = $photos[0]->getAlt();
                    }
                }
                $post_excerpt = ($e = $article->getHtmlMetaDescription())? $e: $article->getExtract();
                $post_date_array = $this->getPostDate($article);
                $post_date = $post_date_array[1];
                $post_date_gmt = $post_date_array[0];
                
                $compacted_article = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'post_excerpt');
                $compacted_article['post_category'] = $this->assignCategories($article);
                $compacted_article['tags_input'] = $this->assignTags($article);
               
                if($post_id){//If the post existed but we are overriding values
                    $compacted_article['ID'] = $post_id;
                    $post_id = wp_update_post($compacted_article);
                }
                else{//if the post doesn't exists we add it to the database
                    $post_id = wp_insert_post($compacted_article);
                }
                $meta_array = array(
                    'brafton_id'        => $brafton_id
                );
                if(is_plugin_active('wordpress-seo/wp-seo.php')){
                    $meta_array = array_merge($meta_array, array(
                        '_yoast_wpseo_title'    => $post_title,
                        '_yoast_wpseo_metadesc' => $post_excerpt,
                        '_yoast_wpseo_metakeywords' => ''
                    ));
                }
                if(function_exists('aioseop_get_version')){
                    $meta_array = array_merage($meta_array, array(
                        '_aioseop_description'  => $post_excerpt,
                        '_aioseop_keywords'     => ''
                    ));
                }
                $this->add_needed_meta($post_id, $meta_array);
                //update_post_meta($post_id, 'brafton_id', $brafton_id);
                if($post_image != 'NULL' && $post_image != NULL){
                    $temp_name = $this->image_download($post_image, $post_id, $image_id, $image_alt, $post_image_caption);
                    update_post_meta($post_id, 'pic_id', $image_id);
                }
                
                
                //post meta data
                ++$counter;
                ++$this->errors->level;
            }//end actual importing
             
        }//end individual article loop
        return;
    }
    
}
?>