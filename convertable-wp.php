<?php
/*
Plugin Name: Convertable
Plugin URI: http://convertable.com/wordpress/
Description: Convertable combines a contact form builder, web analytics and a lead management dashboard into a single all-in-one lead tracking platform.
Version: 1.4
Author: Streamline Metrics, LLC.
Author URI: http://streamlinemetrics.com
Author Email: support@convertable.com
License:

  Copyright 2016 Streamline Metrics, LLC. (support@convertable.com)

*/

if (!defined('DB_NAME')) {
	header('HTTP/1.0 403 Forbidden');
	die;
}
if (!defined('CONVERTABLE_URL'))
	define('CONVERTABLE_URL', plugin_dir_url(__FILE__));
if (!defined('CONVERTABLE_PATH') )
	define('CONVERTABLE_PATH', plugin_dir_path(__FILE__));

class Convertable {

	public static $version = '1.3';
	public static $api_version = '1.0';
	public static $min_wp_version = '3.2';
	//public static $demo_account_id = '953'; // Dev
	//public static $demo_account_id = '962'; // Local
	public static $demo_account_id = '1637'; // Prod
	var $_api;
	var $settings = array();
	var $default_settings = array();
	var $user;
	var $is_demo = false;
	//var $convertable_url = 'convertabledev.info';
	//var $convertable_url = 'convertable.hess.com';
	var $convertable_url = 'convertable.com';
	var $convertable_dir = '';
	var $lead_status = array('Awarded','Followed Up','Needs Contact','Needs Quote','Not Qualified','Quoted');
	var $lead_mediums = array('Cpc','Direct Visit','Email','Emailutm_term','Organic Search','Ppc','Referring Website','Web');
	var $_hooks = array('convertable','convertable_form','convertable_reports');

	function __construct() {

		global $wp_version;

		$proto = 'http'.((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 's' : '') . '://';
		$this->convertable_url = $proto.$this->convertable_url;

		$plugin_path = parse_url(CONVERTABLE_URL, PHP_URL_PATH);
		if ($plugin_path !== FALSE) {
			$plugin_path_parts = array_filter(explode('/', $plugin_path));
			$this->convertable_dir = end($plugin_path_parts);
		} else {
			$this->convertable_dir = 'convertable-wp';
		}

		require_once('lib/convertableapi.php');
		$this->_api = new ConvertableAPI(array('api-url' => $this->convertable_url, 'api-version' => self::$api_version));

		load_plugin_textdomain('convertable-locale', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		if (!class_exists('WP_Http') || version_compare($wp_version, self::$min_wp_version, '<' )) {
			if (isset( $_GET['activate'] )) {
				wp_redirect('plugins.php?deactivate=true');
				exit();
			} else {
				$current = get_option('active_plugins');
				$plugins = array($this->convertable_dir.'/convertable-wp.php', 'convertable-wp.php' );
				foreach ($plugins as $plugin) {
					if (!in_array( $plugin, $current )) continue;
					array_splice( $current, array_search( $plugin, $current ), 1 ); // Fixed Array-fu!
				}
				update_option('active_plugins', $current);
				add_action('admin_notices', array(&$this, 'convertable_wp_version_notice'));
				return;
			}
		}

		$this->user = wp_get_current_user();

		// SETTINGS
		$this->default_settings = array(
			'trackerID' => '',
			'accountName' => '',
			'accountID' => '',
			'thankYouPageID' => ''
		);
		$user_settings = (array) get_option('convertable_options');
		$this->settings = $this->default_settings;
		if ($user_settings !== $this->default_settings) {
			foreach ((array)$user_settings as $key => $value) {
				if (is_array($value)) {
					foreach ($value as $key1 => $value1) {
						$this->settings[$key][$key1] = $value1;
					}
				} else {
					$this->settings[$key] = $value;
				}
			}
		}
		$this->is_demo = ($this->settings['accountID'] == self::$demo_account_id || empty($this->settings['accountID']));

		// Register general hooks
		add_filter('plugin_action_links', array(&$this, 'add_plugin_action_link'), 10, 2);
		add_action('admin_menu', array(&$this, 'register_settings_pages'));
		add_action('admin_init', array(&$this, 'handle_actions'));
		add_action('admin_notices', array($this, 'show_messages'));
		add_action('wp_head', array(&$this, 'tracking_code'));
		add_action('admin_print_styles', array($this, 'register_admin_styles'));
		add_action('admin_enqueue_scripts', array($this, 'register_admin_scripts'));
		add_action('wp_enqueue_scripts', array($this, 'register_site_scripts'));
		add_action('admin_footer', array($this, 'admin_scripts'));
		add_action('wp_ajax_convertable_report_data', array($this, 'report_data'));
		add_action('wp_ajax_convertable_form_data', array($this, 'form_data'));
		add_action('wp_ajax_convertable_update_form', array($this, 'update_form'));
		add_action('wp_ajax_convertable_lead_data', array($this, 'lead_data'));
		add_action('wp_ajax_convertable_update_lead', array($this, 'update_lead'));
		add_action('wp_ajax_convertable_delete_lead', array($this, 'delete_lead'));
		add_shortcode('convertable', array(&$this, 'shortcode_convertable'));

		register_deactivation_hook(__FILE__, array($this, 'convertable_deactivate'));
	}

	public static function init() {
        register_activation_hook(__FILE__, array( 'Convertable', 'install'));
	}

	public static function install() {
		//error_log('Convertable->install');
		//$convertable_url = 'convertable.hess.com';
		//$convertable_url = 'convertabledev.info';
		$convertable_url = 'convertable.com';
		$proto = 'http'.((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 's' : '') . '://';
		$convertable_url = $proto.$convertable_url;
		require_once('lib/convertableapi.php');
		$settings = array(
			'trackerID' => '',
			'accountName' => '',
			'accountID' => '',
			'thankYouPageID' => ''
		);
		$api = new ConvertableAPI(array('api-url' => $convertable_url, 'api-version' => Convertable::$api_version));
		$response = json_decode($api->authenticate('demo', 'demo'));
		//error_log('Convertable->install->authenticate->response: '.print_r($response, true));
		if ($response->success) {
			if (!empty($response->account)) {
				foreach($response->account as $key => $value) {
					$settings[$key] = $value;
				}
				//error_log('Convertable->install->settings: '.print_r($settings, true));
				update_option('convertable_options', $settings);
				$form_data = json_decode($api->form($settings['accountID'], $settings['secretKey']));
				//error_log('Convertable->install->form->data: '.print_r($form_data, true));
				$form = $form_data->form;
				foreach($form as $field) {
					if (!empty($field->fieldName) && !empty($field->fieldType)) {
						$data[] = array(
							'fieldName' => $field->fieldName,
							'fieldAlias' => $field->fieldAlias,
							'fieldType' => $field->fieldType,
							'fieldAlias' => $field->fieldAlias,
							'fieldSequence' => $field->fieldSequence,
							'fieldRule' => $field->fieldRule,
							'fieldRequired' => $field->fieldRequired
						);
					}
				}
				update_option('convertable_form', $data);
			}
		} else {

		}
	}

	function convertable_deactivate() {
		delete_option('convertable_options');
		delete_option('convertable_form');
		delete_option('convertable_messages');
		//error_log('Convertable->deactivate');
	}

	function convertable_wp_version_notice() {
		global $wp_version;
		echo '<div class="error"><p>' . sprintf( __( '<strong>Convertable</strong> requires WordPress %s or newer. You have version %s. Please upgrade!', 'convertable' ), $this->min_wp_version, $wp_version) . "</p></div>\n";
		//printf(__('The Convertable plugin requires Wordpress 3.1 or greater. You have version %s. Please upgrade your version of Wordpress'), $wp_version);
	}

	function add_plugin_action_link($links, $file) {
		static $this_plugin;
		if (empty($this_plugin)) $this_plugin = plugin_basename(__FILE__);
		if ($file == $this_plugin) {
			$settings_link = '<a href="'.admin_url('admin.php?page=convertable').'">'.__('Settings', 'convertable-locale').'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	function admin_scripts() {
		if (!empty($_GET['page'])) {
			if ('convertable_reports' == $_GET['page']) { ?>
				<script type="text/javascript" src="https://www.google.com/jsapi"></script>
				<script type="text/javascript">
				google.load("visualization", "1", {packages:["corechart"]});
				lead_phases = ['<?php echo implode("','", $this->lead_status); ?>'];
				Number.prototype.formatMoney = function(decPlaces, thouSeparator, decSeparator) {
					var n = this,
					decPlaces = isNaN(decPlaces = Math.abs(decPlaces)) ? 2 : decPlaces,
					decSeparator = decSeparator == undefined ? "." : decSeparator,
					thouSeparator = thouSeparator == undefined ? "," : thouSeparator,
					sign = n < 0 ? "-" : "",
					i = parseInt(n = Math.abs(+n || 0).toFixed(decPlaces)) + "",
					j = (j = i.length) > 3 ? j % 3 : 0;
					return sign + (j ? i.substr(0, j) + thouSeparator : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + thouSeparator) + (decPlaces ? decSeparator + Math.abs(n - i).toFixed(decPlaces).slice(2) : "");
				};
				var jqVersion;
				jQuery(document).ready(function($) {
					jqVersion = parseInt($.fn.jquery.replace(/\./g, ''));
					convertable_data = {
						action: 'convertable_report_data',
						filter: '',
						medium: '',
						page_num: 1
					};
					lead_data = {
						action: 'convertable_lead_data',
						lead_id: 0
					}
					update_lead_data = {
						action: 'convertable_update_lead',
						lead_id: 0,
						phase: '',
						assignment: '',
						quote: 0,
						award: 0,
						notes: ''
					}
					delete_lead_data = {
						action: 'convertable_delete_lead',
						lead_id: 0
					}
					getConvertableData();
				});
				function convertablePager(p) {
					if (!jQuery(p).hasClass('disabled')) {
						convertable_data.page_num = parseInt(jQuery(p).attr('data-page'));
						getConvertableData();
					}
				}
				function convertablePage(p) {
					var page_num = parseInt(p);
					if (page_num > 0) {
						convertable_data.page_num = page_num;
						getConvertableData();
					}
				}
				function closeLead(lead_id) {
					jQuery('#edit-'+lead_id).slideUp(function(){
						jQuery(this).remove();
						jQuery('#lead-'+lead_id+' .lead-detail').removeClass('expanded').removeClass('loading');
					});
					return false;
				}
				function updateLead(lead_id) {
					update_lead_data.lead_id = lead_id;
					var quoted = jQuery('#lead-'+lead_id+'-quote').val();
					var awarded = jQuery('#lead-'+lead_id+'-award').val();
					var phase = jQuery('#lead-'+lead_id+'-phase').val();
					update_lead_data.phase = phase;
					update_lead_data.assignment = jQuery('#lead-'+lead_id+'-assignment').val();
					update_lead_data.quote = quoted;
					update_lead_data.award = awarded;
					update_lead_data.notes = jQuery('#lead-'+lead_id+'-notes').val();
					jQuery.ajax({
						type: 'post',
						url: ajaxurl,
						data: update_lead_data,
						dataType: 'json',
						success: function(data){
							quoted = parseInt(quoted);
							awarded = parseInt(awarded);
							quoted = '$'+quoted.formatMoney(2, ',', '.');
							awarded = '$'+awarded.formatMoney(2, ',', '.');
							jQuery('#lead-'+lead_id).find('td.phase:first').text(phase);
							jQuery('#lead-'+lead_id).find('td.quoted:first').text(quoted);
							jQuery('#lead-'+lead_id).find('td.awarded:first').text(awarded);
							alert(data.message);
						},
						error: function(jqXHR, textStatus){
							alert("Update failed: "+textStatus);
						}
					});
					return false;
				}
				function deleteLead(lead_id) {
					if (confirm('Delete this Lead?')) {
						delete_lead_data.lead_id = lead_id;
						jQuery.ajax({
							type: 'post',
							url: ajaxurl,
							data: delete_lead_data,
							dataType: 'json',
							success: function(data){
								if (data.success) {
									jQuery('#lead-'+lead_id+', #edit-'+lead_id).remove();
								}
								alert(data.message);
							},
							error: function(jqXHR, textStatus){
								alert("Delete failed: "+textStatus);
							}
						});
					}
					return false;
				}
				function viewLead(lead_id) {
					lead_data.lead_id = lead_id;
					var is_expanded = (jQuery('#edit-'+lead_id).length > 0) ? true : false;
					if (!is_expanded) {
						jQuery('#lead-list td .lead-detail').removeClass('expanded');
						jQuery('#lead-list tr.inline-editor').remove();
						jQuery('#lead-'+lead_id+' .lead-detail').addClass('loading');
						jQuery.ajax({
							type: 'post',
							url: ajaxurl,
							data: lead_data,
							dataType: 'json',
							success: function(data) {
								//alert(JSON.stringify(data));
								if (data.success) {
									jQuery('#lead-'+lead_id+' .lead-detail').addClass('expanded');
									if (data.lead) {
										var lead = '<tr id="edit-'+data.lead.id+'" class="inline-edit-row inline-edit-row-page inline-edit-page quick-edit-row quick-edit-row-page inline-edit-page inline-editor">';
										lead += '<td class="colspanchange" colspan="9"><fieldset class="inline-edit-col-left"><div class="inline-edit-col"><h4>LEAD DETAIL</h4>';
										if (data.lead.form) {
											jQuery.each(data.lead.form, function(i, fitem) {
												var field_val = (fitem.ftype == 'email') ? '<a href="mailto:'+fitem.fval+'">'+fitem.fval+'</a>' : fitem.fval;
												lead += '<label><span class="title">'+fitem.falias+'</span><span class="input-text-wrap '+fitem.ftype+'">'+field_val+'</span></label>';
											});
										}
										if (data.lead.ip) {
											lead += '<label><span class="title">IP Address</span><span class="input-text-wrap">'+data.lead.ip+'</span></label>';
										}
										if (data.lead.host) {
											lead += '<label><span class="title">Hostname</span><span class="input-text-wrap">'+data.lead.host+'</span></label>';
										}
										if (data.lead.geocity || data.lead.georeg || data.lead.geocntn || data.lead.geocntc) {
											lead += '<label><span class="title">Geolocation</span><span class="input-text-wrap">';
											if (data.lead.geocity) {
												lead += data.lead.geocity+'<br/>';
											}
											if (data.lead.georeg) {
												lead += data.lead.georeg+'<br/>';
											}
											if (data.lead.geocntn) {
												lead += data.lead.geocntn+'<br/>';
											}
											if (data.lead.geocntc) {
												lead += '<img src="<?php echo $this->convertable_url; ?>/geoip/flag_icons/png/'+data.lead.geocntc+'.png" />';
											}
											lead += '</span></label>';
										}
										if (data.lead.web) {
											lead += '<label><span class="title">Browser</span><span class="input-text-wrap">'+data.lead.web+'</span></label>';
										}
										if (data.lead.ptf) {
											lead += '<label><span class="title">Platform</span><span class="input-text-wrap">'+data.lead.ptf+'</span></label>';
										}
										if (data.lead.med) {
											lead += '<label><span class="title">Traffic Medium</span><span class="input-text-wrap">'+data.lead.med+'</span></label>';
										}
										if (data.lead.src) {
											lead += '<label><span class="title">Traffic Source</span><span class="input-text-wrap">'+data.lead.src+'</span></label>';
										}
										if (data.lead.kwd) {
											lead += '<label><span class="title">Keyword</span><span class="input-text-wrap">'+data.lead.kwd+'</span></label>';
										}
										if (data.lead.csrc) {
											lead += '<label><span class="title">Campaign Source</span><span class="input-text-wrap">'+data.lead.csrc+'</span></label>';
										}
										if (data.lead.cmed) {
											lead += '<label><span class="title">Campaign Medium</span><span class="input-text-wrap">'+data.lead.cmed+'</span></label>';
										}
										if (data.lead.cnme) {
											lead += '<label><span class="title">Campaign Name</span><span class="input-text-wrap">'+data.lead.cnme+'</span></label>';
										}
										if (data.lead.cterm) {
											lead += '<label><span class="title">Campaign Term</span><span class="input-text-wrap">'+data.lead.cterm+'</span></label>';
										}
										if (data.lead.ccon) {
											lead += '<label><span class="title">Campaign Content</span><span class="input-text-wrap">'+data.lead.ccon+'</span></label>';
										}
										if (data.lead.ref) {
											lead += '<label><span class="title">Referral URL</span><span class="input-text-wrap"><textarea class="long">'+data.lead.ref+'</textarea></span></label>';
										}
										if (data.lead.entr) {
											lead += '<label><span class="title">Landing Page URL</span><span class="input-text-wrap"><textarea class="long">'+data.lead.entr+'</textarea></span></label>';
										}
										if (data.lead.frm) {
											lead += '<label><span class="title">Contact Form URL</span><span class="input-text-wrap"><textarea class="long">'+data.lead.frm+'</textarea></span></label>';
										}
										if (data.lead.uid) {
											lead += '<label><span class="title">Random UID</span><span class="input-text-wrap">'+data.lead.uid+'</span></label>';
										}
										lead += '</div></fieldset><fieldset class="inline-edit-col-right"><div class="inline-edit-col">';
										lead += '<h4>Engagement Summary</h4><form name="edit-lead" id="edit-lead-form-'+lead_id+'"><label><span class="title">Sales Status</span><select name="leadPhase" id="lead-'+lead_id+'-phase"><option value="">Select</option>';
										for (p in lead_phases) {
											lead += '<option value="'+lead_phases[p]+'"';
											if (lead_phases[p] == data.lead.phs) {
												lead += ' selected';
											}
											lead += '>'+lead_phases[p]+'</option>';
										}
										lead += '</select></label>';
										lead += '<label><span class="title">Lead Assignment</span><span class="input-text-wrap"><input type="text" value="'+data.lead.asgn+'" name="leadAssignment" id="lead-'+lead_id+'-assignment"></span></label>';
										var quoted = 0;
										if (data.lead.qut != '') {
											quoted = (isNaN(data.lead.qut)) ? 0 : parseInt(data.lead.qut);
										}
										lead += '<label><span class="title">Amount Quoted</span><span class="input-text-wrap"><input type="text" value="'+quoted+'" name="conversionQuote" id="lead-'+lead_id+'-quote"></span></label>';
										var awarded = 0;
										if (data.lead.awd != '') {
											awarded = (isNaN(data.lead.awd)) ? 0 : parseInt(data.lead.awd);
										}
										lead += '<label><span class="title">Amount Awarded</span><span class="input-text-wrap"><input type="text" value="'+awarded+'" name="conversionAward" id="lead-'+lead_id+'-award"></span></label>';
										lead += '<label><span class="title">Updates/Notes</span><span class="input-text-wrap"><textarea class="notes" name="conversionNote" id="lead-'+lead_id+'-notes" rows="6">'+data.lead.note+'</textarea></span></label>';
										lead += '</form></fieldset>';
										if (data.lead.det) {
											var detail = JSON.stringify(data.lead.det);
											if (detail.length > 2) {
												lead += '<br class="clear"><fieldset><div class="inline-edit-col"><h4>PREVIOUS VISITS</h4>'
												jQuery.each(data.lead.det, function(i, item) {
													lead += '<h5>Visit #'+(i+1)+'</h5><ul class="pvisits">';
													jQuery.each(item, function(j, ditem) {
														lead += '<li>'+ditem+'</li>';
													});
													lead += '</ul></li>';
												});
												lead += '</div></fieldset>';
											}
										}
										lead += '<p class="submit inline-edit-save">';
										lead += '<a class="button-secondary cancel alignleft" title="Close" onclick="closeLead('+data.lead.id+'); return false;">Close</a>';
										lead += '<a class="button-primary save alignright" title="Update" href="#update-lead" onclick="updateLead('+data.lead.id+'); return false;">Update</a>';
										lead += '<a class="button-primary delete alignright" title="Delete" href="#delete-lead" onclick="deleteLead('+data.lead.id+'); return false;">Delete</a>';
										lead += '<br class="clear"></p></td></tr>';
										//alert('#lead-'+data.lead.id+': '+lead);
										jQuery('#lead-'+data.lead.id).after(lead);
										jQuery('html, body').animate({
											scrollTop: jQuery('#lead-'+data.lead.id).offset().top - 50
										}, 500);
									} else {
										alert('No lead data found');
									}
								} else {
									alert(data.message);
								}
							},
							error: function(jqXHR, textStatus) {
								alert( "Request failed: "+textStatus);
							},
							complete: function() {
								jQuery('#lead-'+lead_id+' .lead-detail').removeClass('loading');
							}
						});
					} else {
						closeLead(lead_id);
					}
				}
				function getConvertableData() {
					//console.log('jqVersion = '+jqVersion);
					convertable_data.filter = jQuery('#lead-status-filter').val();
					convertable_data.medium = jQuery('#lead-medium-filter').val();
					jQuery('#lead-list').html('<tr id="convertable-loading"><td colspan="8">&nbsp;</td></tr>');
					jQuery.ajax({
						type: 'post',
						url: ajaxurl,
						data: convertable_data,
						dataType: 'json',
						success: function(data) {
							if (data.success) {
								if (data.leads.items) {
									jQuery.each(data.leads.items, function(i, lead) {
										var row_class = (i % 2 == 0) ? 'alternate' : '';
										var item = '<tr id="lead-'+lead.id+'" class="'+row_class+'"><td><div class="lead-detail" data-lead-id="'+lead.id+'" onclick="viewLead('+lead.id+'); return false;"><div></div></div></td><td nowrap>'+lead.num+'</td><td>'+lead.date+'</td><td class="wrapped">'+lead.med+'</td><td nowrap>';
										if (lead.src.toLowerCase().indexOf('google') != -1) {
											item += '<img class="convertable-icon" src="<?php echo CONVERTABLE_URL; ?>/assets/images/icon-google.png" />';
										} else if (lead.src.toLowerCase().indexOf('bing') != -1) {
											item += '<img class="convertable-icon" src="<?php echo CONVERTABLE_URL; ?>/assets/images/icon-bing.png" />';
										} else if (lead.src.toLowerCase().indexOf('yahoo') != -1) {
											item += '<img class="convertable-icon" src="<?php echo CONVERTABLE_URL; ?>/assets/images/icon-yahoo.png" />';
										}
										item += lead.src;
										item += '</td><td class="wrapped">'+lead.kwd+'</td>';
										item += '<td class="phase">'+lead.phs+'</td>';
										item += '<td class="quoted" nowrap>'+lead.qut+'</td>';
										item += '<td class="awarded" nowrap>'+lead.awd+'</td>';
										item += '<td><a href="#" onclick="deleteLead('+lead.id+'); return false;" title="Delete"Lead"><img src="<?php echo CONVERTABLE_URL; ?>/assets/images/cross.png" alt="Delete Lead" /></a></td></tr>';
										jQuery('#lead-list').append(item);
									});
								} else {
									jQuery('#lead-list').html('<tr id="convertable-no-data"><td colspan="9"><b>No report data available</b></td></tr>')
								}
								var total_leads = parseInt(data.leads.total);
								var total_revenue = parseInt(data.revenue.total);
								var leads_today = parseInt(data.leads.timeframe.today);
								var leads_week = parseInt(data.leads.timeframe.week);
								var leads_month = parseInt(data.leads.timeframe.month);
								var convertable_page_num = parseInt(data.page);
								var convertable_page_count = parseInt(data.pages);
								jQuery('#convertable-total-leads').html(total_leads.formatMoney(0, ',', '.'));
								jQuery('#convertable-revenue').html('$'+total_revenue.formatMoney(0, ',', '.'));
								if (data.leads.timeframe && total_leads > 0) {
									jQuery('#convertable-today-leads').html(leads_today.formatMoney(0, ',', '.'));
									jQuery('#convertable-week-leads').html(leads_week.formatMoney(0, ',', '.'));
									jQuery('#convertable-month-leads').html(leads_month.formatMoney(0, ',', '.'));
								}
								if (convertable_page_count > 1) {
									var filters = '<div class="alignleft actions"><select name="lead-status-filter" id="lead-status-filter"><option value="">Filter By Status</option>';
									<?php foreach ($this->lead_status as $status) : ?>
										filters += '<option value="<?php echo $status; ?>"><?php echo $status; ?></option>';
									<?php endforeach; ?>
									filters += '</select><select name="lead-medium-filter" id="lead-medium-filter"><option value="">Filter By Medium</option>';
									<?php foreach ($this->lead_mediums as $medium) : ?>
										filters += '<option value="<?php echo $medium; ?>"><?php echo $medium; ?></option>';
									<?php endforeach; ?>
									filters += '</select><input type="submit" value="Filter" onclick="getConvertableData(); return false;" class="button" id="convertable-filter-by-status"></div>';
									var first_page_class = (convertable_page_num == 1) ? 'first-page disabled' : 'first-page';
									var prev_page_class = (convertable_page_num == 1) ? 'prev-page disabled' : 'prev-page';
									var prev_page = (convertable_page_num == 1) ? 1 : convertable_page_num - 1;
									var next_page_class = (convertable_page_num == convertable_page_count) ? 'next-page disabled' : 'next-page';
									var next_page = (convertable_page_num == convertable_page_count) ? convertable_page_num : convertable_page_num + 1;
									var last_page_class = (convertable_page_num == convertable_page_count) ? 'last-page disabled' : 'last-page';
									var top_pager = '<div class="tablenav-pages"><div class="tablenav-pages"><span class="displaying-num">'+data.leads.total+' items</span><span class="pagination-links">';
									top_pager += '<a href="#1" onclick="convertablePager(this); return false;" title="Go to the first page" data-page="1" class="'+first_page_class+'">&laquo;</a>';
									top_pager += '<a href="#'+prev_page+'" onclick="convertablePager(this); return false;" title="Go to the previous page" data-page="'+prev_page+'" class="'+prev_page_class+'">&lsaquo;</a>';
									var bottom_pager = top_pager;
									top_pager += '<span class="paging-input"><input type="text" onchange="convertablePage(this.value); return false;" size="1" value="'+convertable_page_num+'" name="paged" title="Current page" class="current-page"> of <span class="total-pages">'+convertable_page_count+'</span></span>';
									bottom_pager += '<span class="paging-input">'+convertable_page_num+' of <span class="total-pages">'+convertable_page_count+'</span></span>';
									top_pager += '<a href="#'+next_page+'" onclick="convertablePager(this); return false;" title="Go to the next page" data-page="'+next_page+'" class="'+next_page_class+'">&rsaquo;</a>';
									top_pager += '<a href="#'+convertable_page_count+'" onclick="convertablePager(this); return false;" title="Go to the last page" data-page="'+convertable_page_count+'" class="'+last_page_class+'">&raquo;</a>';
									bottom_pager += '<a href="#'+next_page+'" onclick="convertablePager(this); return false;" title="Go to the next page" data-page="'+next_page+'" class="'+next_page_class+'">&rsaquo;</a>';
									bottom_pager += '<a href="#'+convertable_page_count+'" onclick="convertablePager(this); return false;" title="Go to the last page" data-page="'+convertable_page_count+'" class="'+last_page_class+'">&raquo;</a>';
									jQuery('#convertable-leads-pager-top').html(filters+top_pager);
									jQuery('#convertable-leads-pager-bottom').html(bottom_pager);
								} else {
									jQuery('#convertable-leads-pager-top .tablenav-pages, #convertable-leads-pager-bottom').html('');
								}
								if (data.leads.sources) {
									var pie_chart_data = new google.visualization.DataTable();
									pie_chart_data.addColumn('string', 'Source');
									pie_chart_data.addColumn('number', 'Leads');
									var num_sources = 0;
									jQuery.each(data.leads.sources, function(source, source_count) {
										num_sources++;
									});
									pie_chart_data.addRows(num_sources);
									var i = 0;
									jQuery.each(data.leads.sources, function(source, source_count) {
										pie_chart_data.setCell(i, 0, source);
										pie_chart_data.setCell(i, 1, parseInt(source_count));
										i++;
									});
									var pie_chart_view = new google.visualization.DataView(pie_chart_data);
									pie_chart_view.setColumns([0, 1]);
									var pie_chart_options = {
										title: "Lead Sources",
										colors: ["#73A737", "#89B15A", "#96CA59", "#A7D86E", "#ACD87A"],
										chartArea: {left: 0, top: 35, width: "80%", height: "80%"},
										is3D: true
									};
									var pie_chart = new google.visualization.PieChart(document.getElementById('convertable-pie-chart'));
									pie_chart.draw(pie_chart_view, pie_chart_options);
								}
								if (data.revenue.sources) {
									var bar_chart_data = new google.visualization.DataTable();
									bar_chart_data.addColumn('string', 'Source');
									bar_chart_data.addColumn('number', 'Revenue');
									var num_sources = 0;
									jQuery.each(data.revenue.sources, function(source, source_revenue) {
										num_sources++;
									});
									bar_chart_data.addRows(num_sources);
									var i = 0;
									jQuery.each(data.revenue.sources, function(source, source_revenue) {
										bar_chart_data.setCell(i, 0, source);
										bar_chart_data.setCell(i, 1, parseInt(source_revenue));
										i++;
									});
									var bar_chart_view = new google.visualization.DataView(bar_chart_data);
									bar_chart_view.setColumns([0, 1]);
									var formatter = new google.visualization.NumberFormat({prefix: "$"});
									formatter.format(bar_chart_data, 1);
									var bar_chart_options = {
										title: "Revenue By Source",
										hAxis: {title: "Revenue"},
										colors: ["#73A737", "#89B15A", "#96CA59", "#A7D86E", "#ACD87A"],
										chartArea: {left: 100, top: 35, width: "60%", height: "80%"}
									};
									var bar_chart = new google.visualization.BarChart(document.getElementById('convertable-revenue-chart'));
									bar_chart.draw(bar_chart_view, bar_chart_options);
								}
								jQuery('#lead-status-filter').val(data.filter);
								jQuery('#lead-medium-filter').val(data.medium);
							} else {
								alert(data.message);
							}
						},
						error: function(jqXHR, textStatus){
							alert( "Request failed: "+textStatus);
						},
						complete: function(){
							jQuery('#convertable-loading, #convertable-no-data').remove();
						}
					});
				}
				</script><?php
			} elseif ('convertable_form' == $_GET['page']) {
				$nonce = wp_create_nonce('convertable-form-data');
				$form_load_url = $this->get_admin_url('convertable_form_data').'&action=load_form&_wpnonce='.$nonce;
				$form_save_url = $this->get_admin_url('convertable_form_data').'&action=save_form&_wpnonce='.$nonce; ?>
				<script type="text/javascript">
					jQuery(document).ready(function($) {
						$('#form-builder').formbuilder({
							'save_url': 'convertable_update_form',
							'load_url': 'convertable_form_data',
							'useJson' : true
						});
						$('#form-builder ul.frmb').sortable({opacity: 0.6, cursor: 'move'});
					});
				</script><?php
			}
		}
	}

	function form_data() {
		error_reporting(0);
		//error_log('Convertable->form_data');
		$data = json_decode($this->_api->form($this->settings['accountID'], $this->settings['secretKey']));
		$form = $data->form;
		//error_log('Convertable->form_data->form: '.print_r($form, true));
		$this->save_form_data($form);
		$form_data = array('form_id' => 'form-'.$this->settings['accountID'], 'form_structure' => array());
		foreach ($form as $field) {
			$title = '';
			$values = '';
			switch($field->fieldType) {
				case 'text':
				case 'text|10':
					$field_type = 'input_text';
					$values = $field->fieldAlias;
					break;
				case 'textarea':
				case 'text|40':
				case 'textareaL':
					$field_type = 'textarea';
					$values = $field->fieldAlias;
					break;
				case 'checkbox':
					$field_type = 'checkbox';
					$title = $field->fieldAlias;
					$options = explode(',', $field->fieldRule);
					if (!empty($options)) {
						foreach ($options as $i => $opt) {
							$key = $i + 1;
							$values[$key] = array('value' => $opt, 'baseline' => 'false');
						}
					} else {
						$values['1'] = array('value' => 'None', 'baseline' => 'false');
					}
					break;
				case 'radio':
					$field_type = 'radio';
					$title = $field->fieldAlias;
					$options = explode(',', $field->fieldRule);
					if (!empty($options)) {
						foreach ($options as $i => $opt) {
							$key = $i + 1;
							$values[$key] = array('value' => $opt, 'baseline' => 'false');
						}
					} else {
						$values['1'] = array('value' => 'None', 'baseline' => 'false');
					}
					break;
				case 'select':
					$field_type = 'select';
					$title = $field->fieldAlias;
					//error_log('Convertable->form_data->form->field('.$field->fieldAlias.')->rule: '.$field->fieldRule);
					if (!empty($field->fieldRule)) {
						$options = explode(',', $field->fieldRule);
						//error_log('Convertable->form_data->form->field('.$field->fieldAlias.')->options: '.print_r($options, true));
						if (!empty($options)) {
							foreach ($options as $i => $opt) {
								$key = $i + 1;
								$values[$key] = array('value' => $opt, 'baseline' => 'false');
							}
						} else {
							$values['1'] = array('value' => 'None', 'baseline' => 'false');
						}
					} else {
						$values['1'] = array('value' => 'None', 'baseline' => 'false');
					}
					break;
				default:
					$field_type = $field->fieldType;
					$values = $field->fieldAlias;
					break;
			}
			if (is_array($values)) {
				//error_log('Convertable->form_data->form->field('.$field->fieldAlias.')->values: '.print_r($values, true));
			} else {
				//error_log('Convertable->form_data->form->field('.$field->fieldAlias.')->values: '.$values);
			}
			$form_item = array(
					'required' => (!empty($field->fieldRequired)) ? 'checked' : 'false',
					'cssClass' => $field_type,
					'values' => $values
			);
			if (!empty($title)) $form_item['title'] = $title;
			$form_data['form_structure'][] = $form_item;
		}
		//error_log('Convertable->form_data->form_data: '.print_r($form_data, true));
		echo json_encode($form_data);
		die();
	}

	function update_form() {
		$response = array(
			'success' => false,
			'messages' => array()
		);
		if (!empty($_POST)) {
			//print_r($_POST);
			if (!empty($_POST['frmb'])) {
				$form_data = $_POST['frmb'];
				if (!empty($_POST['thank-you-page'])) {
					//error_log('Convertable->update_form->thank-you-page('.$_POST['thank-you-page'].')');
					$page_id = (int) $_POST['thank-you-page'];
					$thank_you_url = get_permalink($page_id);
					if ($thank_you_url === FALSE) {
						$this->settings['thankYouPageID'] = '';
						$thank_you_url = '';
					} else {
						$this->settings['thankYouPageID'] = $page_id;
					}
				} else {
					$thank_you_url = '';
					$this->settings['thankYouPageID'] = '';
				}
				$this->settings['useThankYouURL'] = (!empty($thank_you_url)) ? 1 : 0;
				$this->settings['thankyouURL'] = $thank_you_url;
				update_option('convertable_options', $this->settings);
				//error_log('Convertable->update_form->thank_you_url('.$thank_you_url.')');
				//error_log('Convertable->update_form->form_data('.print_r($form_data, true).')');
				if (!empty($form_data)) {
					$processed_form = array();
					foreach ($form_data as $i => $field) {
						$rule = '';
						switch($field['cssClass']) {
							case 'input_text':
								$title = (!empty($field['values'])) ? $field['values'] : 'Text '+($i+1);
								$ftype = 'text';
								break;
							case 'textarea':
								$title = (!empty($field['values'])) ? $field['values'] : 'Textarea '+($i+1);
								$ftype = 'textarea';
								break;
							case 'radio':
								$title = (!empty($field['title'])) ? $field['title'] : 'Radio '+($i+1);
								$ftype = 'radio';
								if (!empty($field['values'])) {
									if (is_array($field['values'])) {
										$options = array();
										foreach ($field['values'] as $x => $field_data) {
											$options[] = (!empty($field_data['value'])) ? $field_data['value'] : 'Option '+$x;
										}
										$options = array_filter($options);
										$rule = implode(',', $options);
									} else {
										$rule = 'Option 1';
									}
								} else {
									$rule = 'Option 1';
								}
								break;
							case 'select':
								$title = (!empty($field['title'])) ? $field['title'] : 'Radio '+($i+1);
								$ftype = 'select';
								if (!empty($field['values'])) {
									//error_log('Convertable->update_form->field('.$field['title'].')->values:'.print_r($field['values'], true));
									if (is_array($field['values'])) {
										$options = array();
										foreach ($field['values'] as $x => $field_data) {
											////error_log('Convertable->update_form->field('.$field['title'].'):'.print_r($field_data, true));
											$options[] = (!empty($field_data['value'])) ? $field_data['value'] : 'Option '+$x;
										}
										$options = array_filter($options);
										$rule = implode(',', $options);
									} else {
										$rule = 'Option 1';
									}
								} else {
									$rule = 'Option 1';
								}
								break;
							case 'checkbox':
								$title = (!empty($field['title'])) ? $field['title'] : 'Radio '+($i+1);
								$ftype = 'checkbox';
								if (!empty($field['values'])) {
									if (is_array($field['values'])) {
										$options = array();
										foreach ($field['values'] as $x => $field_data) {
											$options[] = (!empty($field_data['value'])) ? $field_data['value'] : 'Option '+$x;
										}
										$options = array_filter($options);
										$rule = implode(',', $options);
									} else {
										$rule = 'Option 1';
									}
								} else {
									$rule = 'Option 1';
								}
								break;

						}
						$required = ($field['required'] == 'true') ? 1 : 0;
						$item = array(
							'fieldAlias' => $title,
							'fieldName' => $this->gen_field_name($title),
							'fieldSequence' => $i+1,
							'fieldType' => $ftype,
							'fieldRequired' => $required,
							'fieldRule' => $rule
						);
						$processed_form[] = $item;
					}
					//print_r($processed_form);
					//error_log('Convertable->update_form->processed_form:'.print_r($processed_form, true));
					$response = json_decode($this->_api->update_form($this->settings['accountID'], $this->settings['secretKey'], $thank_you_url, $processed_form));
					//error_log('Convertable->update_form->_api->update_form->response:'.print_r($response, true));
					if (!empty($response->form)) {
						//error_log('Convertable->update_form->_api->update_form->response->form: '.$response->form);
						$this->save_form_data($response->form);
					}
				} else {
					$response['messages'][] = 'No form data submitted';
				}
			} else {
				$response['messages'][] = 'No form data submitted';
			}
		} else {
			$response['messages'][] = 'No form data submitted';
		}
		echo json_encode($response);
		die();
	}

	function gen_field_name($str = '') {
		$replace = '_';
		$trans = array(
			'&\#\d+?;'				=> '',
			'&\S+?;'				=> '',
			'\s+'					=> $replace,
			'[^a-z0-9\-\._]'		=> '',
			$replace.'+'			=> $replace,
			$replace.'$'			=> $replace,
			'^'.$replace			=> $replace,
			'\.+$'					=> ''
		);
		$str = strip_tags($str);
		foreach ($trans as $key => $val) {
			$str = preg_replace("#".$key."#i", $val, $str);
		}
		$str = strtolower($str);
		return substr(trim(stripslashes($str)), 0, 50);
	}

	function update_lead() {
		$lead_id = (!empty($_POST['lead_id'])) ? (int) $_POST['lead_id'] : 0;
		if (!empty($_POST)) {
			//error_log('Convertable->update_lead->_POST('.print_r($_POST, true).')');
			$params = array();
			$params['leadPhase'] = (!empty($_POST['phase'])) ? trim(strip_tags($_POST['phase'])) : '';
			$params['leadAssignment'] = (!empty($_POST['assignment'])) ? trim(strip_tags($_POST['assignment'])) : '';
			$params['conversionQuote'] = (!empty($_POST['quote'])) ? trim(strip_tags($_POST['quote'])) : 0;
			$params['conversionAward'] = (!empty($_POST['award'])) ? trim(strip_tags($_POST['award'])) : 0;
			$params['conversionNote'] = (!empty($_POST['notes'])) ? trim(strip_tags($_POST['notes'])) : '';
			////error_log('Convertable->update_lead->params('.print_r($params, true).')');
			$response = json_decode($this->_api->update_lead($this->settings['accountID'], $this->settings['secretKey'], $lead_id, $params));
			echo json_encode($response);
			die();
		} else {
			echo json_encode(array(
				'success' => false,
				'message' => 'No data to update lead'
			));
			die();
		}
	}

	function delete_lead() {
		$lead_id = (!empty($_POST['lead_id'])) ? (int) $_POST['lead_id'] : 0;
		if (!empty($lead_id)) {
			//error_log('Convertable->delete_lead->_POST('.print_r($_POST, true).')');
			$response = json_decode($this->_api->delete_lead($this->settings['accountID'], $this->settings['secretKey'], $lead_id));
			echo json_encode($response);
			die();
		} else {
			echo json_encode(array(
				'success' => false,
				'message' => 'No ID for lead to delete'
			));
			die();
		}
	}

	function report_data() {
		$page_num = (!empty($_POST['page_num'])) ? (int) $_POST['page_num'] : 1;
		if (!empty($_POST['filter'])) {
			if (in_array($_POST['filter'], $this->lead_status)) {
				$filter = $_POST['filter'];
			} else {
				$filter = '';
			}
		} else {
			$filter = '';
		}
		if (!empty($_POST['medium'])) {
			if (in_array($_POST['medium'], $this->lead_mediums)) {
				$medium = $_POST['medium'];
			} else {
				$medium = '';
			}
		} else {
			$medium = '';
		}
		$response = json_decode($this->_api->report_data($this->settings['accountID'], $this->settings['secretKey'], $page_num, $filter, $medium));
		echo json_encode($response);
		die();
	}

	function lead_data() {
		$lead_id = (!empty($_POST['lead_id'])) ? (int) $_POST['lead_id'] : 0;
		//error_log('lead_data->lead_id = '.$lead_id);
		$response = json_decode($this->_api->lead_data($this->settings['accountID'], $this->settings['secretKey'], $lead_id));
		echo json_encode($response);
		die();
	}

	function form_builder() {
		require CONVERTABLE_PATH.'views/header.php'; ?>
		<div class="metabox-holder">
			<p>Paste <code>[convertable]</code> into any Wordpress page or post to use this form.</p>
			<p>Drag and drop the form elements on the left to change their order. Add new form elements from the list on the right.</p>
			<div class="postbox-container" style="width: 75%;">
				<div class="postbox">
					<h3><span>Customize Form</span></h3>
					<div id="content" class="wrapper">
						<div id="work-area">
							<div id="form-builder" class="clearfix"></div><!-- /#form-builder -->
						</div><!-- /#work-area -->
					</div><!-- /#content -->
				</div><!-- /.postbox -->
			</div><!-- /.postbox-container -->
			<div class="alignright" style="width: 24%;">
				<div class="postbox">
					<h3>Thank You Page</h3>
					<div class="inside">
						<p>If you already have a thank you page on your site, you can select it here. If not, the form will show a "thank you" response on your form page.</p>
						<?php
						$args = array(
							'name' => 'thank-you-page',
							'sort_order'   => 'ASC',
							'sort_column'  => 'post_title',
							'post_type' => 'page',
							'show_option_none' => 'I don\'t have a thank you page'
						);
						if (!empty($this->settings['thankYouPageID'])) {
							$args['selected'] = $this->settings['thankYouPageID'];
						}
						wp_dropdown_pages($args); ?>
					</div>
				</div>
				<div class="postbox">
					<h3>Form Elements</h3>
					<div id="controls">
						<p>Select a new field to add from the list below.</p>
					</div>
				</div>
			</div>
		</div>
		<?php require CONVERTABLE_PATH.'views/footer.php';
	}

	function get_admin_url($hook = 'convertable') {
		$hook = (!empty($hook)) ? $hook : $this->_hooks[0];
		return admin_url('admin.php?page='.$hook);
	}

	function handle_actions() {

		if (empty($_GET['action']) || empty($_GET['page']) || !in_array($_GET['page'], $this->_hooks)) {
			return;
		}
		$log_str = 'Convertable->handle_actions->page('.$_GET['page'].')';
		if (!empty($_GET['action'])) $log_str .= ')->action->('.$_GET['action'].')';
		//error_log($log_str);
		if ('login' == $_GET['action']) {
			$nonce = $_REQUEST['_wpnonce'];
			if (!wp_verify_nonce($nonce, 'convertable-login')) die('Security check');
			check_admin_referer('convertable-login');
			if (!empty($_POST)) {
				$username = (!empty($_POST['convertable_user'])) ? strip_tags(stripslashes($_POST['convertable_user'])) : '';
				$password = (!empty($_POST['convertable_password'])) ? strip_tags(stripslashes($_POST['convertable_password'])) : '';
				//error_log('Convertable->authenticate('.$username.', '.$password.')');
				$response = json_decode($this->_api->authenticate($username, $password));
				if ($response->success) {
					$redirect_args = array('message' => 'success');
					if (!empty($response->account)) {
						foreach($response->account as $key => $value) {
							$this->settings[$key] = $value;
						}
						update_option('convertable_options', $this->settings);
					}
				} else {
					$redirect_args = array('message' => 'failure');
				}
				if (!empty($response->message)) {
					$this->set_message($response->message);
				}
			}
			wp_safe_redirect(add_query_arg($redirect_args, remove_query_arg(array('action','_wpnonce'))));
			exit;
		} elseif ('account' == $_GET['action']) {
			$nonce = $_REQUEST['_wpnonce'];
			if (!wp_verify_nonce($nonce, 'convertable-account')) die('Security check');
			check_admin_referer('convertable-account');
			if (!empty($_POST)) {
				$username = (!empty($_POST['convertable_user'])) ? strip_tags(stripslashes($_POST['convertable_user'])) : '';
				$password = (!empty($_POST['convertable_password'])) ? strip_tags(stripslashes($_POST['convertable_password'])) : '';
				$password_conf = (!empty($_POST['convertable_password_conf'])) ? strip_tags(stripslashes($_POST['convertable_password_conf'])) : '';
				//error_log('Convertable->account('.$username.', '.$password.')');
				$response = json_decode($this->_api->signup($username, $password, $password_conf, get_option('siteurl')));
				if ($response->success) {
					$redirect_args = array('message' => 'success');
					if (!empty($response->account)) {
						foreach($response->account as $key => $value) {
							$this->settings[$key] = $value;
						}
						update_option('convertable_options', $this->settings);
					}
				} else {
					$redirect_args = array('message' => 'failure');
				}
				if (!empty($response->message)) {
					$this->set_message($response->message);
				}
			}
			wp_safe_redirect(add_query_arg($redirect_args, remove_query_arg(array('action','_wpnonce'))));
			exit;
		} elseif ('settings' == $_GET['action']) {
			$nonce = $_REQUEST['_wpnonce'];
			if (!wp_verify_nonce($nonce, 'convertable-settings')) die('Security check');
			check_admin_referer('convertable-settings');
			if (!empty($_POST)) {
				//error_log('Convertable->settings->_POST('.print_r($_POST, true).')');
				$params = array();
				$params['accountEmail'] = (!empty($_POST['convertable_email'])) ? trim(strip_tags($_POST['convertable_email'])) : '';
				$params['accountName'] = (!empty($_POST['convertable_name'])) ? trim(strip_tags($_POST['convertable_name'])) : '';
				$params['alertEmail1'] = (!empty($_POST['convertable_alert_email1'])) ? trim(strip_tags($_POST['convertable_alert_email1'])) : '';
				$params['alertEmail2'] = (!empty($_POST['convertable_alert_email2'])) ? trim(strip_tags($_POST['convertable_alert_email2'])) : '';
				$params['alertEmail3'] = (!empty($_POST['convertable_alert_email3'])) ? trim(strip_tags($_POST['convertable_alert_email3'])) : '';
				$params['subDomain'] = (!empty($_POST['convertable_subdomain'])) ? trim(strip_tags($_POST['convertable_subdomain'])) : '';
				$params['accountURL'] = get_option('siteurl');
				//error_log('Convertable->settings->params('.print_r($params, true).')');
				$response = json_decode($this->_api->settings($this->settings['accountID'], $this->settings['secretKey'], $params));
				if ($response->success) {
					$redirect_args = array('message' => 'success');
					if (!empty($response->account)) {
						foreach($response->account as $key => $value) {
							$this->settings[$key] = $value;
						}
						update_option('convertable_options', $this->settings);
					}
				} else {
					$redirect_args = array('message' => 'failure');
				}
				if (!empty($response->message)) {
					$this->set_message($response->message);
				}
			}
			wp_safe_redirect(add_query_arg($redirect_args, remove_query_arg(array('action','_wpnonce'))));
			exit;
		}
	}

	function get_message() {
		$messages = (array) get_option('convertable_messages');
		if (!empty($messages[$this->user->ID])) {
			$msg = __($messages[$this->user->ID], 'convertable');
			//error_log('Convertable->messages->user_ID('.$this->user->ID.'): '.$msg);
			//error_log('Convertable->messages(before): '.print_r($messages, true));
			unset($messages[$this->user->ID]);
			//error_log('Convertable->messages(after): '.print_r($messages, true));
			update_option('convertable_messages', $messages);
			return $msg;
		} else {
			return;
		}
	}

	function set_message($message) {
		$messages = (array) get_option('convertable_messages');
		$messages[$this->user->ID] = $message;
		update_option('convertable_messages', $messages);
	}

	function show_messages() {
		if (!empty($_GET['message'])) {
			$msg_type = 'updated';
			switch ($_GET['message']) {
				case 'success':
					$msg = __('Success', 'convertable');
					$user_msg = $this->get_message();
					if (!empty($user_msg)) {
						$msg .= ': '.$user_msg;
					}
					break;
				case 'failure':
					$msg = __('Failure', 'convertable');
					$user_msg = $this->get_message();
					if (!empty($user_msg)) {
						$msg .= ': '.$user_msg;
					}
					$msg_type = 'error';
					break;
			}
			if (!empty($msg)) {
				echo '<div class="'.$msg_type.'"><p>'.esc_html($msg).'</p></div>';
			}
		}
	}

	function register_admin_styles() {
		wp_enqueue_style('convertable-admin-styles', plugins_url($this->convertable_dir.'/assets/css/admin.css'));
	}

	function register_admin_scripts() {
		// NOTHING
	}

	function register_site_scripts() {
		wp_enqueue_style('convertable', plugins_url($this->convertable_dir.'/assets/css/convertable.css'));
	}

	function formbuilder_admin_styles() {
		wp_enqueue_style('convertable-formbuilder', plugins_url($this->convertable_dir.'/assets/css/jquery.formbuilder.css'), array('convertable-admin-styles'), '1', 'all');
	}

	function formbuilder_admin_scripts() {
		wp_enqueue_script('convertable-formbuilder', plugins_url($this->convertable_dir.'/assets/js/jquery.formbuilder.js'), array('jquery','jquery-ui-sortable'), '1', false);
	}

	function register_settings_pages() {
		add_menu_page(__('Convertable', 'convertable-locale'), __('Convertable', 'convertable-locale'), 'manage_options', 'convertable', array($this, 'settings_page'), plugin_dir_url( __FILE__ ).'assets/images/icon.png');
		$formbuilder_page = add_submenu_page('convertable', __('Form Builder', 'convertable-locale'), __('Form Builder', 'convertable-locale'), 'manage_options', 'convertable_form', array($this, 'form_builder'));
		add_submenu_page('convertable', __('Reports', 'convertable-locale'), __('Reports', 'convertable-locale'), 'manage_options', 'convertable_reports', array($this, 'reports') );
		add_action('admin_print_styles-'.$formbuilder_page, array($this, 'formbuilder_admin_styles'));
		add_action('admin_print_scripts-'.$formbuilder_page, array($this, 'formbuilder_admin_scripts'));
	}

	function reports() {
		require CONVERTABLE_PATH.'views/header.php'; ?>
		<div id="convertable-charts" class="clearfix">
			<div id="convertable-pie-chart"></div>
			<div id="convertable-revenue-chart"></div>
			<div id="convertable-data-table">
				<div id="convertable-total-leads-label" class="convertable-label">Total Leads</div>
				<div id="convertable-total-leads">0</div>
				<div id="convertable-today-leads-label" class="convertable-label">Today</div>
				<div id="convertable-today-leads">0</div>
				<div id="convertable-week-leads-label" class="convertable-label">This Week</div>
				<div id="convertable-week-leads">0</div>
				<div id="convertable-month-leads-label" class="convertable-label">This Month</div>
				<div id="convertable-month-leads">0</div>
				<div id="convertable-revenue-label" class="convertable-label">Total Revenue</div>
				<div id="convertable-revenue">0</div>
			</div>
		</div>
		<h3>Leads</h3>
		<div id="convertable-leads-pager-top" class="tablenav top"></div>
		<table id="convertable-leads" class="wp-list-table widefat fixed posts" cellspacing="0">
			<thead>
				<tr>
					<th class="manage-column column-lead-detail" id="lead-detail" scope="col">Detail</th>
					<th class="manage-column column-lead-id" id="lead-id" scope="col">Lead#</th>
					<th class="manage-column column-lead-date" id="lead-date" scope="col">Date</th>
					<th class="manage-column column-medium" id="lead-medium" scope="col">Medium</th>
					<th class="manage-column column-source" id="lead-source" scope="col">Source</th>
					<th class="manage-column column-keyword" id="lead-keyword" scope="col">Keyword</th>
					<th class="manage-column column-status" id="lead-status" scope="col">Status</th>
					<th class="manage-column column-quoted" id="lead-quoted" scope="col">Quoted</th>
					<th class="manage-column column-awarded" id="lead-awarded" scope="col">Awarded</th>
					<th>&nbsp;</th>
				</tr>
			</thead>
			<tfoot>
				<tr>
					<th class="manage-column column-lead-detail" scope="col">Detail</th>
					<th class="manage-column column-lead-id" scope="col">Lead#</th>
					<th class="manage-column column-lead-date" scope="col">Date</th>
					<th class="manage-column column-medium" scope="col">Medium</th>
					<th class="manage-column column-source" scope="col">Source</th>
					<th class="manage-column column-keyword" scope="col">Keyword</th>
					<th class="manage-column column-status" scope="col">Status</th>
					<th class="manage-column column-quoted" scope="col">Quoted</th>
					<th class="manage-column column-awarded" scope="col">Awarded</th>
					<th>&nbsp;</th>
				</tr>
			</tfoot>
			<tbody id="lead-list"></tbody>
		</table>
		<div id="convertable-leads-pager-bottom" class="tablenav bottom"></div>
		<?php require CONVERTABLE_PATH.'views/footer.php';
	}

	function settings_page() {
		require CONVERTABLE_PATH.'views/header.php';
		if (!empty($this->settings['trackerID']) && !$this->is_demo) {
			$nonce = wp_create_nonce('convertable-settings');
			$form_url = $this->get_admin_url().'&action=settings&_wpnonce='.$nonce;
			?>
			<div class="metabox-holder">
				<form id="convertable_settings_form" method="post" action="<?php echo $form_url ?>">
					<input type="hidden" name="accountURL" id="accountURL" value="<?php echo get_option('siteurl'); ?>" />
					<div class="postbox">
						<h3><span>Convertable Settings</span></h3>
						<div class="inside">
							<table class="form-table">
								<tbody>
									<tr valign="top">
										<th scope="row"><label for="convertable_email">Account Email:</label></th>
										<td><input type="text" name="convertable_email" id="convertable_email" value="<?php echo (!empty($this->settings['accountEmail'])) ? $this->settings['accountEmail'] : ''; ?>" class="regular-text"></td>
									</tr>
									<tr valign="top">
										<th scope="row"><label for="convertable_name">Company:</label></th>
										<td><input type="text" name="convertable_name" id="convertable_name" value="<?php echo (!empty($this->settings['accountName'])) ? $this->settings['accountName'] : ''; ?>" class="regular-text"></td>
									</tr>
									<tr valign="top">
										<th scope="row"><label for="convertable_alert_email1">Alert Email 1:</label></th>
										<td><input type="text" name="convertable_alert_email1" id="convertable_alert_email1" value="<?php echo (!empty($this->settings['alertEmail1'])) ? $this->settings['alertEmail1'] : ''; ?>" class="regular-text"></td>
									</tr>
									<tr valign="top">
										<th scope="row"><label for="convertable_alert_email2">Alert Email 2:</label></th>
										<td><input type="text" name="convertable_alert_email2" id="convertable_alert_email2" value="<?php echo (!empty($this->settings['alertEmail2'])) ? $this->settings['alertEmail2'] : ''; ?>" class="regular-text"></td>
									</tr>
									<tr valign="top">
										<th scope="row"><label for="convertable_alert_email3">Alert Email 3:</label></th>
										<td><input type="text" name="convertable_alert_email3" id="convertable_alert_email3" value="<?php echo (!empty($this->settings['alertEmail3'])) ? $this->settings['alertEmail3'] : ''; ?>" class="regular-text"></td>
									</tr>
									<tr valign="top">
										<th scope="row"><label for="convertable_subdomain">Custom Login URL:</label></th>
										<td>http://<input type="text" name="convertable_subdomain" id="convertable_subdomain" value="<?php echo (!empty($this->settings['subDomain'])) ? $this->settings['subDomain'] : ''; ?>">.convertable.com</td>
									</tr>
									<tr valign="top">
								</tbody>
							</table>
							<div class="submit">
								<?php submit_button(__('Update Settings', 'convertable-locale'), 'primary', 'convertable_submit', '', array('style' => 'width: 120px')); ?>
							</div>
						</div>
					</div>
				</form>
			</div>
		<?php } else { ?>
			<div class="metabox-holder">
				<h2>Track the source of every single web lead with Convertable</h2>
				<p>Convertable combines a simple online form builder, real-time analytics and a simple back-end database to allow you the ability to create forms, store form submissions online, and find out where they originated. You even get an email alert with the submission information.</p>
				<p>Typical web analytic software stops tracking as soon as your customer submits the contact form. Convertable takes tracking to the next step, allowing you to attach a dollar amount to each lead - giving you insight to which sources actually result in sales. You can then allocate your marketing dollars to the most effective channels.</p>
				<p>Convertable is a fully web-based system so you don't have to install or download any software. Just log in to your account to check leads - it's always available.</p>
				<?php
				$nonce = wp_create_nonce('convertable-account');
				$form_url = $this->get_admin_url().'&action=account&_wpnonce='.$nonce;
				?>
				<div>
					<form id="convertable_account_form" method="post" action="<?php echo $form_url ?>">
						<div class="postbox">
							<h3><span>Create Your Free Convertable Account</span></h3>
							<div class="inside">
								<table class="form-table">
									<tbody>
										<tr valign="top">
											<th scope="row"><label for="convertable_user">Email Address:</label></th>
											<td><input type="text" name="convertable_user" id="convertable_user" value="" class="regular-text"></td>
										</tr>
										<tr valign="top">
											<th scope="row"><label for="convertable_password">Password:</label></th>
											<td><input type="password" name="convertable_password" id="convertable_password" value="" class="regular-text"></td>
										</tr>
										<tr valign="top">
											<th scope="row"><label for="convertable_password_conf">Retype Password:</label></th>
											<td><input type="password" name="convertable_password_conf" id="convertable_password_conf" value="" class="regular-text"></td>
										</tr>
									</tbody>
								</table>
								<div class="submit">
									<?php submit_button(__('Sign Up', 'convertable-locale'), 'primary', 'convertable_account_btn', '', array('style' => 'width: 100px')); ?>
								</div>
							</div>
						</div>
					</form>
				</div>
				<?php
				$nonce = wp_create_nonce('convertable-login');
				$form_url = $this->get_admin_url().'&action=login&_wpnonce='.$nonce;
				?>
				<div>
					<form id="convertable_login_form" method="post" action="<?php echo $form_url ?>">
						<div class="postbox">
							<h3><span>Sign-In To Existing Convertable Account</span></h3>
							<div class="inside">
								<table class="form-table">
									<tbody>
										<tr valign="top">
											<th scope="row"><label for="convertable_user">Username:</label></th>
											<td><input type="text" name="convertable_user" id="convertable_user" value="" class="regular-text"></td>
										</tr>
										<tr valign="top">
											<th scope="row"><label for="convertable_password">Password:</label></th>
											<td><input type="password" name="convertable_password" id="convertable_password" value="" class="regular-text"></td>
										</tr>
									</tbody>
								</table>
								<div class="submit">
									<?php submit_button(__('Log In', 'convertable-locale'), 'primary', 'convertable_login_btn', '', array('style' => 'width: 100px')); ?>
								</div>
							</div>
						</div>
					</form>
				</div>
			</div>
		<? }
		require CONVERTABLE_PATH.'views/footer.php';
	}

	function save_form_data($form) {
		//error_log('Convertable->save_form_data(): '.print_r($form, true));
		$data = array();
		foreach($form as $field) {
			if (!empty($field->fieldName) && !empty($field->fieldType)) {
				$data[] = array(
					'fieldName' => $field->fieldName,
					'fieldAlias' => $field->fieldAlias,
					'fieldType' => $field->fieldType,
					'fieldAlias' => $field->fieldAlias,
					'fieldSequence' => $field->fieldSequence,
					'fieldRule' => $field->fieldRule,
					'fieldRequired' => $field->fieldRequired
				);
			}
		}
		update_option('convertable_form', $data);
	}

	function shortcode_convertable($atts) {
		extract(shortcode_atts(array('width' => '100%'),$atts));
		$form = get_option('convertable_form');
		$form = (!empty($form)) ? (array) $form : array();
		$code = '';
		if (!empty($form)) {
			$code .= '<div class="convertable-form" style="width: '.$width.'">'."\n";
			if ($this->is_demo) {
				$code .= '<form id="LeadForm" name="LeadForm" method="post" action="#" onsubmit="return false;">'."\n";
			} else {
				$code .= '<form id="LeadForm" name="LeadForm" method="post" action="'.$this->convertable_url.'/admin/formprocess.php" show_alert="1" onsubmit="return Validate(this);">'."\n";
			}
			foreach ($form as $field) {
				$fieldAlias = (!empty($field['fieldAlias'])) ? $field['fieldAlias'] : ucwords($field['fieldName']);
				$code .= '<fieldset>'."\n";
				$code .= '<label for="'.$field['fieldName'].'">'.$fieldAlias.'</label>'."\n";
				if ($field['fieldRequired'] == '0') {
					$code .= '<span class="required"> * </span>'."\n";
				}
				if ($field['fieldType'] == 'textarea' || $field['fieldType'] == 'textareaL') {
					$code .= '<textarea name="'.$field['fieldName'].'" id="'.$field['fieldName'].'"';
					if ($field['fieldRequired'] == '0') {
						$code .= ' validate="not_empty" msg="'.$field['fieldAlias'].' is required." required';
					}
					$code .= ($field['fieldType'] == 'textareaL') ? ' class="textarea-standard">' : ' class="textarea-wide">';
					$code .= '</textarea>'."\n";
				} elseif ($field['fieldType'] == 'select') {
					$opts = explode(',',$field['fieldRule']);
					$opts = array_filter($opts);
					if (!empty($opts)) {
						$code .= '<select name="'.$field['fieldName'].'" id="'.$field['fieldName'].'"';
						if ($field['fieldRequired'] == '1') {
							$code .= ' validate="not_empty" msg="'.$field['fieldAlias'].' is required." required';
						}
						$code .= '>';
						foreach ($opts as $opt) {
							$code .= '<option value="'.trim($opt).'">'.trim($opt).'</option>'."\n";
						}
						$code .= '</select>'."\n";
					}
				} elseif ($field['fieldType'] == 'radio' || $field['fieldType'] == 'checkbox') {
					$opts = explode(',',$field['fieldRule']);
					$opts = array_filter($opts);
					if (!empty($opts)) {
						$code .= '<span class="options">'."\n";
						foreach ($opts as $i => $opt) {
							$field_id = $field['fieldName'].'-'.$i;
							$code .= '<input type="'.$field['fieldType'].'" name="'.$field['fieldName'];
							if ($field['fieldType'] == 'checkbox'){
								$code .= '[]';
							}
							$code .= '" id="'.$field_id.'" value="'.trim($opt).'"';
							if ($field['fieldRequired'] == '1') {
								$code .= ' validate="not_empty" msg="'.$field['fieldAlias'].' is required." required';
							}
							$code .= '> <label for="'.$field_id.'">'.trim($opt).'</label>'."\n";
						}
						$code .= '</span>'."\n";
					}
				} elseif (!empty($field['fieldName'])) {
					$text_size = (substr($field['fieldType'],0,5)=='text|') ? substr($field['fieldType'],5) : '20';
					$code .= '<input type="text" name="'.$field['fieldName'].'" id="'.$field['fieldName'].'" size="'.$text_size.'" maxlength="400"';
					if ($field['fieldRequired'] == '0') {
						$code .= ' validate="not_empty" msg="'.$field['fieldAlias'].' is required." required';
					}
					$code .= ' style="width: '.$text_size.'0px;" />'."\n";
				}
				$code .= '</fieldset>'."\n";
			}
			$code .= '<input type="hidden" name="uid" value="" />'."\n";
			$code .= '<input type="hidden" name="referrer" value="" />'."\n";
			$code .= '<input type="hidden" name="tracker"  value="" />'."\n";
			$code .= '<input type="hidden" name="entry" value="" />'."\n";
			$code .= '<input type="hidden" name="form" value="" />'."\n";
			$code .= '<input type="hidden" name="time" value="" />'."\n";
			$code .= '<input type="hidden" name="formName" value="'.$this->settings['accountID'].'" />'."\n";
			$code .= '<input type="hidden" id="action" name="action" value="submitform" />'."\n";
			if ($this->is_demo) {
				$code .= '<fieldset><input type="submit" name="submit" value="SUBMIT" id="submit" onclick="alert(\'Signup for a free Convertable account to enable this form\'); return false;"></fieldset>'."\n";
			} else {
				$code .= '<fieldset><input type="submit" name="submit" value="SUBMIT" id="submit" onclick="getForm();"></fieldset>'."\n";
			}
			$code .= '<fieldset><a href="'.$this->convertable_url.'" title="100% Free Lead Management Software" target="_blank"><img class="convertable-pwd-by" alt="100% Free Lead Management Software" src="'.plugins_url($this->convertable_dir.'/assets/images/convertable-pwd-by.png').'" /></a></fieldset>'."\n";
			$code .= '</form>'."\n";
			$code .= '</div>'."\n";
		} else {
			if (current_user_can('manage_options')) {
				$code .= '<p>No convertable form data. Please <a href="'.$this->get_admin_url().'">manage your Convertable form</a>.</p>';
			}
		}
		return $code;
	}

	function tracking_code() {
		if (!empty($this->settings['trackerID']) && !$this->is_demo) :
?><script type="text/javascript">
var trackerID = '<?php echo $this->settings['trackerID']; ?>';
</script>
<script type="text/javascript" src="<?php echo $this->convertable_url; ?>/admin/script.js"></script>
<?php endif;
	}

}

add_action('init', 'convertableInit');
function convertableInit() {
	global $convertable;
	$convertable = new Convertable();
}
Convertable::init();