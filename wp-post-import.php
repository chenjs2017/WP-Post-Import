<?php
/*
Plugin Name: WP Post Import
Plugin URI: techesthete.net
Description: Import the post instantly from your parent website
Version: 1.3
Author: TechEsthete
Author URI: techesthte.net
*/

if ( !class_exists('wp_post_import') ) {
	class wp_post_import {
		
		public  $plugin_dir;
		public  $plugin_url;
		private $extender	= array();
		public  $notices;
		public  $action ;
		function __construct() {
			global $wpdb;
			register_activation_hook( __FILE__, array( $this, '__activate') );
			$this->plugin_dir = dirname(__FILE__). '/';
			$this->plugin_url = plugins_url('/', __FILE__);
			$this->action = 'create_update_post';
			add_action('wp_enqueue_scripts', array($this,'__enqueue_scripts'));
			add_action('admin_enqueue_scripts', array($this,'__admin_scripts'));
			add_action("wp_ajax_".$this->action,  array($this,"create_update_post"));
            add_action("wp_ajax_nopriv_".$this->action, array($this, "create_update_post"));
            add_action('admin_menu', array($this, '__admin_menu') );    
			add_action("init", array($this,"__add_post_columns"));
			//add_action("init", array($this,"process_post"));
		}
		function __get_post_types(){
			$post_types = get_post_types(  array( '_builtin' => false )); 
			$post_types['post'] = 'post'; 
			$post_types['page'] = 'page'; 
			return $post_types;
		}

		function __add_post_columns(){
			$post_types = $this->__get_post_types();
			foreach ($post_types as $key => $post_type) {
				add_filter( 'manage_edit-'.$post_type.'_columns', array( $this, 'edit_columns' ) ,999,1);
				add_action( 'manage_'.$post_type.'_posts_custom_column', array( $this, 'display_sync_date' ),999,2 );
			}
		}
		/**
		 * Change the columns shown in admin.
		 */
		public function edit_columns( $existing_columns ) {
			$existing_columns['post_sync']    = __( 'WP Import', 'woocommerce' );
			return $existing_columns;
		}

		/* Display custom column */
		function display_sync_date( $column, $post_id ) {

			switch ( $column) {
				case 'post_sync':
					$syncdate = get_post_meta( $post_id,'syncdate',true);							    	
			    	if ( !empty( $syncdate )) {
			    		$syncdate = date( 'F j, Y g:i a' , $syncdate );
			    		echo '<span title="'.$syncdate.'" class="te-synced">imported</span>';
			    	} else {
			    		echo '<span class="te-not-synced">local</span>';
			    	}
			    	
				break;
			}
		}


		function add_log( $text ){
			$debug = true;
			if ( $debug ) {
				$filepath = $this->plugin_dir."log.txt";
				$myfile = fopen($filepath, "w");
				if ( $myfile ) {
					fwrite($myfile, $output);
				}
				
			}
		}

		function delete_post(){

			$remote_post_id   = $_REQUEST['remote_post_id'];
			wp_delete_post( $remote_post_id );
			$response['success'] 	= 'true';
			echo  json_encode($response);
			exit;
		}

		public function my_var_dump( $object=null ){
			ob_start();                    // start buffer capture
			var_dump( $object );           // dump the values
			$contents = ob_get_contents(); // put the buffer into a variable
			ob_end_clean();                // end capture
			error_log( $contents );        // log contents of the result of var_dump( $object )
		}

		function create_update_post() {
			
			
			ob_start();
			$this->my_var_dump($_REQUEST);
			/*
			$this->add_log( '************** End of Request *********************' );
			print_r( $_REQUEST );
			*/

			
			$response['remote_post_id'] = 	'';
			$response['error'] 			= 	'';
			$remote_post_id				=	'';

			
			$passkey			=	trim($_REQUEST['passkey']);
			if ( $passkey !== $this->__get_key() ) {
				$response['error'] 	= 'Key is not correct';
				echo  json_encode($response);
				exit;
			}

			$cutomaction = 	@$_REQUEST['cutomaction'];
			if ( $cutomaction == 'delete' ) {
				$this->delete_post();
				exit;
			}

			$post_type			=	@$_REQUEST['post_type'];
			if ( $post_type == 'nav_menu_item' ) {
				$remote_post_id =  $this->__create_update_menu();
			} else {
				$post 				= 	$_REQUEST['post'];
				$post_meta			=	$_REQUEST['post_meta'];
				
				$featuredimage_url 	= 	$_REQUEST['featuredimage_url'];
				$post_id = $post['ID'];
				$post['import_id'] = $post_id;
				$remote_post_id = wp_insert_post($post);
				if ( is_wp_error( $remote_post_id ) ) {
					$response['error'] = $result->get_error_message();
				}

				if ( !is_wp_error( $remote_post_id )) {
					//categories
					if ( isset( $_REQUEST['post_taxonomies_cats'] )) {
						$taxonomies = $_REQUEST['post_taxonomies_cats'];
						foreach ($taxonomies as $taxonomy_slug => $terms) {
							if ( !$terms ) {
								$terms = array();
							}
							$this->process_post_categories( $remote_post_id , $taxonomy_slug ,$terms  ) ;
						}
					}
					//tags
					if ( isset( $_REQUEST['post_taxonomies_tags'] )) {
						$taxonomies = $_REQUEST['post_taxonomies_tags'];
						foreach ($taxonomies as $taxonomy_slug => $terms) {
							if ( !$terms ) {
								$terms = array();
							}
							$this->process_post_tags( $remote_post_id , $taxonomy_slug ,$terms  ) ;

						}
					}
					
					foreach($post_meta as $key => $value) {
						$val = $value[0]; 
						update_post_meta($remote_post_id,$key,$val);		
					}
					update_post_meta($remote_post_id, 'imported_post', 1);
					update_post_meta($remote_post_id, 'syncdate', time());
				}
			}
			$response['remote_post_id'] = $remote_post_id;
			echo  json_encode($response);
			exit;
		}

		

		function add_attachment($url,$post_id,$is_featured = true) {	
			$image = $url;
			$upload_dir = wp_upload_dir();
			//Get the remote image and save to uploads directory
			$img_name = time().'_'.basename( $image );
			$img = wp_remote_get( $image );
			$img = wp_remote_retrieve_body( $img );
			$fp = fopen( $upload_dir['path'].'/'.$img_name , 'w');
			fwrite($fp, $img);
			fclose($fp);
			  
			$wp_filetype = wp_check_filetype( $img_name , null );
			$attachment = array(
			  'post_mime_type' => $wp_filetype['type'],
			  'post_title' => preg_replace('/\.[^.]+$/', '', $img_name ),
			  'post_content' => '',
			  'post_status' => 'inherit'
			);
			//require for wp_generate_attachment_metadata which generates image related meta-data also creates thumbs
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			$attach_id = wp_insert_attachment( $attachment, $upload_dir['path'].'/'.$img_name, $post_id );
			//Generate post thumbnail of different sizes.
			$attach_data = wp_generate_attachment_metadata( $attach_id , $upload_dir['path'].'/'.$img_name );
			wp_update_attachment_metadata( $attach_id,  $attach_data );
			if ( $is_featured ) {
				//Set as featured image.
				delete_post_meta( $post_id, '_thumbnail_id' );
				add_post_meta( $post_id , '_thumbnail_id' , $attach_id, true);			
			}
			return $attach_id;
			
		}

		function process_post_tags( $post_id , $taxonomy , $received_data=array() )  {
			$terms = wp_get_post_terms( $post_id, $taxonomy , array("fields" => "names") );
			$previoustags = array();
			foreach ($terms as $key => $value) {
				$previoustags[] = $value->name; 
			}
			
			$newtags = array();
			foreach($received_data as  $searchterm){
				//if tag is not present add it
				if ( !in_array( $searchterm['name'], $newtags )) {
					$newtags[] = $searchterm['name'];
				}
			}

			$finalInsert = array_intersect($previoustags,$newtags);
			//$this->p_rr( $received_data );
			//$this->p_rr( $newtags );
			wp_set_post_terms( $post_id,$newtags, $taxonomy );
			
		}
		function process_post_categories( $post_id , $taxonomy , $received_data )  {
			$termstoadd = array();
			$terms = wp_get_post_terms( $post_id, $taxonomy );
			foreach($received_data as  $searchterm){
				$found = false;
				foreach ($terms as $key => $value) {
					if ( $searchterm['slug'] == $value->slug) {
						$termstoadd[] =  $value->term_id ;
						$found = true;
						break;
					}
				}
				if( !$found ) {
					$data['description'] 	= $searchterm['description'];
					$data['slug'] 			= $searchterm['slug'];
					$data['parent'] 		= $searchterm['parent'];
					
					//$term = term_exists( $searchterm['name'] , $taxonomy );
					$term = get_term_by('slug', $searchterm['slug'], $taxonomy,ARRAY_A );
					if ($term) {
						$termstoadd[] =  $term['term_id'] ;
					} else {
						$ret = wp_insert_term( $searchterm['name'] , $taxonomy  , $data );
						if ( !is_wp_error( $ret ) ) {
							$termstoadd[] =  $ret['term_id'] ;
						}		
					}
				}

			}
			$termstoadd = array_unique( $termstoadd );
			wp_set_post_terms( $post_id,$termstoadd, $taxonomy );
			
			/* remove extra categories */
			$terms = wp_get_post_terms( $post_id, $taxonomy );
			foreach ($terms as $key => $value) {
				foreach($received_data as  $searchterm) {
					$found = false;
					if ( $searchterm['slug'] == $value->slug) {
						$found = true;
						break;
					}
				}
				if( !$found ) {
					$terms = array( $value->term_id );
					wp_remove_object_terms( $post_id, $terms, $taxonomy );	
				}
			}
		}
		function __create_update_menu(){
			
			$menu_data = $_REQUEST['menu_data'];
			$new_menu_title = trim( esc_html( $menu_data['menu-name'] ) );
			//$_nav_menu_selected_id = wp_update_nav_menu_object( 0, array('menu-name' => $new_menu_title) );
			$_nav_menu_selected_id = wp_update_nav_menu_object( 0, $menu_data );
			if ( $new_menu_title ) {
				if ( is_wp_error( $_nav_menu_selected_id ) ) {
					return 0;
				}
			}
			return $_nav_menu_selected_id;
		}
		function __enqueue_scripts() {
			//wp_enqueue_script( 'share-post', $this->plugin_url.'/assets/js/share-post.js',array( 'jquery' ) );	
		}
		function __admin_menu() {		
			$page_hook_suffix = add_menu_page('WP Post Import','WP Post Import','manage_options','__import_settings', array(&$this,'__import_settings'), $this->plugin_url.'/assets/images/menu-icon.png');
			/* load css js only specific page of wordpress*/
			//add_action('admin_print_scripts-' . $page_hook_suffix, array($this, '__admin_scripts') );
		}
	 
		function __get_import_url(){
			return rtrim(  admin_url('admin-ajax.php').'?action='.$this->action ,'/'); 			
		}
		function __get_key(){
			return md5( $this->__get_import_url() ); 			
		}
		function __import_settings(){
		?>
			<div class="te_main_heading" style="width: 97.3%;margin-top: 15px;padding: 10px;background:none repeat scroll 0 0 #2980b9;color:#fff;font-size: 15px;">WP Post Import</div>
				<div class="wrap te-websites-wrap" style="border: 2px solid #ccc;padding: 10px; margin:0;width: 97%;border-top:none">
				<div class="te-form-control">
					<label class="te-label" >URL:</label>
					<input class="te_select_input" type="text" readonly value="<?php echo $this->__get_import_url();?>">

					<label class="te-label">Pass Key:</label>
					<input class="te_select_input" type="text" readonly value="<?php echo $this->__get_key();?>">
				</div>
			<?php /* ?>
			<!-- Table to show lis -->
				<div class="row">
					<h3 style="margin-bottom: 5px;">List of Imported Posts</h3>
						<table class="wp-list-table widefat fixed posts" id="website_table">
							<thead>
								<tr>
								<th style="font-weight: bold;">Post Title</th>
								<th style="font-weight: bold;">Synced Date</th>
								<th style="font-weight: bold;">Last Modified Date</th>
								</tr>
							</thead>
							<tfoot>
								<tr>
								<th style="font-weight: bold;">Post Title</th>
								<th style="font-weight: bold;">Synced Date</th>
								<th style="font-weight: bold;">Last Modified Date</th>
								</tr>
							</tfoot>
							<tbody>
							<?php
								$post_types = get_post_types(  array( '_builtin' => false )); 
								$post_types['post'] = 'post'; 
								$post_types['page'] = 'page'; 
								
								global $wpdb;
								$postcount = 0;
								foreach ($post_types as $key => $post_type) {
								
									$args = array(
							        	'post_type' => $post_type,
										'posts_per_page' => 10,
										'paged'=> max( 1, $_GET['paged'] ),
										'meta_query' => array(
											array(
												'key'     => 'imported_post',
												'value'   => '1',
												'compare' => '=',
											),						
									));

									global $wp_query;
									$wp_query =  new WP_Query( $args );
								    if ( $wp_query->have_posts() ){
										while ( $wp_query->have_posts() ) {
											$postcount++;
											$wp_query->the_post(); 
									    	$syncdate = get_post_meta( get_the_ID(),'syncdate',true);							    	
									    	if ( !empty( $syncdate )) {
									    		$syncdate = date( 'F j, Y g:i a' , $syncdate );
									    	}
											?>
											<tr>
												<td class="post-title page-title column-title">
													<a href="<?php the_permalink(); ?>">
													<div style="max-width: 100px;float: left;"><?php echo the_post_thumbnail('',array('style'=>'max-width: 100%;width: 100px;height: 100%;')); ?> </div>
													<strong><?php the_title();?></strong>
													</a>
												</td>
												<td><?php echo $syncdate; ?></td>
												<td><?php the_modified_date('F j, Y'); ?> at <?php the_modified_date('g:i a'); ?></td>

											</tr>
											<?php
								   		}
								   	}
								}
							   	if ( $postcount == 0) {
							   		?>
							   		<tr><td colspan="3">No Post Found.</td></tr>
							   		<?php
							   	}
									
								?>
							</tbody>
						</table>
						 <?php
						 $big = 999999999; // need an unlikely integer
						 	echo paginate_links( array(
								'base' => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
								'format' => '?paged=%#%',
								'current' => max( 1, get_query_var('paged') ),
								'total' => $wp_query->max_num_pages,
								'type' => 'list',
							) );
						 ?> 
				</div>
				<?php */ ?>
			</div>
			<script>
				jQuery('.te_select_input').click(function(){
					jQuery(this).select();
				});
			</script>

		<?php
		}
		function p_rr($arr) {
			echo '<pre>';
			print_r($arr);
			echo '</pre>';
		}
		function __admin_scripts() {
			wp_enqueue_script( 'import-admin-script', $this->plugin_url.'/assets/js/admin-script.js',array( 'jquery' ) );	
			wp_enqueue_style( 'import-style', $this->plugin_url.'/assets/css/style.css' );
		}
		function __scripts() {}
		function __styles() {}
		function __dashboard() {}
		function __admin_search() {}
		function __activate() {}
		function _init() {}
	}	
}

global $__wp_post_import;
if ( class_exists('wp_post_import') ) {
	$__wp_post_import = new wp_post_import();
}
?>
