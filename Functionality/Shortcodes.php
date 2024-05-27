<?php

namespace FrontendUserAvatar\Functionality;

class Shortcodes {
	protected $plugin_id;
    protected $plugin_version;

    private $editing_user_id;
    
    public function __construct($plugin_id, $plugin_version) {
        $this->plugin_id = $plugin_id;
        $this->plugin_version = $plugin_version;
        
        // Actions
        add_action('show_user_profile', [$this, 'edit_user_avatar_profile']); 
        add_action('edit_user_profile', [$this, 'edit_user_avatar_profile']); 
        add_action('personal_options_update', [$this, 'update_user_avatar_profile']);
        add_action('edit_user_profile_update', [$this, 'update_user_avatar_profile']);

        // Filters
        add_filter('get_avatar_data', [$this, 'customize_avatar_data'], 10, 2);
        add_filter('get_avatar', [$this, 'customize_avatar'], 10, 6);

        // Shortcodes
        add_shortcode('frontend-user-avatar', [$this, 'user_avatar_shortcode']);
    }

	// Front-end shortcode
    function user_avatar_shortcode() {
		if (!is_user_logged_in())
            return;

        $user_id = get_current_user_id();
        $profile_user = get_userdata($user_id);

        if (isset($_POST['manage_avatar_submit'])){
            $this->update_user_avatar_profile($user_id);
        }

        ob_start();
        ?>
        <form id="basic-user-avatar-form" method="post" enctype="multipart/form-data">
            <?php
            echo get_avatar($profile_user->ID);

            $options = get_option('user_avatars_caps');
            if (empty($options['user_avatars_caps']) || current_user_can('upload_files')) {
                wp_nonce_field('user_avatar_nonce', '_user_avatar_nonce', false);
                
                echo '<p><input type="file" name="basic-user-avatar" id="basic-local-avatar" /></p>';

                if (empty($profile_user->user_avatar)) {
                    echo '<p class="description">' . apply_filters('bu_avatars_no_avatar_set_text', esc_html__('No local avatar is set. Use the upload field to add a local avatar.', 'basic-user-avatars'), $profile_user) . '</p>';
                } else {
                    				
                    echo '<p class="description">' . apply_filters('bu_avatars_replace_avatar_text', esc_html__('Upload a new avatar.', 'basic-user-avatars'), $profile_user) . '</p>';
                }

                echo '<input type="submit" name="manage_avatar_submit" value="' . apply_filters('bu_avatars_update_button_text', esc_attr__('Update Avatar', 'basic-user-avatars')) . '" />';

            } else {
                if (empty($profile_user->user_avatar)) {
                    echo '<p class="description">' . apply_filters('bu_avatars_no_avatar_set_text', esc_html__('No local avatar is set.', 'basic-user-avatars'), $profile_user) . '</p>';
                } else {
                    echo '<p class="description">' . apply_filters('bu_avatars_permissions_text', esc_html__('You do not have permission to change your avatar.', 'basic-user-avatars'), $profile_user) . '</p>';
                }	
            }
            ?>
        </form>
        <?php
        return ob_get_clean();
    }

    public function customize_avatar($avatar, $id_or_email, $size = 96, $default = '', $alt = false, $args = []) {		
		return apply_filters('user_custom_avatar', $avatar, $id_or_email);
	}

	// Function to filter avatar
    public function customize_avatar_data($args, $id_or_email) {
		if (!empty($args['force_default'])) {
			return $args;
		}

		global $wpdb;

		$return_args = $args;

		if (is_numeric($id_or_email) && 0 < $id_or_email) {
			$user_id = (int) $id_or_email;
		} elseif (is_object($id_or_email) && isset($id_or_email->user_id) && 0 < $id_or_email->user_id) {
			$user_id = $id_or_email->user_id;
		} elseif (is_object($id_or_email) && isset($id_or_email->ID) && isset($id_or_email->user_login) && 0 < $id_or_email->ID) {
			$user_id = $id_or_email->ID;
		} elseif (is_string($id_or_email) && false !== strpos($id_or_email, '@')) {
			$_user = get_user_by('email', $id_or_email);

			if (!empty($_user)) {
				$user_id = $_user->ID;
			}
		}

		if (empty($user_id)) {
			return $args;
		}

		$user_avatar_url = null;

		$local_avatars = get_user_meta($user_id, 'user_avatar', true);

		if (empty($local_avatars) || empty($local_avatars['full'])) {
			$wp_user_avatar_id = get_user_meta($user_id, $wpdb->get_blog_prefix() . 'user_avatar', true);
			if (!empty($wp_user_avatar_id)) {
				$wp_user_avatar_url = wp_get_attachment_url(intval($wp_user_avatar_id));
				$local_avatars = ['full' => $wp_user_avatar_url];
				update_user_meta($user_id, 'user_avatar', $local_avatars);
			} else {
				return $args;
			}	
		}
		
		$size = apply_filters('user_custom_avatars_default_size', (int) $args['size'], $args);

		if (empty($local_avatars[$size])) {

			$upload_path      = wp_upload_dir();
			$avatar_full_path = str_replace($upload_path['baseurl'], $upload_path['basedir'], $local_avatars['full']);
			$image            = wp_get_image_editor($avatar_full_path);
			$image_sized      = null;

			if (!is_wp_error($image)) {
				$image->resize($size, $size, true);
				$image_sized = $image->save();
			}

			if (empty($image_sized) || is_wp_error($image_sized)) {
				$local_avatars[$size] = $local_avatars['full'];
			} else {
				$local_avatars[$size] = str_replace($upload_path['basedir'], $upload_path['baseurl'], $image_sized['path']);
			}

			update_user_meta($user_id, 'user_avatar', $local_avatars);

		} elseif (substr($local_avatars[$size], 0, 4) != 'http') {
			$local_avatars[$size] = home_url($local_avatars[$size]);
		}

		if (is_ssl()) {
			$local_avatars[$size] = str_replace('http:', 'https:', $local_avatars[$size]);
		}

		$user_avatar_url = $local_avatars[$size];

		if ($user_avatar_url) {
			$return_args['url']  = $user_avatar_url;
			$return_args['found_avatar'] = true;
		}

		return apply_filters('user_custom_avatar_data', $return_args);
	}

    // Function to add fields to update the profile picture
    public function edit_user_avatar_profile($profile_user) {
        ?>

		<h2>Avatar</h2>
		<table class="form-table">
			<tr>
				<th><label for="basic-user-avatar">Upload Avatar</label></th>
				<td style="width: 50px;" valign="top">
					<?php echo get_avatar($profile_user->ID); ?>
				</td>
				<td>
				<?php
				$options = get_option('user_avatars_caps');
				if (empty($options['user_avatars_caps']) || current_user_can('upload_files')) {
					wp_nonce_field('user_avatar_nonce', '_user_avatar_nonce', false);
					
					echo '<input type="file" name="basic-user-avatar" id="basic-local-avatar" />';

					if (empty($profile_user->user_avatar)) {
						echo '<p class="description">' . esc_html__('No local avatar is set. Use the upload field to add a local avatar.', 'basic-user-avatars') . '</p>';
					} else {
						
						echo '<p class="description">' . esc_html__('Upload a new avatar.', 'basic-user-avatars') . '</p>';
					}

				} else {
					if (empty($profile_user->user_avatar)) {
						echo '<p class="description">' . esc_html__('No local avatar is set. Set up your avatar at Gravatar.com.', 'basic-user-avatars') . '</p>';
					} else {
						echo '<p class="description">' . esc_html__('You do not have media management permissions. To change your local avatar, contact the site administrator.', 'basic-user-avatars') . '</p>';
					}	
				}
				?>
				</td>
			</tr>
		</table>
		<script type="text/javascript">var form = document.getElementById('your-profile');form.encoding = 'multipart/form-data';form.setAttribute('enctype', 'multipart/form-data');</script>
		<?php
    }

    // Function to save the profile picture
    public function update_user_avatar_profile($user_id) {
		if (!isset($_POST['_user_avatar_nonce']) || !wp_verify_nonce($_POST['_user_avatar_nonce'], 'user_avatar_nonce'))
            return;

        if (!empty($_FILES['basic-user-avatar']['name'])) {

            $mimes = [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif'          => 'image/gif',
                'png'          => 'image/png',
            ];

            if (!function_exists('wp_handle_upload'))
                require_once ABSPATH . 'wp-admin/includes/file.php';

            $this->avatar_delete($this->editing_user_id);

            if (strstr($_FILES['basic-user-avatar']['name'], '.php'))
                wp_die('For security reasons, the extension ".php" cannot be in your file name.');

            $this->editing_user_id = $user_id; 
            $avatar = wp_handle_upload($_FILES['basic-user-avatar'], [
                'mimes' => $mimes, 
                'test_form' => false, 
                'unique_filename_callback' => [$this, 'generate_unique_filename']
            ]);

            if (empty($avatar['file'])) {  
                switch ($avatar['error']) {
                case 'File type does not meet security guidelines. Try another.' :
                    add_action('user_profile_update_errors', function($error = 'avatar_error'){
                        esc_html__("Please upload a valid image file for the avatar.","basic-user-avatars");
                    });
                    break;
                default :
                    add_action('user_profile_update_errors', function($error = 'avatar_error'){
                        if (empty($avatar['error'])) {
                            return;
                        }

                        "<strong>".esc_html__("There was an error uploading the avatar:","basic-user-avatars")."</strong> ". esc_attr($avatar['error']);
                    });
                }
                return;
            }

            update_user_meta($user_id, 'user_avatar', ['full' => $avatar['url']]);

        } elseif (!empty($_POST['basic-user-avatar-erase'])) {
            $this->avatar_delete($user_id);
        }
    }

	// Function to delete the avatar
    public function avatar_delete($user_id) {
		$old_avatars = get_user_meta($user_id, 'user_avatar', true);
		$upload_path = wp_upload_dir();

		if (is_array($old_avatars)) {
			foreach ($old_avatars as $old_avatar) {
				$old_avatar_path = str_replace($upload_path['baseurl'], $upload_path['basedir'], $old_avatar);
				@unlink($old_avatar_path);
			}
		}

		delete_user_meta($user_id, 'user_avatar');
	}
}

      