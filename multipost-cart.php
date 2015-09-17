<?php
/**
 * @package Multipost Cart
 * @version 1.0
 */
/*
Plugin Name: Multipost Cart
Description: This plugin gives "cart" functionality to Multipost MU plugin.
Author: Varg242
Version: 1.0
Author URI: http://php-junior.ru/
Plugin URI: http://php-junior.ru/wordpress-2/multipost-cart
*/

/* Null point: load multipost-mu plugin
   for HMMultipostMU class (we will extend it)
*/
include "multipost-mu/multipost-mu.php";

/* First, register our js */
function mpv242_register_scripts($hook) {
	wp_enqueue_script('jquery-ui-core');
	wp_enqueue_script('jquery-ui-sortable');
	wp_enqueue_script('jquery-ui-dialog');
	wp_enqueue_script('postbox');
	wp_enqueue_script('admin-bar');
	wp_enqueue_script('common');
	wp_enqueue_script('suggest');
	wp_enqueue_script('jquery-ui-widget');
	wp_enqueue_script('utils');
	wp_enqueue_style('jquery-ui-dialog');
 wp_enqueue_style('jquery-style', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css'); 


}
add_action('admin_enqueue_scripts', 'mpv242_register_scripts');

global $cur_scr;

if(!class_exists('MultiPostCart') && class_exists('HMMultipostMU'))
{
	class MultiPostCart extends HMMultipostMU
	{

		function multiPost( $postID ) {
			global $switched, $blog_id, $current_user;
			get_currentuserinfo();
			// ensure multipost is only triggered from source blog to prevent massive cascade of posts
			if( $blog_id != $_POST['HMMPMU_source_blog_id'] ){
				return false;
			}
			// get existing child posts, if any
			$childPosts = unserialize( get_post_meta( $postID, 'HMMultipostMU_children', true ) );
			if( empty( $childPosts ) ) {
				$childPosts = array(); // key = blog_id, val = post_id
			}
			// get post
			$thisPost = get_post( $postID );
			$postCustomFields = get_post_custom( $postID );
			unset( $postCustomFields['HMMultipostMU_children'] ); // we don't want to copy this one to sub-blogs... rippled chaos will ensue!
			unset( $postCustomFields['_edit_lock'] ); 
			unset( $postCustomFields['_edit_last'] );
			$thisPostTags = wp_get_post_tags( $postID );
			// get array of categories (need ->name parameter)
			$thisPostCategories = wp_get_object_terms( $postID, 'category' );
			$masterPostCats = array();
			// pull category id/name into array for easier searching
			foreach( $thisPostCategories as $thisPostCategory ) {
				$masterPostCats[$thisPostCategory->term_id] = $thisPostCategory->name;
			}
			$thisPostTags_string = '';
			foreach( $thisPostTags as $thisPostTag ) {
				$thisPostTags_string .= $thisPostTag->name .',';
			}
			$thisPostTags_string = trim( $thisPostTags_string, ',' );
			// create post object with this post's data
			$dupePost = array(
				'post_title' => $thisPost->post_title, 
				'post_content' => $thisPost->post_content, 
				'post_status' => 'draft', 
				'post_author' => $thisPost->post_author, 
				'post_excerpt' => $thisPost->post_excerpt, 
				'post_date' => $thisPost->post_date, 
				'post_date_gmt' => $thisPost->post_date_gmt, 
				'post_modified' => $thisPost->post_modified, 
				'post_modified_gmt' => $thisPost->post_modified_gmt, 
				'tags_input' => $thisPostTags_string
			);
			//check if post is sticky
			$sticky = is_sticky($postID);
			
			// get list of blogs
			//$subBlogs = get_blog_list( 0, 'all' );
			$subBlogs = get_blogs_of_user( $current_user->ID );
			// get the subBlogs in chronological order as get_blog_list() pulls in reverse cron order
			
			foreach( $subBlogs as $subBlog ) {
				// if user selected specific blogs in which to post and this blog isn't among them, skip to next
				if( !empty( $_POST['HMMPMU_selectedSubBlogs'] ) && !in_array( $subBlog->userblog_id, $_POST['HMMPMU_selectedSubBlogs'] ) ) {
					// if a previous post exists on this blog, but isnt now needed, delete it
					if( in_array( $subBlog->userblog_id, array_keys( $childPosts ) ) ) {
						if( switch_to_blog( $subBlog->userblog_id ) === true ) { 
							wp_delete_post( $childPosts[$subBlog->userblog_id] );
							// jump back to master blog
							restore_current_blog();
							unset( $childPosts[$subBlog->userblog_id] );
						}
					}
					continue;
				}
				if( $blog_id != $subBlog->userblog_id ) { // skip the current blog
					$childPostID = 0;	// used to hold new/updated post for each sub-blog
					// switch each sub-blog
					if( switch_to_blog( $subBlog->userblog_id ) === true ) { 
							if( isset( $childPosts[$subBlog->userblog_id] ) ) {
								// there is already an existing post for this blog
								$dupePost['ID'] = $childPosts[$subBlog->userblog_id];	// set post ID
								$childPostID = wp_update_post( $dupePost );
								unset( $dupePost['ID'] );	// remove post ID from duped post object
							} else {
								// no existing post for this blog, and was checked, create a new post
								if( !empty( $_POST['HMMPMU_selectedSubBlogs'] ) && in_array( $subBlog->userblog_id, $_POST['HMMPMU_selectedSubBlogs'] ) ) {
									$childPostID = wp_insert_post( $dupePost );
								}
							}
							if( $childPostID > 0 ) {
								// get the new post's object
								$childPost = get_post( $childPostID );
								// get existing categories for this blog
								$childBlogCats = get_terms( 'category' );
								// if matching category found, add post to it
								$matchingCatID = 0;
								$childCatsToAdd = array();
								foreach( $masterPostCats as $masterPostCats_key=>$masterPostCats_value ) {
									$matchingTerm = get_term_by( 'name', $masterPostCats_value, 'category' );
									if( $matchingTerm === false ) {
										// create new term/category
										$newCatID = wp_create_category( $masterPostCats_value );
										$matchingTerm = get_term( $newCatID, 'category' );
									}
									array_push( $childCatsToAdd, $matchingTerm->term_id );
								}
								// add terms/categories to post
								wp_set_post_categories( $childPostID, $childCatsToAdd );
								// update or set custom fields
								foreach( $postCustomFields as $postCustomFieldKey=>$postCustomFieldValue ) {
									//update existing custom field (this adds first if fields does not yet exist)
									foreach( $postCustomFieldValue as $postCustomFieldValueItem ){
										update_post_meta( $childPostID, $postCustomFieldKey, $postCustomFieldValueItem );
									}
								}
								// if the update/new post was successful, add it to the array of child posts
								$childPosts[$subBlog->userblog_id] = $childPostID;
								
								// if the original post was sticky, set the new one sticky. otherwise remove sticky.
								if($sticky === true){
									stick_post($childPostID);	
								} elseif(is_sticky($childPostID)===true) {
									unstick_post($childPostID);
								}
							}
						// jump back to master blog
						restore_current_blog();
					}
				}
			} /* /foreach */
			
			// add list of child posts to master post as metadata
			if( !empty( $childPosts ) ) {
				update_post_meta( $postID, 'HMMultipostMU_children', serialize( $childPosts ) );
			}
		}

		function multiPostPage( $postID ) {
			global $switched, $blog_id, $current_user;
			get_currentuserinfo();
			// ensure multipost is only triggered from source blog to prevent massive cascade of posts
			if( $blog_id != $_POST['HMMPMU_source_blog_id'] ) {
				return false;
			}
			// get existing child pages, if any
			$childPages = unserialize( get_post_meta( $postID, 'HMMultipostMU_children', true ) );
			if( empty( $childPages ) ) {
				$childPages = array(); // key = blog_id, val = post_id
			}
			
			// get page template setting
			$template_filename = get_post_meta( $postID, '_wp_page_template', true );
			//die("DEBUG: tf = $template_filename");
			
			// get page
			$thisPage = get_page( $postID, ARRAY_A );
			// create page object with this page's data
			$dupePage = $thisPage;
			// get the parent page (we'll need this later)
			if( $thisPage['post_parent'] > 0 ) {
				// get the parent page and get it's multipost children
				$parentsChildPages = unserialize( get_post_meta( $thisPage['post_parent'], 'HMMultipostMU_children', true ) );
				if( empty( $parentsChildPages ) ) {
					$parentsChildPages = array(); // key = blog_id, val = post_id
				}
			}
			unset( $dupePage['post_parent'] );
			unset( $dupePage['ID'] );
			unset( $dupePage['guid'] );
			
			$dupePage['post_status'] = 'draft';
			/*
			echo "<pre>";
			print_r( $dupePage );
			echo "</pre>";
			*/
			// get list of blogs
			//$subBlogs = get_blog_list( 0, 'all' );
			$subBlogs = get_blogs_of_user( $current_user->ID );
			// get the subBlogs in chronological order as get_blog_list() pulls in reverse cron order
			foreach( $subBlogs as $subBlog ){
				// if user selected specific blogs in which to page and this blog isn't among them, skip to next
				if( !empty( $_POST['HMMPMU_selectedSubBlogs'] ) && !in_array( $subBlog->userblog_id, $_POST['HMMPMU_selectedSubBlogs'] ) ) {
					// if a previous page exists on this blog, but isnt now needed, delete it
					if( in_array( $subBlog->userblog_id, array_keys( $childPages ) ) ) {
						if( switch_to_blog( $subBlog->userblog_id ) === true ) { 
							wp_delete_post( $childPages[$subBlog->userblog_id] );
							// jump back to master blog
							restore_current_blog();
							unset( $childPages[$subBlog->userblog_id] );
						}
					}
					continue;
				}
				if( $blog_id != $subBlog->userblog_id ) { // skip the current blog
					$childPageID = 0;	// used to hold new/updated page for each sub-blog
					// switch each sub-blog
					if( switch_to_blog( $subBlog->userblog_id ) === true ) { 
							// if the current page has a valid parent, set the parent accordingly
							if( isset( $parentsChildPages[$subBlog->userblog_id] ) ) {
								$dupePage['post_parent'] = $parentsChildPages[$subBlog->userblog_id];	// set parent ID
							}
							if( isset( $childPages[$subBlog->userblog_id] ) ) {
								// there is already an existing page for this blog
								$dupePage['ID'] = $childPages[$subBlog->userblog_id];	// set post ID
								$childPageID = wp_update_post( $dupePage );
								unset( $dupePage['ID'] );	// remove page ID from duped page object
							}else{
								// no existing page for this blog, and was checked, create a new page
								if( !empty( $_POST['HMMPMU_selectedSubBlogs'] ) && in_array( $subBlog->userblog_id, $_POST['HMMPMU_selectedSubBlogs'] ) ) {
									$childPageID = wp_insert_post( $dupePage );
								}
							}
							if( $childPageID > 0 ){
								// get the new pages's object
								$childPage = get_page( $childPageID );
								// if the update/new post was successful, add it to the array of child posts
								$childPages[$subBlog->userblog_id] = $childPageID;
							}
							
							// set the meta for the page template too same as original
							// todo: might be worthwhile to check if the template file exists in the active theme before changing from "default".
							if(!empty($template_filename)){
								update_post_meta( $childPageID, '_wp_page_template', $template_filename);
							}
							
						// jump back to master blog
						restore_current_blog();
					}
				}
			}
			// add list of child posts to master post as metadata
			if( !empty( $childPages ) ) {
				update_post_meta( $postID, 'HMMultipostMU_children', serialize( $childPages ) );
			}
		}

		
		function gs()
		{
		global $cur_scr;
		$cur_scr = &get_current_screen()->base;
		}		
		
		function MultiPostCart()
		{
		session_start();
		// adds javascript handler for action
		add_action( 'admin_head-edit.php', array( &$this, 'add_bulk_actions_via_javascript' ) );
		add_action('admin_head-edit.php', array( &$this, 'gs' ) );
		// redirects to admin page to handle selections
		add_action( 'admin_action_multipost_cart', array( &$this, 'bulk_action_handler' ) );

		global $cur_scr;
		print_r($cur_scr);
		add_filter('admin_footer_text', array( &$this, 'metabox_render' ) );
		// adds javascript handler for action
		add_action( 'admin_head-edit.php', array( &$this, 'add_metabox_via_javascript' ) );
		session_write_close();
		}
		
		// Add new items to the Bulk Actions using Javascript
		function add_bulk_actions_via_javascript() { ?>
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$('select[name^="action"] option:last-child').before('<option value="multipost_cart"><?php echo esc_attr( __( 'Add To Multipost Cart', 'multipost_cart' ) ); ?></option>');
				});
			</script>
		<?php
		}
		
		// Handles the bulk actions POST
function bulk_action_handler() {
	global $blog_id;
   session_start();
   if(isset($_REQUEST['act']) && $_REQUEST['act']=='remove')
   {
    $id=$_REQUEST['sid'];
    $arr=$_SESSION["pmove"];
    $num=array_search($id,$arr);
    unset($arr[$num]);
    $_SESSION["pmove"]=$arr;
    print_r($arr);
    die();
   }
   elseif(isset($_REQUEST['act']) && $_REQUEST['act']=='post')
   {
	$_POST['HMMPMU_source_blog_id']=$blog_id;
        $item=explode(',',$_REQUEST['pid']);
	foreach($item as $id)
        {
		$thisposttype = get_post_type($id);
		if($thisposttype == "post") $this->multiPost($id);
		else $this->multiPostPage($id);
    		$arr=$_SESSION["pmove"];
    		$num=array_search($id,$arr);
    		unset($arr[$num]);
    		$_SESSION["pmove"]=$arr;
	}
	
	//print_r(get_bloginfo());die();
   	wp_redirect(admin_url( 'edit.php'));    
   }
   if(!isset($_SESSION["pmove"])) $_SESSION["pmove"] = array();
   $ids = implode(",", $_REQUEST["post"]);
   $ids_arr = explode(",", $ids);
   if(isset($_REQUEST['post']))
   foreach($ids_arr as $id)
   {
  //  echo $id;
    if($_SESSION["pmove"])
    {
     if(!in_array($id, array_values($_SESSION["pmove"])))
     {
      $_SESSION["pmove"][] = $id;
     }
    }
    else
    {
     $_SESSION["pmove"][] = $id;
    }
   }
//print_r($_SESSION['pmove']);die();
   // Can't use wp_nonce_url() as it escapes HTML entities
   wp_redirect( add_query_arg('someids', $ids, admin_url( 'edit.php')));
   exit();
  }		
		function add_metabox_via_javascript()
		{
			?>
			<style type="text/css">
			#postbox h3.hndle
			{
			font-size: 15px;
			font-weight: normal;
			line-height: 1;
			margin: 0;
			padding: 7px 10px;
			height: 25px;
			}
			.postbox .handlediv
			{
			cursor: pointer;
			float: right;
			height: 30px;
			width: 27px;
			}
			</style>
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$('#mc-cart').hide();
					$('#posts-filter').append($('#mc-cart'));
					$('#mc-cart').show();
				});
			</script>
			<?php
		}

function showBlogBoxes( $post,$posts=0 ) {
	global $current_user, $blog_id, $hmMultipostMU;
	wp_enqueue_script('jquery');
	?>
<script type="text/javascript">
  jQuery(document).ready( function($){
		jQuery('#HMMPMU_checkall').click( function(e){
			e.preventDefault();
			HMMPMU_check( 'check' );
		});
		jQuery('#HMMPMU_checknone').click( function(e){
			e.preventDefault();
			HMMPMU_check( 'uncheck' );
		});
	});
	function HMMPMU_check( action ){
		if( action == 'check' ){
			jQuery('.HMMPMU_selectedSubBlogs_checkbox').attr('checked', 'true');
		}else{
			jQuery('.HMMPMU_selectedSubBlogs_checkbox[disabled!=true]').removeAttr('checked');
		}
	}

  </script>
<input type="hidden" name="HMMPMU_source_blog_id" value="<?php echo $blog_id; ?>" />
<p style="float: right; font-size: 0.8em;">Check <a href="#" id="HMMPMU_checkall">all</a> / <a href="#"id="HMMPMU_checknone">none</a></p>
<p>Post to:</p>
<form method="post" action="<?php echo get_bloginfo('url');?>/wp-admin/edit.php?action=multipost_cart&act=post">
<?php
	get_currentuserinfo();
	// get existing child posts, if any
	// in wp 3.0.1 it looks like ID is never 0 so this runs every time which is probably fine, just a bit slower for new posts	
	
	$oSubBlogs = get_blogs_of_user( $current_user->ID );
	$subBlogs = array();
	foreach( $oSubBlogs as $oSubBlog ) {
		$subBlogs[$oSubBlog->userblog_id] = $oSubBlog->blogname;
	}
	asort( $subBlogs, SORT_STRING );
	foreach( $subBlogs as $subBlogID => $subBlogName ) {
			$checkedHTML = '';
			$disabledHTML = '';
			//updated for wp 3.0.1 since it looks like we dont get $post->ID == 0 now. on new post creation it already has an id.
			if( (int)$subBlogID == (int)$blog_id ) {
				$checkedHTML = 'checked="true"';
				$disabledHTML = 'disabled = "true"';
			}
		
			?>
      <input type="checkbox" 
              class="HMMPMU_selectedSubBlogs_checkbox" 
              name="HMMPMU_selectedSubBlogs[]" 
              <?php echo $checkedHTML; ?>
              <?php echo $disabledHTML; ?>
              value="<?php echo $subBlogID; ?>" />
            <?php //$currentBlog = get_blog_details( $subBlog->userblog_id );
            echo $subBlogName;?>
      <br />
      <?php
	} /* /foreach */
	?>
<?php
if($posts==0)
{
?>
<input type='hidden' name='pid' value='<?php echo $post?>'>
<?php
}
else
{
?>
<input type='hidden' name='pid' value='<?php echo implode(',',$_SESSION["pmove"])?>'>
<?php
}
?>
<input type='submit'>
</form>
<?php
}

		
		function metabox_render()
		{
			if(strpos($_SERVER['PHP_SELF'],'edit.php')===false)
			return '';
				
		?>
<style>
.ui-helper-hidden{display:none;}
.ui-helper-hidden-accessible{position:absolute;left:-99999999px;}
.ui-helper-reset{margin:0;padding:0;border:0;outline:0;line-height:1.3;text-decoration:none;font-size:100%;list-style:none;}
.ui-helper-clearfix:after{content:".";display:block;height:0;clear:both;visibility:hidden;}.ui-helper-clearfix{display:inline-block;}/* required comment for clearfix to work in Opera \*/ * html .ui-helper-clearfix{height:1%;}.ui-helper-clearfix{display:block;}/* end clearfix */ .ui-helper-zfix{width:100%;height:100%;top:0;left:0;position:absolute;opacity:0;filter:Alpha(Opacity=0);}.ui-state-disabled{cursor:default!important;}.ui-icon{display:block;text-indent:-99999px;overflow:hidden;background-repeat:no-repeat;}.ui-widget-overlay{position:absolute;top:0;left:0;width:100%;height:100%;}.ui-resizable{position:relative;}.ui-resizable-handle{position:absolute;font-size:.1px;z-index:99999;display:block;}.ui-resizable-disabled .ui-resizable-handle,.ui-resizable-autohide .ui-resizable-handle{display:none;}.ui-resizable-n{cursor:n-resize;height:7px;width:100%;top:-5px;left:0;}.ui-resizable-s{cursor:s-resize;height:7px;width:100%;bottom:-5px;left:0;}.ui-resizable-e{cursor:e-resize;width:7px;right:-5px;top:0;height:100%;}.ui-resizable-w{cursor:w-resize;width:7px;left:-5px;top:0;height:100%;}.ui-resizable-se{cursor:se-resize;width:12px;height:12px;right:1px;bottom:1px;}.ui-resizable-sw{cursor:sw-resize;width:9px;height:9px;left:-5px;bottom:-5px;}.ui-resizable-nw{cursor:nw-resize;width:9px;height:9px;left:-5px;top:-5px;}.ui-resizable-ne{cursor:ne-resize;width:9px;height:9px;right:-5px;top:-5px;}.wp-dialog{position:absolute;width:300px;overflow:hidden;}.wp-dialog .ui-dialog-titlebar{position:relative;}.wp-dialog .ui-dialog-titlebar-close span{display:block;margin:1px;}.wp-dialog .ui-dialog-content{position:relative;border:0;padding:0;background:none;overflow:auto;zoom:1;}.wp-dialog .ui-dialog-buttonpane{text-align:left;border-width:1px 0 0 0;background-image:none;margin:.5em 0 0 0;padding:.3em 1em .5em .4em;}.wp-dialog .ui-dialog-buttonpane .ui-dialog-buttonset{float:right;}.wp-dialog .ui-dialog-buttonpane button{margin:.5em .4em .5em 0;cursor:pointer;}.wp-dialog .ui-resizable-se{width:14px;height:14px;right:3px;bottom:3px;}.ui-draggable .ui-dialog-titlebar{cursor:move;}.wp-dialog{border:1px solid #999;-moz-box-shadow:0 0 16px rgba(0,0,0,0.3);-webkit-box-shadow:0 0 16px rgba(0,0,0,0.3);box-shadow:0 0 16px rgba(0,0,0,0.3);}.wp-dialog .ui-dialog-title{display:block;text-align:center;padding:1px 0 2px;}.wp-dialog .ui-dialog-titlebar{padding:0 1em;background-color:#444;font-weight:bold;font-size:11px;line-height:18px;color:#e5e5e5;}.wp-dialog{background-color:#f5f5f5;-webkit-border-top-left-radius:4px;border-top-left-radius:4px;-webkit-border-top-right-radius:4px;border-top-right-radius:4px;}.wp-dialog .ui-dialog-titlebar{-webkit-border-top-left-radius:3px;border-top-left-radius:3px;-webkit-border-top-right-radius:3px;border-top-right-radius:3px;}.wp-dialog .ui-dialog-titlebar-close{position:absolute;width:29px;height:16px;top:2px;right:6px;background:url('../js/tinymce/plugins/inlinepopups/skins/clearlooks2/img/buttons.gif') no-repeat -87px -16px;padding:0;}.wp-dialog .ui-dialog-titlebar-close:hover,.wp-dialog .ui-dialog-titlebar-close:focus{background-position:-87px -32px;}.ui-widget-overlay{background-color:#000;opacity:.6;filter:alpha(opacity=60);}
</style>
		
		<div id="mc-cart" class="postbox" style="float: right; width: 450px;">
			<div class="handlediv" title="Click to toggle" onclick="if(jQuery('.inside').is(':visible')){jQuery('.inside').hide();}else{jQuery('.inside').show();}">
			<br>
			</div>
			<h3 class="hndle" style="height: 30px; font-family: Georgia,Times New Roman,Bitstream Charter,Times,serif;">
			<span style="margin-left: 10px; line-height: 30px;">
			Multipost Cart<?php
			if(isset($_SESSION['pmove'])) echo ": <span id='mskcounter'>".count($_SESSION['pmove'])."</span>";
			?>
			</span>
			</h3>
			<div class="inside">
					<p class="hide-if-no-js">
						<table class="wp-list-table widefat fixed posts">

						<?php   if(isset($_SESSION['pmove']) && count($_SESSION['pmove']))
							foreach($_SESSION['pmove'] as $key => $value)
							{
						?>
							<tr class="post type-post status-publish format-standard hentry category--  iedit author-self" id="<?php echo "tr".$value;?>">
								<td class="post-title page-title column-title">
									<strong><?=get_the_title($value)?></strong>
								</td>
								<td class="table-multipost"><a href="#" onclick="jQuery('#dlg-<?php echo $value;?>').dialog();return false;">Multipost</a><div id="dlg-<?php echo $value;?>" title='Select blogs to post' style="display:none;"><?php $this->showBlogBoxes($value);?></div></td>
								<td class="table-remove-from" onclick="jQuery('tr#tr<?php echo $value;?>').hide();jQuery.post('/wp-admin/edit.php?action=multipost_cart&act=remove&sid=<?php echo $value;?>');jQuery('#mskcounter').html(Number(jQuery('#mskcounter').html())-1);return false;"><a href="#">Remove</a></td>
							</tr>
						<?php
							}
						?>
						</table>
					</p>
				<p><a onclick="jQuery('#dlg-multipost').dialog();return false;" href='/wp-admin/edit.php?action=multipost_cart&act=madd&sids=<?php if(is_array($_SESSION['pmove'])){echo implode(',',$_SESSION['pmove']);}?>'>Move All Posts</a><div id="dlg-multipost" title='Select blogs to post' style="display:none;"><?php $this->showBlogBoxes($value,1);?></div></p>
			</div>
		</div>
		<div style="clear: both;">
		<?php
		}
		
	}
}

add_action('init', 'add_action2');

function add_action2(){
 if(isset($_REQUEST['action2'])) {
   do_action('admin_action_' . $_REQUEST['action2']);
 }
}

if(class_exists('MultiPostCart'))
{
$a = new MultiPostCart;
}
?>
